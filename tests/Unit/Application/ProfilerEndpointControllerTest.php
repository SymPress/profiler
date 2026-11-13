<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\ProfilerEndpointController;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Application\ProfileViewBuilder;
use SymPress\Profiler\Infrastructure\FilesystemProfileStorage;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\View\PhpViewRenderer;
use SymPress\Profiler\View\ProfilerPageRenderer;
use SymPress\Profiler\View\ToolbarRenderer;
use SymPress\Profiler\View\WebProfilerAssets;

final class ProfilerEndpointControllerTest extends TestCase
{
    private string $storageDirectory;
    private ProfilerEndpointController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/profiler-endpoint-' . bin2hex(random_bytes(4));
        $storage = new FilesystemProfileStorage($this->storageDirectory);
        $storage->save(new ProfileRecord('abc123', '2026-11-13T10:00:00.000+00:00', ['method' => 'GET', 'url' => 'https://example.test/'], []));
        $urls = new ProfilerUrlGenerator();
        $assets = new WebProfilerAssets();
        $this->controller = new ProfilerEndpointController(
            $this->gate(),
            new ProfilerRequestMatcher(),
            $urls,
            $storage,
            new ProfileViewBuilder([], new ToolbarRenderer($assets, $urls)),
            new ProfilerPageRenderer(new PhpViewRenderer(dirname(__DIR__, 3) . '/views'), $assets, $urls),
            $assets,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->storageDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->storageDirectory);
        unset($GLOBALS['profiler_test_logged_in']);
        $_SERVER = [];
    }

    public function testEveryRouteIsDeniedWithoutAnAuthorizedUser(): void
    {
        $GLOBALS['profiler_test_logged_in'] = false;

        foreach (['/_profiler', '/_profiler/abc123', '/_profiler/latest', '/_wdt/abc123', '/_wdt/styles', '/_profiler/font/JetBrainsMono'] as $path) {
            self::assertSame(['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Access denied.'], $this->respond($path), $path);
        }
    }

    public function testOtherPathsAreLeftToWordPress(): void
    {
        self::assertNull($this->respond('/blog/'));
        self::assertNull($this->respond('/_profiler-guide/'));
    }

    public function testAuthorizedRoutes(): void
    {
        self::assertSame([200, 'text/css; charset=UTF-8'], $this->statusAndType('/_wdt/styles'));
        self::assertSame([200, 'font/woff2'], $this->statusAndType('/_profiler/font/JetBrainsMono'));
        self::assertSame([404, 'text/plain; charset=UTF-8'], $this->statusAndType('/_profiler/font/..wp-config'));
        // Encoded slashes decode into extra path segments: no font route, only the profile list.
        self::assertSame([200, 'text/html; charset=UTF-8'], $this->statusAndType('/_profiler/font/..%2F..%2Fwp-config'));
        self::assertSame([200, 'text/html; charset=UTF-8'], $this->statusAndType('/_wdt/abc123'));
        self::assertSame([404, 'text/html; charset=UTF-8'], $this->statusAndType('/_wdt/missing'));
        self::assertSame([200, 'text/html; charset=UTF-8'], $this->statusAndType('/_profiler'));

        $profile = $this->respond('/_profiler/abc123');
        self::assertSame(200, $profile['status'] ?? null);
        self::assertStringContainsString('SymPress Profiler', $profile['body'] ?? '');

        $missing = $this->respond('/_profiler/missing');
        self::assertSame(404, $missing['status'] ?? null);
        self::assertStringContainsString('Profile not found', $missing['body'] ?? '');

        $latest = $this->respond('/_profiler/latest');
        self::assertSame(302, $latest['status'] ?? null);
        self::assertStringContainsString('/_profiler/abc123', $latest['location'] ?? '');
    }

    /** @return array{status: int, type: string, body: string, location?: string}|null */
    private function respond(string $path): ?array
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        return $this->controller->response();
    }

    /** @return array{int|null, string|null} */
    private function statusAndType(string $path): array
    {
        $response = $this->respond($path);

        return [$response['status'] ?? null, $response['type'] ?? null];
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
}
