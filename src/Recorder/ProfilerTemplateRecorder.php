<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerTemplateRecorder
{
    /** Template types of get_query_template(); each has a "{type}_template_hierarchy" filter. */
    private const array TEMPLATE_TYPES = [
        '404', 'archive', 'attachment', 'author', 'category', 'date', 'embed', 'frontpage', 'home', 'index', 'page',
        'paged', 'privacypolicy', 'search', 'single', 'singular', 'tag', 'taxonomy',
    ];

    private bool $enabled = false;

    /** @var list<array{type: string, templates: list<string>}> */
    private array $hierarchy = [];

    /** @var list<array{slug: string, name: string, templates: list<string>, args: array<array-key, mixed>, captured_at: float}> */
    private array $parts = [];

    public function __construct(
        private readonly ProfileGate $gate,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = $this->gate->shouldCollect();

        if (!$this->enabled || !function_exists('add_filter')) {
            return;
        }

        // One filter per template type: registered here instead of 18 service tags. Last, to see the final list.
        foreach (self::TEMPLATE_TYPES as $type) {
            add_filter($type . '_template_hierarchy', $this->recordHierarchy(...), 999999);
        }
    }

    /**
     * WordPress passes its candidate list for each template type it tries through these filters, in the
     * order it tries them. Recording them shows the real hierarchy, custom page templates included.
     */
    public function recordHierarchy(mixed $templates): mixed
    {
        if ($this->enabled && function_exists('current_filter')) {
            $this->hierarchy[] = [
                'type'      => str_replace('_template_hierarchy', '', (string) current_filter()),
                'templates' => $this->stringList($templates),
            ];
        }

        return $templates;
    }

    /** @return list<array{type: string, templates: list<string>}> */
    public function hierarchy(): array
    {
        return $this->hierarchy;
    }

    public function recordTemplatePart(string $slug, ?string $name = null, mixed $templates = [], mixed $args = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->parts[] = [
            'slug'        => $slug,
            'name'        => $name ?? '',
            'templates'   => $this->stringList($templates),
            'args'        => is_array($args) ? $args : [],
            'captured_at' => microtime(true),
        ];
    }

    /** @return list<array{slug: string, name: string, templates: list<string>, args: array<array-key, mixed>, captured_at: float}> */
    public function parts(): array
    {
        return $this->parts;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_scalar($item) && !($item instanceof \Stringable)) {
                continue;
            }

            $strings[] = (string) $item;
        }

        return $strings;
    }
}
