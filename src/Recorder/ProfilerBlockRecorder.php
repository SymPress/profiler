<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerBlockRecorder
{
    private bool $enabled = false;

    /**
     * Rendered blocks in document order. A block that starts reserves its place, so children land after it.
     *
     * @var array<int, array{name: string, attrs: array<array-key, mixed>, duration_ms: float, timed: bool, dynamic: bool, memory_mb: float, depth: int, output_bytes: int}>
     */
    private array $blocks = [];

    /** @var list<array{index: int, started_at: float, signature: string}> Open blocks, outermost first. */
    private array $stack = [];

    /** @var list<array{index: int, size: int}> Subtrees of the directly rendered blocks since the last start or end. */
    private array $run = [];

    /** @var array{index: int, started_at: float, signature: string, depth: int}|null */
    private ?array $lastClosed = null;

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

        $this->run = [];
        $this->blocks[] = $this->entry($parsedBlock, count($this->stack));
        $this->stack[] = [
            'index'      => array_key_last($this->blocks),
            'started_at' => microtime(true),
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

        $signature = $this->signature($parsedBlock);
        // Other filters may return a non-string; pass it through untouched like core does.
        $bytes = is_string($blockContent) ? strlen($blockContent) : 0;

        // The nearest open block with this identity ends here. Blocks opened above it never reported their
        // end (a filter changed them in between) and are closed with it.
        for ($position = count($this->stack) - 1; $position >= 0; $position--) {
            if ($this->stack[$position]['signature'] !== $signature) {
                continue;
            }

            $open = $this->stack[$position];
            array_splice($this->stack, $position);
            $this->run = [];
            $this->lastClosed = [...$open, 'depth' => count($this->stack)];
            $this->close($open['index'], $open['started_at'], $bytes, $block);

            return $blockContent;
        }

        // core/comment-template and core/comments render the same block again for each comment:
        // the last call is the real end.
        if ($this->lastClosed !== null && $this->lastClosed['signature'] === $signature && $this->lastClosed['depth'] === count($this->stack)) {
            $this->close($this->lastClosed['index'], $this->lastClosed['started_at'], $bytes, $block);

            return $blockContent;
        }

        $this->recordDirectRender($parsedBlock, $bytes, $block);

        return $blockContent;
    }

    /**
     * Blocks in document order (parents before their children).
     *
     * @return list<array{name: string, attrs: array<array-key, mixed>, duration_ms: float, timed: bool, dynamic: bool, memory_mb: float, depth: int, output_bytes: int}>
     */
    public function blocks(): array
    {
        return array_values($this->blocks);
    }

    /**
     * core/navigation renders its children with WP_Block::render(), which fires render_block without
     * render_block_data: such a block never started here and has no own timing. It ends after its
     * children, so it adopts the directly rendered blocks before it, one per inner block.
     *
     * @param array<string, mixed> $parsedBlock
     */
    private function recordDirectRender(array $parsedBlock, int $bytes, mixed $block): void
    {
        $entry = $this->entry($parsedBlock, count($this->stack));
        $entry['output_bytes'] = $bytes;
        $entry['dynamic'] = $this->isDynamic($block);
        $innerBlocks = is_array($parsedBlock['innerBlocks'] ?? null) ? count($parsedBlock['innerBlocks']) : 0;
        $children = $innerBlocks > 0 ? array_splice($this->run, -min($innerBlocks, count($this->run))) : [];

        if ($children === []) {
            $this->blocks[] = $entry;
            $this->run[] = ['index' => array_key_last($this->blocks), 'size' => 1];

            return;
        }

        $first = $children[0]['index'];
        $size = array_sum(array_column($children, 'size'));

        for ($index = $first; $index < $first + $size; $index++) {
            $this->blocks[$index]['depth']++;
        }

        array_splice($this->blocks, $first, 0, [$entry]);
        $this->run[] = ['index' => $first, 'size' => $size + 1];
    }

    private function close(int $index, float $startedAt, int $bytes, mixed $block): void
    {
        if (!isset($this->blocks[$index])) {
            return;
        }

        $this->blocks[$index] = [
            ...$this->blocks[$index],
            'duration_ms'  => round((microtime(true) - $startedAt) * 1000, 2),
            'timed'        => true,
            'dynamic'      => $this->isDynamic($block),
            'output_bytes' => $bytes,
        ];
    }

    /**
     * @param array<string, mixed> $parsedBlock
     * @return array{name: string, attrs: array<array-key, mixed>, duration_ms: float, timed: bool, dynamic: bool, memory_mb: float, depth: int, output_bytes: int}
     */
    private function entry(array $parsedBlock, int $depth): array
    {
        return [
            'name'         => $this->blockName($parsedBlock),
            'attrs'        => $this->blockAttrs($parsedBlock),
            'duration_ms'  => 0.0,
            'timed'        => false,
            'dynamic'      => false,
            'memory_mb'    => round(memory_get_usage(true) / 1048576, 2),
            'depth'        => $depth,
            'output_bytes' => 0,
        ];
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
     * Identity for pairing start and end: name, attributes and markup length. Not the inner blocks:
     * core/block (synced patterns) replaces them between the two filters.
     *
     * @param array<string, mixed> $block
     */
    private function signature(array $block): string
    {
        return sprintf(
            '%s|%s|%d',
            $this->blockName($block),
            hash('crc32b', serialize($this->blockAttrs($block))),
            is_string($block['innerHTML'] ?? null) ? strlen($block['innerHTML']) : 0,
        );
    }

    private function isDynamic(mixed $block): bool
    {
        $type = is_object($block) ? ($block->block_type ?? null) : null;

        return is_object($type) && method_exists($type, 'is_dynamic') && $type->is_dynamic() === true;
    }
}
