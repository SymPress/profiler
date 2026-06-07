<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
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
    ) {
    }

    public function key(): string
    {
        return 'template';
    }

    public function label(): string
    {
        return 'Templates';
    }

    public function icon(): string
    {
        return 'template';
    }

    public function collect(ProfileContext $context): array
    {
        $template = (string) ($context->template() ?? '');
        $renderStartedAt = $this->renderStartedAt();
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;

        return [
            'template' => $template,
            'template_name' => $template !== '' ? basename($template) : '',
            'template_dir' => $template !== '' ? dirname($template) : '',
            'render_ms' => round(max(0.0, ($context->finishedAt() - $renderStartedAt) * 1000), 2),
            'hierarchy' => $this->templateHierarchy(),
            'template_parts' => $this->templates->parts(),
            'theme' => [
                'name' => is_object($theme) ? (string) $theme->get('Name') : '',
                'version' => is_object($theme) ? (string) $theme->get('Version') : '',
                'stylesheet' => is_object($theme) ? (string) $theme->get_stylesheet() : '',
                'template' => is_object($theme) ? (string) $theme->get_template() : '',
                'theme_root' => is_object($theme) ? (string) $theme->get_theme_root() : '',
            ],
            'captured_at' => $context->finishedAtIso(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $templateRows = [[
            $this->stringValue($payload, 'template_name', 'n/a'),
            sprintf('%.1f ms', $this->floatValue($payload, 'render_ms')),
            Html::codeCell($this->stringValue($payload, 'template')),
        ]];
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
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%.0f ms', $this->floatValue($payload, 'render_ms')),
        );
    }

    private function renderStartedAt(): float
    {
        foreach ($this->lifecycle->events() as $event) {
            if ($event['name'] === 'template_include') {
                return $event['started_at'];
            }
        }

        return $this->lifecycle->requestStartedAt();
    }

    /**
     * @return list<array{template: string, exists: bool, path: string}>
     */
    private function templateHierarchy(): array
    {
        $candidates = $this->templateCandidates();
        $rows = [];

        foreach ($candidates as $candidate) {
            $path = function_exists('locate_template') ? locate_template([$candidate], false, false) : '';
            $rows[] = [
                'template' => $candidate,
                'exists' => $path !== '',
                'path' => $path,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function templateCandidates(): array
    {
        $candidates = [];

        if (function_exists('is_front_page') && is_front_page()) {
            $candidates[] = 'front-page.php';
        }

        if (function_exists('is_home') && is_home()) {
            $candidates[] = 'home.php';
        }

        if (function_exists('is_page') && is_page()) {
            $object = function_exists('get_queried_object') ? get_queried_object() : null;
            $slug = is_object($object) && is_scalar($object->post_name ?? null) ? (string) $object->post_name : '';
            $id = is_object($object) && is_numeric($object->ID ?? null) ? (int) $object->ID : 0;

            if ($slug !== '') {
                $candidates[] = 'page-' . $slug . '.php';
            }

            if ($id > 0) {
                $candidates[] = 'page-' . $id . '.php';
            }

            $candidates[] = 'page.php';
        }

        if (function_exists('is_single') && is_single()) {
            $object = function_exists('get_queried_object') ? get_queried_object() : null;
            $type = is_object($object) && is_scalar($object->post_type ?? null) ? (string) $object->post_type : 'post';
            $slug = is_object($object) && is_scalar($object->post_name ?? null) ? (string) $object->post_name : '';

            if ($type !== '') {
                $candidates[] = 'single-' . $type . ($slug !== '' ? '-' . $slug : '') . '.php';
                $candidates[] = 'single-' . $type . '.php';
            }

            $candidates[] = 'single.php';
        }

        if (function_exists('is_archive') && is_archive()) {
            $candidates[] = 'archive.php';
        }

        if (function_exists('is_search') && is_search()) {
            $candidates[] = 'search.php';
        }

        if (function_exists('is_404') && is_404()) {
            $candidates[] = '404.php';
        }

        $candidates[] = 'singular.php';
        $candidates[] = 'index.php';

        return array_values(array_unique($candidates));
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
                'exists' => $this->boolValue($candidate, 'exists'),
                'path' => $this->stringValue($candidate, 'path'),
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
                'slug' => $this->stringValue($part, 'slug'),
                'name' => $this->stringValue($part, 'name'),
                'templates' => $this->stringList($templates),
                'args' => is_array($args) ? $args : [],
            ];
        }

        return $rows;
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
