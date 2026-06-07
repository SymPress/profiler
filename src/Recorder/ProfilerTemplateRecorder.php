<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerTemplateRecorder
{
    private bool $enabled = false;

    /**
     * @var list<array{slug: string, name: string, templates: list<string>, args: array<array-key, mixed>, captured_at: float}>
     */
    private array $parts = [];

    public function __construct(
        private readonly ProfileGate $gate,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = $this->gate->shouldCollect();
    }

    /**
     * @param list<string>|mixed $templates
     * @param array<array-key, mixed>|mixed $args
     */
    public function recordTemplatePart(string $slug, ?string $name = null, mixed $templates = [], mixed $args = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->parts[] = [
            'slug' => $slug,
            'name' => $name ?? '',
            'templates' => $this->stringList($templates),
            'args' => is_array($args) ? $args : [],
            'captured_at' => microtime(true),
        ];
    }

    /**
     * @return list<array{slug: string, name: string, templates: list<string>, args: array<array-key, mixed>, captured_at: float}>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item) || $item instanceof \Stringable) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }
}
