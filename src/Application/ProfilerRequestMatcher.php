<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Profiler\Value\ProfileSearchCriteria;

final class ProfilerRequestMatcher
{
    public function __construct(
        private readonly string $profilerPrefix = '/_profiler',
        private readonly string $toolbarPrefix = '/_wdt',
    ) {
    }

    public function isInternalProfilerRequest(): bool
    {
        $path = $this->path();

        return str_starts_with($path, $this->profilerPrefix)
            || str_starts_with($path, $this->toolbarPrefix);
    }

    public function isProfilerRequest(): bool
    {
        return str_starts_with($this->path(), $this->profilerPrefix);
    }

    public function isToolbarRequest(): bool
    {
        return preg_match('#^' . preg_quote($this->toolbarPrefix, '#') . '/(?P<token>[A-Za-z0-9_-]+)/?$#', $this->path()) === 1;
    }

    public function isToolbarStylesheetRequest(): bool
    {
        return $this->path() === $this->toolbarPrefix . '/styles';
    }

    public function isFontRequest(): bool
    {
        return preg_match('#^' . preg_quote($this->profilerPrefix, '#') . '/font/(?P<font>[^/]+)$#', $this->path()) === 1;
    }

    public function token(): ?string
    {
        if (preg_match('#^' . preg_quote($this->profilerPrefix, '#') . '/(?P<token>[A-Za-z0-9_-]+)/?$#', $this->path(), $matches) !== 1) {
            return null;
        }

        $token = $matches['token'];

        if ($token === 'font') {
            return null;
        }

        return $token;
    }

    public function toolbarToken(): ?string
    {
        if (preg_match('#^' . preg_quote($this->toolbarPrefix, '#') . '/(?P<token>[A-Za-z0-9_-]+)/?$#', $this->path(), $matches) !== 1) {
            return null;
        }

        return $matches['token'];
    }

    public function fontName(): ?string
    {
        if (preg_match('#^' . preg_quote($this->profilerPrefix, '#') . '/font/(?P<font>[^/]+)$#', $this->path(), $matches) !== 1) {
            return null;
        }

        return rawurldecode($matches['font']);
    }

    public function panel(string $default = 'request'): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing parameter.
        $panel = $this->queryParam('panel');

        if ($panel === null || preg_match('/^[a-z0-9_-]+$/i', $panel) !== 1) {
            return $default;
        }

        return strtolower($panel);
    }

    public function searchQuery(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search parameter.
        return $this->queryParam('q') ?? '';
    }

    public function searchCriteria(int $defaultLimit = 50): ProfileSearchCriteria
    {
        $status = $this->queryParam('status');
        $limit = $this->queryParam('limit');

        return new ProfileSearchCriteria(
            $this->queryParam('q') ?? '',
            $this->queryParam('token') ?? '',
            $this->queryParam('method') ?? '',
            $this->queryParam('url') ?? '',
            $this->queryParam('ip') ?? '',
            is_numeric($status) ? (int) $status : null,
            $this->queryParam('context') ?? '',
            is_numeric($limit) ? (int) $limit : $defaultLimit,
            $this->queryParam('start') ?? '',
            $this->queryParam('end') ?? '',
        );
    }

    public function path(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- parsed as a routing path only.
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        if (!is_scalar($requestUri) && !$requestUri instanceof \Stringable) {
            $requestUri = '/';
        }

        $requestUri = function_exists('wp_unslash') ? wp_unslash((string) $requestUri) : (string) $requestUri;
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);
        $basePath = $this->basePath();

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        return '/' . ltrim($path, '/');
    }

    private function basePath(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        $homePath = (string) (parse_url((string) home_url('/'), PHP_URL_PATH) ?? '');

        if ($homePath === '/' || $homePath === '') {
            return '';
        }

        return rtrim($homePath, '/');
    }

    private function queryParam(string $key): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only profiler routes.
        if (!array_key_exists($key, $_GET)) {
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized below.
        $value = $_GET[$key];

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $string = (string) $value;
        $unslashed = function_exists('wp_unslash') ? wp_unslash($string) : $string;

        return function_exists('sanitize_text_field')
            ? sanitize_text_field($unslashed)
            : trim($unslashed);
    }
}
