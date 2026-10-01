<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Collector\RequestCollector;
use SymPress\Profiler\Support\ArraySanitizer;
use SymPress\Profiler\Value\ProfileContext;

final class RequestSecurityTest extends TestCase
{
    public function testCookiesHeadersAndNestedCredentialsAreRedactedBeforePersistence(): void
    {
        $_COOKIE = ['wordpress_logged_in_abcdef' => 'raw-auth', 'wordpress_sec_abcdef' => 'raw-sec', 'wordpress_abcdef' => 'raw-cookie', 'preference' => 'dark'];
        $_SERVER = ['HTTP_COOKIE' => 'raw-header', 'HTTP_AUTHORIZATION' => 'Bearer raw-token', 'APP_SECRET' => 'server-secret', 'HTTP_HOST' => 'example.test', 'REQUEST_METHOD' => 'GET'];
        $_POST = ['service' => ['api_key' => 'raw-api', 'dsn' => 'mysql://user:pass@db', 'credentials' => ['access' => 'raw-access']]];
        try {
            $collector = new RequestCollector(new ArraySanitizer(), WpContext::new()->force(WpContext::FRONTOFFICE));
            $payload = $collector->collect(new ProfileContext('abc', microtime(true), microtime(true), 0, 0, 0, 200, null, [], ['set-cookie' => 'raw-response'], '/_profiler/abc'));
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            foreach (['raw-auth', 'raw-sec', 'raw-cookie', 'raw-header', 'raw-token', 'server-secret', 'raw-api', 'user:pass', 'raw-access', 'raw-response'] as $secret) {
                self::assertStringNotContainsString($secret, $json);
            }
            self::assertSame('dark', $payload['cookies']['preference']);
            self::assertArrayNotHasKey('APP_SECRET', $payload['server']);
            self::assertSame('[redacted]', (new ArraySanitizer())->sanitize('x', key: 'private_key'));
            self::assertSame('https://example.test/?access_token=[redacted]&%74oken=[redacted]&view=public', (new ArraySanitizer())->sanitize('https://example.test/?access_token=secret&%74oken=hidden&view=public'));
            self::assertSame('mysql://[redacted]@db', (new ArraySanitizer())->sanitize('mysql://user:pass@db'));
        } finally {
            $_COOKIE = $_SERVER = $_POST = [];
        }
    }

    public function testUserinfoIsMaskedRegardlessOfPasswordSyntax(): void
    {
        $sanitizer = new ArraySanitizer();
        foreach (['review-canary-value', 'user:', 'user:review-canary-value', 'review%40canary%3Avalue'] as $userinfo) {
            self::assertSame('https://[redacted]@example.test/path', $sanitizer->sanitize('https://' . $userinfo . '@example.test/path'));
        }
        self::assertSame('https://example.test/path?view=public', $sanitizer->sanitize('https://example.test/path?view=public'));
    }
}
