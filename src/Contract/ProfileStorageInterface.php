<?php

declare(strict_types=1);

namespace SymPress\Profiler\Contract;

use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;

interface ProfileStorageInterface
{
    public function save(ProfileRecord $profile): void;

    public function load(string $token): ?ProfileRecord;

    /**
     * Newest first. Records are summaries (token, date, meta); use load() for collector payloads.
     *
     * @return list<ProfileRecord>
     */
    public function latest(int $limit = 20): array;

    /**
     * Newest first. Records are summaries (token, date, meta); use load() for collector payloads.
     *
     * @return list<ProfileRecord>
     */
    public function search(ProfileSearchCriteria $criteria): array;
}
