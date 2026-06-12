<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Support\HtmlString;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class HookCollector extends AbstractCollector implements DataCollectorInterface
{
    private const int MAX_HOOKS = 100;

    public function getKey(): string
    {
        return 'hooks';
    }

    public function getLabel(): string
    {
        return 'Events';
    }

    public function getIcon(): string
    {
        return 'event';
    }

    public function collect(ProfileContext $context): array
    {
        $actions = [];
        $currentStack = [];
        $firedOrder = [];
        $registeredHooks = (array) ($GLOBALS['wp_filter'] ?? []);

        foreach ((array) ($GLOBALS['wp_actions'] ?? []) as $hook => $count) {
            $hookObject = $registeredHooks[$hook] ?? null;
            $listenerCount = 0;
            $priorityCount = 0;

            if (is_object($hookObject) && isset($hookObject->callbacks) && is_array($hookObject->callbacks)) {
                $priorityCount = count($hookObject->callbacks);

                foreach ($hookObject->callbacks as $callbacks) {
                    if (!is_array($callbacks)) {
                        continue;
                    }

                    $listenerCount += count($callbacks);
                }
            }

            $actions[] = [
                'hook'       => (string) $hook,
                'count'      => is_numeric($count) ? (int) $count : 0,
                'listeners'  => $listenerCount,
                'priorities' => $priorityCount,
            ];
            $firedOrder[] = (string) $hook;
        }

        usort(
            $actions,
            static function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];

                if ($countComparison !== 0) {
                    return $countComparison;
                }

                return $left['hook'] <=> $right['hook'];
            },
        );

        foreach ((array) ($GLOBALS['wp_current_filter'] ?? []) as $filter) {
            if (!is_scalar($filter) && !($filter instanceof \Stringable)) {
                continue;
            }

            $currentStack[] = (string) $filter;
        }

        return [
            'fired_count'      => count((array) ($GLOBALS['wp_actions'] ?? [])),
            'registered_count' => count($registeredHooks),
            'top_hooks'        => array_slice($actions, 0, self::MAX_HOOKS),
            'called_listeners' => $this->calledListeners($registeredHooks),
            'fired_order'      => array_slice($firedOrder, 0, self::MAX_HOOKS),
            'current_stack'    => $currentStack,
            'captured_at'      => $context->finishedAtIso(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $topHooks = $this->topHooks($payload);
        $topHook = $topHooks[0]['hook'] ?? 'n/a';

        return new ToolbarBlock(
            'hooks',
            'Events',
            sprintf('%d fired', $this->intValue($payload, 'fired_count')),
            sprintf('Top: %s', $topHook),
            $this->profileUrl($profile, 'hooks'),
            'orange',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $rows = [];

        foreach ($this->topHooks($payload) as $hook) {
            $rows[] = [
                $hook['priorities'],
                new HtmlString(sprintf(
                    '<strong>%s</strong><br><span class="text-muted">%d listener(s), fired %d time(s)</span>',
                    Html::escape($hook['hook']),
                    $hook['listeners'],
                    $hook['count'],
                )),
            ];
        }

        $listenerRows = [];

        foreach ($this->calledListenerRows($payload) as $listener) {
            $listenerRows[] = [
                $listener['hook'],
                $listener['priority'],
                $listener['callback'],
                $listener['origin'],
                $listener['file'] !== '' ? Html::codeCell($listener['file']) : 'n/a',
            ];
        }

        $html = '<h2>Dispatched Events</h2>';
        $html .= Html::tabNavigation([
            ['label' => 'wordpress_hooks', 'active' => true],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Called Listeners', 'badge' => count($listenerRows), 'active' => true, 'target' => '#hooks-called-listeners'],
            ['label' => 'Not Called Listeners', 'badge' => 0, 'target' => '#hooks-not-called-listeners'],
            ['label' => 'Orphaned Events', 'badge' => 0, 'target' => '#hooks-orphaned-events'],
        ]);
        $html .= '<div id="hooks-called-listeners" class="profiler-tab-target">';
        $html .= Html::section('Listener Details', Html::table(['Hook', 'Priority', 'Callback', 'Origin', 'File'], $listenerRows));
        $html .= Html::section('Fired Hook Summary', Html::table(['Priority', 'Listener'], $rows));
        $html .= '</div>';
        $html .= '<div id="hooks-not-called-listeners" class="profiler-tab-target">'
            . Html::emptyPanel('No not called listeners were captured during this request.')
            . '</div>';
        $html .= '<div id="hooks-orphaned-events" class="profiler-tab-target">'
            . Html::emptyPanel('No orphaned events were captured during this request.')
            . '</div>';
        $html .= Html::section('Hook Order', Html::codeBlock($payload['fired_order'] ?? []));
        $html .= Html::section('Current Filter Stack', Html::codeBlock($this->currentStack($payload)));

        return $this->panel(
            'hooks',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'fired_count')),
        );
    }

    /**
     * @param array<array-key, mixed> $registeredHooks
     * @return list<array{hook: string, priority: int, callback: string, origin: string, file: string}>
     */
    private function calledListeners(array $registeredHooks): array
    {
        $firedHooks = array_keys((array) ($GLOBALS['wp_actions'] ?? []));
        $rows = [];

        foreach ($firedHooks as $hook) {
            $hookObject = $registeredHooks[$hook] ?? null;

            if (!is_object($hookObject) || !isset($hookObject->callbacks) || !is_array($hookObject->callbacks)) {
                continue;
            }

            foreach ($hookObject->callbacks as $priority => $callbacks) {
                if (!is_array($callbacks)) {
                    continue;
                }

                foreach ($callbacks as $callback) {
                    if (!is_array($callback)) {
                        continue;
                    }

                    $function = $callback['function'] ?? null;
                    $file = $this->callbackFile($function);
                    $rows[] = [
                        'hook'     => (string) $hook,
                        'priority' => is_numeric($priority) ? (int) $priority : 0,
                        'callback' => $this->callbackName($function),
                        'origin'   => $this->originFromFile($file),
                        'file'     => $file,
                    ];

                    if (count($rows) >= 250) {
                        return $rows;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{hook: string, count: int, listeners: int, priorities: int}>
     */
    private function topHooks(array $payload): array
    {
        $topHooks = $payload['top_hooks'] ?? [];

        if (!is_array($topHooks)) {
            return [];
        }

        $normalized = [];

        foreach ($topHooks as $hook) {
            if (!is_array($hook)) {
                continue;
            }

            $normalized[] = [
                'hook'       => $this->stringValue($hook, 'hook'),
                'count'      => $this->intValue($hook, 'count'),
                'listeners'  => $this->intValue($hook, 'listeners'),
                'priorities' => $this->intValue($hook, 'priorities'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function currentStack(array $payload): array
    {
        $stack = $payload['current_stack'] ?? [];

        if (!is_array($stack)) {
            return [];
        }

        $normalized = [];

        foreach ($stack as $value) {
            if (!is_scalar($value) && !($value instanceof \Stringable)) {
                continue;
            }

            $normalized[] = (string) $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{hook: string, priority: int, callback: string, origin: string, file: string}>
     */
    private function calledListenerRows(array $payload): array
    {
        $listeners = $payload['called_listeners'] ?? [];

        if (!is_array($listeners)) {
            return [];
        }

        $rows = [];

        foreach ($listeners as $listener) {
            if (!is_array($listener)) {
                continue;
            }

            $rows[] = [
                'hook'     => $this->stringValue($listener, 'hook'),
                'priority' => $this->intValue($listener, 'priority'),
                'callback' => $this->stringValue($listener, 'callback'),
                'origin'   => $this->stringValue($listener, 'origin', 'unknown'),
                'file'     => $this->stringValue($listener, 'file'),
            ];
        }

        return $rows;
    }

    private function callbackName(mixed $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if ($callback instanceof \Closure) {
            return 'Closure';
        }

        if (is_array($callback) && count($callback) === 2) {
            $target = $callback[0] ?? '';
            $method = $callback[1] ?? '';

            if ((!is_object($target) && !is_string($target)) || (!is_scalar($method) && !$method instanceof \Stringable)) {
                return 'unknown';
            }

            $class = is_object($target) ? $target::class : $target;

            return $class . '::' . (string) $method;
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return $callback::class . '::__invoke';
        }

        return 'unknown';
    }

    private function callbackFile(mixed $callback): string
    {
        try {
            if (is_string($callback) && function_exists($callback)) {
                $reflection = new \ReflectionFunction($callback);

                return (string) $reflection->getFileName();
            }

            if ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);

                return (string) $reflection->getFileName();
            }

            if (is_array($callback) && count($callback) === 2) {
                $target = $callback[0] ?? null;
                $method = $callback[1] ?? null;

                if ((!is_object($target) && !is_string($target)) || (!is_scalar($method) && !$method instanceof \Stringable)) {
                    return '';
                }

                $reflection = new \ReflectionMethod($target, (string) $method);

                return (string) $reflection->getFileName();
            }

            if (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');

                return (string) $reflection->getFileName();
            }
        } catch (\ReflectionException) {
            return '';
        }

        return '';
    }

    private function originFromFile(string $file): string
    {
        if ($file === '') {
            return 'unknown';
        }

        $normalized = str_replace('\\', '/', $file);
        $muPluginDir = defined('WPMU_PLUGIN_DIR') ? str_replace('\\', '/', (string) WPMU_PLUGIN_DIR) : '';
        $pluginDir = defined('WP_PLUGIN_DIR') ? str_replace('\\', '/', (string) WP_PLUGIN_DIR) : '';
        $themeRoot = function_exists('get_theme_root') ? str_replace('\\', '/', (string) get_theme_root()) : '';
        $abspath = defined('ABSPATH') ? str_replace('\\', '/', (string) ABSPATH) : '';

        if ($muPluginDir !== '' && str_starts_with($normalized, $muPluginDir)) {
            return 'mu-plugin';
        }

        if ($pluginDir !== '' && str_starts_with($normalized, $pluginDir)) {
            return 'plugin';
        }

        if ($themeRoot !== '' && str_starts_with($normalized, $themeRoot)) {
            return 'theme';
        }

        if ($abspath !== '' && str_starts_with($normalized, $abspath)) {
            return 'core';
        }

        return 'project';
    }
}
