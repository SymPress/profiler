<?php

declare(strict_types=1);

namespace SymPress\Profiler\Stopwatch;

final class ProfilerStopwatch
{
    public const string ROOT = '__root__';

    /** @var array<string, ProfilerStopwatchEvent> */
    private array $events = [];

    /** @var array<string, list<string>> */
    private array $sections = [
        self::ROOT => [],
    ];

    private string $currentSection = self::ROOT;

    public function start(string $name, string $category = 'default'): ProfilerStopwatchEvent
    {
        $event = new ProfilerStopwatchEvent($name, $category, microtime(true));
        $this->events[$name] = $event;
        $this->sections[$this->currentSection] ??= [];

        if (!in_array($name, $this->sections[$this->currentSection], true)) {
            $this->sections[$this->currentSection][] = $name;
        }

        return $event;
    }

    public function stop(string $name): ProfilerStopwatchEvent
    {
        return $this->event($name)->stop();
    }

    public function lap(string $name): ProfilerStopwatchEvent
    {
        return $this->event($name)->lap();
    }

    public function getEvent(string $name): ?ProfilerStopwatchEvent
    {
        return $this->events[$name] ?? null;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->sections = [self::ROOT => []];
        $this->currentSection = self::ROOT;
    }

    public function openSection(?string $id = null): void
    {
        $section = is_string($id) && trim($id) !== ''
            ? trim($id)
            : 'section_' . (count($this->sections) + 1);

        $this->sections[$section] ??= [];
        $this->currentSection = $section;
    }

    public function stopSection(string $id): void
    {
        $this->sections[$id] ??= [];
        $this->currentSection = self::ROOT;
    }

    /** @return list<ProfilerStopwatchEvent> */
    public function getSectionEvents(string $id = self::ROOT): array
    {
        $names = $this->sections[$id] ?? [];
        $events = [];

        foreach ($names as $name) {
            if (!isset($this->events[$name])) {
                continue;
            }

            $events[] = $this->events[$name];
        }

        return $events;
    }

    /** @return list<array{name: string, category: string, started_at: float, duration_ms: float, memory_mb: float, periods: list<array{started_at: float, stopped_at: float, duration_ms: float, memory_mb: float}>}> */
    public function events(): array
    {
        return array_values(array_map(
            static fn (ProfilerStopwatchEvent $event): array => $event->toArray(),
            $this->events,
        ));
    }

    private function event(string $name): ProfilerStopwatchEvent
    {
        return $this->events[$name] ?? $this->start($name);
    }
}
