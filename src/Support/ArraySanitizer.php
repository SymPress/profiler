<?php

declare(strict_types=1);

namespace SymPress\Profiler\Support;

final class ArraySanitizer
{
    private const int MAX_DEPTH = 4;
    private const int MAX_ITEMS = 50;
    private const int MAX_STRING_LENGTH = 500;

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public function sanitizeArray(array $value): array
    {
        $sanitized = $this->sanitize($value);

        return is_array($sanitized) ? $sanitized : [];
    }

    public function sanitize(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if ($key !== null && $this->shouldRedact($key)) {
            return '[redacted]';
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[depth limit reached]';
        }

        if (is_array($value)) {
            $sanitized = [];
            $slice = array_slice($value, 0, self::MAX_ITEMS, true);

            foreach ($slice as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitize(
                    $itemValue,
                    $depth + 1,
                    is_string($itemKey) ? $itemKey : null,
                );
            }

            if (count($value) > self::MAX_ITEMS) {
                $sanitized['__truncated'] = sprintf(
                    '%d additional item(s) omitted.',
                    count($value) - self::MAX_ITEMS,
                );
            }

            return $sanitized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return $this->truncate((string) $value);
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_object($value)) {
            return sprintf('[object %s]', $value::class);
        }

        return sprintf('[%s]', gettype($value));
    }

    private function shouldRedact(string $key): bool
    {
        $normalizedKey = strtolower($key);

        foreach (['password', 'pass', 'pwd', 'nonce', 'token', 'authorization', 'cookie'] as $fragment) {
            if (str_contains($normalizedKey, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '…';
    }
}
