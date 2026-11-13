<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use SymPress\Profiler\Collector\BlockCollector;
use SymPress\Profiler\Collector\TemplateCollector;
use SymPress\Profiler\Recorder\ProfilerBlockRecorder;
use SymPress\Profiler\Recorder\ProfilerLifecycleRecorder;
use SymPress\Profiler\Recorder\ProfilerTemplateRecorder;
use SymPress\Profiler\Value\ProfileContext;

final class BlockRecorderTest extends TestCase
{
    public function testChildrenRenderedDirectlyByTheirParentDoNotCloseTheParent(): void
    {
        $recorder = new ProfilerBlockRecorder($this->gate());
        $recorder->enable();
        $link = ['blockName' => 'core/navigation-link', 'attrs' => ['label' => 'Docs'], 'innerHTML' => '', 'innerBlocks' => []];
        $navigation = ['blockName' => 'core/navigation', 'attrs' => [], 'innerHTML' => '', 'innerBlocks' => [$link, $link]];
        $group = ['blockName' => 'core/group', 'attrs' => [], 'innerHTML' => '<div></div>', 'innerBlocks' => [$navigation]];
        $dynamic = new class {
            public object $block_type;

            public function __construct()
            {
                $this->block_type = new class {
                    public function is_dynamic(): bool
                    {
                        return true;
                    }
                };
            }
        };

        $recorder->start($group);
        $recorder->start($navigation);
        // core/navigation calls $inner_block->render(): render_block fires without render_block_data.
        $recorder->finish('<li>Docs</li>', $link);
        $recorder->finish('<li>Docs</li>', $link);
        $recorder->finish('<nav><li>Docs</li><li>Docs</li></nav>', $navigation, $dynamic);
        $recorder->finish('<div><nav><li>Docs</li><li>Docs</li></nav></div>', $group);
        $recorder->finish('<p>text</p>', ['blockName' => null, 'attrs' => [], 'innerHTML' => '<p>text</p>', 'innerBlocks' => []]);

        $blocks = $recorder->blocks();
        self::assertSame(['core/group', 'core/navigation', 'core/navigation-link', 'core/navigation-link', 'core/freeform'], array_column($blocks, 'name'));
        self::assertSame([0, 1, 2, 2, 0], array_column($blocks, 'depth'));
        self::assertSame([true, true, false, false, false], array_column($blocks, 'timed'));
        self::assertSame([false, true, false, false, false], array_column($blocks, 'dynamic'));
        self::assertSame(48, $blocks[0]['output_bytes']);

        $payload = (new BlockCollector($recorder))->collect(new ProfileContext('t', microtime(true), microtime(true), 0, 0, 0, 200, null, [], [], '/_profiler/t'));
        self::assertSame(1, $payload['dynamic_count']);
        self::assertSame($blocks[0]['duration_ms'], $payload['total_duration_ms']);
    }

    public function testBlockThemesReportTheirTemplateAndTemplateParts(): void
    {
        $gate = $this->gate();
        $recorder = new ProfilerBlockRecorder($gate);
        $recorder->enable();
        $part = ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'header', 'theme' => 'sympress'], 'innerHTML' => '', 'innerBlocks' => []];
        $recorder->start($part);
        $recorder->finish('<header></header>', $part);
        $GLOBALS['_wp_current_template_id'] = 'sympress//page';

        try {
            $payload = (new TemplateCollector(new ProfilerLifecycleRecorder($gate), new ProfilerTemplateRecorder($gate), $recorder))
                ->collect(new ProfileContext('t', microtime(true) - 1, microtime(true), 0, 0, 0, 200, '/wp-includes/template-canvas.php', [], [], '/_profiler/t'));
        } finally {
            unset($GLOBALS['_wp_current_template_id']);
        }

        self::assertSame('sympress//page', $payload['template_name']);
        self::assertSame('sympress//page', $payload['block_template']);
        self::assertSame(['slug' => 'header', 'name' => 'sympress', 'templates' => ['parts/header.html']], array_intersect_key($payload['template_parts'][0], ['slug' => 1, 'name' => 1, 'templates' => 1]));
        // No template_include event was recorded: nothing was rendered, so no render time is invented.
        self::assertSame(0.0, $payload['render_ms']);
    }

    private function gate(): ProfileGate
    {
        $config = $this->createStub(SiteConfig::class);
        $config->method('env')->willReturn(EnvConfig::LOCAL);
        $config->method('envIs')->willReturnCallback(static fn (string $environment): bool => $environment === EnvConfig::LOCAL);
        $config->method('hosting')->willReturn(SiteConfig::HOSTING_OTHER);
        $config->method('hostingIs')->willReturn(false);
        $config->method('locations')->willReturn($this->createStub(Locations::class));
        $config->method('get')->willReturn(null);
        $config->method('jsonSerialize')->willReturn([]);

        return new ProfileGate($config, WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
    }
}
