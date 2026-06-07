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
    public function __construct(
        private readonly ProfilerBlockRecorder $recorder,
    ) {
    }

    public function key(): string
    {
        return 'blocks';
    }

    public function label(): string
    {
        return 'Blocks';
    }

    public function icon(): string
    {
        return 'twig-components';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $rendered = $this->recorder->blocks();
        $parsed = $this->parsedBlocksFromQuery();
        $totalDuration = array_reduce(
            $rendered,
            static fn (float $carry, array $block): float => $carry + (float) $block['duration_ms'],
            0.0,
        );

        return [
            'rendered' => $rendered,
            'parsed' => $parsed,
            'rendered_count' => count($rendered),
            'parsed_count' => count($parsed),
            'total_duration_ms' => round($totalDuration, 2),
            'dynamic_count' => count(array_filter($rendered, static fn (array $block): bool => $block['name'] !== 'core/freeform')),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $renderRows = [];

        foreach ($this->renderedRows($payload) as $index => $block) {
            $renderRows[] = [
                $index + 1,
                str_repeat('  ', $block['depth']) . $block['name'],
                sprintf('%.2f ms', $block['duration_ms']),
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
            . Html::table(['#', 'Block', 'Duration', 'Output', 'Attributes'], $renderRows)
            . '</div>';
        $html .= '<div id="blocks-parsed" class="profiler-tab-target">'
            . Html::table(['Post ID', 'Post', 'Block', 'Attributes'], $parsedRows)
            . '</div>';

        return $this->panel(
            'blocks',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'rendered_count')),
        );
    }

    /**
     * @return list<array{post_id: int, post_title: string, name: string, attrs: array<array-key, mixed>, depth: int}>
     */
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

        return array_slice($rows, 0, 200);
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
                'post_id' => $postId,
                'post_title' => $postTitle,
                'name' => $name !== '' ? $name : 'core/freeform',
                'attrs' => $attrs,
                'depth' => $depth,
            ];

            if (is_array($block['innerBlocks'] ?? null)) {
                $this->appendParsedBlocks($block['innerBlocks'], $postId, $postTitle, $depth + 1, $rows);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, attrs: array<array-key, mixed>, duration_ms: float, memory_mb: float, depth: int, output_bytes: int}>
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
                'name' => $this->stringValue($block, 'name', 'core/freeform'),
                'attrs' => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
                'duration_ms' => $this->floatValue($block, 'duration_ms'),
                'memory_mb' => $this->floatValue($block, 'memory_mb'),
                'depth' => $this->intValue($block, 'depth'),
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
                'post_id' => $this->intValue($block, 'post_id'),
                'post_title' => $this->stringValue($block, 'post_title'),
                'name' => $this->stringValue($block, 'name', 'core/freeform'),
                'attrs' => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
                'depth' => $this->intValue($block, 'depth'),
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
