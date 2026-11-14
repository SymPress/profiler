<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\View;

use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;
use SymPress\Profiler\View\ToolbarRenderer;
use SymPress\Profiler\View\WebProfilerAssets;
use PHPUnit\Framework\TestCase;

final class ToolbarRendererTest extends TestCase
{
    public function test_it_renders_expected_toolbar_blocks(): void
    {
        $renderer = new ToolbarRenderer(
            new WebProfilerAssets(),
            new ProfilerUrlGenerator(),
        );
        $profile = new ProfileRecord(
            'token123',
            '2026-04-19T10:00:00+00:00',
            [
                'profiler_url' => 'https://example.test/_profiler/token123?panel=request',
            ],
            [
                'request' => [
                    'status_code' => 200,
                    'status_text' => 'OK',
                    'method' => 'GET',
                    'url' => 'https://example.test/',
                    'uri' => '/',
                    'context' => 'frontoffice',
                ],
                'routing' => [
                    'conditionals' => ['is_front_page' => true],
                    'queried_object' => [],
                ],
                'performance' => [
                    'total_duration_ms' => 109.0,
                    'bootstrap_ms' => 12.0,
                    'runtime_ms' => 51.0,
                    'render_ms' => 46.0,
                    'peak_memory_mb' => 4.0,
                ],
                'database' => [
                    'count' => 4,
                    'total_duration_ms' => 4.09,
                    'enabled' => true,
                    'last_error' => '',
                ],
                'localization' => [
                    'locale' => 'de_DE',
                    'domain_count' => 8,
                    'is_rtl' => false,
                    'timezone' => 'Europe/Berlin',
                ],
                'security' => [
                    'logged_in' => false,
                    'user' => [],
                    'ssl' => true,
                    'has_auth_cookie' => false,
                ],
                'template' => [
                    'template_name' => 'front-page.php',
                    'template' => '/var/www/html/theme/front-page.php',
                    'render_ms' => 97.0,
                ],
                'kernel' => [
                    'wordpress_version' => '6.8.0',
                    'environment' => 'development',
                    'php_version' => '8.5.3',
                    'theme' => ['name' => 'Brian Theme'],
                ],
            ],
        );

        $html = $renderer->renderContent(
            $profile,
            [
                new ToolbarBlock('request', 'Request', '24.1 ms', 'GET /foo · 200', 'https://example.test/_profiler/token123?panel=request', 'cyan'),
                new ToolbarBlock('database', 'Database', '8 queries', '3.2 ms total', 'https://example.test/_profiler/token123?panel=database', 'green'),
            ],
        );

        self::assertStringContainsString('@homepage', $html);
        self::assertStringContainsString('109 ms', $html);
        self::assertStringContainsString('4.0 MiB', $html);
        self::assertStringContainsString('4.09 ms', $html);
        self::assertStringContainsString('6.8.0', $html);
        self::assertStringContainsString('sf-toolbar-block-right', $html);
        self::assertStringContainsString('sf-toolbar-ajax-clear', $html);
        self::assertStringContainsString('sfToolbarMainContent-token123', $html);

        // The page only gets a placeholder; the loader script fetches the content above from /_wdt/{token}.
        $bootstrap = $renderer->renderBootstrap('token123');
        self::assertStringContainsString('Profiler Toolbar', $bootstrap);
        self::assertStringContainsString('id="sfwdttoken123"', $bootstrap);
        self::assertStringContainsString('sfToolbarMainContent-token123', $bootstrap);
        self::assertStringContainsString('Loading…', $bootstrap);
        self::assertStringContainsString('/_wdt/styles', $bootstrap);
        self::assertStringNotContainsString('109 ms', $bootstrap);
    }

    public function testRequestsAreNamedAfterWhatTheyResolvedTo(): void
    {
        $renderer = new ToolbarRenderer(new WebProfilerAssets(), new ProfilerUrlGenerator());
        $label = static fn (array $conditionals, string $uri, array $queriedObject = []): string => $renderer->renderContent(
            new ProfileRecord('token123', '2026-11-14T10:00:00+00:00', ['method' => 'GET'], [
                'request' => ['method' => 'GET', 'uri' => $uri, 'status_code' => 200],
                'routing' => ['conditionals' => $conditionals, 'queried_object' => $queriedObject, 'request' => trim($uri, '/')],
            ]),
            [new ToolbarBlock('request', 'Request', '1 ms', '', '#', 'cyan')],
        );

        self::assertStringContainsString('@homepage', $label(['is_front_page' => true, 'is_home' => true], '/'));
        self::assertStringContainsString('@blog', $label(['is_home' => true], '/blog/'));
        self::assertStringNotContainsString('@homepage', $label(['is_home' => true], '/blog/'));
        self::assertStringContainsString('@search', $label(['is_search' => true], '/'));
        self::assertStringContainsString('@404', $label(['is_404' => true], '/missing-page/'));
        self::assertStringContainsString('@migrations-are-classes', $label(['is_single' => true], '/blog/migrations-are-classes/', ['name' => 'migrations-are-classes']));
    }
}
