<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerBlockRecorder
{
    private bool $enabled = false;
    private int $sequence = 0;

    /** @var list<array{order: int, name: string, attrs: array<array-key, mixed>, started_at: float, memory_mb: float, depth: int, signature: string}> */
    private array $stack = [];

    /** @var array<int, array{name: string, attrs: array<array-key, mixed>, duration_ms: float, timed: bool, dynamic: bool, memory_mb: float, depth: int, output_bytes: int}> */
    private array $blocks = [];

    public function __construct(
        private readonly ProfileGate $gate,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = $this->gate->shouldCollect();
    }

    /**
     * @param array<string, mixed> $parsedBlock
     * @param array<string, mixed> $sourceBlock
     * @return array<string, mixed>
     */
    public function start(array $parsedBlock, array $sourceBlock = [], mixed $parentBlock = null): array
    {
        unset($sourceBlock, $parentBlock);

        if (!$this->enabled) {
            return $parsedBlock;
        }

        $this->stack[] = [
            'order'      => $this->nextOrder(),
            'name'       => $this->blockName($parsedBlock),
            'attrs'      => $this->blockAttrs($parsedBlock),
            'started_at' => microtime(true),
            'memory_mb'  => round(memory_get_usage(true) / 1048576, 2),
            'depth'      => count($this->stack),
            'signature'  => $this->signature($parsedBlock),
        ];

        return $parsedBlock;
    }

    /** @param array<string, mixed> $parsedBlock */
    public function finish(mixed $blockContent, array $parsedBlock = [], mixed $block = null): mixed
    {
        if (!$this->enabled) {
            return $blockContent;
        }

        $open = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
        // Blocks such as core/navigation render their children with WP_Block::render(), which fires
        // render_block without render_block_data. Such a block never started here: record it as an
        // untimed child of the open block instead of closing that block with the child's output.
        $timed = $open !== null && $open['signature'] === $this->signature($parsedBlock);

        if ($timed) {
            array_pop($this->stack);
        }

        $this->blocks[$timed ? $open['order'] : $this->nextOrder()] = [
            'name'         => $timed ? $open['name'] : $this->blockName($parsedBlock),
            'attrs'        => $timed ? $open['attrs'] : $this->blockAttrs($parsedBlock),
            'duration_ms'  => $timed ? round((microtime(true) - $open['started_at']) * 1000, 2) : 0.0,
            'timed'        => $timed,
            'dynamic'      => $this->isDynamic($block),
            'memory_mb'    => $timed ? $open['memory_mb'] : round(memory_get_usage(true) / 1048576, 2),
            'depth'        => $timed ? $open['depth'] : count($this->stack),
            // Other filters may return a non-string; pass it through untouched like core does.
            'output_bytes' => is_string($blockContent) ? strlen($blockContent) : 0,
        ];

        return $blockContent;
    }

    /**
     * Blocks in document order (parents before their children).
     *
     * @return list<array{name: string, attrs: array<array-key, mixed>, duration_ms: float, timed: bool, dynamic: bool, memory_mb: float, depth: int, output_bytes: int}>
     */
    public function blocks(): array
    {
        $blocks = $this->blocks;
        ksort($blocks);

        return array_values($blocks);
    }

    private function nextOrder(): int
    {
        $order = $this->sequence;
        $this->sequence++;

        return $order;
    }

    /** @param array<string, mixed> $block */
    private function blockName(array $block): string
    {
        $name = $block['blockName'] ?? null;

        // Markup outside block comments is parsed as a block without a name.
        return is_string($name) && $name !== '' ? $name : 'core/freeform';
    }

    /**
     * @param array<string, mixed> $block
     * @return array<array-key, mixed>
     */
    private function blockAttrs(array $block): array
    {
        $attrs = $block['attrs'] ?? [];

        return is_array($attrs) ? $attrs : [];
    }

    /**
     * Cheap identity for pairing start and finish; filters may still change attributes in between.
     *
     * @param array<string, mixed> $block
     */
    private function signature(array $block): string
    {
        return sprintf(
            '%s|%d|%d',
            $this->blockName($block),
            is_string($block['innerHTML'] ?? null) ? strlen($block['innerHTML']) : 0,
            is_array($block['innerBlocks'] ?? null) ? count($block['innerBlocks']) : 0,
        );
    }

    private function isDynamic(mixed $block): bool
    {
        $type = is_object($block) ? ($block->block_type ?? null) : null;

        return is_object($type) && method_exists($type, 'is_dynamic') && $type->is_dynamic() === true;
    }
}
