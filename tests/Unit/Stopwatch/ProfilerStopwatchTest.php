<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Stopwatch;

use SymPress\Profiler\Stopwatch\ProfilerStopwatch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Stopwatch\Stopwatch;

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

    public function test_services_preserve_native_stopwatch_and_custom_timing(): void
    {
        $container = new ContainerBuilder();
        $container->register('debug.stopwatch', Stopwatch::class)->setPublic(true);
        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 3) . '/Resources/config'));
        $loader->load('services.yaml');

        self::assertFalse($container->hasAlias('debug.stopwatch'));
        self::assertSame(Stopwatch::class, $container->getDefinition('debug.stopwatch')->getClass());
        self::assertTrue($container->hasDefinition(ProfilerStopwatch::class));
        $custom = new ProfilerStopwatch();
        $custom->start('custom')->stop();
        self::assertCount(1, $custom->events());
    }

    public function test_services_leave_optional_native_stopwatch_absent(): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 3) . '/Resources/config'));
        $loader->load('services.yaml');

        self::assertFalse($container->has('debug.stopwatch'));
        self::assertTrue($container->hasDefinition(ProfilerStopwatch::class));
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
