<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Infrastructure;

use SymPress\Profiler\Infrastructure\FilesystemProfileStorage;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

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

        foreach (glob($this->storageDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->storageDirectory);
    }

    public function testSearchUsesSmallIndexesBeforeLoadingSelectedPayloads(): void
    {
        $storage = new FilesystemProfileStorage($this->storageDirectory);
        $profile = new ProfileRecord('indexed', '2026-10-01T10:00:00+00:00', ['method' => 'GET'], ['request' => ['body' => str_repeat('x', 100000)]]);
        $storage->save($profile);
        $index = (string) file_get_contents($this->storageDirectory . '/indexed.json.index');
        self::assertLessThan(500, strlen($index));
        self::assertStringNotContainsString('body', $index);
        unlink($this->storageDirectory . '/indexed.json.index');
        self::assertCount(1, $storage->latest(1));
        self::assertFileExists($this->storageDirectory . '/indexed.json.index');
        // The search predicate comes from the index even if a payload is separately changed.
        $changed = new ProfileRecord('indexed', $profile->createdAt, ['method' => 'POST'], $profile->collectors);
        file_put_contents($this->storageDirectory . '/indexed.json', json_encode($changed, JSON_THROW_ON_ERROR));
        self::assertSame([], $storage->search(new ProfileSearchCriteria(method: 'POST')));
        self::assertCount(1, $storage->search(new ProfileSearchCriteria(method: 'GET')));
    }

    public function testNewStorageAndReplacementsStayPrivateWithPermissiveUmask(): void
    {
        $previousUmask = umask(0000);
        try {
            $storage = new FilesystemProfileStorage($this->storageDirectory);
            $profile = new ProfileRecord('private', '2026-10-03T10:00:00+00:00', [], []);
            $storage->save($profile);
            $storage->save($profile);
            self::assertSame(0000, umask());
            self::assertSame(0700, fileperms($this->storageDirectory) & 07777);
            foreach (glob($this->storageDirectory . '/*') ?: [] as $file) {
                clearstatcache(true, $file);
                self::assertSame(0600, fileperms($file) & 07777);
            }
        } finally {
            umask($previousUmask);
        }
    }

    public function testLegacyProfileAndIndexPermissionsAreTightenedBeforeRead(): void
    {
        mkdir($this->storageDirectory, 0755);
        chmod($this->storageDirectory, 0755);
        $profile = new ProfileRecord('legacy', '2026-10-03T10:00:00+00:00', [], []);
        foreach (['legacy.json', 'legacy.json.index'] as $name) {
            $file = $this->storageDirectory . '/' . $name;
            file_put_contents($file, json_encode($profile, JSON_THROW_ON_ERROR));
            chmod($file, 0644);
        }
        self::assertInstanceOf(ProfileRecord::class, (new FilesystemProfileStorage($this->storageDirectory))->load('legacy'));
        clearstatcache();
        self::assertSame(0700, fileperms($this->storageDirectory) & 07777);
        self::assertSame(0600, fileperms($this->storageDirectory . '/legacy.json') & 07777);
        self::assertSame(0600, fileperms($this->storageDirectory . '/legacy.json.index') & 07777);
    }

    public function testPrivateReadOnlyLegacyStoreKeepsSearchFallback(): void
    {
        mkdir($this->storageDirectory, 0700);
        $file = $this->storageDirectory . '/readonly.json';
        file_put_contents($file, json_encode(new ProfileRecord('readonly', '2026-10-03T10:00:00+00:00', [], []), JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('chmod');
        $filesystem->method('dumpFile')->willThrowException(new IOException('Read-only store.'));
        self::assertCount(1, (new FilesystemProfileStorage($this->storageDirectory, $filesystem))->latest());
        self::assertFileDoesNotExist($file . '.index');
    }

    public function testLegacySymlinkIsRejectedWithoutChangingOutsideFile(): void
    {
        mkdir($this->storageDirectory, 0700);
        $outside = tempnam(sys_get_temp_dir(), 'profiler-outside-');
        self::assertIsString($outside);
        chmod($outside, 0644);
        symlink($outside, $this->storageDirectory . '/link.json');
        try {
            (new FilesystemProfileStorage($this->storageDirectory))->latest();
            self::fail('A symbolic link must not be followed.');
        } catch (IOException) {
            clearstatcache(true, $outside);
            self::assertSame(0644, fileperms($outside) & 07777);
        } finally {
            unlink($outside);
        }
    }

    public function testStorageCannotContinueWhenFilesystemIgnoresChmod(): void
    {
        mkdir($this->storageDirectory, 0755);
        chmod($this->storageDirectory, 0755);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('chmod');
        $this->expectException(IOException::class);
        (new FilesystemProfileStorage($this->storageDirectory, $filesystem))->latest();
    }

    public function testStorageTokenCannotEscapePrivateDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FilesystemProfileStorage($this->storageDirectory))->load('../outside');
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
