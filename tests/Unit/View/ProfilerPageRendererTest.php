<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\View;

use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use SymPress\Profiler\Value\ToolbarBlock;
use SymPress\Profiler\View\PhpViewRenderer;
use SymPress\Profiler\View\ProfilerPageRenderer;
use SymPress\Profiler\View\WebProfilerAssets;
use PHPUnit\Framework\TestCase;

final class ProfilerPageRendererTest extends TestCase
{
    public function test_it_renders_profile_page_with_profiler_branding(): void
    {
        $renderer = new ProfilerPageRenderer(
            new PhpViewRenderer(dirname(__DIR__, 3) . '/views'),
            new WebProfilerAssets(),
            new ProfilerUrlGenerator(),
        );
        $profile = new ProfileRecord(
            'token123',
            '2026-04-20T10:00:00+00:00',
            [
                'method' => 'GET',
                'url' => 'https://example.test/',
                'status_code' => 200,
                'profiler_url' => 'https://example.test/_profiler/token123?panel=request',
            ],
            [
                'request' => ['ip' => '127.0.0.1'],
            ],
        );

        $html = $renderer->renderProfile(
            $profile,
            [new ToolbarBlock('request', 'Request', '24.1 ms', 'GET / · 200', '#')],
            [
                new CollectorPanel('request', 'Request / Response', 'request', '<p>Request panel</p>', '200'),
                new CollectorPanel('logs', 'Logs', 'logger', '<p>Logs panel</p>'),
            ],
            'request',
        );

        self::assertStringContainsString('Symfony Profiler', $html);
        self::assertStringContainsString('Search profiles', $html);
        self::assertStringNotContainsString('Last 10', $html);
        self::assertStringContainsString('Request panel', $html);
    }

    public function test_it_renders_search_filters_on_results_page(): void
    {
        $renderer = new ProfilerPageRenderer(
            new PhpViewRenderer(dirname(__DIR__, 3) . '/views'),
            new WebProfilerAssets(),
            new ProfilerUrlGenerator(),
        );
        $profile = new ProfileRecord(
            'token123',
            '2026-04-20T10:00:00+00:00',
            [
                'method' => 'GET',
                'url' => 'https://example.test/',
                'context' => 'frontoffice',
                'status_code' => 200,
                'ip' => '127.0.0.1',
                'profiler_url' => 'https://example.test/_profiler/token123?panel=request',
            ],
            [],
        );

        $html = $renderer->renderResults(
            [$profile],
            new ProfileSearchCriteria(text: 'example', limit: 10),
        );

        self::assertStringContainsString('name="token"', $html);
        self::assertStringContainsString('name="method"', $html);
        self::assertStringContainsString('name="status"', $html);
        self::assertStringContainsString('results found', $html);
    }
}
