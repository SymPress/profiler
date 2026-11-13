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

    /**
     * Normalize diagnostic payloads for storage without the request input's truncation limits.
     *
     * @template TKey of array-key
     * @param array<TKey, mixed> $value
     * @return array<TKey, mixed>
     */
    public function normalizePayload(array $value, int $depth = 0): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $normalized[$key] = $depth >= 64 ? '[depth limit reached]' : $this->normalizePayload($item, $depth + 1);
            } elseif ($item instanceof \Stringable) {
                $normalized[$key] = (string) $item;
            } elseif (is_object($item)) {
                $normalized[$key] = sprintf('[object %s]', $item::class);
            } elseif (is_resource($item)) {
                $normalized[$key] = '[resource]';
            } else {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    public function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[depth limit reached]';
        }

        if (is_array($value)) {
            $sanitized = [];
            $slice = array_slice($value, 0, self::MAX_ITEMS, true);

            foreach ($slice as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitize($itemValue, $depth + 1);
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

        if (is_string($value) || $value instanceof \Stringable) {
            return $this->truncate((string) $value);
        }

        if (is_object($value)) {
            return sprintf('[object %s]', $value::class);
        }

        return sprintf('[%s]', gettype($value));
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '…';
    }
}
