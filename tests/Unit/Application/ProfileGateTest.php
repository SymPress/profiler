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
    }

    public function test_it_collects_profiles_before_pluggable_user_functions_are_available(): void
    {
        $gate = new ProfileGate(
            $this->config(EnvConfig::LOCAL),
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
        );

        self::assertTrue($gate->shouldCollect());
        self::assertTrue($gate->canAccessProfiler());
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
