<?php

declare(strict_types=1);

namespace SymPress\Profiler\Infrastructure;

use SymPress\Profiler\Contract\ProfileStorageInterface;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use Symfony\Component\Filesystem\Filesystem;

final class FilesystemProfileStorage implements ProfileStorageInterface
{
    private const int TTL_SECONDS = 172800;

    public function __construct(
        private readonly string $storageDirectory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function save(ProfileRecord $profile): void
    {
        $this->filesystem->mkdir($this->storageDirectory);
        $this->filesystem->dumpFile(
            $this->profileFile($profile->token),
            json_encode(
                $profile->jsonSerialize(),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );

        if (random_int(1, 20) !== 1) {
            return;
        }

        $this->cleanup();
    }

    public function load(string $token): ?ProfileRecord
    {
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
        return array_slice($this->allProfiles(), 0, $limit);
    }

    public function search(ProfileSearchCriteria $criteria): array
    {
        $profiles = array_filter(
            $this->allProfiles(),
            fn (ProfileRecord $profile): bool => $this->matches($profile, $criteria),
        );

        return array_slice(array_values($profiles), 0, $criteria->limit);
    }

    private function cleanup(): void
    {
        $files = glob($this->storageDirectory . '/*.json') ?: [];
        $cutoff = time() - self::TTL_SECONDS;

        foreach ($files as $file) {
            if (!is_file($file) || filemtime($file) >= $cutoff) {
                continue;
            }

            $this->filesystem->remove($file);
        }
    }

    private function profileFile(string $token): string
    {
        return sprintf('%s/%s.json', rtrim($this->storageDirectory, '/'), $token);
    }

    /** @return list<ProfileRecord> */
    private function allProfiles(): array
    {
        if (!is_dir($this->storageDirectory)) {
            return [];
        }

        $files = glob($this->storageDirectory . '/*.json') ?: [];
        $profiles = [];

        foreach ($files as $file) {
            $token = pathinfo($file, PATHINFO_FILENAME);
            $profile = $this->load($token);

            if (!($profile instanceof ProfileRecord)) {
                continue;
            }

            $profiles[] = $profile;
        }

        usort(
            $profiles,
            static fn (ProfileRecord $left, ProfileRecord $right): int => strcmp($right->createdAt, $left->createdAt),
        );

        return $profiles;
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
