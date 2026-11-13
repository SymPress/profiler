<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use SymPress\Profiler\Collector\AssetCollector;
use SymPress\Profiler\Collector\CacheCollector;
use SymPress\Profiler\Collector\CronCollector;
use SymPress\Profiler\Collector\DatabaseCollector;
use SymPress\Profiler\Collector\OptionsCollector;
use SymPress\Profiler\Collector\RestAjaxCollector;
use SymPress\Profiler\Recorder\ProfilerLifecycleRecorder;
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

    public function testDatabaseKeepsExecutionOrderRealDuplicatesAndSkipsProfilerQueries(): void
    {
        $option = static fn (string $name): string => "SELECT option_value FROM wp_options WHERE option_name = '" . $name . "' LIMIT 1";
        $GLOBALS['wpdb'] = (object) [
            'num_queries' => 5,
            'last_error' => '',
            'queries' => [
                [$option('a'), 0.001, 'get_option', 1.0],
                [$option('b'), 0.005, 'get_option', 2.0],
                [$option('a'), 0.002, 'get_option', 3.0],
                ['SELECT option_name FROM wp_options', 0.5, "do_action('shutdown'), SymPress\\Profiler\\Collector\\OptionsCollector->transients", 4.0],
                ['SELECT 1', 0.003, 'wp', 5.0],
            ],
        ];

        try {
            $payload = (new DatabaseCollector())->collect($this->context());
        } finally {
            unset($GLOBALS['wpdb']);
        }

        self::assertSame(4, $payload['count']);
        self::assertSame(11.0, $payload['total_duration_ms']);
        self::assertSame([1.0, 5.0, 2.0, 3.0], array_column($payload['queries'], 'duration_ms'));
        self::assertSame(5.0, $payload['slow_queries'][0]['duration_ms']);
        self::assertCount(1, $payload['duplicate_queries']);
        self::assertSame(2, $payload['duplicate_queries'][0]['count']);
        self::assertFalse($payload['truncated']);
    }

    public function testTimelineKeepsTheFirstMarkOfEachHook(): void
    {
        $recorder = new ProfilerLifecycleRecorder($this->gate());

        foreach (['plugins_loaded', 'parse_query', 'parse_query', 'wp', 'parse_query'] as $index => $hook) {
            $GLOBALS['profiler_test_current_filter'] = $hook;
            $index === 0 ? $recorder->bootstrap() : $recorder->mark();
        }

        unset($GLOBALS['profiler_test_current_filter']);

        self::assertSame(['plugins_loaded', 'parse_query', 'wp'], array_column($recorder->events(), 'name'));
    }

    public function testScriptModulesListEnqueuedModulesAndTheirImports(): void
    {
        $GLOBALS['profiler_test_script_modules'] = new class {
            /** @var array<string, array{src: string, version: string|false|null, dependencies: list<array{id: string, import: string}>}> */
            private array $registered = [
                '@wordpress/interactivity' => ['src' => '/interactivity.js', 'version' => false, 'dependencies' => []],
                '@wordpress/block-library/navigation/view' => ['src' => '/navigation.js', 'version' => 'abc', 'dependencies' => [['id' => '@wordpress/interactivity', 'import' => 'static']]],
                '@wordpress/a11y' => ['src' => '/a11y.js', 'version' => null, 'dependencies' => []],
            ];

            /** @return list<string> */
            public function get_queue(): array
            {
                return ['@wordpress/block-library/navigation/view'];
            }

            /** @return array<string, mixed>|null */
            public function get_registered(string $id): ?array
            {
                return $this->registered[$id] ?? null;
            }
        };

        try {
            $modules = (new AssetCollector())->collect($this->context())['script_modules'];
        } finally {
            unset($GLOBALS['profiler_test_script_modules']);
        }

        self::assertSame([
            ['id' => '@wordpress/block-library/navigation/view', 'src' => '/navigation.js', 'version' => 'abc', 'dependencies' => ['@wordpress/interactivity'], 'enqueued' => true],
            ['id' => '@wordpress/interactivity', 'src' => '/interactivity.js', 'version' => '6.8.0', 'dependencies' => [], 'enqueued' => false],
        ], $modules);
    }

    private function gate(): ProfileGate
    {
        $config = $this->createStub(SiteConfig::class);
        $config->method('env')->willReturn(EnvConfig::LOCAL);
        $config->method('envIs')->willReturnCallback(static fn (string $environment): bool => $environment === EnvConfig::LOCAL);
        $config->method('hosting')->willReturn(SiteConfig::HOSTING_OTHER);
        $config->method('hostingIs')->willReturn(false);
        $config->method('locations')->willReturn($this->createStub(Locations::class));
        $config->method('get')->willReturn(null);
        $config->method('jsonSerialize')->willReturn([]);

        return new ProfileGate($config, WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
    }

    private function context(): ProfileContext
    {
        return new ProfileContext('token', microtime(true), microtime(true), 0, 0, 0, 200, null, [], [], '/_profiler/token');
    }
}
