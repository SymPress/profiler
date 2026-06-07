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
     * @return list<ProfileRecord>
     */
    public function latest(int $limit = 20): array;

    /**
     * @return list<ProfileRecord>
     */
    public function search(ProfileSearchCriteria $criteria): array;
}
