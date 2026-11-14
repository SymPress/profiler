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
use SymPress\Profiler\Support\TemplateName;
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

    public function testSyncedPatternsRepeatedRendersAndDirectlyRenderedParentsKeepTheTree(): void
    {
        $recorder = new ProfilerBlockRecorder($this->gate());
        $recorder->enable();
        $block = static fn (string $name, array $attrs = [], array $inner = [], string $html = ''): array => ['blockName' => $name, 'attrs' => $attrs, 'innerHTML' => $html, 'innerBlocks' => $inner];
        $paragraph = $block('core/paragraph', [], [], '<p>Hi</p>');
        $pattern = $block('core/block', ['ref' => 42]);
        $link = static fn (string $label): array => $block('core/navigation-link', ['label' => $label]);
        $submenu = $block('core/navigation-submenu', ['label' => 'Docs'], [$link('A'), $link('B')]);
        $navigation = $block('core/navigation', [], [$link('Home'), $submenu]);
        $commentContent = $block('core/comment-content');
        $template = $block('core/comment-template', [], [$commentContent]);
        $comments = $block('core/comments', [], [$template], '<div></div>');

        // A synced pattern: core replaces the inner blocks between render_block_data and render_block.
        $recorder->start($pattern);
        $recorder->start($paragraph);
        $recorder->finish('<p>Hi</p>', $paragraph);
        $recorder->finish('<p>Hi</p>', [...$pattern, 'innerBlocks' => [$paragraph]]);

        // core/navigation renders links and submenus directly; a submenu ends after its own links.
        $recorder->start($navigation);
        $recorder->finish('<li>Home</li>', $link('Home'));
        $recorder->finish('<li>A</li>', $link('A'));
        $recorder->finish('<li>B</li>', $link('B'));
        $recorder->finish('<li>Docs<ul><li>A</li><li>B</li></ul></li>', $submenu);
        $recorder->finish('<nav>…</nav>', $navigation);

        // core/comment-template renders a copy of itself per comment with WP_Block::render(): each copy only
        // reports its end, the first one while the template is still open.
        $recorder->start($comments);
        $recorder->start($template);
        $recorder->start($commentContent);
        $recorder->finish('first', $commentContent);
        $recorder->finish('<li>first</li>', $template);
        $recorder->start($commentContent);
        $recorder->finish('second', $commentContent);
        $recorder->finish('<li>second</li>', $template);
        $recorder->finish('<ol><li>first</li><li>second</li></ol>', $template);
        $recorder->finish('<div>…</div>', $comments);
        $recorder->start($paragraph);
        $recorder->finish('<p>Hi</p>', $paragraph);

        $rows = array_map(
            static fn (array $row): string => str_repeat('  ', $row['depth']) . $row['name'] . ($row['timed'] ? '' : ' (untimed)') . (isset($row['attrs']['label']) ? ' ' . $row['attrs']['label'] : ''),
            $recorder->blocks(),
        );

        self::assertSame([
            'core/block',
            '  core/paragraph',
            'core/navigation',
            '  core/navigation-link (untimed) Home',
            '  core/navigation-submenu (untimed) Docs',
            '    core/navigation-link (untimed) A',
            '    core/navigation-link (untimed) B',
            'core/comments',
            '  core/comment-template',
            '    core/comment-content',
            '    core/comment-content',
            'core/paragraph',
        ], $rows);
        self::assertSame(38, $recorder->blocks()[8]['output_bytes']);
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

    public function testHierarchyIsWhatWordPressTriedAndTemplatesAreNamedByTheirBlockTemplate(): void
    {
        $gate = $this->gate();
        $templates = new ProfilerTemplateRecorder($gate);
        $templates->enable();
        $GLOBALS['_wp_current_template_id'] = 'sympress//page-package';

        try {
            // WordPress asks for the page hierarchy (custom page template first), finds one and stops.
            $GLOBALS['profiler_test_current_filter'] = 'page_template_hierarchy';
            self::assertSame(['page-package.php', 'page-kernel.php', 'page-626.php', 'page.php'], apply_filters('page_template_hierarchy', ['page-package.php', 'page-kernel.php', 'page-626.php', 'page.php']));
            $payload = (new TemplateCollector(new ProfilerLifecycleRecorder($gate), $templates, new ProfilerBlockRecorder($gate)))
                ->collect(new ProfileContext('t', microtime(true) - 1, microtime(true), 0, 0, 0, 200, '/wp-includes/template-canvas.php', [], [], '/_profiler/t'));

            self::assertSame('sympress//page-package', TemplateName::label('/wp-includes/template-canvas.php'));
            self::assertSame('single.php', TemplateName::label('/themes/classic/single.php'));
        } finally {
            unset($GLOBALS['_wp_current_template_id'], $GLOBALS['profiler_test_current_filter']);
            \ProfilerTestFilters::reset();
        }

        self::assertSame(['page-package.php', 'page-kernel.php', 'page-626.php', 'page.php'], array_column($payload['hierarchy'], 'template'));
        // The resolved block template counts as existing even without a theme file (it may live in the database).
        self::assertSame([true, false, false, false], array_column($payload['hierarchy'], 'exists'));
        self::assertNotContains('singular.php', array_column($payload['hierarchy'], 'template'));
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
