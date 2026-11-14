<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Kernel\KernelInterface;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use SymPress\Profiler\Collector\AssetCollector;
use SymPress\Profiler\Collector\CacheCollector;
use SymPress\Profiler\Collector\CronCollector;
use SymPress\Profiler\Application\Profiler;
use SymPress\Profiler\Collector\DatabaseCollector;
use SymPress\Profiler\Collector\HookCollector;
use SymPress\Profiler\Collector\KernelCollector;
use SymPress\Profiler\Collector\LocalizationCollector;
use SymPress\Profiler\Collector\OptionsCollector;
use SymPress\Profiler\Collector\RequestCollector;
use SymPress\Profiler\Collector\RestAjaxCollector;
use SymPress\Profiler\Collector\WordPressQueryCollector;
use SymPress\Profiler\Recorder\ProfilerHttpClientRecorder;
use SymPress\Profiler\Recorder\ProfilerLifecycleRecorder;
use SymPress\Profiler\Support\ArraySanitizer;
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

    public function testTranslationTellsLoadedDomainsFromLookupsWithoutAFile(): void
    {
        $GLOBALS['l10n'] = ['sympress' => new \stdClass(), 'default' => new \NOOP_Translations()];

        try {
            $payload = (new LocalizationCollector())->collect($this->context());
        } finally {
            unset($GLOBALS['l10n']);
        }

        self::assertSame([['domain' => 'default', 'loaded' => false], ['domain' => 'sympress', 'loaded' => true]], $payload['domains']);
        self::assertSame(1, $payload['domain_count']);
        self::assertSame('de_DE', $payload['user_locale']);
    }

    public function testHttpRecorderAcceptsWhateverWordPressPassesAsUrl(): void
    {
        $recorder = new ProfilerHttpClientRecorder($this->gate());
        $recorder->enable();

        // wp_remote_get(false): core hands the raw value to pre_http_request and returns a WP_Error.
        self::assertFalse($recorder->track(false, ['method' => 'GET'], false));
        $recorder->record(new \WP_Error('http_request_failed', 'A valid URL was not provided.'), 'response', 'WP_Http', ['method' => 'GET'], false);

        self::assertSame('', $recorder->entries()[0]['url']);
        self::assertSame('A valid URL was not provided.', $recorder->entries()[0]['error']);
    }

    public function testListenersRegisteredThroughTheKernelAreNamedByTheirService(): void
    {
        // The kernel's HookLoader registers a closure that captures the service id and method.
        $register = static function (string $serviceId, string $method): \Closure {
            return static fn (mixed ...$arguments): mixed => [$serviceId, $method, $arguments];
        };
        $GLOBALS['wp_actions'] = ['init' => 1];
        $GLOBALS['wp_filter'] = ['init' => (object) ['callbacks' => [10 => [
            ['function' => $register(OptionsCollector::class, 'collect'), 'accepted_args' => 1],
            ['function' => static fn (): bool => true, 'accepted_args' => 1],
        ]]]];

        try {
            $listeners = (new HookCollector())->collect($this->context())['called_listeners'];
        } finally {
            unset($GLOBALS['wp_actions'], $GLOBALS['wp_filter']);
        }

        self::assertSame(OptionsCollector::class . '::collect', $listeners[0]['callback']);
        self::assertStringEndsWith('OptionsCollector.php', $listeners[0]['file']);
        self::assertSame('Closure', $listeners[1]['callback']);
    }

    public function testRedirectNoteIsReadFromTheCookieAndValidated(): void
    {
        $collect = fn (): ?array => (new RequestCollector(new ArraySanitizer(), WpContext::new()->force(WpContext::FRONTOFFICE)))->collect($this->context())['redirect'];

        try {
            $_COOKIE = [Profiler::REDIRECT_COOKIE => '{"token":"abc123","route":"/old-path","method":"GET","status_code":301}'];
            self::assertSame(['token' => 'abc123', 'route' => '/old-path', 'method' => 'GET', 'status_code' => 301], $collect());
            $_COOKIE = [Profiler::REDIRECT_COOKIE => '{"token":"../../etc","route":"/x"}'];
            self::assertNull($collect());
            $_COOKIE = [];
            self::assertNull($collect());
        } finally {
            $_COOKIE = [];
        }
    }

    public function testKernelBundlesComeFromTheCompiledContainer(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::never())->method('discoverBundles');
        $collector = new KernelCollector(
            $this->siteConfig(),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            $kernel,
            ['ProfilerBundle' => 'SymPress\\Profiler\\ProfilerBundle', 'RuntimeBundle' => 'SymPress\\Runtime\\RuntimeBundle'],
            ['ProfilerBundle' => ['path' => '/p', 'package' => 'sympress/profiler', 'type' => 'wordpress-muplugin', 'entry' => 'profiler/profiler.php']],
        );

        $payload = $collector->collect($this->context());

        self::assertSame([
            ['package' => 'sympress/profiler', 'type' => 'wordpress-muplugin', 'entry' => 'profiler/profiler.php', 'bundle' => 'SymPress\\Profiler\\ProfilerBundle'],
            ['package' => 'RuntimeBundle', 'type' => '', 'entry' => '', 'bundle' => 'SymPress\\Runtime\\RuntimeBundle'],
        ], $payload['bundles']);
        self::assertSame(get_included_files(), $payload['included_files']);
    }

    public function testQuerySqlAndQueryVarsShowPercentSignsInsteadOfPlaceholderHashes(): void
    {
        $GLOBALS['wpdb'] = new class {
            public function remove_placeholder_escape(string $query): string
            {
                return str_replace('{abc}', '%', $query);
            }
        };
        $GLOBALS['wp_query'] = (object) [
            'request'    => "SELECT * FROM wp_posts WHERE post_title LIKE '{abc}kernel{abc}'",
            'query_vars' => ['s' => 'kernel', 'search_orderby_title' => ["post_title LIKE '{abc}kernel{abc}'"], 'paged' => 0],
        ];

        try {
            $payload = (new WordPressQueryCollector())->collect($this->context());
        } finally {
            unset($GLOBALS['wpdb'], $GLOBALS['wp_query']);
        }

        self::assertSame("SELECT * FROM wp_posts WHERE post_title LIKE '%kernel%'", $payload['request_sql']);
        self::assertSame(['s' => 'kernel', 'search_orderby_title' => ["post_title LIKE '%kernel%'"], 'paged' => 0], $payload['query_vars']);
    }

    private function gate(): ProfileGate
    {
        return new ProfileGate($this->siteConfig(), WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
    }

    private function siteConfig(): SiteConfig
    {
        $config = $this->createStub(SiteConfig::class);
        $config->method('env')->willReturn(EnvConfig::LOCAL);
        $config->method('envIs')->willReturnCallback(static fn (string $environment): bool => $environment === EnvConfig::LOCAL);
        $config->method('hosting')->willReturn(SiteConfig::HOSTING_OTHER);
        $config->method('hostingIs')->willReturn(false);
        $config->method('locations')->willReturn($this->createStub(Locations::class));
        $config->method('get')->willReturn(null);
        $config->method('jsonSerialize')->willReturn([]);

        return $config;
    }

    private function context(): ProfileContext
    {
        return new ProfileContext('token', microtime(true), microtime(true), 0, 0, 0, 200, null, [], [], '/_profiler/token');
    }
}
