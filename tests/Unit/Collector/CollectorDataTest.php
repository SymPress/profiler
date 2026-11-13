<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Profiler\Collector\CacheCollector;
use SymPress\Profiler\Collector\CronCollector;
use SymPress\Profiler\Collector\OptionsCollector;
use SymPress\Profiler\Collector\RestAjaxCollector;
use SymPress\Profiler\Value\ProfileContext;

final class CollectorDataTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wp_rest_server'],
            $GLOBALS['wp_object_cache'],
            $GLOBALS['profiler_test_rest_server_built'],
            $GLOBALS['profiler_test_cron'],
            $GLOBALS['profiler_test_alloptions'],
        );
        $_SERVER = [];
    }

    public function testRestRoutesAreReadWithoutBuildingTheRestServer(): void
    {
        $collector = new RestAjaxCollector();

        self::assertSame([], $collector->collect($this->context())['registered_routes']);
        self::assertArrayNotHasKey('profiler_test_rest_server_built', $GLOBALS);

        $GLOBALS['wp_rest_server'] = new class {
            /** @return array<string, list<array{methods: array<string, bool>}>> */
            public function get_routes(): array
            {
                $routes = [];

                foreach (range(1, 130) as $index) {
                    $routes['/demo/v1/item-' . $index] = [['methods' => ['GET' => true]]];
                }

                return $routes;
            }
        };

        self::assertCount(130, $collector->collect($this->context())['registered_routes']);
    }

    public function testCacheGroupsAreReportedByName(): void
    {
        $GLOBALS['wp_object_cache'] = (object) [
            'cache' => ['users' => [1 => 'a'], 'posts' => [1 => 'b']],
            'global_groups' => ['users' => true, 'site-options' => true],
        ];

        $payload = (new CacheCollector())->collect($this->context());
        $groups = array_column($payload['groups'], 'global', 'name');

        self::assertSame(['users', 'site-options'], $payload['global_groups']);
        self::assertTrue($groups['users']);
        self::assertFalse($groups['posts']);
    }

    public function testCronEventsUseSiteTimeAndNameSingleEvents(): void
    {
        $timestamp = (int) strtotime('2026-11-13T16:42:44+00:00');
        $GLOBALS['profiler_test_cron'] = [$timestamp => ['demo_hook' => ['key' => ['schedule' => false, 'args' => []]]]];

        $event = (new CronCollector())->collect($this->context())['events'][0];

        self::assertSame('2026-11-13 17:42:44 CET', $event['next_run']);
        self::assertSame('single', $event['schedule']);
    }

    public function testOptionValuesAreDecodedLikeGetOption(): void
    {
        $GLOBALS['profiler_test_alloptions'] = ['rewrite' => serialize(['a' => 'b']), 'blogname' => 'SymPress', 'widget' => serialize(new \stdClass())];

        $rows = array_column((new OptionsCollector())->collect($this->context())['largest_autoloaded'], null, 'name');

        self::assertSame(['a' => 'b'], $rows['rewrite']['value']);
        self::assertSame('array', $rows['rewrite']['type']);
        self::assertSame('SymPress', $rows['blogname']['value']);
        self::assertSame(serialize(new \stdClass()), $rows['widget']['value']);
    }

    private function context(): ProfileContext
    {
        return new ProfileContext('token', microtime(true), microtime(true), 0, 0, 0, 200, null, [], [], '/_profiler/token');
    }
}
