<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Recorder\ProfilerHttpClientRecorder;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class HttpClientCollector extends AbstractCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly ProfilerHttpClientRecorder $httpClient,
    ) {
    }

    public function key(): string
    {
        return 'http_client';
    }

    public function label(): string
    {
        return 'HTTP Client';
    }

    public function icon(): string
    {
        return 'forward';
    }

    public function collect(ProfileContext $context): array
    {
        $entries = $this->httpClient->entries();
        $errorCount = 0;
        $totalDuration = 0.0;

        foreach ($entries as $entry) {
            $totalDuration += $entry['duration_ms'];

            if ($entry['error'] !== '') {
                $errorCount++;
            }
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => $right['duration_ms'] <=> $left['duration_ms'],
        );

        return [
            'count' => count($entries),
            'error_count' => $errorCount,
            'total_duration_ms' => round($totalDuration, 2),
            'requests' => $entries,
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
        $requests = $payload['requests'] ?? [];

        if (!is_array($requests) || $requests === []) {
            return $this->panel(
                'http_client',
                $this->label(),
                $this->icon(),
                Html::emptyPanel('No outgoing HTTP API requests were captured.'),
                enabled: false,
            );
        }

        $overview = Html::definitionTable([
            'Requests' => $this->intValue($payload, 'count'),
            'Errors' => $this->intValue($payload, 'error_count'),
            'Total duration (ms)' => $this->floatValue($payload, 'total_duration_ms'),
        ]);

        $rows = [];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $rows[] = [
                $this->stringValue($request, 'method'),
                $this->stringValue($request, 'url'),
                $this->stringValue($request, 'error') !== ''
                    ? $this->stringValue($request, 'error')
                    : $this->statusText($request['status_code'] ?? null),
                $this->floatValue($request, 'duration_ms'),
                $this->stringValue($request, 'transport'),
                $this->boolValue($request, 'blocking') ? 'yes' : 'no',
                $this->floatValue($request, 'timeout'),
                $this->intValue($request, 'response_size'),
            ];
        }

        $html = Html::section('Overview', $overview);
        $html .= Html::section('Requests', Html::table(
            ['Method', 'URL', 'Status / Error', 'Duration (ms)', 'Transport', 'Blocking', 'Timeout', 'Bytes'],
            $rows,
        ));

        return $this->panel(
            'http_client',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'count')),
        );
    }

    private function statusText(mixed $statusCode): string
    {
        return is_numeric($statusCode) ? (string) (int) $statusCode : 'n/a';
    }
}
