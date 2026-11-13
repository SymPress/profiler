<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Kernel\WpContext;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Contract\ProfileStorageInterface;
use SymPress\Profiler\Support\ArraySanitizer;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;

final class Profiler
{
    private bool $started = false;
    private bool $finalized = false;
    private bool $forceEnabled = false;
    private bool $disabled = false;
    private bool $htmlBufferStarted = false;
    private float $startedAt = 0.0;
    private int $startMemoryBytes = 0;
    /** @var array{finished_at: float, memory: int, peak_memory: int}|null */
    private ?array $measurement = null;
    private string $token = '';
    private ?string $template = null;
    private ?ProfileRecord $profile = null;

    /** @var list<array{class: string, message: string, file: string, line: int}> */
    private array $throwables = [];

    /** @param iterable<DataCollectorInterface> $collectors */
    public function __construct(
        private readonly iterable $collectors,
        private readonly ProfileGate $gate,
        private readonly ProfileStorageInterface $storage,
        private readonly ProfileViewBuilder $viewBuilder,
        private readonly ProfilerUrlGenerator $urls,
        private readonly WpContext $context,
    ) {
    }

    public function start(): void
    {
        if ($this->started || $this->disabled || (!$this->forceEnabled && !$this->gate->shouldCollect())) {
            return;
        }

        $this->started = true;
        $this->startedAt = $this->requestStartedAt();
        $this->startMemoryBytes = memory_get_usage(true);
        $this->token = bin2hex(random_bytes(6));
        $this->emitHeaders();
    }

    public function enable(): void
    {
        if (!$this->gate->canAccessProfiler()) {
            return;
        }

        $this->disabled = false;
        $this->forceEnabled = true;
        $this->start();
    }

    public function disable(): void
    {
        $this->disabled = true;
        $this->profile = null;
        $this->finalized = true;
    }

    public function isEnabled(): bool
    {
        return $this->started && !$this->disabled;
    }

    public function isDisabled(): bool
    {
        return $this->disabled || (!$this->started && !$this->forceEnabled && !$this->gate->shouldCollect());
    }

    public function loadProfile(string $token): ?ProfileRecord
    {
        return $this->storage->load($token);
    }

    public function loadProfileFromResponse(mixed $response): ?ProfileRecord
    {
        $token = $this->debugTokenFromResponse($response);

        return is_string($token) && $token !== ''
            ? $this->loadProfile($token)
            : null;
    }

    /** @return list<ProfileRecord> */
    public function find(
        string $ip = '',
        string $url = '',
        int $limit = 10,
        string $method = '',
        string $start = '',
        string $end = '',
    ): array {

        return $this->storage->search(new ProfileSearchCriteria(
            method: $method,
            url: $url,
            ip: $ip,
            limit: $limit,
            start: $start,
            end: $end,
        ));
    }

    public function beginHtmlBuffer(): void
    {
        if (!$this->started || $this->disabled || $this->htmlBufferStarted || !$this->gate->shouldInjectToolbar()) {
            return;
        }

        $this->htmlBufferStarted = ob_start($this->injectToolbar(...));
    }

    public function captureTemplate(mixed $template): mixed
    {
        // Core accepts any value from template_include filters; record strings and pass everything through.
        $this->template = is_string($template) ? $template : null;

        return $template;
    }

    public function recordThrowable(\Throwable $throwable): void
    {
        if (!$this->started || $this->disabled) {
            return;
        }

        $this->throwables[] = $this->throwableSummary($throwable);
    }

    /** @return array{class: string, message: string, file: string, line: int} */
    private function throwableSummary(\Throwable $throwable): array
    {
        return [
            'class'   => $throwable::class,
            'message' => $throwable->getMessage(),
            'file'    => $throwable->getFile(),
            'line'    => $throwable->getLine(),
        ];
    }

    public function finish(): void
    {
        $this->ensureProfile(true);
    }

    private function injectToolbar(string $content, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
    {
        // An explicit flush hands over a partial document: wait for the final chunk.
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0) {
            return $content;
        }

        $profile = $this->ensureProfile();

        if (!$profile instanceof ProfileRecord || !$this->shouldInjectIntoPayload($content)) {
            return $content;
        }

        $toolbar = $this->viewBuilder->renderToolbarBootstrap($profile);

        if ($toolbar === '') {
            return $content;
        }

        // The last closing tag ends the document; earlier ones can sit inside inline scripts or templates.
        $position = strripos($content, '</body>');

        return $position === false ? $content . $toolbar : substr_replace($content, $toolbar, $position, 0);
    }

