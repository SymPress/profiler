<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Recorder\ProfilerBlockRecorder;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class BlockCollector extends AbstractCollector implements DataCollectorInterface
{
    private const int MAX_PARSED_BLOCKS = 200;

    public function __construct(
        private readonly ProfilerBlockRecorder $recorder,
    ) {
    }

    public function getKey(): string
    {
        return 'blocks';
    }

    public function getLabel(): string
    {
        return 'Blocks';
    }

    public function getIcon(): string
    {
        return 'twig-components';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $rendered = $this->recorder->blocks();
        $parsed = $this->parsedBlocksFromQuery();
        // Durations include nested blocks: only top-level blocks add up to real render time.
        $totalDuration = array_reduce(
            $rendered,
            static fn (float $carry, array $block): float => $block['depth'] === 0 ? $carry + $block['duration_ms'] : $carry,
            0.0,
        );

        return [
            'rendered'          => $rendered,
            'parsed'            => array_slice($parsed, 0, self::MAX_PARSED_BLOCKS),
            'rendered_count'    => count($rendered),
            'parsed_count'      => count($parsed),
            'total_duration_ms' => round($totalDuration, 2),
            'dynamic_count'     => count(array_filter($rendered, static fn (array $block): bool => $block['dynamic'])),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        return new ToolbarBlock(
            'blocks',
            'Blocks',
            sprintf('%d rendered', $this->intValue($payload, 'rendered_count')),
            sprintf('%.1f ms', $this->floatValue($payload, 'total_duration_ms')),
            $this->profileUrl($profile, 'blocks'),
            $this->floatValue($payload, 'total_duration_ms') > 50.0 ? 'yellow' : 'green',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $renderRows = [];

        foreach ($this->renderedRows($payload) as $index => $block) {
            $renderRows[] = [
                $index + 1,
                str_repeat('  ', $block['depth']) . $block['name'],
                $block['timed'] ? sprintf('%.2f ms', $block['duration_ms']) : 'n/a',
                $this->formatBytes($block['output_bytes']),
                Html::dumpValue($block['attrs']),
            ];
        }

        $parsedRows = [];

        foreach ($this->parsedRows($payload) as $block) {
            $parsedRows[] = [
                $block['post_id'],
                $block['post_title'],
                str_repeat('  ', $block['depth']) . $block['name'],
                Html::dumpValue($block['attrs']),
            ];
        }

        $html = '<h2>Blocks</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Rendered blocks', 'value' => (string) $this->intValue($payload, 'rendered_count')],
            ['label' => 'Render time', 'value' => sprintf('%.2f ms', $this->floatValue($payload, 'total_duration_ms'))],
            ['label' => 'Dynamic blocks', 'value' => (string) $this->intValue($payload, 'dynamic_count')],
            ['label' => 'Parsed blocks', 'value' => (string) $this->intValue($payload, 'parsed_count')],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Rendered Blocks', 'badge' => count($renderRows), 'active' => true, 'target' => '#blocks-rendered'],
            ['label' => 'Parsed Content', 'badge' => count($parsedRows), 'target' => '#blocks-parsed'],
        ]);
        $html .= '<div id="blocks-rendered" class="profiler-tab-target">'
            . '<p class="help">Durations include nested blocks. Blocks rendered directly by their parent (for example navigation links) have no own timing.</p>'
            . Html::table(['#', 'Block', 'Duration', 'Output', 'Attributes'], $renderRows)
            . '</div>';
        $html .= '<div id="blocks-parsed" class="profiler-tab-target">'
            . (count($parsedRows) < $this->intValue($payload, 'parsed_count')
                ? sprintf('<p class="help">Showing the first %d of %d parsed blocks.</p>', count($parsedRows), $this->intValue($payload, 'parsed_count'))
                : '')
            . Html::table(['Post ID', 'Post', 'Block', 'Attributes'], $parsedRows)
            . '</div>';

        return $this->panel(
            'blocks',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'rendered_count')),
        );
    }

    /** @return list<array{post_id: int, post_title: string, name: string, attrs: array<array-key, mixed>, depth: int}> */
    private function parsedBlocksFromQuery(): array
    {
        if (!function_exists('parse_blocks')) {
            return [];
        }

        $query = $GLOBALS['wp_query'] ?? null;

        if (!is_object($query) || !is_array($query->posts ?? null)) {
            return [];
        }

        $rows = [];

        foreach (array_slice($query->posts, 0, 20) as $post) {
            if (!is_object($post) || !is_string($post->post_content ?? null)) {
                continue;
            }

            $this->appendParsedBlocks(
                parse_blocks($post->post_content),
                is_numeric($post->ID ?? null) ? (int) $post->ID : 0,
                is_scalar($post->post_title ?? null) ? (string) $post->post_title : '',
                0,
                $rows,
            );
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $blocks
     * @param list<array{post_id: int, post_title: string, name: string, attrs: array<array-key, mixed>, depth: int}> $rows
     */
    private function appendParsedBlocks(array $blocks, int $postId, string $postTitle, int $depth, array &$rows): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = is_scalar($block['blockName'] ?? null) ? (string) $block['blockName'] : 'core/freeform';
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            $rows[] = [
                'post_id'    => $postId,
                'post_title' => $postTitle,
                'name'       => $name !== '' ? $name : 'core/freeform',
                'attrs'      => $attrs,
                'depth'      => $depth,
            ];

            if (!is_array($block['innerBlocks'] ?? null)) {
                continue;
            }

            $this->appendParsedBlocks($block['innerBlocks'], $postId, $postTitle, $depth + 1, $rows);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, attrs: array<array-key, mixed>, duration_ms: float, timed: bool, memory_mb: float, depth: int, output_bytes: int}>
     */
    private function renderedRows(array $payload): array
    {
        $blocks = $payload['rendered'] ?? [];

        if (!is_array($blocks)) {
            return [];
        }

        $rows = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $rows[] = [
                'name'         => $this->stringValue($block, 'name', 'core/freeform'),
                'attrs'        => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
                'duration_ms'  => $this->floatValue($block, 'duration_ms'),
                // Profiles stored before 1.2.2 have no flag: their blocks were all timed.
                'timed'        => ($block['timed'] ?? true) === true,
                'memory_mb'    => $this->floatValue($block, 'memory_mb'),
                'depth'        => $this->intValue($block, 'depth'),
                'output_bytes' => $this->intValue($block, 'output_bytes'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{post_id: int, post_title: string, name: string, attrs: array<array-key, mixed>, depth: int}>
     */
    private function parsedRows(array $payload): array
    {
        $blocks = $payload['parsed'] ?? [];

        if (!is_array($blocks)) {
            return [];
        }

        $rows = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $rows[] = [
                'post_id'    => $this->intValue($block, 'post_id'),
                'post_title' => $this->stringValue($block, 'post_title'),
                'name'       => $this->stringValue($block, 'name', 'core/freeform'),
                'attrs'      => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
                'depth'      => $this->intValue($block, 'depth'),
            ];
        }

        return $rows;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f MiB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.2f KiB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
