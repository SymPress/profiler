<?php

declare(strict_types=1);

namespace SymPress\Profiler\Contract;

use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

interface DataCollectorInterface
{
    public function key(): string;

    public function label(): string;

    public function icon(): string;

    /**
     * @return array<string, mixed>
     */
    public function collect(ProfileContext $context): array;

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock;

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel;
}
