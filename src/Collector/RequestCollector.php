<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Kernel\WpContext;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\ArraySanitizer;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class RequestCollector extends AbstractCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly ArraySanitizer $sanitizer,
        private readonly WpContext $context,
    ) {
    }

    public function getKey(): string
    {
        return 'request';
    }

    public function getLabel(): string
    {
        return 'Request / Response';
    }

    public function getIcon(): string
    {
        return 'request';
    }

    public function collect(ProfileContext $context): array
    {
        $requestMethod = strtoupper($this->serverValue('REQUEST_METHOD', 'GET'));
        $requestUri = $this->serverValue('REQUEST_URI', '/');
        $responseHeaders = $context->responseHeaders();

        return [
            'method'                => $requestMethod,
            'uri'                   => $requestUri,
            'url'                   => $this->currentUrl(),
            'ip'                    => $this->serverValue('REMOTE_ADDR'),
            'user_agent'            => $this->serverValue('HTTP_USER_AGENT'),
            'referer'               => $this->serverValue('HTTP_REFERER'),
            'content_type'          => $this->serverValue('CONTENT_TYPE'),
            'context'               => $this->contextLabel(),
            'context_flags'         => $this->contextFlags(),
            'status_code'           => $context->statusCode(),
            'status_text'           => $this->statusText($context->statusCode()),
            'started_at'            => $context->startedAtIso(),
            'finished_at'           => $context->finishedAtIso(),
            'duration_ms'           => $context->durationMs(),
            'memory_mb'             => $context->memoryMb(),
            'peak_memory_mb'        => $context->peakMemoryMb(),
            'template'              => $context->template(),
            'request_headers'       => $this->requestHeaders(),
            'response_headers'      => $responseHeaders,
            'response_content_type' => $responseHeaders['content-type'] ?? '',
            // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
            'query'                 => $this->requestArray($_GET),
            'post'                  => $this->requestArray($_POST),
            'cookies'               => $this->requestArray($_COOKIE),
            'files'                 => $this->filesArray($_FILES),
            // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
            'server'                => $this->serverParameters(),
            'user'                  => $this->currentUser(),
            'throwables'            => $context->throwables(),
            'php_error'             => $this->lastPhpError(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $duration = $this->floatValue($payload, 'duration_ms');
        $statusCode = $this->intValue($payload, 'status_code', 200);
        $path = (string) (parse_url($this->stringValue($payload, 'uri', '/'), PHP_URL_PATH) ?? '/');
        $accent = $statusCode >= 500 ? 'red' : ($statusCode >= 400 ? 'yellow' : 'cyan');

        return new ToolbarBlock(
            'request',
            'Request',
            sprintf('%.1f ms', $duration),
            sprintf('%s %s · %d', $this->stringValue($payload, 'method', 'GET'), $path, $statusCode),
            $this->profileUrl($profile, 'request'),
            $accent,
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $query = $this->arrayPayload($payload, 'query');
        $post = $this->arrayPayload($payload, 'post');
        $files = $this->arrayPayload($payload, 'files');
        $cookies = $this->arrayPayload($payload, 'cookies');
        $server = $this->arrayPayload($payload, 'server');
        $requestHeaders = $this->arrayPayload($payload, 'request_headers');
        $responseHeaders = $this->arrayPayload($payload, 'response_headers');
        $attributes = $this->requestAttributes($payload, $profile);

        $requestHtml = '<div class="profiler-request-parameters">';
        $requestHtml .= '<section><h3>GET Parameters</h3>' . Html::parameterBox($this->dumpedArray($query)) . '</section>';
        $requestHtml .= '<section><h3>POST Parameters</h3>' . Html::parameterBox($this->dumpedArray($post)) . '</section>';
        $requestHtml .= '<section><h3>Uploaded Files</h3>' . Html::parameterBox($this->dumpedArray($files)) . '</section>';
        $requestHtml .= '</div>';
        $requestHtml .= Html::section('Request Attributes', Html::keyValueTable($attributes));
        $requestHtml .= Html::section('Request Headers', Html::keyValueTable($this->dumpedArray($requestHeaders), 'Header', 'Value'));
        $requestHtml .= Html::section(
            'Request Content',
            '<div class="empty profiler-empty-box"><p>Request content not available (it was retrieved as a resource).</p></div>',
        );

        $responseHtml = Html::section('Response Headers', Html::keyValueTable($this->dumpedArray($responseHeaders), 'Header', 'Value'));
        $responseHtml .= Html::section('Response Metadata', Html::keyValueTable($this->dumpedArray([
            'status_code'    => $this->intValue($payload, 'status_code', 200),
            'status_text'    => $this->stringValue($payload, 'status_text'),
            'content_type'   => $this->stringValue($payload, 'response_content_type'),
            'duration_ms'    => $this->floatValue($payload, 'duration_ms'),
            'memory_mb'      => $this->floatValue($payload, 'memory_mb'),
            'peak_memory_mb' => $this->floatValue($payload, 'peak_memory_mb'),
        ])));

        $html = '<h2>' . Html::escape($this->requestTitle($payload)) . '</h2>';
        $html .= Html::tabs([
            ['label' => 'Request', 'content' => $requestHtml, 'active' => true],
            ['label' => 'Response', 'content' => $responseHtml],
            ['label' => 'Cookies', 'content' => Html::section('Cookies', Html::keyValueTable($this->dumpedArray($cookies), 'Name', 'Value'))],
            ['label' => 'Session', 'content' => Html::emptyPanel('No session data was captured.')],
            ['label' => 'Flashes', 'content' => Html::emptyPanel('No flash messages were captured.')],
            ['label' => 'Server Parameters', 'content' => Html::section('Server Parameters', Html::keyValueTable($this->dumpedArray($server), 'Parameter', 'Value'))],
        ]);

        return $this->panel(
            'request',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            (string) $this->intValue($payload, 'status_code', 200),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>
     */
    private function arrayPayload(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function dumpedArray(array $values): array
    {
        $dumped = [];

        foreach ($values as $key => $value) {
            $dumped[(string) $key] = Html::dumpValue($value);
        }

        return $dumped;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestAttributes(array $payload, ProfileRecord $profile): array
    {
        $attributes = [
            '_stopwatch_token' => Html::dumpValue($profile->token),
            '_context'         => Html::dumpValue($this->stringValue($payload, 'context')),
            '_context_flags'   => Html::dumpValue($this->stringList($this->arrayPayload($payload, 'context_flags'))),
            '_template'        => Html::dumpValue($this->stringValue($payload, 'template')),
            '_started_at'      => Html::dumpValue($this->stringValue($payload, 'started_at')),
            '_finished_at'     => Html::dumpValue($this->stringValue($payload, 'finished_at')),
            '_ip'              => Html::dumpValue($this->stringValue($payload, 'ip')),
            '_user_agent'      => Html::dumpValue($this->stringValue($payload, 'user_agent')),
        ];

        $user = $payload['user'] ?? null;

        if ($user !== null) {
            $attributes['_user'] = Html::dumpValue($user);
        }

        if (($payload['throwables'] ?? []) !== []) {
            $attributes['_throwables'] = Html::dumpValue($payload['throwables']);
        }

        if (($payload['php_error'] ?? null) !== null) {
            $attributes['_php_error'] = Html::dumpValue($payload['php_error']);
        }

        return $attributes;
    }

    /** @param array<string, mixed> $payload */
    private function requestTitle(array $payload): string
    {
        $template = $this->stringValue($payload, 'template');

        if ($template !== '') {
            return basename($template);
        }

        $uri = $this->stringValue($payload, 'uri', '/');
        $method = $this->stringValue($payload, 'method', 'GET');

        return sprintf('%s %s', strtoupper($method), $uri);
    }

    /** @return array<string, string> */
    private function requestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || (!str_starts_with($key, 'HTTP_') && $key !== 'CONTENT_TYPE')) {
                continue;
            }

            $name = $key === 'CONTENT_TYPE'
                ? 'content-type'
                : strtolower(str_replace('_', '-', substr($key, 5)));
            $sanitized = $this->sanitizer->sanitize($value, 0, $name);
            $headers[$name] = is_scalar($sanitized) || $sanitized === null ? (string) $sanitized : '[complex]';
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function filesArray(array $files): array
    {
        if ($files === []) {
            return [];
        }

        return $this->sanitizer->sanitizeArray($files);
    }

    /** @return array<string, mixed> */
    private function serverParameters(): array
    {
        $server = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $server[$key] = $value;
        }

        $sanitized = $this->sanitizer->sanitizeArray($server);
        $normalized = [];

        foreach ($sanitized as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /** @return array{id: int, login: string, roles: list<string>}|null */
    private function currentUser(): ?array
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

    /** @return array{type: int, message: string, file: string, line: int}|null */
    private function lastPhpError(): ?array
    {
        $error = error_get_last();

        if (!is_array($error)) {
            return null;
        }

        return [
            'type'    => (int) $error['type'],
            'message' => (string) $error['message'],
            'file'    => (string) $error['file'],
            'line'    => (int) $error['line'],
        ];
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

    /** @return list<string> */
    private function contextFlags(): array
    {
        $flags = [];

        foreach (
            [
                WpContext::CORE        => $this->context->isCore(),
                WpContext::FRONTOFFICE => $this->context->isFrontoffice(),
                WpContext::BACKOFFICE  => $this->context->isBackoffice(),
                WpContext::AJAX        => $this->context->isAjax(),
                WpContext::LOGIN       => $this->context->isLogin(),
                WpContext::REST        => $this->context->isRest(),
                WpContext::CRON        => $this->context->isCron(),
                WpContext::CLI         => $this->context->isWpCli(),
                WpContext::XML_RPC     => $this->context->isXmlRpc(),
            ] as $name => $active
        ) {
            if (!$active) {
                continue;
            }

            $flags[] = $name;
        }

        return $flags;
    }

    /**
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    private function requestArray(array $source): array
    {
        $unslashed = function_exists('wp_unslash') ? wp_unslash($source) : $source;

        return $this->sanitizer->sanitizeArray($unslashed);
    }

    private function serverValue(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $_SERVER)) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized centrally below.
        $value = $_SERVER[$key];

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return $default;
        }

        $string = (string) $value;
        $unslashed = function_exists('wp_unslash') ? wp_unslash($string) : $string;

        return function_exists('sanitize_text_field')
            ? sanitize_text_field($unslashed)
            : $unslashed;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_scalar($value) && !($value instanceof \Stringable)) {
                continue;
            }

            $normalized[] = (string) $value;
        }

        return $normalized;
    }

    private function statusText(int $statusCode): string
    {
        if (function_exists('get_status_header_desc')) {
            return (string) get_status_header_desc($statusCode);
        }

        return '';
    }
}
