<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Application;

use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use PHPUnit\Framework\TestCase;

final class ProfileGateTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        unset(
            $GLOBALS['profiler_test_logged_in'],
            $GLOBALS['profiler_test_can_manage'],
            $GLOBALS['profiler_test_multisite'],
            $GLOBALS['profiler_test_super_admin'],
            $GLOBALS['profiler_test_user_lookups'],
        );
    }

    public function test_it_rejects_anonymous_requests_even_in_local_environment(): void
    {
        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
        );

        $GLOBALS['profiler_test_logged_in'] = false;
        self::assertFalse($gate->shouldCollect());
        self::assertFalse($gate->canAccessProfiler());
    }

    public function test_it_shows_toolbar_in_development_html_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
        );

        self::assertTrue($gate->shouldInjectToolbar());
    }

    public function testBackgroundNoiseIsNotProfiled(): void
    {
        $gate = fn (string $context): ProfileGate => new ProfileGate($this->config(EnvConfig::LOCAL), WpContext::new()->force($context), new ProfilerRequestMatcher());

        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        $_REQUEST = ['action' => 'heartbeat'];
        self::assertFalse($gate(WpContext::AJAX)->shouldCollect());
        $_REQUEST = ['action' => 'save_draft'];
        self::assertTrue($gate(WpContext::AJAX)->shouldCollect());
        $_REQUEST = [];

        $_SERVER['REQUEST_URI'] = '/favicon.ico?v=2';
        self::assertFalse($gate(WpContext::FRONTOFFICE)->shouldCollect());
        $_SERVER['REQUEST_URI'] = '/blog/';
        self::assertTrue($gate(WpContext::FRONTOFFICE)->shouldCollect());
    }

    public function testToolbarIsOnlyBufferedForHtmlNavigations(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $gate = new ProfileGate($this->config(EnvConfig::LOCAL), WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());

        foreach (['text/html,application/xhtml+xml;q=0.9' => true, 'application/xhtml+xml' => true, '*/*' => false, 'application/json' => false, '' => false] as $accept => $expected) {
            $_SERVER['HTTP_ACCEPT'] = $accept;
            self::assertSame($expected, $gate->shouldInjectToolbar(), $accept);
        }
    }

    public function test_it_does_not_collect_profiles_in_the_backoffice(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::BACKOFFICE),
            new ProfilerRequestMatcher(),
        );

        self::assertFalse($gate->shouldCollect());
        self::assertFalse($gate->shouldInjectToolbar());
    }

    public function test_it_does_not_collect_profiles_for_the_login_screen(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::LOGIN),
            new ProfilerRequestMatcher(),
        );

        self::assertFalse($gate->shouldCollect());
        self::assertFalse($gate->shouldInjectToolbar());
    }

    public function test_it_does_not_enable_profiles_in_production_from_public_request_parameters(): void
    {
        $_GET['profile'] = '1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $gate = new ProfileGate(
            $this->config(EnvConfig::PRODUCTION),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
        );

        self::assertFalse($gate->shouldCollect());
        self::assertFalse($gate->canAccessProfiler());
        self::assertFalse($gate->shouldInjectToolbar());
    }

    public function test_it_can_collect_conditionally_from_a_configured_parameter(): void
    {
        $_GET['profile'] = '1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
            false,
            'profile',
        );

        self::assertTrue($gate->shouldCollect());
        self::assertTrue($gate->shouldInjectToolbar());
    }

    public function test_it_does_not_collect_when_disabled_without_the_configured_parameter(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
            false,
            'profile',
        );

        self::assertFalse($gate->shouldCollect());
        self::assertFalse($gate->shouldInjectToolbar());
    }

    public function test_it_can_enable_toolbar_replacement_headers(): void
    {
        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
            true,
            '',
            true,
        );

        self::assertTrue($gate->shouldReplaceToolbarAfterAjax());
    }

    public function testMultisiteRequiresSuperAdmin(): void
    {
        $gate = new ProfileGate($this->config(EnvConfig::LOCAL), WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
        $GLOBALS['profiler_test_multisite'] = true;

        self::assertFalse($gate->canAccessProfiler());
        $GLOBALS['profiler_test_super_admin'] = true;
        self::assertTrue($gate->canAccessProfiler());
    }

    public function testClosedProductionGateDoesNotResolveTheCurrentUser(): void
    {
        $gate = new ProfileGate($this->config(EnvConfig::PRODUCTION), WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());

        self::assertFalse($gate->canAccessProfiler());
        self::assertFalse($gate->shouldCollect());
        self::assertArrayNotHasKey('profiler_test_user_lookups', $GLOBALS);
    }

    public function testOnlyWholePathSegmentsAreProfilerRoutes(): void
    {
        $matcher = new ProfilerRequestMatcher();

        foreach (['/_profiler' => true, '/_profiler/abc' => true, '/_wdt/abc' => true, '/_profiler-guide/' => false, '/_wdtx' => false, '/blog/_profiler' => false] as $uri => $expected) {
            $_SERVER['REQUEST_URI'] = $uri;
            self::assertSame($expected, $matcher->isInternalProfilerRequest(), $uri);
        }
    }

    public function testUnauthorizedCapabilityCannotCollectOrViewEvenInDevelopment(): void
    {
        $GLOBALS['profiler_test_can_manage'] = false;
        $gate = new ProfileGate($this->config(EnvConfig::LOCAL), WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
        self::assertFalse($gate->canAccessProfiler());
        self::assertFalse($gate->shouldCollect());
        self::assertFalse($gate->shouldInjectToolbar());
    }

    public function testProductionOptInStillRequiresAuthenticatedAdministrator(): void
    {
        \ProfilerTestFilters::reset();
        add_filter('profiler.enable_outside_development', static fn (): bool => true);
        $gate = new ProfileGate($this->config(EnvConfig::PRODUCTION), WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
        $GLOBALS['profiler_test_logged_in'] = false;
        self::assertFalse($gate->shouldCollect());
        $GLOBALS['profiler_test_logged_in'] = true;
        self::assertTrue($gate->shouldCollect());
        \ProfilerTestFilters::reset();
    }

    private function config(string $environment): SiteConfig
    {
        $locations = $this->createStub(Locations::class);
        $config = $this->createStub(SiteConfig::class);

        $config->method('env')->willReturn($environment);
        $config->method('envIs')->willReturnCallback(
            static fn (string $env): bool => $environment === $env,
        );
        $config->method('hosting')->willReturn(SiteConfig::HOSTING_OTHER);
        $config->method('hostingIs')->willReturn(false);
        $config->method('locations')->willReturn($locations);
        $config->method('get')->willReturn(null);
        $config->method('jsonSerialize')->willReturn([]);

        return $config;
    }
}
