<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class PluginThemeCollector extends AbstractCollector implements DataCollectorInterface
{
    public function key(): string
    {
        return 'plugins';
    }

    public function label(): string
    {
        return 'Plugins / Theme';
    }

    public function icon(): string
    {
        return 'wordpress';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $this->loadPluginApi();

        return [
            'active_plugins' => $this->activePlugins(),
            'network_plugins' => $this->networkPlugins(),
            'plugins' => $this->plugins(),
            'mu_plugins' => $this->muPlugins(),
            'dropins' => $this->dropins(),
            'theme' => $this->theme(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $activeCount = count($this->activePluginRows($payload));

        return new ToolbarBlock(
            'plugins',
            'Plugins / Theme',
            sprintf('%d active', $activeCount),
            $this->stringValue((array) ($payload['theme'] ?? []), 'name', 'theme n/a'),
            $this->profileUrl($profile, 'plugins'),
            'green',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $pluginRows = [];

        foreach ($this->pluginRows($payload) as $plugin) {
            $pluginRows[] = [
                $plugin['name'],
                $plugin['version'],
                $plugin['status'],
                $plugin['file'],
                $plugin['author'],
            ];
        }

        $muRows = [];

        foreach ($this->muPluginRows($payload) as $plugin) {
            $muRows[] = [$plugin['name'], $plugin['version'], $plugin['file'], $plugin['author']];
        }

        $dropinRows = [];

        foreach ($this->dropinRows($payload) as $dropin) {
            $dropinRows[] = [$dropin['name'], $dropin['file'], $dropin['description']];
        }

        $theme = is_array($payload['theme'] ?? null) ? $payload['theme'] : [];

        $html = '<h2>Plugins / Theme</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Active plugins', 'value' => (string) count($this->activePluginRows($payload))],
            ['label' => 'Installed plugins', 'value' => (string) count($pluginRows)],
            ['label' => 'MU plugins', 'value' => (string) count($muRows)],
            ['label' => 'Drop-ins', 'value' => (string) count($dropinRows)],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Plugins', 'badge' => count($pluginRows), 'active' => true, 'target' => '#plugins-regular'],
            ['label' => 'MU Plugins', 'badge' => count($muRows), 'target' => '#plugins-mu'],
            ['label' => 'Drop-ins', 'badge' => count($dropinRows), 'target' => '#plugins-dropins'],
            ['label' => 'Theme', 'target' => '#plugins-theme'],
        ]);
        $html .= '<div id="plugins-regular" class="profiler-tab-target">'
            . Html::table(['Name', 'Version', 'Status', 'File', 'Author'], $pluginRows)
            . '</div>';
        $html .= '<div id="plugins-mu" class="profiler-tab-target">'
            . Html::table(['Name', 'Version', 'File', 'Author'], $muRows)
            . '</div>';
        $html .= '<div id="plugins-dropins" class="profiler-tab-target">'
            . Html::table(['Name', 'File', 'Description'], $dropinRows)
            . '</div>';
        $html .= '<div id="plugins-theme" class="profiler-tab-target">'
            . Html::keyValueTable([
                'Name' => $this->stringValue($theme, 'name'),
                'Version' => $this->stringValue($theme, 'version'),
                'Stylesheet' => $this->stringValue($theme, 'stylesheet'),
                'Template' => $this->stringValue($theme, 'template'),
                'Child theme' => Html::dumpValue($this->boolValue($theme, 'child')),
                'Theme root' => $this->stringValue($theme, 'theme_root'),
                'Parent' => Html::dumpValue($theme['parent'] ?? []),
            ], 'Name', 'Value')
            . '</div>';

        return $this->panel(
            'plugins',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', count($this->activePluginRows($payload))),
        );
    }

    private function loadPluginApi(): void
    {
        if (function_exists('get_plugins') && function_exists('get_mu_plugins')) {
            return;
        }

        $file = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';

        if ($file !== '' && is_file($file)) {
            require_once $file;
        }
    }

    /**
     * @return list<string>
     */
    private function activePlugins(): array
    {
        $plugins = function_exists('get_option') ? get_option('active_plugins', []) : [];

        return $this->stringList($plugins);
    }

    /**
     * @return list<string>
     */
    private function networkPlugins(): array
    {
        $plugins = function_exists('get_site_option') ? get_site_option('active_sitewide_plugins', []) : [];

        return is_array($plugins) ? array_map('strval', array_keys($plugins)) : [];
    }

    /**
     * @return list<array{name: string, version: string, author: string, file: string, status: string}>
     */
    private function plugins(): array
    {
        if (!function_exists('get_plugins')) {
            return [];
        }

        $active = $this->activePlugins();
        $network = $this->networkPlugins();
        $rows = [];

        foreach (get_plugins() as $file => $data) {
            $status = 'inactive';

            if (in_array((string) $file, $network, true)) {
                $status = 'network active';
            } elseif (in_array((string) $file, $active, true)) {
                $status = 'active';
            }

            $rows[] = [
                'name' => $this->stringValue($data, 'Name', (string) $file),
                'version' => $this->stringValue($data, 'Version'),
                'author' => $this->stringValue($data, 'Author'),
                'file' => (string) $file,
                'status' => $status,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['status'], $left['name']] <=> [$right['status'], $right['name']]);

        return $rows;
    }

    /**
     * @return list<array{name: string, version: string, author: string, file: string}>
     */
    private function muPlugins(): array
    {
        if (!function_exists('get_mu_plugins')) {
            return [];
        }

        $rows = [];

        foreach (get_mu_plugins() as $file => $data) {
            $rows[] = [
                'name' => $this->stringValue($data, 'Name', (string) $file),
                'version' => $this->stringValue($data, 'Version'),
                'author' => $this->stringValue($data, 'Author'),
                'file' => (string) $file,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, file: string, description: string}>
     */
    private function dropins(): array
    {
        if (!function_exists('get_dropins')) {
            return [];
        }

        $rows = [];

        foreach (get_dropins() as $file => $data) {
            $rows[] = [
                'name' => $this->stringValue($data, 'Name', (string) $file),
                'file' => (string) $file,
                'description' => $this->stringValue($data, 'Description'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function theme(): array
    {
        if (!function_exists('wp_get_theme')) {
            return [];
        }

        $theme = wp_get_theme();
        $parent = $theme->parent();

        return [
            'name' => (string) $theme->get('Name'),
            'version' => (string) $theme->get('Version'),
            'stylesheet' => (string) $theme->get_stylesheet(),
            'template' => (string) $theme->get_template(),
            'theme_root' => (string) $theme->get_theme_root(),
            'child' => $parent !== false,
            'parent' => is_object($parent)
                ? [
                    'name' => (string) $parent->get('Name'),
                    'version' => (string) $parent->get('Version'),
                    'stylesheet' => (string) $parent->get_stylesheet(),
                ]
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, string>>
     */
    private function pluginRows(array $payload): array
    {
        return $this->rows($payload, 'plugins', ['name', 'version', 'author', 'file', 'status']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, string>>
     */
    private function activePluginRows(array $payload): array
    {
        return array_values(array_filter(
            $this->pluginRows($payload),
            static fn (array $plugin): bool => $plugin['status'] !== 'inactive',
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, string>>
     */
    private function muPluginRows(array $payload): array
    {
        return $this->rows($payload, 'mu_plugins', ['name', 'version', 'author', 'file']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, string>>
     */
    private function dropinRows(array $payload): array
    {
        return $this->rows($payload, 'dropins', ['name', 'file', 'description']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     * @return list<array<string, string>>
     */
    private function rows(array $payload, string $key, array $keys): array
    {
        $items = $payload[$key] ?? [];

        if (!is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($keys as $rowKey) {
                $row[$rowKey] = $this->stringValue($item, $rowKey);
            }

            $rows[] = $row;
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
