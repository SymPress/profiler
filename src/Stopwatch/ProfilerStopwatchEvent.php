<?php

declare(strict_types=1);

namespace SymPress\Profiler\Stopwatch;

final class ProfilerStopwatchEvent
{
    /** @var list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}> */
    private array $periods = [];

    private ?float $periodStartedAt = null;
    private float $maxMemoryMb = 0.0;

    public function __construct(
        private readonly string $name,
        private readonly string $category,
        private readonly float $startedAt,
    ) {

        $this->periodStartedAt = $startedAt;
        $this->maxMemoryMb = $this->memoryMb();
    }

    public function __toString(): string
    {
        return sprintf('%.2f MiB - %.0f ms', $this->getMemory(), $this->getDuration());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function getDuration(): float
    {
        $duration = 0.0;

        foreach ($this->periods() as $period) {
            $duration += $period['duration_ms'];
        }

        return round($duration, 2);
    }

    public function getMemory(): float
    {
        $this->maxMemoryMb = max($this->maxMemoryMb, $this->memoryMb());

        return round($this->maxMemoryMb, 2);
    }

    public function lap(): self
    {
        $this->closeCurrentPeriod(microtime(true));
        $this->periodStartedAt = microtime(true);

        return $this;
    }

    public function stop(): self
    {
        $this->closeCurrentPeriod(microtime(true));
        $this->periodStartedAt = null;

        return $this;
    }

    /** @return list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}> */
    public function periods(): array
    {
        $periods = $this->periods;

        if ($this->periodStartedAt !== null) {
            $now = microtime(true);
            $periods[] = [
                'started_at'  => $this->periodStartedAt,
                'stopped_at'  => $now,
                'duration_ms' => round(($now - $this->periodStartedAt) * 1000, 2),
                'memory_mb'   => $this->memoryMb(),
            ];
        }

        return $periods;
    }

    /** @return array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>} */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'category'    => $this->category,
            'started_at'  => $this->startedAt,
            'duration_ms' => $this->getDuration(),
            'memory_mb'   => $this->getMemory(),
            'periods'     => $this->periods(),
        ];
    }

    private function closeCurrentPeriod(float $stoppedAt): void
    {
        if ($this->periodStartedAt === null) {
            return;
        }

        $memory = $this->memoryMb();
        $this->maxMemoryMb = max($this->maxMemoryMb, $memory);
        $this->periods[] = [
            'started_at'  => $this->periodStartedAt,
            'stopped_at'  => $stoppedAt,
            'duration_ms' => max(0.0, round(($stoppedAt - $this->periodStartedAt) * 1000, 2)),
            'memory_mb'   => $memory,
        ];
    }

    private function memoryMb(): float
    {
        return round(memory_get_usage(true) / 1048576, 2);
    }
}
