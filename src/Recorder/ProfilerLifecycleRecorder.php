<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerLifecycleRecorder
{
    private bool $enabled = false;
    private float $requestStartedAt = 0.0;

    /** @var list<array{name: string, started_at: float, memory_mb: float, detail: string}> */
    private array $events = [];

    public function __construct(
        private readonly ProfileGate $gate,
    ) {
    }

    public function bootstrap(): void
    {
        if (!$this->gate->shouldCollect()) {
            return;
        }

        $this->enabled = true;
        $this->requestStartedAt = $this->serverRequestTime();
        $this->mark();
    }

    public function mark(mixed ...$arguments): void
    {
        unset($arguments);

        if (!$this->enabled) {
            return;
        }

        $name = function_exists('current_filter') ? (string) current_filter() : 'runtime';

        if ($name === '') {
            $name = 'runtime';
        }

        $this->events[] = [
            'name'       => $name,
            'started_at' => microtime(true),
            'memory_mb'  => round(memory_get_usage(true) / 1048576, 2),
            'detail'     => '',
        ];
    }

    public function captureTemplate(string $template): string
    {
        if ($this->enabled) {
            $this->events[] = [
                'name'       => 'template_include',
                'started_at' => microtime(true),
                'memory_mb'  => round(memory_get_usage(true) / 1048576, 2),
                'detail'     => $template,
            ];
        }

        return $template;
    }

    /** @return list<array{name: string, started_at: float, memory_mb: float, detail: string}> */
    public function events(): array
    {
        return $this->events;
    }

    public function requestStartedAt(): float
    {
        return $this->requestStartedAt > 0.0
            ? $this->requestStartedAt
            : $this->serverRequestTime();
    }

    private function serverRequestTime(): float
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- numeric server timestamp, not user-controlled text input.
        $value = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_numeric($value) ? (float) $value : microtime(true);
    }
}