    private function ensureProfile(bool $finalize = false): ?ProfileRecord
    {
        if (!$this->started || $this->disabled || !$this->gate->canAccessProfiler()) {
            return null;
        }

        // Ask again: a theme or plugin may add a profiler.collect filter after the gate opened at plugins_loaded.
        if (!$this->forceEnabled && !$this->gate->shouldCollect()) {
            return null;
        }

        if ($this->finalized && $this->profile instanceof ProfileRecord) {
            return $this->profile;
        }

        // The toolbar is rendered when WordPress flushes the page at shutdown priority 1. That profile is
        // provisional: finish() collects again at the end of shutdown so fatal errors, late log entries
        // and the shutdown timeline reach the stored profile (Symfony's collect/lateCollect split).

        // Measure once, when the response is done: the final pass must not add shutdown work and the
        // profiler's own collection to the request duration and memory.
        $this->measurement ??= [
            'finished_at' => microtime(true),
            'memory'      => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];

        $context = new ProfileContext(
            $this->token,
            $this->startedAt,
            $this->measurement['finished_at'],
            $this->startMemoryBytes,
            $this->measurement['memory'],
            $this->measurement['peak_memory'],
            $this->statusCode(),
            $this->template,
            $this->throwables,
            $this->headerMap(),
            $this->profilerUrl($this->token),
        );

        $collectorPayloads = [];
        $sanitizer = new ArraySanitizer();

        foreach ($this->collectors as $collector) {
            try {
                $collectorPayloads[$collector->getKey()] = $sanitizer->normalizePayload($collector->collect($context));
            } catch (\Throwable $throwable) {
                // A failing collector must not break the page or the other collectors.
                $collectorPayloads[$collector->getKey()] = [
                    ProfileViewBuilder::COLLECTOR_ERROR_KEY => $this->throwableSummary($throwable),
                ];
            }
        }

        $this->profile = new ProfileRecord(
            $context->token(),
            $context->finishedAtIso(),
            $this->meta($context),
            $collectorPayloads,
        );

        try {
            $this->storage->save($this->profile);
        } catch (\Throwable $throwable) {
            // Profiling runs during shutdown: an unwritable store must not turn into a fatal error.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the only channel left during shutdown; the page must not fail.
            error_log(sprintf('Profiler could not store profile %s: %s', $this->token, $throwable->getMessage()));
        }

        $this->finalized = $finalize;

        return $this->profile;
    }

    /** @return array<string, mixed> */
    private function meta(ProfileContext $context): array
    {
        $requestUri = $this->serverValue('REQUEST_URI', '/');

        return [
            'method'         => strtoupper($this->serverValue('REQUEST_METHOD', 'GET')),
            'uri'            => $requestUri,
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native path-only diagnostics also run before WordPress URL helpers are available.
            'path'           => (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/'),
            'url'            => $this->currentUrl(),
            'ip'             => $this->serverValue('REMOTE_ADDR'),
            'referer'        => $this->serverValue('HTTP_REFERER'),
            'content_type'   => $this->serverValue('CONTENT_TYPE'),
            'status_code'    => $context->statusCode(),
            'duration_ms'    => $context->durationMs(),
            'memory_mb'      => $context->memoryMb(),
            'peak_memory_mb' => $context->peakMemoryMb(),
            'context'        => $this->contextLabel(),
            'template'       => $context->template(),
            'profiler_url'   => $context->profilerUrl(),
            'user'           => $this->currentUserSummary(),
        ];
    }

    /** @return array{id: int, login: string, roles: list<string>}|null */
    private function currentUserSummary(): ?array
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !function_exists('wp_get_current_user')) {
            return null;
        }

        $user = wp_get_current_user();
        $roles = array_values($user->roles);

