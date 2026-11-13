<?php

declare(strict_types=1);

namespace SymPress\Profiler\Value;

final class ProfileContext
{
    /**
     * @param list<array{class: string, message: string, file: string, line: int}> $throwables
     * @param array<string, string|list<string>> $responseHeaders
     */
    public function __construct(
        private readonly string $token,
        private readonly float $startedAt,
        private readonly float $finishedAt,
        private readonly int $startMemoryBytes,
        private readonly int $finalMemoryBytes,
        private readonly int $peakMemoryBytes,
        private readonly int $statusCode,
        private readonly ?string $template,
        private readonly array $throwables,
        private readonly array $responseHeaders,
        private readonly string $profilerUrl,
    ) {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function finishedAt(): float
    {
        return $this->finishedAt;
    }

    public function startedAtIso(): string
    {
        return $this->formatTimestamp($this->startedAt);
    }

    public function finishedAtIso(): string
    {
        return $this->formatTimestamp($this->finishedAt);
    }

    public function durationMs(): float
    {
        return round(($this->finishedAt - $this->startedAt) * 1000, 2);
    }

    public function memoryMb(): float
    {
        return round($this->finalMemoryBytes / 1048576, 2);
    }

    public function peakMemoryMb(): float
    {
        return round($this->peakMemoryBytes / 1048576, 2);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    /** @return list<array{class: string, message: string, file: string, line: int}> */
    public function throwables(): array
    {
        return $this->throwables;
    }

    /**
     * Header values by lower-case name; a header sent more than once holds the list of its values.
     *
     * @return array<string, string|list<string>>
     */
    public function responseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function profilerUrl(): string
    {
        return $this->profilerUrl;
    }

    public function startMemoryMb(): float
    {
        return round($this->startMemoryBytes / 1048576, 2);
    }

    private function formatTimestamp(float $timestamp): string
    {
        $dateTime = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6F', $timestamp),
            new \DateTimeZone('UTC'),
        );

        // Milliseconds: profiles of the same second (a page and its assets) must sort in request order.
        if ($dateTime instanceof \DateTimeImmutable) {
            return $dateTime->format(DATE_RFC3339_EXTENDED);
        }

        return gmdate(DATE_ATOM, (int) $timestamp);
    }
}
