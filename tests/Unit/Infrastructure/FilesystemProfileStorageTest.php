<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Infrastructure;

use SymPress\Profiler\Infrastructure\FilesystemProfileStorage;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use PHPUnit\Framework\TestCase;

final class FilesystemProfileStorageTest extends TestCase
{
    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/profiler-storage-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storageDirectory)) {
            return;
        }

        foreach (glob($this->storageDirectory . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->storageDirectory);
    }

    public function test_it_saves_and_loads_profiles(): void
    {
        $storage = new FilesystemProfileStorage($this->storageDirectory);
        $profile = new ProfileRecord(
            'abc123',
            '2026-04-19T10:00:00+00:00',
            [
                'method' => 'GET',
                'path' => '/profiling',
                'profiler_url' => 'https://example.test/_profiler/abc123?panel=request',
            ],
            [
                'request' => ['duration_ms' => 12.4],
            ],
        );

        $storage->save($profile);
        $loaded = $storage->load('abc123');

        self::assertInstanceOf(ProfileRecord::class, $loaded);
        self::assertSame('abc123', $loaded->token);
        self::assertSame('/profiling', $loaded->meta['path']);
        self::assertSame(12.4, $loaded->collectors['request']['duration_ms']);
    }

    public function test_it_returns_latest_profiles_in_reverse_chronological_order(): void
    {
        $storage = new FilesystemProfileStorage($this->storageDirectory);

        $storage->save(new ProfileRecord('first', '2026-04-19T10:00:00+00:00', ['profiler_url' => '#'], []));
        usleep(20000);
        $storage->save(new ProfileRecord('second', '2026-04-19T10:01:00+00:00', ['profiler_url' => '#'], []));

        $latest = $storage->latest(2);

        self::assertCount(2, $latest);
        self::assertSame('second', $latest[0]->token);
        self::assertSame('first', $latest[1]->token);
    }

    public function test_it_filters_profiles_using_search_criteria(): void
    {
        $storage = new FilesystemProfileStorage($this->storageDirectory);

        $storage->save(new ProfileRecord(
            'front-get',
            '2026-04-19T10:00:00+00:00',
            [
                'method' => 'GET',
                'url' => 'https://example.test/',
                'context' => 'frontoffice',
                'status_code' => 200,
                'ip' => '127.0.0.1',
                'profiler_url' => '#',
            ],
            [],
        ));
        $storage->save(new ProfileRecord(
            'rest-post',
            '2026-04-19T10:01:00+00:00',
            [
                'method' => 'POST',
                'url' => 'https://example.test/wp-json/demo',
                'context' => 'rest',
                'status_code' => 500,
                'ip' => '10.0.0.5',
                'profiler_url' => '#',
            ],
            [],
        ));

        $filtered = $storage->search(new ProfileSearchCriteria(
            method: 'POST',
            context: 'rest',
            statusCode: 500,
            limit: 10,
        ));

        self::assertCount(1, $filtered);
        self::assertSame('rest-post', $filtered[0]->token);
    }

    public function test_it_supports_excluded_urls_and_date_ranges_like_the_profiler_find_method(): void
    {
        $storage = new FilesystemProfileStorage($this->storageDirectory);

        $storage->save(new ProfileRecord(
            'front',
            '2026-04-19T10:00:00+00:00',
            [
                'method' => 'GET',
                'url' => 'https://example.test/',
                'profiler_url' => '#',
            ],
            [],
        ));
        $storage->save(new ProfileRecord(
            'api',
            '2026-04-20T10:00:00+00:00',
            [
                'method' => 'GET',
                'url' => 'https://example.test/wp-json/demo',
                'profiler_url' => '#',
            ],
            [],
        ));

        $filtered = $storage->search(new ProfileSearchCriteria(
            url: '!/wp-json',
            limit: 10,
            start: '2026-04-19 00:00:00',
            end: '2026-04-19 23:59:59',
        ));

        self::assertCount(1, $filtered);
        self::assertSame('front', $filtered[0]->token);
    }
}
