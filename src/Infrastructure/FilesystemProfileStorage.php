<?php

declare(strict_types=1);

namespace SymPress\Profiler\Infrastructure;

use SymPress\Profiler\Contract\ProfileStorageInterface;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

final class FilesystemProfileStorage implements ProfileStorageInterface
{
    private const int TTL_SECONDS = 172800;

    private bool $storageSecured = false;

    public function __construct(
        private readonly string $storageDirectory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function save(ProfileRecord $profile): void
    {
        $this->secureStorage(true);
        $this->dumpPrivateFile(
            $this->profileFile($profile->token),
            json_encode(
                $profile->jsonSerialize(),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );

        $this->writeIndex($profile);

        if (random_int(1, 20) !== 1) {
            return;
        }

        $this->cleanup();
    }

    private function writeIndex(ProfileRecord $profile): void
    {
        $request = $profile->collector('request');
        $this->dumpPrivateFile(
            $this->profileFile($profile->token) . '.index',
            json_encode([
                'token'      => $profile->token,
                'created_at' => $profile->createdAt,
                'meta'       => $profile->meta,
                'collectors' => ['request' => array_intersect_key($request, array_flip(['referer', 'user_agent']))],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function load(string $token): ?ProfileRecord
    {
        $this->secureStorage(false);
        $profileFile = $this->profileFile($token);

        if (!is_file($profileFile)) {
            return null;
        }

        $contents = file_get_contents($profileFile);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return null;
        }

        return ProfileRecord::fromArray($decoded);
    }

    public function latest(int $limit = 20): array
    {
        return $this->search(new ProfileSearchCriteria(limit: $limit));
    }

    public function search(ProfileSearchCriteria $criteria): array
    {
        $profiles = [];

        foreach ($this->allProfiles() as $summary) {
            if (!$this->matches($summary, $criteria)) {
                continue;
            }

            $profile = $this->load($summary->token);

            if ($profile instanceof ProfileRecord) {
                $profiles[] = $profile;
            }

            if (count($profiles) >= $criteria->limit) {
                break;
            }
        }

        return $profiles;
    }

    private function cleanup(): void
    {
        $files = glob($this->storageDirectory . '/*.json') ?: [];
        $cutoff = time() - self::TTL_SECONDS;

        foreach ($files as $file) {
            if (!is_file($file) || filemtime($file) >= $cutoff) {
                continue;
            }

            $this->filesystem->remove([$file, $file . '.index']);
        }
    }

    private function profileFile(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid profiler storage token.');
        }

        return sprintf('%s/%s.json', rtrim($this->storageDirectory, '/'), $token);
    }

    /** @return list<ProfileRecord> */
    private function allProfiles(): array
    {
        $this->secureStorage(false);
        if (!is_dir($this->storageDirectory)) {
            return [];
        }

        $files = glob($this->storageDirectory . '/*.json') ?: [];
        $profiles = [];

        foreach ($files as $file) {
            $token = pathinfo($file, PATHINFO_FILENAME);
            $indexFile = $file . '.index';
            $index = is_file($indexFile) ? file_get_contents($indexFile) : false;
            $decoded = is_string($index) ? json_decode($index, true) : null;
            $profile = is_array($decoded) ? ProfileRecord::fromArray($decoded) : $this->load($token);

            if (!($profile instanceof ProfileRecord)) {
                continue;
            }

            if (!is_array($decoded)) {
                try {
                    // Backfill legacy files once so subsequent searches use small indexes.
                    $this->writeIndex($profile);
                } catch (IOExceptionInterface) {
                    // Read-only legacy stores retain compatibility through the full-file fallback.
                }
            }

            $profiles[] = $profile;
        }

        usort(
            $profiles,
            static fn (ProfileRecord $left, ProfileRecord $right): int => strcmp($right->createdAt, $left->createdAt),
        );

        return $profiles;
    }

    private function secureStorage(bool $create): void
    {
        if ($this->storageSecured) {
            return;
        }

        if (is_link($this->storageDirectory)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception path metadata, never rendered HTML.
            throw new IOException('Profiler storage must be a private directory, not a symbolic link.', 0, null, $this->storageDirectory);
        }

        if (!is_dir($this->storageDirectory)) {
            if (!$create) {
                return;
            }

            $this->filesystem->mkdir($this->storageDirectory, 0700);
        }

        $this->restrictPermissions($this->storageDirectory, 0700);

        // Tighten only this store's files during an upgrade, before reading or writing data.
        foreach (glob($this->storageDirectory . '/*.json{,.index}', GLOB_BRACE) ?: [] as $file) {
            $this->restrictPermissions($file, 0600);
        }

        $this->storageSecured = true;
    }

    private function dumpPrivateFile(string $file, string $contents): void
    {
        if (file_exists($file) || is_link($file)) {
            $this->restrictPermissions($file, 0600);
        }

        // Symfony writes atomically. Keep its temporary and final files private even with umask 0000.
        $previousUmask = umask(0077);
        try {
            $this->filesystem->dumpFile($file, $contents);
        } finally {
            umask($previousUmask);
        }

        $this->restrictPermissions($file, 0600);
    }

    private function restrictPermissions(string $path, int $mode): void
    {
        if (is_link($path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception path metadata, never rendered HTML.
            throw new IOException('Profiler storage must not contain symbolic links.', 0, null, $path);
        }

        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if ($permissions !== false && ($permissions & 07777) === $mode) {
            return;
        }
        // Failure closes the storage boundary; already-private read-only stores need no chmod.
        $this->filesystem->chmod($path, $mode);
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 07777) !== $mode) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception path metadata, never rendered HTML.
            throw new IOException('Profiler storage permissions could not be restricted.', 0, null, $path);
        }
    }

    private function matches(ProfileRecord $profile, ProfileSearchCriteria $criteria): bool
    {
        if ($criteria->text !== '' && !$this->contains($this->searchHaystack($profile), $criteria->text)) {
            return false;
        }

        if ($criteria->token !== '' && !$this->contains($profile->token, $criteria->token)) {
            return false;
        }

        if ($criteria->method !== '' && strtoupper($this->metaString($profile, 'method')) !== $criteria->method) {
            return false;
        }

        if ($criteria->url !== '') {
            $urlNeedle = ltrim($criteria->url, '!');
            $urlMatches = $this->contains($this->metaString($profile, 'url'), $urlNeedle);

            if (str_starts_with($criteria->url, '!')) {
                if ($urlNeedle !== '' && $urlMatches) {
                    return false;
                }
            } elseif (!$urlMatches) {
                return false;
            }
        }

        if ($criteria->ip !== '' && !$this->contains($this->metaString($profile, 'ip'), $criteria->ip)) {
            return false;
        }

        if ($criteria->statusCode !== null && $this->metaInt($profile, 'status_code') !== $criteria->statusCode) {
            return false;
        }

        if ($criteria->context !== '' && strtolower($this->metaString($profile, 'context')) !== $criteria->context) {
            return false;
        }

        if (!$this->matchesCreatedAtRange($profile, $criteria)) {
            return false;
        }

        return true;
    }

    private function matchesCreatedAtRange(ProfileRecord $profile, ProfileSearchCriteria $criteria): bool
    {
        if ($criteria->start === '' && $criteria->end === '') {
            return true;
        }

        $createdAt = strtotime($profile->createdAt);

        if ($createdAt === false) {
            return false;
        }

        if ($criteria->start !== '') {
            $start = strtotime($criteria->start);

            if ($start !== false && $createdAt < $start) {
                return false;
            }
        }

        if ($criteria->end !== '') {
            $end = strtotime($criteria->end);

            if ($end !== false && $createdAt > $end) {
                return false;
            }
        }

        return true;
    }

    private function searchHaystack(ProfileRecord $profile): string
    {
        $request = $profile->collector('request');
        $parts = array_filter(
            [
                $profile->token,
                $this->metaString($profile, 'method'),
                $this->metaString($profile, 'path'),
                $this->metaString($profile, 'url'),
                $this->metaString($profile, 'context'),
                $this->metaString($profile, 'ip'),
                is_scalar($request['referer'] ?? null) ? (string) $request['referer'] : '',
                is_scalar($request['user_agent'] ?? null) ? (string) $request['user_agent'] : '',
            ],
            static fn (string $value): bool => $value !== '',
        );

        return implode(' ', $parts);
    }

    private function contains(string $haystack, string $needle): bool
    {
        return str_contains(strtolower($haystack), strtolower($needle));
    }

    private function metaString(ProfileRecord $profile, string $key): string
    {
        $value = $profile->meta[$key] ?? '';

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    private function metaInt(ProfileRecord $profile, string $key): ?int
    {
        $value = $profile->meta[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
