<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;
use SymPress\Profiler\View\ToolbarRenderer;

final class ProfileViewBuilder
{
    /** @param iterable<DataCollectorInterface> $collectors */
    public function __construct(
        private readonly iterable $collectors,
        private readonly ToolbarRenderer $toolbarRenderer,
    ) {
    }

    public function renderToolbarBootstrap(ProfileRecord $profile): string
    {
        return $this->toolbarRenderer->renderBootstrap($profile, $this->toolbarBlocks($profile));
    }

    public function renderToolbarContent(ProfileRecord $profile): string
    {
        return $this->toolbarRenderer->renderContent($profile, $this->toolbarBlocks($profile));
    }

    /** @return list<ToolbarBlock> */
    public function toolbarBlocks(ProfileRecord $profile): array
    {
        $blocks = [];

        foreach ($this->collectors as $collector) {
            $payload = $profile->collector($collector->getKey());
            $block = $collector->createToolbarBlock($payload, $profile);

            if (!$block instanceof ToolbarBlock) {
                continue;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /** @return list<CollectorPanel> */
    public function panels(ProfileRecord $profile): array
    {
        $panels = [];

        foreach ($this->collectors as $collector) {
            $payload = $profile->collector($collector->getKey());
            $panels[] = $collector->renderPanel($payload, $profile);
        }

        return $panels;
    }
}
