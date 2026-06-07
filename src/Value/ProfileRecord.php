<?php

declare(strict_types=1);

namespace SymPress\Profiler\Value;

final class ProfileRecord implements \JsonSerializable
{
    private readonly string $tokenValue;
    private readonly string $createdAtValue;

    /**
     * @var array<string, mixed>
     */
    private readonly array $metaValue;

    /**
     * @var array<string, array<string, mixed>>
     */
    private readonly array $collectorsValue;

    public string $token {
        get => $this->tokenValue;
    }

    public string $createdAt {
        get => $this->createdAtValue;
    }

    /**
     * @var array<string, mixed>
     */
    public array $meta {
        get => $this->metaValue;
    }

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $collectors {
        get => $this->collectorsValue;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $collectors
     */
    public function __construct(
        string $token,
        string $createdAt,
        array $meta,
        array $collectors,
    ) {
        $this->tokenValue = $token;
        $this->createdAtValue = $createdAt;
        $this->metaValue = $meta;
        $this->collectorsValue = $collectors;
    }

    /**
     * @param array{token?: mixed, created_at?: mixed, meta?: mixed, collectors?: mixed} $profile
     */
    public static function fromArray(array $profile): self
    {
        return new self(
            self::normalizeString($profile['token'] ?? ''),
            self::normalizeString($profile['created_at'] ?? ''),
            self::normalizeMeta($profile['meta'] ?? []),
            self::normalizeCollectors($profile['collectors'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function collector(string $key): array
    {
        return $this->collectors[$key] ?? [];
    }

    /**
     * @return array{token: string, created_at: string, meta: array<string, mixed>, collectors: array<string, array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'token' => $this->token,
            'created_at' => $this->createdAt,
            'meta' => $this->meta,
            'collectors' => $this->collectors,
        ];
    }

    private static function normalizeString(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeMeta(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $meta = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $meta[$key] = $item;
        }

        return $meta;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeCollectors(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $collectors = [];

        foreach ($value as $key => $payload) {
            if (!is_string($key) || !is_array($payload)) {
                continue;
            }

            $collectors[$key] = self::normalizeMeta($payload);
        }

        return $collectors;
    }
}
