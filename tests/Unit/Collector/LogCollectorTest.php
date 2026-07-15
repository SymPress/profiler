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
use SymPress\Profiler\Collector\LogCollector;
use SymPress\Profiler\Recorder\ProfilerErrorRecorder;
use SymPress\Profiler\Support\ArraySanitizer;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;

final class LogCollectorTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        \ProfilerTestFilters::reset();
    }

    public function testItNormalizesTheMonologFilterPayloadAndEscapesRenderedMessages(): void
    {
        add_filter('sympress_profiler_log_entries', static function (array $entries): array {
            $entries[] = [
                'level' => 'WARNING',
                'message' => '<script>alert(1)</script>',
                'captured_at' => '2026-04-19T10:00:00+00:00',
                'channel' => 'app',
                'context' => [
                    'order' => 42,
                    'password' => 'do-not-store',
                    'nested' => ['access_token' => 'do-not-store'],
                ],
                'extra' => ['client_secret' => 'do-not-store'],
            ];
            $entries[] = [
                'level' => 'info',
                'message' => 'HTTP request',
                'captured_at' => '2026-04-19T09:00:00+00:00',
                'channel' => 'http',
            ];
            $entries[] = 'invalid';

            return $entries;
        });
        $collector = new LogCollector(new ProfilerErrorRecorder($this->gate()), new ArraySanitizer());
        $context = $this->context();

        $payload = $collector->collect($context);

        self::assertSame(2, $payload['count']);
        self::assertSame(['info' => 1, 'warning' => 1], $payload['counts']);
        self::assertSame(['http', 'app'], $payload['channels']);
        self::assertSame('HTTP request', $payload['entries'][0]['message']);
        self::assertSame([
            'order' => 42,
            'password' => '[redacted]',
            'nested' => ['access_token' => '[redacted]'],
        ], $payload['entries'][1]['context']);
        self::assertSame(['client_secret' => '[redacted]'], $payload['entries'][1]['extra']);

        $panel = $collector->renderPanel(
            $payload,
            new ProfileRecord('token', '2026-04-19T10:00:00+00:00', ['profiler_url' => '#'], []),
        );
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $panel->html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $panel->html);
    }

    private function gate(): ProfileGate
    {
        $locations = $this->createStub(Locations::class);
        $config = $this->createStub(SiteConfig::class);
        $config->method('env')->willReturn(EnvConfig::LOCAL);
        $config->method('envIs')->willReturnCallback(
            static fn (string $environment): bool => $environment === EnvConfig::LOCAL,
        );
        $config->method('hosting')->willReturn(SiteConfig::HOSTING_OTHER);
        $config->method('hostingIs')->willReturn(false);
        $config->method('locations')->willReturn($locations);
        $config->method('get')->willReturn(null);
        $config->method('jsonSerialize')->willReturn([]);

        return new ProfileGate(
            $config,
            WpContext::new()->force(WpContext::FRONTOFFICE),
            new ProfilerRequestMatcher(),
        );
    }

    private function context(): ProfileContext
    {
        return new ProfileContext(
            'token',
            1_776_589_200.0,
            1_776_589_201.0,
            1024,
            2048,
            4096,
            200,
            null,
            [],
            [],
            'https://example.test/_profiler/token',
        );
    }
}
