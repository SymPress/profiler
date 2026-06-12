<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Application;

use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Application\ProfileViewBuilder;
use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;
use SymPress\Profiler\View\ToolbarRenderer;
use SymPress\Profiler\View\WebProfilerAssets;
use PHPUnit\Framework\TestCase;

final class ProfileViewBuilderTest extends TestCase
{
    public function test_it_keeps_sidebar_panels_and_toolbar_blocks_separate(): void
    {
        $profile = new ProfileRecord('token', '2026-04-19T10:00:00+00:00', ['profiler_url' => '#'], []);

        $builder = new ProfileViewBuilder(
            [
                $this->collector('request', 'Request / Response', 'request', true),
                $this->collector('logs', 'Logs', 'logger', false),
            ],
            new ToolbarRenderer(
                new WebProfilerAssets(),
                new ProfilerUrlGenerator(),
            ),
        );

        self::assertCount(1, $builder->toolbarBlocks($profile));
        self::assertCount(2, $builder->panels($profile));
        self::assertStringContainsString('sfToolbarMainContent-token', $builder->renderToolbarBootstrap($profile));
    }

    private function collector(string $key, string $label, string $icon, bool $hasToolbarBlock): DataCollectorInterface
    {
        return new class ($key, $label, $icon, $hasToolbarBlock) implements DataCollectorInterface {
            public function __construct(
                private readonly string $keyValue,
                private readonly string $labelValue,
                private readonly string $iconValue,
                private readonly bool $hasToolbarBlockValue,
            ) {
            }

            public function getKey(): string
            {
                return $this->keyValue;
            }

            public function getLabel(): string
            {
                return $this->labelValue;
            }

            public function getIcon(): string
            {
                return $this->iconValue;
            }

            public function collect(ProfileContext $context): array
            {
                return [];
            }

            public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock
            {
                if (!$this->hasToolbarBlockValue) {
                    return null;
                }

                return new ToolbarBlock(
                    $this->keyValue,
                    $this->labelValue,
                    'value',
                    'detail',
                    '#',
                );
            }

            public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
            {
                return new CollectorPanel(
                    $this->keyValue,
                    $this->labelValue,
                    $this->iconValue,
                    '<p>Panel</p>',
                );
            }
        };
    }
}