        return [
            'id'    => (int) $user->ID,
            'login' => (string) ($user->user_login ?? ''),
            'roles' => $roles,
        ];
    }

    private function emitHeaders(): void
    {
        // Page caches that store logged-in responses would otherwise serve the admin's toolbar to others.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (headers_sent()) {
            return;
        }

        header('Cache-Control: private, no-cache, max-age=0, must-revalidate', true);
        header('X-Accel-Expires: 0', true);
        header(sprintf('X-Debug-Token: %s', $this->token), true);
        header(sprintf('X-Debug-Token-Link: %s', $this->profilerUrl($this->token)), true);

        if (!$this->gate->shouldReplaceToolbarAfterAjax()) {
            return;
        }

        header('Symfony-Debug-Toolbar-Replace: 1', true);
    }

    private function debugTokenFromResponse(mixed $response): ?string
    {
        if (is_array($response)) {
            return $this->debugTokenFromHeaderArray($response);
        }

        if (!is_object($response)) {
            return null;
        }

        if (method_exists($response, 'getHeaderLine')) {
            $value = $response->getHeaderLine('X-Debug-Token');

            if (is_scalar($value) || $value instanceof \Stringable) {
                return trim((string) $value) ?: null;
            }
        }

        if (method_exists($response, 'getHeader')) {
            $value = $response->getHeader('X-Debug-Token');

            if (is_array($value)) {
                return $this->firstScalarValue($value);
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                return trim((string) $value) ?: null;
            }
        }

        $headers = $this->objectProperty($response, 'headers');

        if (is_object($headers) && method_exists($headers, 'get')) {
            $value = $headers->get('X-Debug-Token');

            if (is_scalar($value) || $value instanceof \Stringable) {
                return trim((string) $value) ?: null;
            }
        }

        if (is_array($headers)) {
            return $this->debugTokenFromHeaderArray($headers);
        }

        return null;
    }

    /** @param array<array-key, mixed> $headers */
    private function debugTokenFromHeaderArray(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'x-debug-token') {
                continue;
            }

            if (is_array($value)) {
                return $this->firstScalarValue($value);
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                return trim((string) $value) ?: null;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $values */
    private function firstScalarValue(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                return trim((string) $value) ?: null;
            }
        }

        return null;
    }

    private function objectProperty(object $object, string $property): mixed
    {
        if (!property_exists($object, $property) && !isset($object->{$property})) {
            return null;
        }

        return $object->{$property};
    }

    /** @return array<string, string|list<string>> */
    private function headerMap(): array
    {
        $headers = [];

        foreach (headers_list() as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $normalizedName = strtolower(trim($name));

            if ($normalizedName === '' || $value === '') {
                continue;
            }

            // A response can repeat a header (Link, Set-Cookie, Vary): keep every value.
            $headers[$normalizedName] = array_key_exists($normalizedName, $headers)
                ? [...(array) $headers[$normalizedName], trim($value)]
                : trim($value);
        }

        return $headers;
    }

    private function statusCode(): int
    {
        $statusCode = http_response_code();

        if (is_int($statusCode) && $statusCode > 0) {
            return $statusCode;
        }

        if (isset($GLOBALS['wp_query']) && is_object($GLOBALS['wp_query']) && method_exists($GLOBALS['wp_query'], 'is_404')) {
            return $GLOBALS['wp_query']->is_404() ? 404 : 200;
        }

        return 200;
    }

    private function shouldInjectIntoPayload(string $content): bool
    {
        if (trim($content) === '') {
            return false;
        }

        $contentType = implode(', ', (array) ($this->headerMap()['content-type'] ?? []));

        if (
            $contentType !== ''
            && !str_contains($contentType, 'text/html')
            && !str_contains($contentType, 'application/xhtml+xml')
        ) {
            return false;
        }

        $trimmedContent = ltrim($content);

        return str_starts_with($trimmedContent, '<!DOCTYPE html')
            || str_starts_with($trimmedContent, '<html')
            || str_contains($trimmedContent, '<body')
            // After an explicit flush the final chunk only holds the end of the document.
            || stripos($trimmedContent, '</body>') !== false;
    }

    private function profilerUrl(string $token): string
    {
        return $this->urls->profile($token);
    }

    private function currentUrl(): string
    {
        $scheme = 'http';

        if (
            (function_exists('is_ssl') && is_ssl())
            || strtolower($this->serverValue('HTTPS')) === 'on'
        ) {
            $scheme = 'https';
        }

        $host = $this->serverValue('HTTP_HOST', 'localhost');
        $requestUri = $this->serverValue('REQUEST_URI', '/');

        return sprintf('%s://%s%s', $scheme, $host, $requestUri);
    }

    private function contextLabel(): string
    {
        return match (true) {
            $this->context->isAjax() => WpContext::AJAX,
            $this->context->isRest() => WpContext::REST,
            $this->context->isBackoffice() => WpContext::BACKOFFICE,
            $this->context->isFrontoffice() => WpContext::FRONTOFFICE,
            $this->context->isLogin() => WpContext::LOGIN,
            default => WpContext::CORE,
        };
    }

    private function serverValue(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $_SERVER)) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- raw value is intentional: the profiler shows request data verbatim and views escape output.
        $value = $_SERVER[$key];

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return $default;
        }

        $string = (string) $value;

        return function_exists('wp_unslash') ? wp_unslash($string) : $string;
    }

    private function requestStartedAt(): float
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- numeric server timestamp, not user-controlled text input.
        $value = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_numeric($value) ? (float) $value : microtime(true);
    }
}
