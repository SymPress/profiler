<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class CacheCollector extends AbstractCollector implements DataCollectorInterface
{
    public function key(): string
    {
        return 'cache';
    }

    public function label(): string
    {
        return 'Cache';
    }

    public function icon(): string
    {
        return 'cache';
    }

    public function collect(ProfileContext $context): array
    {
        $objectCache = $GLOBALS['wp_object_cache'] ?? null;
        $cacheStore = is_object($objectCache) && is_array($objectCache->cache ?? null)
            ? $objectCache->cache
            : [];

        return [
            'backend_class' => is_object($objectCache) ? $objectCache::class : '',
            'persistent' => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : false,
            'global_groups' => is_object($objectCache) && is_array($objectCache->global_groups ?? null)
                ? array_values($objectCache->global_groups)
                : [],
            'non_persistent_groups' => is_object($objectCache) && is_array($objectCache->non_persistent_groups ?? null)
                ? array_values($objectCache->non_persistent_groups)
                : [],
            'cache_entry_count' => $this->countCacheEntries($cacheStore),
            'groups' => $this->cacheGroups($cacheStore),
            'stats' => $this->readStats($objectCache),
            'nginx_cache' => $this->nginxCacheData(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock
    {
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];
        $hits = $this->intValue($stats, 'cache_hits');
        $misses = $this->intValue($stats, 'cache_misses');
        $reads = $hits + $misses;
        $hitRatio = $reads > 0 ? round(($hits / $reads) * 100) : 0;
        $backend = $this->stringValue($payload, 'backend_class');
        $nginxCache = $this->arrayPayload($payload, 'nginx_cache');
        $groupRows = [];

        if ($backend === '') {
            $backend = 'Object cache';
        }

        foreach ($this->groupRows($payload) as $group) {
            $groupRows[] = [
                $group['name'],
                $group['entries'],
                $this->formatBytes($group['bytes']),
                $group['global'] ? 'yes' : 'no',
                $group['non_persistent'] ? 'yes' : 'no',
            ];
        }

        $poolTabs = [
            ['label' => $backend, 'active' => true, 'target' => '#cache-object-pool'],
        ];

        if ($nginxCache !== []) {
            $poolTabs[] = ['label' => 'Nginx Cache', 'target' => '#cache-nginx-pool'];
        }

        $poolTabs[] = ['label' => 'Pools without calls', 'badge' => 0, 'disabled' => true];

        $html = '<h2>Cache</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Total calls', 'value' => (string) $reads],
            ['label' => 'Total hits', 'value' => (string) $hits],
            ['label' => 'Total misses', 'value' => (string) $misses],
            ['label' => 'Hits/reads', 'value' => $hitRatio . '%'],
            ['label' => 'Cache entries', 'value' => (string) $this->intValue($payload, 'cache_entry_count')],
        ]);
        $html .= Html::section('Pools', Html::tabNavigation($poolTabs));
        $html .= '<div id="cache-object-pool" class="profiler-tab-target">';
        $html .= '<h3>Adapter</h3><div class="card profiler-code-line">'
            . Html::escape($backend)
            . '</div>';
        $html .= Html::section('Metrics', Html::metricTiles([
            ['label' => 'Reads', 'value' => (string) $reads],
            ['label' => 'Hits', 'value' => (string) $hits],
            ['label' => 'Misses', 'value' => (string) $misses],
            ['label' => 'Hits/reads', 'value' => $hitRatio . '%'],
        ]));
        $html .= Html::section('Groups', Html::table(['Group', 'Entries', 'Approx. size', 'Global', 'Non-persistent'], $groupRows));
        $html .= Html::section('Calls', Html::keyValueTable([
            'Persistent object cache' => Html::dumpValue($this->boolValue($payload, 'persistent')),
            'Global groups' => Html::dumpValue($payload['global_groups'] ?? []),
            'Non-persistent groups' => Html::dumpValue($payload['non_persistent_groups'] ?? []),
            'Backend stats' => Html::dumpValue($stats),
        ], 'Call', 'Hit'));
        $html .= '</div>';

        if ($nginxCache !== []) {
            $html .= '<div id="cache-nginx-pool" class="profiler-tab-target">';
            $html .= $this->renderNginxCache($nginxCache);
            $html .= '</div>';
        }

        return $this->panel('cache', $this->label(), $this->icon(), $html);
    }

    /**
     * @param array<array-key, mixed> $cacheStore
     */
    private function countCacheEntries(array $cacheStore): int
    {
        $count = 0;

        foreach ($cacheStore as $group) {
            if (is_array($group)) {
                $count += count($group);
            }
        }

        return $count;
    }

    /**
     * @param array<array-key, mixed> $cacheStore
     * @return list<array{name: string, entries: int, bytes: int, global: bool, non_persistent: bool}>
     */
    private function cacheGroups(array $cacheStore): array
    {
        $groups = [];
        $objectCache = $GLOBALS['wp_object_cache'] ?? null;
        $globalGroups = is_object($objectCache) && is_array($objectCache->global_groups ?? null)
            ? $this->stringList($objectCache->global_groups)
            : [];
        $nonPersistentGroups = is_object($objectCache) && is_array($objectCache->non_persistent_groups ?? null)
            ? $this->stringList($objectCache->non_persistent_groups)
            : [];

        foreach ($cacheStore as $name => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            $bytes = 0;

            foreach ($entries as $value) {
                $bytes += strlen($this->serializeValue($value));
            }

            $groupName = (string) $name;
            $groups[] = [
                'name' => $groupName,
                'entries' => count($entries),
                'bytes' => $bytes,
                'global' => in_array($groupName, $globalGroups, true),
                'non_persistent' => in_array($groupName, $nonPersistentGroups, true),
            ];
        }

        usort($groups, static fn (array $left, array $right): int => $right['entries'] <=> $left['entries']);

        return array_slice($groups, 0, 80);
    }

    /**
     * @return array<string, scalar>
     */
    private function readStats(mixed $objectCache): array
    {
        if (!is_object($objectCache)) {
            return [];
        }

        $stats = [];

        foreach (['cache_hits', 'cache_misses', 'blog_prefix', 'multisite'] as $property) {
            if (!isset($objectCache->{$property})) {
                continue;
            }

            $value = $objectCache->{$property};

            if (is_scalar($value)) {
                $stats[$property] = $value;
            }
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private function nginxCacheData(): array
    {
        $pluginFile = 'nginx-cache/nginx-cache.php';

        if (!$this->nginxCachePluginExists($pluginFile) || !$this->nginxCachePluginActive($pluginFile)) {
            return [];
        }

        $path = function_exists('get_option') ? $this->stringFromMixed(get_option('nginx_cache_path', '')) : '';
        $pathStats = $this->nginxCachePathStats($path);

        return [
            'plugin' => $this->nginxCachePluginDetails($pluginFile),
            'path' => $path,
            'auto_purge' => function_exists('get_option') && (bool) get_option('nginx_auto_purge'),
            'purge_actions' => $this->nginxCachePurgeActions(),
            'admin_url' => function_exists('admin_url') ? admin_url('tools.php?page=nginx-cache') : '',
            ...$pathStats,
        ];
    }

    private function nginxCachePluginExists(string $pluginFile): bool
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return false;
        }

        return is_file(WP_PLUGIN_DIR . '/' . $pluginFile);
    }

    private function nginxCachePluginActive(string $pluginFile): bool
    {
        if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
            return true;
        }

        if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($pluginFile)) {
            return true;
        }

        $pluginApi = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';

        if ($pluginApi !== '' && is_file($pluginApi)) {
            require_once $pluginApi;
        }

        if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
            return true;
        }

        return function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($pluginFile);
    }

    /**
     * @return array{name: string, version: string, description: string, author: string}
     */
    private function nginxCachePluginDetails(string $pluginFile): array
    {
        $file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/' . $pluginFile : '';

        if ($file === '' || !is_file($file)) {
            return [
                'name' => 'Nginx Cache',
                'version' => '',
                'description' => '',
                'author' => '',
            ];
        }

        if (function_exists('get_plugin_data')) {
            $data = get_plugin_data($file, false, false);

            return [
                'name' => $this->stringFromMixed($data['Name']),
                'version' => $this->stringFromMixed($data['Version']),
                'description' => $this->stringFromMixed($data['Description']),
                'author' => $this->stringFromMixed($data['Author']),
            ];
        }

        return [
            'name' => 'Nginx Cache',
            'version' => '',
            'description' => '',
            'author' => '',
        ];
    }

    /**
     * @return list<string>
     */
    private function nginxCachePurgeActions(): array
    {
        $default = [
            'publish_phone',
            'save_post',
            'edit_post',
            'delete_post',
            'wp_trash_post',
            'clean_post_cache',
            'trackback_post',
            'pingback_post',
            'comment_post',
            'edit_comment',
            'delete_comment',
            'wp_set_comment_status',
            'switch_theme',
            'wp_update_nav_menu',
            'edit_user_profile_update',
        ];
        $actions = function_exists('apply_filters')
            ? apply_filters('nginx_cache_purge_actions', $default)
            : $default;

        if (!is_array($actions)) {
            return $default;
        }

        $normalized = [];

        foreach ($actions as $action) {
            if (is_scalar($action) || $action instanceof \Stringable) {
                $normalized[] = (string) $action;
            }
        }

        return $normalized;
    }

    /**
     * @return array{path_exists: bool, path_readable: bool, path_writable: bool, file_count: int, directory_count: int, size_bytes: int, scan_truncated: bool}
     */
    private function nginxCachePathStats(string $path): array
    {
        $stats = [
            'path_exists' => $path !== '' && is_dir($path),
            'path_readable' => $path !== '' && is_readable($path),
            'path_writable' => $path !== '' && is_writable($path),
            'file_count' => 0,
            'directory_count' => 0,
            'size_bytes' => 0,
            'scan_truncated' => false,
        ];

        if (!$stats['path_exists'] || !$stats['path_readable']) {
            return $stats;
        }

        $seen = 0;
        $limit = 10000;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo) {
                    continue;
                }

                ++$seen;

                if ($seen > $limit) {
                    $stats['scan_truncated'] = true;
                    break;
                }

                if ($item->isDir()) {
                    ++$stats['directory_count'];
                    continue;
                }

                if ($item->isFile()) {
                    ++$stats['file_count'];
                    $stats['size_bytes'] += max(0, $item->getSize());
                }
            }
        } catch (\UnexpectedValueException | \RuntimeException) {
            $stats['scan_truncated'] = true;
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderNginxCache(array $payload): string
    {
        $plugin = is_array($payload['plugin'] ?? null) ? $payload['plugin'] : [];
        $path = $this->stringValue($payload, 'path');
        $pathStatus = $this->boolValue($payload, 'path_exists') ? 'configured' : 'missing';

        $html = '<h3>Nginx Cache</h3>';
        $html .= Html::metricTiles([
            ['label' => 'Plugin', 'value' => $this->stringValue($plugin, 'version') !== '' ? 'v' . $this->stringValue($plugin, 'version') : 'active'],
            ['label' => 'Auto purge', 'value' => $this->boolValue($payload, 'auto_purge') ? 'enabled' : 'disabled'],
            ['label' => 'Cache zone', 'value' => $pathStatus],
            ['label' => 'Cached files', 'value' => (string) $this->intValue($payload, 'file_count')],
            ['label' => 'Cache size', 'value' => $this->formatBytes($this->intValue($payload, 'size_bytes'))],
        ]);
        $html .= Html::keyValueTable([
            'Plugin' => $this->stringValue($plugin, 'name', 'Nginx Cache'),
            'Description' => $this->stringValue($plugin, 'description'),
            'Cache Zone Path' => $path !== '' ? $path : 'not configured',
            'Path readable' => Html::dumpValue($this->boolValue($payload, 'path_readable')),
            'Path writable' => Html::dumpValue($this->boolValue($payload, 'path_writable')),
            'Directories' => $this->intValue($payload, 'directory_count'),
            'Scan truncated' => Html::dumpValue($this->boolValue($payload, 'scan_truncated')),
            'Purge actions' => Html::dumpValue($payload['purge_actions'] ?? []),
            'Settings' => $this->settingsLink($this->stringValue($payload, 'admin_url')),
        ], 'Setting', 'Value');

        return $html;
    }

    private function stringFromMixed(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function arrayPayload(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $itemKey => $itemValue) {
            if (is_string($itemKey)) {
                $normalized[$itemKey] = $itemValue;
            }
        }

        return $normalized;
    }

    private function settingsLink(string $url): mixed
    {
        return $url !== '' ? Html::link($url, 'Open Nginx Cache settings') : 'n/a';
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, entries: int, bytes: int, global: bool, non_persistent: bool}>
     */
    private function groupRows(array $payload): array
    {
        $groups = $payload['groups'] ?? [];

        if (!is_array($groups)) {
            return [];
        }

        $rows = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $rows[] = [
                'name' => $this->stringValue($group, 'name'),
                'entries' => $this->intValue($group, 'entries'),
                'bytes' => $this->intValue($group, 'bytes'),
                'global' => $this->boolValue($group, 'global'),
                'non_persistent' => $this->boolValue($group, 'non_persistent'),
            ];
        }

        return $rows;
    }

    private function serializeValue(mixed $value): string
    {
        if (function_exists('maybe_serialize')) {
            $serialized = maybe_serialize($value);

            return is_scalar($serialized) ? (string) $serialized : serialize($serialized);
        }

        return is_scalar($value) || $value === null ? (string) $value : serialize($value);
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GiB', $bytes / 1073741824);
        }

        if ($bytes >= 1048576) {
            return sprintf('%.2f MiB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.2f KiB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
