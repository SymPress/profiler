<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerBlockRecorder
{
    private bool $enabled = false;

    /** @var list<array{name: string, attrs: array<array-key, mixed>, started_at: float, memory_mb: float, depth: int}> */
    private array $stack = [];

    /** @var list<array{name: string, attrs: array<array-key, mixed>, duration_ms: float, memory_mb: float, depth: int, output_bytes: int}> */
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
            'name'       => $this->blockName($parsedBlock),
            'attrs'      => $this->blockAttrs($parsedBlock),
            'started_at' => microtime(true),
            'memory_mb'  => round(memory_get_usage(true) / 1048576, 2),
            'depth'      => count($this->stack),
        ];

        return $parsedBlock;
    }

    /** @param array<string, mixed> $parsedBlock */
    public function finish(string $blockContent, array $parsedBlock = [], mixed $block = null): string
    {
        unset($parsedBlock, $block);

        if (!$this->enabled || $this->stack === []) {
            return $blockContent;
        }

        $event = array_pop($this->stack);

        $this->blocks[] = [
            'name'         => $event['name'],
            'attrs'        => $event['attrs'],
            'duration_ms'  => round((microtime(true) - $event['started_at']) * 1000, 2),
            'memory_mb'    => $event['memory_mb'],
            'depth'        => $event['depth'],
            'output_bytes' => strlen($blockContent),
        ];

        return $blockContent;
    }

    /** @return list<array{name: string, attrs: array<array-key, mixed>, duration_ms: float, memory_mb: float, depth: int, output_bytes: int}> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @param array<string, mixed> $block */
    private function blockName(array $block): string
    {
        $name = $block['blockName'] ?? '';

        return is_scalar($name) || $name instanceof \Stringable
            ? (string) $name
            : 'core/freeform';
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
}
