<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class OptionsCollector extends AbstractCollector implements DataCollectorInterface
{
    public function key(): string
    {
        return 'options';
    }

    public function label(): string
    {
        return 'Options';
    }

    public function icon(): string
    {
        return 'config';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $autoloaded = function_exists('wp_load_alloptions') ? wp_load_alloptions() : [];

        $autoloadRows = [];
        $totalBytes = 0;

        foreach ($autoloaded as $name => $value) {
            $bytes = strlen($this->serializeValue($value));
            $totalBytes += $bytes;
            $autoloadRows[] = [
                'name' => (string) $name,
                'bytes' => $bytes,
                'type' => get_debug_type($value),
            ];
        }

        usort($autoloadRows, static fn (array $left, array $right): int => $right['bytes'] <=> $left['bytes']);

        return [
            'autoload_count' => count($autoloaded),
            'autoload_bytes' => $totalBytes,
            'largest_autoloaded' => array_slice($autoloadRows, 0, 50),
            'transients' => $this->transients(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        return new ToolbarBlock(
            'options',
            'Options',
            $this->formatBytes($this->intValue($payload, 'autoload_bytes')),
            sprintf('%d autoloaded', $this->intValue($payload, 'autoload_count')),
            $this->profileUrl($profile, 'options'),
            $this->intValue($payload, 'autoload_bytes') > 1048576 ? 'yellow' : 'green',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $autoloadRows = [];

        foreach ($this->largestAutoloaded($payload) as $option) {
            $autoloadRows[] = [
                $option['name'],
                $this->formatBytes($option['bytes']),
                $option['bytes'],
                $option['type'],
            ];
        }

        $transientRows = [];

        foreach ($this->transientRows($payload) as $transient) {
            $transientRows[] = [
                $transient['name'],
                $this->formatBytes($transient['bytes']),
                $transient['autoload'],
            ];
        }

        $html = '<h2>Options</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Autoloaded options', 'value' => (string) $this->intValue($payload, 'autoload_count')],
            ['label' => 'Autoload size', 'value' => $this->formatBytes($this->intValue($payload, 'autoload_bytes'))],
            ['label' => 'Largest tracked', 'value' => (string) count($autoloadRows)],
            ['label' => 'Transient rows', 'value' => (string) count($transientRows)],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Autoloaded Options', 'badge' => count($autoloadRows), 'active' => true, 'target' => '#options-autoloaded'],
            ['label' => 'Transients', 'badge' => count($transientRows), 'target' => '#options-transients'],
        ]);
        $html .= '<div id="options-autoloaded" class="profiler-tab-target">';
        $html .= '<p class="help">Values are intentionally not displayed; this panel records names and serialized sizes only.</p>';
        $html .= Html::table(['Name', 'Size', 'Bytes', 'Type'], $autoloadRows);
        $html .= '</div>';
        $html .= '<div id="options-transients" class="profiler-tab-target">';
        $html .= '<p class="help">Transient values are not displayed.</p>';
        $html .= Html::table(['Name', 'Size', 'Autoload'], $transientRows);
        $html .= '</div>';

        return $this->panel(
            'options',
            $this->label(),
            $this->icon(),
            $html,
            $this->formatBytes($this->intValue($payload, 'autoload_bytes')),
        );
    }

    /**
     * @return list<array{name: string, bytes: int, autoload: string}>
     */
    private function transients(): array
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        if (!is_object($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'get_results')) {
            return [];
        }

        $tableName = $wpdb->options;
        $table = is_scalar($tableName) || $tableName instanceof \Stringable
            ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $tableName)
            : '';
        $table = $table !== '' ? $table : 'wp_options';
        $results = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS bytes, autoload FROM {$table} WHERE option_name LIKE '_transient_%' ORDER BY LENGTH(option_value) DESC LIMIT 50",
            'ARRAY_A',
        );

        if (!is_array($results)) {
            return [];
        }

        $rows = [];

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'name' => $this->stringValue($row, 'option_name'),
                'bytes' => $this->intValue($row, 'bytes'),
                'autoload' => $this->stringValue($row, 'autoload'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, bytes: int, type: string}>
     */
    private function largestAutoloaded(array $payload): array
    {
        $options = $payload['largest_autoloaded'] ?? [];

        if (!is_array($options)) {
            return [];
        }

        $rows = [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $rows[] = [
                'name' => $this->stringValue($option, 'name'),
                'bytes' => $this->intValue($option, 'bytes'),
                'type' => $this->stringValue($option, 'type'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, bytes: int, autoload: string}>
     */
    private function transientRows(array $payload): array
    {
        $transients = $payload['transients'] ?? [];

        if (!is_array($transients)) {
            return [];
        }

        $rows = [];

        foreach ($transients as $transient) {
            if (!is_array($transient)) {
                continue;
            }

            $rows[] = [
                'name' => $this->stringValue($transient, 'name'),
                'bytes' => $this->intValue($transient, 'bytes'),
                'autoload' => $this->stringValue($transient, 'autoload'),
            ];
        }

        return $rows;
    }

    private function serializeValue(mixed $value): string
    {
        if (function_exists('maybe_serialize')) {
            $serialized = maybe_serialize($value);

            return is_scalar($serialized) ? (string) $serialized : serialize($serialized);
        }

        return is_scalar($value) || $value === null ? (string) $value : serialize($value);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f MiB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.2f KiB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
