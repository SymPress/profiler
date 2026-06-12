<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Recorder\ProfilerLifecycleRecorder;
use SymPress\Profiler\Stopwatch\ProfilerStopwatch;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class PerformanceCollector extends AbstractCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly ProfilerLifecycleRecorder $lifecycle,
        private readonly ProfilerStopwatch $stopwatch,
    ) {
    }

    public function getKey(): string
    {
        return 'performance';
    }

    public function getLabel(): string
    {
        return 'Performance';
    }

    public function getIcon(): string
    {
        return 'performance';
    }

    public function collect(ProfileContext $context): array
    {
        $events = $this->timeline($context);
        $requestStartedAt = $this->lifecycle->requestStartedAt();
        $totalDuration = round(($context->finishedAt() - $requestStartedAt) * 1000, 2);
        $bootstrapEnd = $this->eventStartedAt($events, 'wp_loaded') ?? $this->eventStartedAt($events, 'init') ?? $requestStartedAt;
        $renderStart = $this->eventStartedAt($events, 'template_include') ?? $this->eventStartedAt($events, 'template_redirect') ?? $context->finishedAt();
        $bootstrapMs = max(0.0, round(($bootstrapEnd - $requestStartedAt) * 1000, 2));
        $renderMs = max(0.0, round(($context->finishedAt() - $renderStart) * 1000, 2));
        $runtimeMs = max(0.0, round($totalDuration - $bootstrapMs - $renderMs, 2));

        return [
            'total_duration_ms' => $totalDuration,
            'bootstrap_ms'      => $bootstrapMs,
            'runtime_ms'        => $runtimeMs,
            'render_ms'         => $renderMs,
            'peak_memory_mb'    => $context->peakMemoryMb(),
            'timeline'          => $events,
            'stopwatch'         => $this->stopwatch->events(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $total = $this->floatValue($payload, 'total_duration_ms');
        $peak = $this->floatValue($payload, 'peak_memory_mb');
        $accent = $total > 1000.0 ? 'yellow' : 'cyan';

        return new ToolbarBlock(
            'performance',
            'Performance',
            sprintf('%.1f ms', $total),
            sprintf('Render %.1f ms · Peak %.2f MiB', $this->floatValue($payload, 'render_ms'), $peak),
            $this->profileUrl($profile, 'performance'),
            $accent,
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $timeline = $this->timelinePayload($payload);
        $metrics = Html::metricTiles([
            ['label' => 'Total execution time', 'value' => sprintf('%.0f ms', $this->floatValue($payload, 'total_duration_ms'))],
            ['label' => 'Symfony initialization', 'value' => sprintf('%.0f ms', $this->floatValue($payload, 'bootstrap_ms'))],
            ['label' => 'Peak memory usage', 'value' => sprintf('%.2f MiB', $this->floatValue($payload, 'peak_memory_mb'))],
        ]);

        $html = Html::section('Performance metrics', $metrics);
        $html .= Html::section(
            'Execution timeline',
            $this->renderTimeline(
                $timeline,
                $this->floatValue($payload, 'total_duration_ms'),
                $this->floatValue($payload, 'peak_memory_mb'),
                $profile->token,
                $profile->collector('database'),
                $this->stopwatchPayload($payload),
            ),
        );
        $html .= Html::section('Stopwatch Events', $this->renderStopwatchEvents($this->stopwatchPayload($payload)));

        return $this->panel(
            'performance',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%.1f ms', $this->floatValue($payload, 'total_duration_ms')),
        );
    }

    /** @return list<array{name: string, started_at: float, memory_mb: float, detail: string, started_ms: float, duration_ms: float}> */
    private function timeline(ProfileContext $context): array
    {
        $events = $this->lifecycle->events();
        $startedAt = $this->lifecycle->requestStartedAt();
        $timeline = [];

        foreach ($events as $index => $event) {
            $nextEvent = $events[$index + 1] ?? null;
            $eventStartedAt = $event['started_at'];
            $nextStartedAt = is_array($nextEvent)
                ? $nextEvent['started_at']
                : $context->finishedAt();

            $timeline[] = [
                'name'        => $event['name'],
                'started_at'  => $eventStartedAt,
                'memory_mb'   => $event['memory_mb'],
                'detail'      => $event['detail'],
                'started_ms'  => round(($eventStartedAt - $startedAt) * 1000, 2),
                'duration_ms' => max(0.0, round(($nextStartedAt - $eventStartedAt) * 1000, 2)),
            ];
        }

        return $timeline;
    }

    /** @param list<array{name: string, started_at: float, memory_mb: float, detail: string, started_ms: float, duration_ms: float}> $events */
    private function eventStartedAt(array $events, string $name): ?float
    {
        foreach ($events as $event) {
            if ($event['name'] === $name) {
                return $event['started_at'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, started_at: float, memory_mb: float, detail: string, started_ms: float, duration_ms: float}>
     */
    private function timelinePayload(array $payload): array
    {
        $timeline = $payload['timeline'] ?? [];

        if (!is_array($timeline)) {
            return [];
        }

        $normalized = [];

        foreach ($timeline as $event) {
            if (!is_array($event)) {
                continue;
            }

            $normalized[] = [
                'name'        => $this->stringValue($event, 'name'),
                'started_at'  => $this->floatValue($event, 'started_at'),
                'memory_mb'   => $this->floatValue($event, 'memory_mb'),
                'detail'      => $this->stringValue($event, 'detail'),
                'started_ms'  => $this->floatValue($event, 'started_ms'),
                'duration_ms' => $this->floatValue($event, 'duration_ms'),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, started_at: float, memory_mb: float, detail: string, started_ms: float, duration_ms: float}> $timeline
     * @param array<string, mixed> $databasePayload
     * @param list<array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>}> $stopwatchEvents
     */
    private function renderTimeline(
        array $timeline,
        float $totalDuration,
        float $peakMemory,
        string $token,
        array $databasePayload,
        array $stopwatchEvents,
    ): string {

        if ($timeline === []) {
            return Html::emptyPanel('No lifecycle events were captured.');
        }

        $events = $this->timelineSvgEvents($timeline, $totalDuration, $peakMemory, $databasePayload, $stopwatchEvents);

        if ($events === []) {
            return Html::emptyPanel('No lifecycle events were captured.');
        }

        $timelineId = 'timeline-' . preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        $timelineId = $timelineId !== 'timeline-' ? $timelineId : 'timeline-profile';

        $html = '<form id="timeline-control" class="profiler-timeline-control" action="" method="get">';
        $html .= '<input type="hidden" name="panel" value="performance">';
        $html .= '<label for="threshold"><strong>Threshold</strong></label>';
        $html .= '<input type="number" name="threshold" id="threshold" value="1" min="0" placeholder="1.1"> ms';
        $html .= '<span class="help">(timeline only displays events with a duration longer than this threshold)</span>';
        $html .= '</form>';
        $html .= '<div id="' . Html::attr($timelineId) . '" class="sf-profiler-timeline">';
        $html .= $this->renderTimelineLegend($events);
        $html .= $this->renderTimelineSvg($events);
        $html .= '</div>';
        $html .= $this->renderTimelineScript($timelineId);

        return $html;
    }

    private function timelineCategory(string $name): string
    {
        if (str_contains($name, 'template')) {
            return 'template';
        }

        if (str_contains($name, 'database') || str_contains($name, 'sql') || $name === 'db_query') {
            return 'doctrine';
        }

        return $name === 'runtime' ? 'default' : 'event_listener';
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>}>
     */
    private function stopwatchPayload(array $payload): array
    {
        $events = $payload['stopwatch'] ?? [];

        if (!is_array($events)) {
            return [];
        }

        $normalized = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $periods = [];
            $rawPeriods = $event['periods'] ?? [];

            if (is_array($rawPeriods)) {
                foreach ($rawPeriods as $period) {
                    if (!is_array($period)) {
                        continue;
                    }

                    $periods[] = [
                        'started_at'  => $this->floatValue($period, 'started_at'),
                        'stopped_at'  => $this->floatValue($period, 'stopped_at'),
                        'duration_ms' => $this->floatValue($period, 'duration_ms'),
                        'memory_mb'   => $this->floatValue($period, 'memory_mb'),
                    ];
                }
            }

            $normalized[] = [
                'name'        => $this->stringValue($event, 'name'),
                'category'    => $this->stringValue($event, 'category', 'default'),
                'started_at'  => $this->floatValue($event, 'started_at'),
                'duration_ms' => $this->floatValue($event, 'duration_ms'),
                'memory_mb'   => $this->floatValue($event, 'memory_mb'),
                'periods'     => $periods,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, started_at: float, memory_mb: float, detail: string, started_ms: float, duration_ms: float}> $timeline
     * @param array<string, mixed> $databasePayload
     * @param list<array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>}> $stopwatchEvents
     * @return list<array{name: string, category: string, start_ms: float, duration_ms: float, memory_mb: float}>
     */
    private function timelineSvgEvents(
        array $timeline,
        float $totalDuration,
        float $peakMemory,
        array $databasePayload,
        array $stopwatchEvents,
    ): array {

        $duration = max(1.0, $totalDuration);
        $requestStartedAt = $timeline[0]['started_at'] - ($timeline[0]['started_ms'] / 1000);
        $events = [
        [
            'name'        => 'request',
            'category'    => 'section',
            'start_ms'    => 0.0,
            'duration_ms' => $duration,
            'memory_mb'   => $peakMemory,
        ],
        ];

        foreach ($timeline as $event) {
            $name = $this->timelineEventName($event);
            $startedMs = max(0.0, $event['started_ms']);
            $eventDuration = max(0.0, $event['duration_ms']);

            if ($startedMs > $duration) {
                continue;
            }

            $events[] = [
                'name'        => $name,
                'category'    => $this->timelineCategory($event['name']),
                'start_ms'    => $startedMs,
                'duration_ms' => min($eventDuration, max(0.0, $duration - $startedMs)),
                'memory_mb'   => $event['memory_mb'],
            ];
        }

        foreach ($this->databaseTimelineEvents($databasePayload, $requestStartedAt, $duration) as $event) {
            $events[] = $event;
        }

        foreach ($this->stopwatchTimelineEvents($stopwatchEvents, $requestStartedAt, $duration) as $event) {
            $events[] = $event;
        }

        usort(
            $events,
            static fn (array $left, array $right): int => $left['start_ms'] <=> $right['start_ms'],
        );

        return $events;
    }

    /**
     * @param list<array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>}> $stopwatchEvents
     * @return list<array{name: string, category: string, start_ms: float, duration_ms: float, memory_mb: float}>
     */
    private function stopwatchTimelineEvents(array $stopwatchEvents, float $requestStartedAt, float $totalDuration): array
    {
        $events = [];

        foreach ($stopwatchEvents as $event) {
            foreach ($event['periods'] as $index => $period) {
                $durationMs = $period['duration_ms'];
                $startedMs = round(($period['started_at'] - $requestStartedAt) * 1000, 2);

                if ($startedMs < 0.0 || $startedMs > $totalDuration || $durationMs <= 0.0) {
                    continue;
                }

                $periodSuffix = count($event['periods']) > 1 ? ' #' . ($index + 1) : '';
                $events[] = [
                    'name'        => $event['name'] . $periodSuffix,
                    'category'    => $event['category'] !== '' ? $event['category'] : 'default',
                    'start_ms'    => $startedMs,
                    'duration_ms' => min($durationMs, max(0.0, $totalDuration - $startedMs)),
                    'memory_mb'   => $period['memory_mb'],
                ];
            }
        }

        return $events;
    }

    /** @param array{name: string, started_at: float, memory_mb: float, detail: string, started_ms: float, duration_ms: float} $event */
    private function timelineEventName(array $event): string
    {
        if ($event['name'] === 'template_include' && $event['detail'] !== '') {
            $path = str_replace('\\', '/', $event['detail']);
            $template = basename($path);

            return $template !== '' ? $template : $event['name'];
        }

        return $event['name'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, category: string, start_ms: float, duration_ms: float, memory_mb: float}>
     */
    private function databaseTimelineEvents(array $payload, float $requestStartedAt, float $totalDuration): array
    {
        $queries = $payload['queries'] ?? [];

        if (!is_array($queries)) {
            return [];
        }

        $events = [];

        foreach ($queries as $query) {
            if (!is_array($query)) {
                continue;
            }

            $startedAt = $this->floatValue($query, 'started_at');
            $durationMs = $this->floatValue($query, 'duration_ms');

            if ($startedAt <= 0.0 || $durationMs <= 0.0) {
                continue;
            }

            $startedMs = round(($startedAt - $requestStartedAt) * 1000, 2);

            if ($startedMs < 0.0 || $startedMs > $totalDuration) {
                continue;
            }

            $events[] = [
                'name'        => $this->databaseTimelineName($this->stringValue($query, 'sql')),
                'category'    => 'doctrine',
                'start_ms'    => $startedMs,
                'duration_ms' => min($durationMs, max(0.0, $totalDuration - $startedMs)),
                'memory_mb'   => 0.0,
            ];
        }

        return $events;
    }

    private function databaseTimelineName(string $sql): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        if ($normalized === '') {
            return 'SQL query';
        }

        $preview = substr($normalized, 0, 44);

        return $preview === $normalized ? $preview : $preview . '...';
    }

    /** @param list<array{name: string, category: string, start_ms: float, duration_ms: float, memory_mb: float}> $events */
    private function renderTimelineLegend(array $events): string
    {
        $present = [];

        foreach ($events as $event) {
            $present[$event['category']] = true;
        }

        $order = [
            'default',
            'section',
            'event_listener',
            'template',
            'doctrine',
            'controller.argument_value_resolver',
        ];
        $extra = array_values(array_diff(array_keys($present), $order));
        sort($extra);
        $order = array_merge($order, $extra);

        $html = '<div class="legends" aria-label="Timeline categories">';

        foreach ($order as $category) {
            if (!isset($present[$category])) {
                continue;
            }

            $html .= '<button type="button" class="timeline-category active present" value="'
                . Html::attr($category)
                . '" style="border-color: '
                . Html::attr($this->timelineCategoryColor($category))
                . '">'
                . Html::escape($category)
                . '</button>';
        }

        return $html . '</div>';
    }

    /** @param list<array{name: string, category: string, start_ms: float, duration_ms: float, memory_mb: float}> $events */
    private function renderTimelineSvg(array $events): string
    {
        $svgWidth = 1000.0;
        $rowHeight = 36.0;
        $minHeight = 252.0;
        $totalDuration = 1.0;

        foreach ($events as $event) {
            $totalDuration = max($totalDuration, $event['start_ms'] + $event['duration_ms']);
        }

        $visibleRows = 0;
        $eventHtml = '';

        foreach ($events as $event) {
            $visible = $event['duration_ms'] >= 1.0;
            $y = $visible ? $visibleRows * $rowHeight : 0.0;

            if ($visible) {
                ++$visibleRows;
            }

            $eventHtml .= $this->renderTimelineSvgEvent($event, $totalDuration, $svgWidth, $y, $visible);
        }

        $height = max($minHeight, $visibleRows * $rowHeight);
        $html = '<svg class="timeline-graph" viewBox="-10 0 1020 '
            . Html::attr(sprintf('%.0f', $height))
            . '" role="img" aria-label="Execution timeline">';
        $html .= '<g class="timeline-grid" data-timeline-grid>';
        $html .= $this->renderTimelineGrid($height);
        $html .= '</g>';
        $html .= $eventHtml;

        return $html . '</svg>';
    }

    private function renderTimelineGrid(float $height): string
    {
        $html = '';

        for ($line = 0; $line <= 10; ++$line) {
            $x = $line * 100;
            $html .= '<path class="timeline-grid-line" d="M'
                . Html::attr((string) $x)
                . ',0 v'
                . Html::attr(sprintf('%.0f', $height))
                . '"></path>';
        }

        for ($y = 0.0; $y <= $height; $y += 36.0) {
            $html .= '<path class="timeline-grid-line" d="M-10,'
                . Html::attr(sprintf('%.0f', $y))
                . ' h1020"></path>';
        }

        return $html;
    }

    /** @param array{name: string, category: string, start_ms: float, duration_ms: float, memory_mb: float} $event */
    private function renderTimelineSvgEvent(
        array $event,
        float $totalDuration,
        float $svgWidth,
        float $y,
        bool $visible,
    ): string {

        $start = $event['start_ms'] / $totalDuration * $svgWidth;
        $width = max(1.0, $event['duration_ms'] / $totalDuration * $svgWidth);
        $end = min($svgWidth, $start + $width);
        $estimatedLabelWidth = min(520, (strlen($event['name']) * 7) + 110);
        $alignLeft = $start + $estimatedLabelWidth <= $svgWidth;
        $labelX = $alignLeft ? $start : $end;
        $anchor = $alignLeft ? 'start' : 'end';
        $periodPath = $event['category'] === 'section'
            ? $this->timelineSectionPath($start, 22.0, $width)
            : $this->timelinePeriodPath($start, 22.0, $width);
        $memoryLabel = $event['memory_mb'] > 0.0
            ? ' / ' . sprintf('%.2f', $event['memory_mb']) . ' MiB'
            : '';

        $html = '<g class="timeline-event" data-timeline-event data-duration="'
            . Html::attr(sprintf('%.3f', $event['duration_ms']))
            . '" data-category="'
            . Html::attr($event['category'])
            . '" transform="translate(0, '
            . Html::attr(sprintf('%.0f', $y))
            . ')"'
            . ($visible ? '' : ' style="display: none"')
            . '>';
        $html .= '<title>' . Html::escape($event['name']) . '</title>';
        $html .= '<path class="timeline-border" d="M-10,0 h1020"></path>';
        $html .= '<text class="timeline-label" x="'
            . Html::attr(sprintf('%.3f', $labelX))
            . '" y="17" text-anchor="'
            . Html::attr($anchor)
            . '">'
            . Html::escape($event['name'])
            . '<tspan class="timeline-sublabel"> '
            . Html::escape($this->formatTimelineDuration($event['duration_ms']))
            . ' ms'
            . Html::escape($memoryLabel)
            . '</tspan></text>';
        $html .= '<path class="timeline-period" fill="'
            . Html::attr($this->timelineCategoryColor($event['category']))
            . '" d="'
            . Html::attr($periodPath)
            . '"></path>';

        return $html . '</g>';
    }

    private function timelineSectionPath(float $x, float $y, float $width): string
    {
        $height = 4.0;
        $markerSize = 6.0;
        $totalHeight = $height + $markerSize;
        $markerWidth = min($markerSize, $width / 2);
        $widthWithoutMarker = max(0.0, $width - ($markerWidth * 2));

        return sprintf(
            'M%.3F,%.3F v%.3F h%.3F v%.3F l%.3F %.3F h%.3F Z',
            $x,
            $y + $totalHeight,
            -$totalHeight,
            $width,
            $totalHeight,
            -$markerWidth,
            -$markerSize,
            -$widthWithoutMarker,
        );
    }

    private function timelinePeriodPath(float $x, float $y, float $width): string
    {
        $height = 4.0;
        $markerWidth = min(2.0, $width);
        $markerHeight = 4.0;
        $totalHeight = $height + $markerHeight;

        return sprintf(
            'M%.3F,%.3F h%.3F v%.3F h%.3F v%.3F h%.3FZ',
            $x + $markerWidth,
            $y + $totalHeight,
            -$markerWidth,
            -$totalHeight,
            $width,
            $height,
            $markerWidth - $width,
        );
    }

    private function timelineCategoryColor(string $category): string
    {
        return match ($category) {
            'section' => '#a3a3a3',
            'event_listener' => '#54aeff',
            'template' => '#4ac26b',
            'doctrine' => '#fd8c73',
            'controller.argument_value_resolver' => '#c297ff',
            default => '#737373',
        };
    }

    /** @param list<array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>}> $events */
    private function renderStopwatchEvents(array $events): string
    {
        if ($events === []) {
            return Html::emptyPanel('No custom stopwatch events were captured.');
        }

        $rows = [];

        foreach ($events as $event) {
            $rows[] = [
                $event['name'],
                $event['category'],
                sprintf('%.2f ms', $event['duration_ms']),
                sprintf('%.2f MiB', $event['memory_mb']),
                (string) count($event['periods']),
            ];
        }

        return Html::table(['Event', 'Category', 'Duration', 'Memory', 'Periods'], $rows);
    }

    private function formatTimelineDuration(float $duration): string
    {
        if ($duration >= 10.0 || floor($duration) === $duration) {
            return sprintf('%.0f', $duration);
        }

        return sprintf('%.1f', $duration);
    }

    private function renderTimelineScript(string $timelineId): string
    {
        $encodedTimelineId = json_encode($timelineId, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG);

        if (!is_string($encodedTimelineId)) {
            $encodedTimelineId = '""';
        }

        return <<<HTML
<script>
(function () {
    var root = document.getElementById({$encodedTimelineId});
    var threshold = document.getElementById('threshold');

    if (!root || !threshold) {
        return;
    }

    var svg = root.querySelector('.timeline-graph');
    var grid = root.querySelector('[data-timeline-grid]');
    var events = Array.prototype.slice.call(root.querySelectorAll('[data-timeline-event]'));
    var rowHeight = 36;
    var minHeight = 252;

    function activeCategories() {
        return Array.prototype.slice.call(root.querySelectorAll('.timeline-category.active')).map(function (button) {
            return button.value;
        });
    }

    function drawGrid(height) {
        var html = '';

        for (var line = 0; line <= 10; line++) {
            html += '<path class="timeline-grid-line" d="M' + (line * 100) + ',0 v' + height + '"></path>';
        }

        for (var y = 0; y <= height; y += rowHeight) {
            html += '<path class="timeline-grid-line" d="M-10,' + y + ' h1020"></path>';
        }

        grid.innerHTML = html;
    }

    function render() {
        var minimumDuration = Number.parseFloat(threshold.value || '0');
        var categories = activeCategories();
        var index = 0;

        events.forEach(function (event) {
            var visible = Number.parseFloat(event.dataset.duration || '0') >= minimumDuration
                && categories.indexOf(event.dataset.category || '') !== -1;

            event.style.display = visible ? '' : 'none';

            if (visible) {
                event.setAttribute('transform', 'translate(0, ' + (index * rowHeight) + ')');
                index++;
            }
        });

        var height = Math.max(minHeight, index * rowHeight);
        svg.setAttribute('viewBox', '-10 0 1020 ' + height);
        drawGrid(height);
    }

    root.querySelectorAll('.timeline-category').forEach(function (button) {
        button.addEventListener('click', function () {
            button.classList.toggle('active');
            render();
        });
    });

    threshold.addEventListener('change', render);
    threshold.addEventListener('input', render);
    render();
}());
</script>
HTML;
    }
}
