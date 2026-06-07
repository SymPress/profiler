<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Recorder\ProfilerErrorRecorder;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Support\HtmlString;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class LogCollector extends AbstractCollector implements DataCollectorInterface
{
    private const string FILTER_LOG_ENTRIES = 'sympress_profiler_log_entries';

    public function __construct(
        private readonly ProfilerErrorRecorder $errors,
    ) {
    }

    public function key(): string
    {
        return 'logs';
    }

    public function label(): string
    {
        return 'Logs';
    }

    public function icon(): string
    {
        return 'logger';
    }

    public function collect(ProfileContext $context): array
    {
        $entries = $this->logEntries();
        $counts = [];
        $channels = [];

        foreach ($entries as $entry) {
            $level = $this->stringValue($entry, 'level', 'info');
            $counts[$level] = ($counts[$level] ?? 0) + 1;

            $channel = $this->stringValue($entry, 'channel');

            if ($channel !== '') {
                $channels[$channel] = true;
            }
        }

        return [
            'count' => count($entries),
            'counts' => $counts,
            'channels' => array_keys($channels),
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock
    {
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $entries = $payload['entries'] ?? [];

        if (!is_array($entries)) {
            $entries = [];
        }

        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $errorCount = $this->severityCount($counts, ['emergency', 'alert', 'critical', 'error']);
        $warningCount = $this->severityCount($counts, ['warning', 'notice']);
        $deprecationCount = $this->intValue($counts, 'deprecation');

        $html = '<h2>Log Messages</h2>';
        $html .= Html::tabNavigation([
            ['label' => 'All messages', 'active' => true, 'target' => '#logs-all-messages'],
            ['label' => 'Errors', 'badge' => $errorCount, 'target' => '#logs-error-messages'],
            ['label' => 'Warnings', 'badge' => $warningCount, 'target' => '#logs-warning-messages'],
            ['label' => 'Deprecations', 'badge' => $deprecationCount, 'target' => '#logs-deprecation-messages'],
            ['label' => 'Level (All)', 'target' => '#logs-all-messages'],
            ['label' => 'Channel (All)', 'target' => '#logs-all-messages'],
        ]);
        $html .= '<div id="logs-all-messages" class="profiler-tab-target">'
            . $this->renderLogEntries($entries, 'No log messages were captured during this request.')
            . '</div>';
        $html .= '<div id="logs-error-messages" class="profiler-tab-target">'
            . $this->renderLogEntries(
                $this->entriesMatchingLevels($entries, ['emergency', 'alert', 'critical', 'error']),
                'No error log messages were captured during this request.',
            )
            . '</div>';
        $html .= '<div id="logs-warning-messages" class="profiler-tab-target">'
            . $this->renderLogEntries(
                $this->entriesMatchingLevels($entries, ['warning', 'notice']),
                'No warning log messages were captured during this request.',
            )
            . '</div>';
        $html .= '<div id="logs-deprecation-messages" class="profiler-tab-target">'
            . $this->renderLogEntries(
                $this->entriesMatchingLevels($entries, ['deprecation']),
                'No deprecation log messages were captured during this request.',
            )
            . '</div>';

        return $this->panel(
            'logs',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'count')),
        );
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param list<string> $levels
     * @return list<array<array-key, mixed>>
     */
    private function entriesMatchingLevels(array $entries, array $levels): array
    {
        $matches = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (in_array($this->stringValue($entry, 'level', 'info'), $levels, true)) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * @param array<array-key, mixed> $entries
     */
    private function renderLogEntries(array $entries, string $emptyMessage): string
    {
        $rows = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $level = $this->stringValue($entry, 'level', 'info');
            $channel = $this->stringValue($entry, 'channel', 'php');
            $source = $this->stringValue($entry, 'source', 'php');
            $file = $this->stringValue($entry, 'file');
            $line = $this->intValue($entry, 'line');
            $context = $file !== '' ? sprintf('%s:%d', $file, $line) : 'runtime';
            $metadata = $this->metadataHtml($entry);

            $rows[] = [
                new HtmlString(
                    Html::escape($this->formatLogTime($this->stringValue($entry, 'captured_at')))
                    . '<br><span class="badge">' . Html::escape($source) . '</span>',
                ),
                new HtmlString(Html::badge($level, $this->levelStatusClass($level))),
                Html::escape($channel),
                new HtmlString(
                    Html::escape($this->stringValue($entry, 'message'))
                    . '<br><span class="badge">' . Html::escape($context) . '</span>'
                    . $metadata,
                ),
            ];
        }

        return $rows === []
            ? Html::emptyPanel($emptyMessage)
            : Html::table(['Time', 'Level', 'Channel', 'Message'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logEntries(): array
    {
        $entries = [];

        foreach ($this->errors->entries() as $entry) {
            $entry['source'] = 'php';
            $entry['channel'] = 'php';
            $entry['context'] = [];
            $entry['extra'] = [];
            $entries[] = $entry;
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters(self::FILTER_LOG_ENTRIES, $entries);

            if (is_array($filtered)) {
                $entries = $filtered;
            }
        }

        $normalized = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $normalized[] = $this->normalizeEntry($entry);
            }
        }

        usort(
            $normalized,
            fn (array $left, array $right): int => strcmp(
                $this->stringValue($left, 'captured_at'),
                $this->stringValue($right, 'captured_at'),
            ),
        );

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalizeEntry(array $entry): array
    {
        return [
            'type' => $this->intValue($entry, 'type'),
            'label' => $this->stringValue($entry, 'label', strtoupper($this->stringValue($entry, 'level', 'info'))),
            'level' => strtolower($this->stringValue($entry, 'level', 'info')),
            'message' => $this->stringValue($entry, 'message'),
            'file' => $this->stringValue($entry, 'file'),
            'line' => $this->intValue($entry, 'line'),
            'captured_at' => $this->stringValue($entry, 'captured_at', gmdate(DATE_ATOM)),
            'source' => $this->stringValue($entry, 'source', 'runtime'),
            'channel' => $this->stringValue($entry, 'channel', 'app'),
            'context' => is_array($entry['context'] ?? null) ? $entry['context'] : [],
            'extra' => is_array($entry['extra'] ?? null) ? $entry['extra'] : [],
        ];
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function metadataHtml(array $entry): string
    {
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        $extra = is_array($entry['extra'] ?? null) ? $entry['extra'] : [];
        $html = '';

        if ($context !== []) {
            $html .= '<details class="metadata"><summary>Context</summary>' . Html::codeBlock($context) . '</details>';
        }

        if ($extra !== []) {
            $html .= '<details class="metadata"><summary>Extra</summary>' . Html::codeBlock($extra) . '</details>';
        }

        return $html;
    }

    /**
     * @param array<array-key, mixed> $counts
     * @param list<string> $levels
     */
    private function severityCount(array $counts, array $levels): int
    {
        $count = 0;

        foreach ($levels as $level) {
            $count += $this->intValue($counts, $level);
        }

        return $count;
    }

    private function levelStatusClass(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'status-error',
            'warning', 'notice', 'deprecation' => 'status-warning',
            default => 'status-success',
        };
    }

    private function formatLogTime(string $value): string
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }

        return $date->format('H:i:s.v');
    }
}
