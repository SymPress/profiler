<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Profiler\Value\ProfileSearchCriteria;

final class ProfilerUrlGenerator
{
    public function __construct(
        private readonly string $profilerPrefix = '/_profiler',
        private readonly string $toolbarPrefix = '/_wdt',
    ) {
    }

    /** @param array<string, scalar|null> $query */
    public function home(array $query = []): string
    {
        return $this->withQuery($this->url($this->profilerPrefix), $query);
    }

    /** @param array<string, scalar|null> $query */
    public function latest(array $query = []): string
    {
        return $this->withQuery($this->url($this->profilerPrefix . '/latest'), $query);
    }

    /** @param array<string, scalar|null> $query */
    public function profile(string $token, string $panel = 'request', array $query = []): string
    {
        $url = $this->url($this->profilerPrefix . '/' . rawurlencode($token));
        $query['panel'] = $panel;

        return $this->withQuery($url, $query);
    }

    /** @param ProfileSearchCriteria|array<string, scalar|null> $criteria */
    public function search(ProfileSearchCriteria|array $criteria = []): string
    {
        if ($criteria instanceof ProfileSearchCriteria) {
            return $this->home($criteria->toQueryArgs());
        }

        return $this->home($criteria);
    }

    public function last(int $limit = 10): string
    {
        return $this->home(['limit' => max(1, $limit)]);
    }

    public function toolbar(string $token): string
    {
        return $this->url($this->toolbarPrefix . '/' . rawurlencode($token));
    }

    public function toolbarPlaceholder(): string
    {
        return $this->toolbar('xxxxxx');
    }

    public function toolbarStylesheet(): string
    {
        return $this->url($this->toolbarPrefix . '/styles');
    }

    public function font(string $fontName): string
    {
        return $this->url($this->profilerPrefix . '/font/' . rawurlencode($fontName));
    }

    public function basePath(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        $homePath = (string) (parse_url((string) home_url('/'), PHP_URL_PATH) ?? '');

        if ($homePath === '' || $homePath === '/') {
            return '';
        }

        return rtrim($homePath, '/');
    }

    public function absoluteBasePath(): string
    {
        if (!function_exists('home_url')) {
            return $this->basePath();
        }

        $parts = parse_url((string) home_url('/'));

        if (!is_array($parts)) {
            return $this->basePath();
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = $this->basePath();

        if ($scheme === '' || $host === '') {
            return $path;
        }

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
    }

    private function url(string $path): string
    {
        if (function_exists('home_url')) {
            return (string) home_url($path);
        }

        return $path;
    }

    /** @param array<string, scalar|null> $query */
    private function withQuery(string $url, array $query): string
    {
        $filtered = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($filtered === []) {
            return $url;
        }

        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($filtered, $url);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($filtered);
    }
}
