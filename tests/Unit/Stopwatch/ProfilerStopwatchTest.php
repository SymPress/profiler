<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Stopwatch;

use SymPress\Profiler\Stopwatch\ProfilerStopwatch;
use PHPUnit\Framework\TestCase;

final class ProfilerStopwatchTest extends TestCase
{
    public function test_it_records_custom_events_and_periods(): void
    {
        $stopwatch = new ProfilerStopwatch();

        $stopwatch->start('export-data', 'export');
        usleep(1000);
        $stopwatch->lap('export-data');
        usleep(1000);
        $event = $stopwatch->stop('export-data');

        $events = $stopwatch->events();

        self::assertSame('export-data', $event->name());
        self::assertSame('export', $event->category());
        self::assertCount(1, $events);
        self::assertSame('export-data', $events[0]['name']);
        self::assertSame('export', $events[0]['category']);
        self::assertCount(2, $events[0]['periods']);
        self::assertGreaterThan(0.0, $events[0]['duration_ms']);
        self::assertStringContainsString('MiB -', (string) $event);
    }

    public function test_it_groups_events_by_section(): void
    {
        $stopwatch = new ProfilerStopwatch();

        $stopwatch->openSection('parsing');
        $stopwatch->start('validating-file')->stop();
        $stopwatch->stopSection('parsing');

        self::assertCount(1, $stopwatch->getSectionEvents('parsing'));
        self::assertCount(0, $stopwatch->getSectionEvents());
    }
}
