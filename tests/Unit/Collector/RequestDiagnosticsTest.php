<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Collector\RequestCollector;
use SymPress\Profiler\Support\ArraySanitizer;
use SymPress\Profiler\Value\ProfileContext;

final class RequestDiagnosticsTest extends TestCase
{
    public function testRequestDataIsKeptVerbatimWithoutTruncation(): void
    {
        $_COOKIE = ['wordpress_logged_in_abcdef' => 'raw-auth', 'PHPSESSID' => 'raw-session', 'preference' => 'dark'];
        $_SERVER = ['HTTP_COOKIE' => 'raw-header', 'HTTP_AUTHORIZATION' => 'Bearer raw-token', 'APP_SECRET' => 'server-secret', 'HTTP_HOST' => 'example.test', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?access_token=raw-query&q=%C3%A4'];
        $_POST = [
            'service' => ['api_key' => 'raw-api', 'dsn' => 'mysql://user:pass@db', 'credentials' => ['access' => 'raw-access']],
            'long' => str_repeat('x', 600),
            'deep' => ['a' => ['b' => ['c' => ['d' => 'raw-deep']]]],
            'many' => range(1, 60),
        ];
        try {
            $collector = new RequestCollector(new ArraySanitizer(), WpContext::new()->force(WpContext::FRONTOFFICE));
            $payload = $collector->collect(new ProfileContext('abc', microtime(true), microtime(true), 0, 0, 0, 200, null, [], ['set-cookie' => 'raw-response'], '/_profiler/abc'));
            self::assertSame($_COOKIE, $payload['cookies']);
            self::assertSame($_POST, $payload['post']);
            self::assertSame('raw-header', $payload['request_headers']['cookie']);
            self::assertSame('Bearer raw-token', $payload['request_headers']['authorization']);
            self::assertSame(['set-cookie' => 'raw-response'], $payload['response_headers']);
            self::assertSame('http://example.test/?access_token=raw-query&q=%C3%A4', $payload['url']);
            self::assertSame($_SERVER, $payload['server']);
        } finally {
            $_COOKIE = $_SERVER = $_POST = [];
        }
    }

    public function testExtensionPayloadKeysThatLookSensitiveAreKept(): void
    {
        $payload = ['wordpress_version' => '6.9.1', 'session' => ['token' => 'raw-token'], 'url' => 'https://user:pass@example.test/?key=raw'];

        self::assertSame($payload, (new ArraySanitizer())->normalizePayload($payload));
    }
}
