<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class AssetCollector extends AbstractCollector implements DataCollectorInterface
{
    public function getKey(): string
    {
        return 'assets';
    }

    public function getLabel(): string
    {
        return 'Assets';
    }

    public function getIcon(): string
    {
        return 'template';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $scripts = $this->collectDependencyGroup($GLOBALS['wp_scripts'] ?? null);
        $styles = $this->collectDependencyGroup($GLOBALS['wp_styles'] ?? null);

        return [
            'scripts'              => $scripts,
            'styles'               => $styles,
            'script_modules'       => $this->scriptModules(),
            'missing_dependencies' => [
                ...$this->missingDependencies($scripts, 'script'),
                ...$this->missingDependencies($styles, 'style'),
            ],
            'duplicate_sources'    => [
                ...$this->duplicateSources($scripts, 'script'),
                ...$this->duplicateSources($styles, 'style'),
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $scriptCount = count($this->enqueued($payload, 'scripts'));
        $styleCount = count($this->enqueued($payload, 'styles'));

        return new ToolbarBlock(
            'assets',
            'Assets',
            sprintf('%d assets', $scriptCount + $styleCount),
            sprintf('%d scripts · %d styles', $scriptCount, $styleCount),
            $this->profileUrl($profile, 'assets'),
            'cyan',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $scriptRows = $this->assetRows($payload, 'scripts');
        $styleRows = $this->assetRows($payload, 'styles');
        $missingRows = [];

        foreach ($this->issueRows($payload, 'missing_dependencies') as $issue) {
            $missingRows[] = [$issue['type'], $issue['handle'], $issue['dependency']];
        }

        $duplicateRows = [];

        foreach ($this->issueRows($payload, 'duplicate_sources') as $issue) {
            $duplicateRows[] = [$issue['type'], $issue['source'], Html::dumpValue($issue['handles'])];
        }

        $html = '<h2>Assets</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Enqueued scripts', 'value' => (string) count($this->enqueued($payload, 'scripts'))],
            ['label' => 'Enqueued styles', 'value' => (string) count($this->enqueued($payload, 'styles'))],
            ['label' => 'Missing deps', 'value' => (string) count($missingRows)],
            ['label' => 'Duplicate sources', 'value' => (string) count($duplicateRows)],
        ]);
        $moduleRows = [];

        foreach (is_array($payload['script_modules'] ?? null) ? $payload['script_modules'] : [] as $module) {
            if (!is_array($module)) {
                continue;
            }

            $moduleRows[] = [
                $this->stringValue($module, 'id'),
                $this->stringValue($module, 'src', 'n/a'),
                $this->stringValue($module, 'version', 'n/a'),
                implode(', ', $this->stringList($module['dependencies'] ?? [])) ?: 'none',
                (bool) ($module['enqueued'] ?? false) ? 'enqueued' : 'dependency',
            ];
        }

        $html .= Html::tabNavigation([
            ['label' => 'Scripts', 'badge' => count($scriptRows), 'active' => true, 'target' => '#assets-scripts'],
            ['label' => 'Script modules', 'badge' => count($moduleRows), 'target' => '#assets-modules'],
            ['label' => 'Styles', 'badge' => count($styleRows), 'target' => '#assets-styles'],
            ['label' => 'Dependency issues', 'badge' => count($missingRows) + count($duplicateRows), 'target' => '#assets-issues'],
        ]);
        $html .= '<div id="assets-scripts" class="profiler-tab-target">'
            . Html::table(['Handle', 'Source', 'Version', 'Dependencies', 'State', 'Group'], $scriptRows)
            . '</div>';
        $html .= '<div id="assets-modules" class="profiler-tab-target">'
            . Html::table(['Module', 'Source', 'Version', 'Dependencies', 'State'], $moduleRows)
            . '</div>';
        $html .= '<div id="assets-styles" class="profiler-tab-target">'
            . Html::table(['Handle', 'Source', 'Version', 'Dependencies', 'State', 'Group'], $styleRows)
            . '</div>';
        $html .= '<div id="assets-issues" class="profiler-tab-target">';
        $html .= Html::section('Missing Dependencies', Html::table(['Type', 'Handle', 'Missing dependency'], $missingRows));
        $html .= Html::section('Duplicate Sources', Html::table(['Type', 'Source', 'Handles'], $duplicateRows));
        $html .= '</div>';

        return $this->panel(
            'assets',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', count($scriptRows) + count($styleRows)),
        );
    }

    /**
     * Enqueued script modules and everything they import. WordPress exposes no list of registered modules,
     * only the queue (6.9) and a lookup by id (7.0); older versions show none.
     *
     * @return list<array{id: string, src: string, version: string, dependencies: list<string>, enqueued: bool}>
     */
    private function scriptModules(): array
    {
        $modules = function_exists('wp_script_modules') ? wp_script_modules() : null;

        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType (get_queue() is 6.9, get_registered() 7.0; the plugin supports 6.9.)
        if (!is_object($modules) || !method_exists($modules, 'get_queue') || !method_exists($modules, 'get_registered')) {
            return [];
        }

        $queue = $this->stringList($modules->get_queue());
        $pending = $queue;
        $rows = [];

        while ($pending !== []) {
            $id = array_shift($pending);

            if (isset($rows[$id])) {
                continue;
            }

            $module = $modules->get_registered($id);
            $dependencies = array_column($module['dependencies'] ?? [], 'id');

            $version = $module['version'] ?? null;
            $rows[$id] = [
                'id'           => $id,
                'src'          => is_string($module['src'] ?? null) ? $module['src'] : '',
                // false means "the WordPress version", null means none: the same rule core applies to the URL.
                'version'      => is_string($version) ? $version : ($version === false ? (string) get_bloginfo('version') : ''),
                'dependencies' => $dependencies,
                'enqueued'     => in_array($id, $queue, true),
            ];
            $pending = [...$pending, ...$dependencies];
        }

        return array_values($rows);
    }

    /**
     * @return array{
     *   registered: array<string, array{handle: string, src: string, deps: list<string>, ver: string, args: string, extra: array<array-key, mixed>, enqueued: bool, done: bool, group: string}>,
     *   queue: list<string>,
     *   done: list<string>
     * }
     */
    private function collectDependencyGroup(mixed $dependencies): array
    {
        if (!is_object($dependencies)) {
            return ['registered' => [], 'queue' => [], 'done' => []];
        }

        $registered = is_array($dependencies->registered ?? null) ? $dependencies->registered : [];
        $queue = $this->stringList($dependencies->queue ?? []);
        $done = $this->stringList($dependencies->done ?? []);
        $items = [];

        foreach ($registered as $handle => $dependency) {
            if (!is_object($dependency)) {
                continue;
            }

            $itemHandle = $this->stringFromMixed($this->readProperty($dependency, 'handle'));

            if ($itemHandle === '') {
                $itemHandle = (string) $handle;
            }

            $extra = $this->readProperty($dependency, 'extra');
            $items[$itemHandle] = [
                'handle'   => $itemHandle,
                'src'      => $this->stringFromMixed($this->readProperty($dependency, 'src')),
                'deps'     => $this->stringList($this->readProperty($dependency, 'deps')),
                // false means "append the WordPress version", null means none: what core prints in the URL.
                'ver'      => $this->readProperty($dependency, 'ver') === false
                    ? (string) get_bloginfo('version')
                    : $this->stringFromMixed($this->readProperty($dependency, 'ver')),
                'args'     => $this->stringFromMixed($this->readProperty($dependency, 'args')),
                'extra'    => is_array($extra) ? $extra : [],
                'enqueued' => in_array($itemHandle, $queue, true),
                'done'     => in_array($itemHandle, $done, true),
                'group'    => $this->groupLabel($dependency),
            ];
        }

        return ['registered' => $items, 'queue' => $queue, 'done' => $done];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<array-key, mixed>>
     */
    private function enqueued(array $payload, string $key): array
    {
        $group = $payload[$key] ?? [];

        if (!is_array($group) || !is_array($group['registered'] ?? null)) {
            return [];
        }

        $items = [];

        foreach ($group['registered'] as $item) {
            if (!is_array($item) || (!(bool) ($item['enqueued'] ?? false) && !(bool) ($item['done'] ?? false))) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<list<mixed>>
     */
    private function assetRows(array $payload, string $key): array
    {
        $rows = [];

        foreach ($this->enqueued($payload, $key) as $asset) {
            $deps = $this->stringList($asset['deps'] ?? []);
            $rows[] = [
                $this->stringValue($asset, 'handle'),
                $this->stringValue($asset, 'src') !== '' ? $this->stringValue($asset, 'src') : 'inline/registered',
                $this->stringValue($asset, 'ver') !== '' ? $this->stringValue($asset, 'ver') : 'n/a',
                $deps !== [] ? implode(', ', $deps) : 'none',
                (bool) ($asset['done'] ?? false) ? 'printed' : 'queued',
                $this->stringValue($asset, 'group', 'default'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $group
     * @return list<array{type: string, handle: string, dependency: string}>
     */
    private function missingDependencies(array $group, string $type): array
    {
        $registered = is_array($group['registered'] ?? null) ? $group['registered'] : [];
        $issues = [];

        foreach ($registered as $handle => $asset) {
            if (!is_array($asset) || !((bool) ($asset['enqueued'] ?? false) || (bool) ($asset['done'] ?? false))) {
                continue;
            }

            foreach ((array) ($asset['deps'] ?? []) as $dependency) {
                if (!is_scalar($dependency) && !$dependency instanceof \Stringable) {
                    continue;
                }

                $dependency = (string) $dependency;

                if ($dependency === '' || array_key_exists($dependency, $registered)) {
                    continue;
                }

                $issues[] = ['type' => $type, 'handle' => (string) $handle, 'dependency' => $dependency];
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $group
     * @return list<array{type: string, source: string, handles: list<string>}>
     */
    private function duplicateSources(array $group, string $type): array
    {
        $registered = is_array($group['registered'] ?? null) ? $group['registered'] : [];
        $sources = [];

        foreach ($registered as $handle => $asset) {
            if (!is_array($asset) || !((bool) ($asset['enqueued'] ?? false) || (bool) ($asset['done'] ?? false))) {
                continue;
            }

            $src = $this->stringValue($asset, 'src');

            if ($src === '') {
                continue;
            }

            $sources[$src][] = (string) $handle;
        }

        $duplicates = [];

        foreach ($sources as $source => $handles) {
            if (count($handles) <= 1) {
                continue;
            }

            $duplicates[] = ['type' => $type, 'source' => $source, 'handles' => $handles];
        }

        return $duplicates;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<array-key, mixed>>
     */
    private function issueRows(array $payload, string $key): array
    {
        $issues = $payload[$key] ?? [];

        if (!is_array($issues)) {
            return [];
        }

        $rows = [];

        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $rows[] = $issue;
        }

        return $rows;
    }

    private function groupLabel(object $dependency): string
    {
        $extra = $this->readProperty($dependency, 'extra');

        if (
            is_array($extra)
            && isset($extra['group'])
            && (is_scalar($extra['group']) || $extra['group'] instanceof \Stringable)
        ) {
            return (string) $extra['group'];
        }

        $args = $this->readProperty($dependency, 'args');

        return is_scalar($args) || $args instanceof \Stringable ? (string) $args : 'default';
    }

    private function readProperty(object $object, string $property): mixed
    {
        $values = (array) $object;

        return $values[$property] ?? null;
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

    private function stringFromMixed(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
