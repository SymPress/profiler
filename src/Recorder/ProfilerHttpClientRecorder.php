<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerHttpClientRecorder
{
    private bool $enabled = false;

    /** @var array<string, list<array{started_at: float, method: string, url: string, args: array<string, mixed>}>> */
    private array $pending = [];

    /** @var list<array{method: string, url: string, status_code: int|null, error: string, duration_ms: float, transport: string, blocking: bool, timeout: float, response_size: int, args: array<string, mixed>}> */
    private array $entries = [];

    public function __construct(
        private readonly ProfileGate $gate,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = $this->gate->shouldCollect();
    }

    /** @param array<string, mixed> $parsedArgs */
    public function track(mixed $preempt, array $parsedArgs, string $url): mixed
    {
        if (!$this->enabled) {
            return $preempt;
        }

        $method = strtoupper($this->mixedToString($parsedArgs['method'] ?? 'GET'));
        $key = $this->pendingKey($method, $url);
        $this->pending[$key] ??= [];
        $this->pending[$key][] = [
            'started_at' => microtime(true),
            'method'     => $method,
            'url'        => $url,
            'args'       => $this->normalizeArgs($parsedArgs),
        ];

        return $preempt;
    }

    /** @param array<string, mixed> $parsedArgs */
    public function record(mixed $response, string $context, string $class, array $parsedArgs, string $url): void
    {
        if (!$this->enabled || $context !== 'response') {
            return;
        }

        $method = strtoupper($this->mixedToString($parsedArgs['method'] ?? 'GET'));
        $pending = $this->popPending($method, $url);
        $startedAt = $pending['started_at'] ?? microtime(true);
        $statusCode = null;
        $error = '';
        $responseSize = 0;

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
        } elseif (is_array($response)) {
            $responseMeta = is_array($response['response'] ?? null) ? $response['response'] : [];
            $statusCode = is_numeric($responseMeta['code'] ?? null)
                ? (int) $responseMeta['code']
                : null;
            $body = $response['body'] ?? '';
            $responseSize = is_string($body) ? strlen($body) : 0;
        }

        $this->entries[] = [
            'method'        => $method,
            'url'           => $url,
            'status_code'   => $statusCode,
            'error'         => $error,
            'duration_ms'   => round((microtime(true) - $startedAt) * 1000, 2),
            'transport'     => $class,
            'blocking'      => (bool) ($parsedArgs['blocking'] ?? true),
            'timeout'       => is_numeric($parsedArgs['timeout'] ?? null) ? (float) $parsedArgs['timeout'] : 0.0,
            'response_size' => $responseSize,
            'args'          => $pending['args'] ?? $this->normalizeArgs($parsedArgs),
        ];
    }

    /** @return list<array{method: string, url: string, status_code: int|null, error: string, duration_ms: float, transport: string, blocking: bool, timeout: float, response_size: int, args: array<string, mixed>}> */
    public function entries(): array
    {
        return $this->entries;
    }

    private function pendingKey(string $method, string $url): string
    {
        return $method . ' ' . $url;
    }

    /** @return array{started_at: float, method: string, url: string, args: array<string, mixed>}|null */
    private function popPending(string $method, string $url): ?array
    {
        $key = $this->pendingKey($method, $url);

        if (($this->pending[$key] ?? []) === []) {
            return null;
        }

        $entry = array_shift($this->pending[$key]);

        if ($this->pending[$key] === []) {
            unset($this->pending[$key]);
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $parsedArgs
     * @return array<string, mixed>
     */
    private function normalizeArgs(array $parsedArgs): array
    {
        $normalized = [];

        foreach (['method', 'timeout', 'redirection', 'httpversion', 'blocking'] as $key) {
            if (!array_key_exists($key, $parsedArgs)) {
                continue;
            }

            $normalized[$key] = $parsedArgs[$key];
        }

        return $normalized;
    }

    private function mixedToString(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
