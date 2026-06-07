<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Recorder\ProfilerErrorRecorder;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class ExceptionCollector extends AbstractCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly ProfilerErrorRecorder $errors,
    ) {
    }

    public function key(): string
    {
        return 'exceptions';
    }

    public function label(): string
    {
        return 'Exception';
    }

    public function icon(): string
    {
        return 'alert-circle';
    }

    public function collect(ProfileContext $context): array
    {
        $entries = [];

        foreach ($context->throwables() as $throwable) {
            $entries[] = [
                'type' => 'throwable',
                'class' => $throwable['class'],
                'message' => $throwable['message'],
                'file' => $throwable['file'],
                'line' => $throwable['line'],
            ];
        }

        $fatal = $this->errors->fatalError();

        if (is_array($fatal)) {
            $entries[] = [
                'type' => 'fatal',
                'class' => $fatal['label'],
                'message' => $fatal['message'],
                'file' => $fatal['file'],
                'line' => $fatal['line'],
            ];
        }

        return [
            'count' => count($entries),
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

        if (!is_array($entries) || $entries === []) {
            return $this->panel(
                'exceptions',
                $this->label(),
                $this->icon(),
                Html::emptyPanel('No uncaught exceptions or fatal errors were captured.'),
                enabled: false,
            );
        }

        $rows = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rows[] = [
                $this->stringValue($entry, 'type'),
                $this->stringValue($entry, 'class'),
                $this->stringValue($entry, 'message'),
                $this->stringValue($entry, 'file'),
                $this->intValue($entry, 'line'),
            ];
        }

        $html = Html::section('Exceptions', Html::table(
            ['Type', 'Class', 'Message', 'File', 'Line'],
            $rows,
        ));

        return $this->panel(
            'exceptions',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'count')),
        );
    }
}
