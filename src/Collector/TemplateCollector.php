<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Recorder\ProfilerBlockRecorder;
use SymPress\Profiler\Recorder\ProfilerLifecycleRecorder;
use SymPress\Profiler\Recorder\ProfilerTemplateRecorder;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class TemplateCollector extends AbstractCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly ProfilerLifecycleRecorder $lifecycle,
        private readonly ProfilerTemplateRecorder $templates,
        private readonly ProfilerBlockRecorder $blocks,
    ) {
    }

    public function getKey(): string
    {
        return 'template';
    }

    public function getLabel(): string
    {
        return 'Templates';
    }

    public function getIcon(): string
    {
        return 'template';
    }

    public function collect(ProfileContext $context): array
    {
        $template = (string) ($context->template() ?? '');
        $renderStartedAt = $this->renderStartedAt();
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        // Block themes render every page through wp-includes/template-canvas.php; the template that was
        // actually chosen is the block template WordPress resolved, e.g. "sympress//page".
        $blockTemplate = is_string($GLOBALS['_wp_current_template_id'] ?? null) ? $GLOBALS['_wp_current_template_id'] : '';

        return [
            'template'       => $template,
            'template_name'  => $blockTemplate !== '' ? $blockTemplate : ($template !== '' ? basename($template) : ''),
            'template_dir'   => $template !== '' ? dirname($template) : '',
            'block_template' => $blockTemplate,
            // Requests without a template (REST, AJAX) rendered nothing.
            'render_ms'      => $renderStartedAt === null ? 0.0 : round(max(0.0, ($context->finishedAt() - $renderStartedAt) * 1000), 2),
            'hierarchy'      => $this->templateHierarchy($blockTemplate),
            'template_parts' => [...$this->templates->parts(), ...$this->blockTemplateParts()],
            'theme'          => [
                'name'       => is_object($theme) ? (string) $theme->get('Name') : '',
                'version'    => is_object($theme) ? (string) $theme->get('Version') : '',
                'stylesheet' => is_object($theme) ? (string) $theme->get_stylesheet() : '',
                'template'   => is_object($theme) ? (string) $theme->get_template() : '',
                'theme_root' => is_object($theme) ? (string) $theme->get_theme_root() : '',
            ],
            'captured_at'    => $context->finishedAtIso(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $renderMs = $this->floatValue($payload, 'render_ms');

        return new ToolbarBlock(
            'template',
            'Templates',
            sprintf('%.0f ms', $renderMs),
            $this->stringValue($payload, 'template_name', 'n/a'),
            $this->profileUrl($profile, 'template'),
            $renderMs > 100.0 ? 'yellow' : 'green',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $templateRows = [
        [
            $this->stringValue($payload, 'template_name', 'n/a'),
            sprintf('%.1f ms', $this->floatValue($payload, 'render_ms')),
            Html::codeCell($this->stringValue($payload, 'template')),
        ],
        ];
        $hierarchyRows = [];

        foreach ($this->hierarchyRows($payload) as $candidate) {
            $hierarchyRows[] = [
                $candidate['template'],
                $candidate['exists'] ? 'yes' : 'no',
                $candidate['path'] !== '' ? Html::codeCell($candidate['path']) : 'n/a',
            ];
        }

        $partRows = [];

        foreach ($this->templatePartRows($payload) as $part) {
            $partRows[] = [
                $part['slug'],
                $part['name'] !== '' ? $part['name'] : 'n/a',
                Html::dumpValue($part['templates']),
                count($part['args']),
            ];
        }

        $html = '<h2>Templates</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Rendered templates', 'value' => $this->stringValue($payload, 'template_name') !== '' ? '1' : '0'],
            ['label' => 'Render time', 'value' => sprintf('%.1f ms', $this->floatValue($payload, 'render_ms'))],
            ['label' => 'Hierarchy candidates', 'value' => (string) count($hierarchyRows)],
            ['label' => 'Template parts', 'value' => (string) count($partRows)],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Templates', 'active' => true, 'target' => '#templates-main'],
            ['label' => 'Hierarchy', 'badge' => count($hierarchyRows), 'target' => '#templates-hierarchy'],
            ['label' => 'Template Parts', 'badge' => count($partRows), 'target' => '#templates-parts'],
            ['label' => 'Theme', 'target' => '#templates-theme'],
        ]);
        $html .= '<div id="templates-main" class="profiler-tab-target">'
            . Html::table(['Template', 'Render time', 'Path'], $templateRows)
            . '</div>';
        $html .= '<div id="templates-hierarchy" class="profiler-tab-target">'
            . Html::table(['Candidate', 'Exists', 'Path'], $hierarchyRows)
            . '</div>';
        $html .= '<div id="templates-parts" class="profiler-tab-target">'
            . Html::table(['Slug', 'Name', 'Candidates', 'Args'], $partRows)
            . '</div>';
        $html .= '<div id="templates-theme" class="profiler-tab-target">'
            . Html::codeBlock($payload['theme'] ?? [])
            . '</div>';

        return $this->panel(
            'template',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%.0f ms', $this->floatValue($payload, 'render_ms')),
        );
    }

    private function renderStartedAt(): ?float
    {
        foreach ($this->lifecycle->events() as $event) {
            if ($event['name'] === 'template_include') {
                return $event['started_at'];
            }
        }

        return null;
    }

    /**
     * Block themes never call get_template_part(): their parts are core/template-part blocks.
     *
     * @return list<array{slug: string, name: string, templates: list<string>, args: array<array-key, mixed>, captured_at: float}>
     */
    private function blockTemplateParts(): array
    {
        $parts = [];

        foreach ($this->blocks->blocks() as $block) {
            if ($block['name'] !== 'core/template-part') {
                continue;
            }

            $slug = is_string($block['attrs']['slug'] ?? null) ? $block['attrs']['slug'] : '';
            $parts[] = [
                'slug'        => $slug,
                'name'        => is_string($block['attrs']['theme'] ?? null) ? $block['attrs']['theme'] : '',
                'templates'   => $slug !== '' ? ['parts/' . $slug . '.html'] : [],
                'args'        => $block['attrs'],
                'captured_at' => 0.0,
            ];
        }

        return $parts;
    }

    /** A block theme answers a hierarchy candidate such as page.php with templates/page.html. */
    private function blockTemplateFile(string $candidate): string
    {
        if (!function_exists('get_stylesheet_directory') || !function_exists('get_template_directory')) {
            return '';
        }

        $file = '/templates/' . basename($candidate, '.php') . '.html';

        foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $directory) {
            if (is_file($directory . $file)) {
                return $directory . $file;
            }
        }

        return '';
    }

    /**
     * The candidates WordPress itself tried, in its order, as recorded from the template hierarchy filters.
     * Requests that load no template (feeds, REST, AJAX) have none.
     *
     * @return list<array{template: string, exists: bool, path: string}>
     */
    private function templateHierarchy(string $blockTemplate): array
    {
        // "theme//page-package" resolves the candidate "page-package.php", also when it lives in the database.
        $resolved = $blockTemplate !== '' ? substr((string) strrchr('/' . $blockTemplate, '/'), 1) . '.php' : '';
        $rows = [];

        foreach ($this->templates->hierarchy() as $step) {
            foreach ($step['templates'] as $candidate) {
                if (isset($rows[$candidate])) {
                    continue;
                }

                $path = function_exists('locate_template') ? locate_template([$candidate], false, false) : '';
                $path = $path !== '' ? $path : $this->blockTemplateFile($candidate);
                $rows[$candidate] = [
                    'template' => $candidate,
                    'exists'   => $path !== '' || basename($candidate) === $resolved,
                    'path'     => $path,
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{template: string, exists: bool, path: string}>
     */
    private function hierarchyRows(array $payload): array
    {
        $hierarchy = $payload['hierarchy'] ?? [];

        if (!is_array($hierarchy)) {
            return [];
        }

        $rows = [];

        foreach ($hierarchy as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $rows[] = [
                'template' => $this->stringValue($candidate, 'template'),
                'exists'   => $this->boolValue($candidate, 'exists'),
                'path'     => $this->stringValue($candidate, 'path'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{slug: string, name: string, templates: list<string>, args: array<array-key, mixed>}>
     */
    private function templatePartRows(array $payload): array
    {
        $parts = $payload['template_parts'] ?? [];

        if (!is_array($parts)) {
            return [];
        }

        $rows = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $templates = $part['templates'] ?? [];
            $args = $part['args'] ?? [];

            $rows[] = [
                'slug'      => $this->stringValue($part, 'slug'),
                'name'      => $this->stringValue($part, 'name'),
                'templates' => $this->stringList($templates),
                'args'      => is_array($args) ? $args : [],
            ];
        }

        return $rows;
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
