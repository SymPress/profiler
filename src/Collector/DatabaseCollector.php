<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Support\HtmlString;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class DatabaseCollector extends AbstractCollector implements DataCollectorInterface
{
    private const int MAX_QUERIES = 150;

    public function getKey(): string
    {
        return 'database';
    }

    public function getLabel(): string
    {
        return 'Doctrine';
    }

    public function getIcon(): string
    {
        return 'database';
    }

    public function collect(ProfileContext $context): array
    {
        global $wpdb;

        $numQueries = is_object($wpdb) && is_numeric($wpdb->num_queries ?? null)
            ? (int) $wpdb->num_queries
            : 0;
        [
            'queries'           => $queries,
            'duplicate_queries' => $duplicateRows,
            'caller_breakdown'  => $callerBreakdown,
            'total_duration_ms' => $totalDuration,
        ] = $this->collectQueryData($wpdb);

        return [
            'enabled'           => defined('SAVEQUERIES') && SAVEQUERIES,
            'count'             => $numQueries,
            'total_duration_ms' => round($totalDuration, 2),
            'last_error'        => is_object($wpdb) ? $this->stringFromMixed($wpdb->last_error ?? '') : '',
            'queries'           => array_slice($queries, 0, self::MAX_QUERIES),
            'slow_queries'      => array_slice($queries, 0, 10),
            'duplicate_queries' => array_slice($duplicateRows, 0, 10),
            'caller_breakdown'  => array_slice($callerBreakdown, 0, 15),
            'truncated'         => count($queries) === self::MAX_QUERIES,
            'captured_at'       => $context->finishedAtIso(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $count = $this->intValue($payload, 'count');
        $duration = $this->floatValue($payload, 'total_duration_ms');
        $accent = $count > 50 || $duration > 100.0 ? 'yellow' : 'green';

        return new ToolbarBlock(
            'database',
            'Doctrine',
            sprintf('%d queries', $count),
            sprintf('%.1f ms total', $duration),
            $this->profileUrl($profile, 'database'),
            $accent,
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $queryRows = [];
        $differentStatements = [];

        foreach ($this->queries($payload, 'queries') as $index => $query) {
            $differentStatements[$this->normalizeSql($query['sql'])] = true;
            $queryRows[] = [
                $index + 1,
                sprintf('%.2f ms', $query['duration_ms']),
                new HtmlString(
                    '<div class="profiler-sql">'
                    . $this->highlightSql($query['sql'])
                    . '</div><div><strong>Caller:</strong> '
                    . Html::escape($query['caller'] !== '' ? $query['caller'] : 'n/a')
                    . '</div><p class="profiler-query-links"><a href="#">View formatted query</a> '
                    . '<a href="#">View runnable query</a> '
                    . '<a href="#">Explain query</a> '
                    . '<a href="#">View query backtrace</a></p>',
                ),
            ];
        }

        $html = '<h2>Query Metrics</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Database Queries', 'value' => (string) $this->intValue($payload, 'count')],
            ['label' => 'Different statements', 'value' => (string) count($differentStatements)],
            ['label' => 'Query time', 'value' => sprintf('%.2f ms', $this->floatValue($payload, 'total_duration_ms'))],
            ['label' => 'Invalid entities', 'value' => '0'],
            ['label' => 'Managed entities', 'value' => '0'],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Queries', 'active' => true, 'target' => '#database-queries'],
            ['label' => 'Database Connections', 'target' => '#database-connections'],
            ['label' => 'Entity Managers', 'target' => '#database-entity-managers'],
            ['label' => 'Second Level Cache', 'disabled' => true],
            ['label' => 'Managed Entities', 'target' => '#database-managed-entities'],
            ['label' => 'Entities Mapping', 'target' => '#database-entities-mapping'],
        ]);
        $html .= '<div id="database-queries" class="profiler-tab-target">';
        $html .= '<p><a href="#">Group similar statements</a></p>';
        $html .= Html::table(['#', 'Time', 'Info'], $queryRows, 'queries-table');
        $html .= '</div>';
        $html .= '<div id="database-connections" class="profiler-tab-target">'
            . Html::emptyPanel('Database connection details are not available for this profile.')
            . '</div>';
        $html .= '<div id="database-entity-managers" class="profiler-tab-target">'
            . Html::emptyPanel('No entity managers were captured during this request.')
            . '</div>';
        $html .= '<div id="database-managed-entities" class="profiler-tab-target">'
            . Html::emptyPanel('No managed entities were captured during this request.')
            . '</div>';
        $html .= '<div id="database-entities-mapping" class="profiler-tab-target">'
            . Html::emptyPanel('No entity mapping information was captured during this request.')
            . '</div>';

        if ($this->stringValue($payload, 'last_error') !== '') {
            $html .= Html::section('Last error', Html::codeBlock($this->stringValue($payload, 'last_error')));
        }

        if ($this->boolValue($payload, 'truncated')) {
            $html .= '<p class="description">Only the first 150 queries are stored to keep profiles manageable.</p>';
        }

        return $this->panel(
            'database',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'count')),
        );
    }

    private function highlightSql(string $sql): string
    {
        $escaped = Html::escape($sql);
        $escaped = preg_replace(
            '/\b(SELECT|UPDATE|INSERT|DELETE|FROM|WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|AS|ON|AND|OR|ORDER|GROUP|BY|LIMIT|OFFSET|VALUES|SET)\b/i',
            '<span class="profiler-sql-keyword">$1</span>',
            $escaped,
        ) ?? $escaped;

        return $escaped;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{sql: string, duration_ms: float, caller: string, started_at: float}>
     */
    private function queries(array $payload, string $key): array
    {
        $queries = $payload[$key] ?? [];

        if (!is_array($queries)) {
            return [];
        }

        $normalized = [];

        foreach ($queries as $query) {
            if (!is_array($query)) {
                continue;
            }

            $normalized[] = [
                'sql'         => $this->stringValue($query, 'sql'),
                'duration_ms' => $this->floatValue($query, 'duration_ms'),
                'caller'      => $this->stringValue($query, 'caller'),
                'started_at'  => $this->floatValue($query, 'started_at'),
            ];
        }

        return $normalized;
    }

    private function stringFromMixed(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    private function floatFromMixed(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function normalizeSql(string $sql): string
    {
        $normalized = strtolower(trim($sql));
        $normalized = preg_replace("/'[^']*'/", '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/"[^"]*"/', '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized) ?? $normalized;

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    /**
     * @return array{
     *   queries: list<array{sql: string, duration_ms: float, caller: string, started_at: float}>,
     *   duplicate_queries: list<array{count: int, duration_ms: float, sample: string}>,
     *   caller_breakdown: list<array{caller: string, count: int, duration_ms: float}>,
     *   total_duration_ms: float
     * }
     */
    // phpcs:ignore Inpsyde.CodeQuality.FunctionLength.TooLong -- single-pass query aggregation is easier to audit than splitting the request-local state machine further.
    private function collectQueryData(mixed $wpdb): array
    {
        $queries = [];
        $duplicates = [];
        $callerBreakdown = [];
        $totalDuration = 0.0;

        if (!is_object($wpdb) || !isset($wpdb->queries) || !is_array($wpdb->queries)) {
            return [
                'queries'           => [],
                'duplicate_queries' => [],
                'caller_breakdown'  => [],
                'total_duration_ms' => 0.0,
            ];
        }

        foreach (array_slice($wpdb->queries, 0, self::MAX_QUERIES) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sql = $this->stringFromMixed($entry[0] ?? '');
            $durationMs = round($this->floatFromMixed($entry[1] ?? 0.0) * 1000, 2);
            $caller = $this->stringFromMixed($entry[2] ?? '');
            $startedAt = $this->floatFromMixed($entry[3] ?? 0.0);
            $normalizedSql = $this->normalizeSql($sql);
            $totalDuration += $durationMs;

            $queries[] = [
                'sql'         => $sql,
                'duration_ms' => $durationMs,
                'caller'      => $caller,
                'started_at'  => $startedAt,
            ];
            $this->aggregateDuplicate($duplicates, $normalizedSql, $sql, $durationMs);
            $this->aggregateCaller($callerBreakdown, $caller, $durationMs);
        }

        usort(
            $queries,
            static fn (array $left, array $right): int => $right['duration_ms'] <=> $left['duration_ms'],
        );

        $duplicateRows = array_values(array_filter(
            $duplicates,
            static fn (array $item): bool => $item['count'] > 1,
        ));
        usort(
            $duplicateRows,
            static fn (array $left, array $right): int => $right['count'] <=> $left['count'],
        );
        usort(
            $callerBreakdown,
            static fn (array $left, array $right): int => $right['duration_ms'] <=> $left['duration_ms'],
        );

        return [
            'queries'           => $queries,
            'duplicate_queries' => $duplicateRows,
            'caller_breakdown'  => array_slice($callerBreakdown, 0, 15),
            'total_duration_ms' => round($totalDuration, 2),
        ];
    }

    /** @param array<string, array{count: int, duration_ms: float, sample: string}> $duplicates */
    private function aggregateDuplicate(array &$duplicates, string $normalizedSql, string $sql, float $durationMs): void
    {
        $duplicates[$normalizedSql] ??= [
            'count'       => 0,
            'duration_ms' => 0.0,
            'sample'      => $sql,
        ];
        $duplicates[$normalizedSql]['count']++;
        $duplicates[$normalizedSql]['duration_ms'] += $durationMs;
    }

    /** @param list<array{caller: string, count: int, duration_ms: float}> $callerBreakdown */
    private function aggregateCaller(array &$callerBreakdown, string $caller, float $durationMs): void
    {
        $key = $caller !== '' ? $caller : 'unknown';

        foreach ($callerBreakdown as &$row) {
            if ($row['caller'] !== $key) {
                continue;
            }

            $row['count']++;
            $row['duration_ms'] += $durationMs;

            return;
        }

        $callerBreakdown[] = [
            'caller'      => $key,
            'count'       => 1,
            'duration_ms' => $durationMs,
        ];
    }
}
