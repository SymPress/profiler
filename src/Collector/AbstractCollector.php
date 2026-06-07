<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileRecord;

abstract class AbstractCollector
{
    /**
     * @param array<array-key, mixed> $payload
     */
    protected function stringValue(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? $default;

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    protected function intValue(array $payload, string $key, int $default = 0): int
    {
        $value = $payload[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    protected function floatValue(array $payload, string $key, float $default = 0.0): float
    {
        $value = $payload[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    protected function boolValue(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    protected function profileUrl(ProfileRecord $profile, string $panel = ''): string
    {
        $profilerUrl = $profile->meta['profiler_url'] ?? '#';
        $url = is_string($profilerUrl) ? $profilerUrl : '#';

        if ($panel === '' || $url === '#') {
            return $url;
        }

        return $url . '#panel-' . rawurlencode($panel);
    }

    protected function panel(
        string $id,
        string $title,
        string $icon,
        string $html,
        string $metric = '',
        bool $enabled = true,
    ): CollectorPanel {
        $contents = $html !== ''
            ? $html
            : Html::emptyPanel('No data was captured for this panel.');

        return new CollectorPanel($id, $title, $icon, $contents, $metric, $enabled);
    }
}
