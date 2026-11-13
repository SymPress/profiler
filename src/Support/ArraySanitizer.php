<?php

declare(strict_types=1);

namespace SymPress\Profiler\Support;

final class ArraySanitizer
{
    /**
     * Normalize diagnostic payloads for JSON storage without truncating or redacting values.
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
}
