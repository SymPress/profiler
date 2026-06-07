<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\KernelInterface;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class KernelCollector extends AbstractCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly SiteConfig $config,
        private readonly WpContext $context,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function key(): string
    {
        return 'kernel';
    }

    public function label(): string
    {
        return 'Configuration';
    }

    public function icon(): string
    {
        return 'config';
    }

    public function collect(ProfileContext $context): array
    {
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $bundles = [];

        foreach ($this->kernel->discoverBundles()->all() as $bundle) {
            $bundles[] = [
                'package' => $bundle->package(),
                'type' => $bundle->type(),
                'entry' => $bundle->entry(),
                'bundle' => $bundle->bundle()::class,
            ];
        }

        return [
            'environment' => $this->config->env(),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'script_debug' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
            'wordpress_version' => $this->wordpressVersion(),
            'context' => $this->contextLabel(),
            'project_dir' => $this->kernel->getProjectDir(),
            'cache_dir' => $this->kernel->getCacheDir(),
            'php_version' => PHP_VERSION,
            'home_url' => function_exists('home_url') ? (string) home_url('/') : '',
            'site_url' => function_exists('site_url') ? (string) site_url('/') : '',
            'app_booted' => App::container() !== null,
            'container_class' => App::container() !== null ? App::container()::class : '',
            'theme' => [
                'name' => is_object($theme) ? (string) $theme->get('Name') : '',
                'version' => is_object($theme) ? (string) $theme->get('Version') : '',
                'stylesheet' => is_object($theme) ? (string) $theme->get_stylesheet() : '',
            ],
            'locale' => function_exists('get_locale') ? (string) get_locale() : '',
            'multisite' => function_exists('is_multisite') ? is_multisite() : false,
            'included_files_count' => count(get_included_files()),
            'included_files' => array_slice(get_included_files(), -80),
            'bundles' => $bundles,
            'captured_at' => $context->finishedAtIso(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $bundleCount = count((array) ($payload['bundles'] ?? []));
        $environment = $this->stringValue($payload, 'environment', 'unknown');

        return new ToolbarBlock(
            'kernel',
            'Configuration',
            sprintf('%d bundles', $bundleCount),
            sprintf('%s · %s', $environment, $this->stringValue($payload, 'context', 'core')),
            $this->profileUrl($profile, 'kernel'),
            'green',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $bundleRows = [];

        foreach ($this->bundles($payload) as $bundle) {
            $bundleRows[] = [
                $bundle['package'],
                $bundle['bundle'],
            ];
        }

        $runner = $this->shortClassName($this->stringValue($payload, 'container_class'));

        if ($runner === '') {
            $runner = 'WordPress Kernel';
        }

        $html = '<h2>Symfony Configuration</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Runner', 'value' => $runner],
        ]);
        $html .= Html::metricTiles([
            ['label' => 'WordPress version', 'value' => $this->stringValue($payload, 'wordpress_version', 'n/a')],
            ['label' => 'Environment', 'value' => $this->stringValue($payload, 'environment', 'n/a')],
            ['label' => 'Debug', 'value' => $this->boolValue($payload, 'debug') ? 'enabled' : 'disabled'],
        ]);
        $html .= Html::section('PHP Configuration', Html::metricTiles([
            ['label' => 'PHP version', 'value' => $this->stringValue($payload, 'php_version', 'n/a')],
            ['label' => 'Intl locale', 'value' => $this->stringValue($payload, 'locale', 'n/a')],
            ['label' => 'Context', 'value' => $this->stringValue($payload, 'context', 'n/a')],
        ]));
        $html .= '<p><a href="#">View full PHP configuration</a></p>';
        $html .= Html::section('Runtime', Html::keyValueTable([
            'home_url' => Html::dumpValue($this->stringValue($payload, 'home_url')),
            'site_url' => Html::dumpValue($this->stringValue($payload, 'site_url')),
            'project_dir' => Html::dumpValue($this->stringValue($payload, 'project_dir')),
            'cache_dir' => Html::dumpValue($this->stringValue($payload, 'cache_dir')),
            'multisite' => Html::dumpValue($this->boolValue($payload, 'multisite')),
            'app_booted' => Html::dumpValue($this->boolValue($payload, 'app_booted')),
            'included_files' => Html::dumpValue($this->intValue($payload, 'included_files_count')),
        ], 'Name', 'Value'));
        $html .= Html::section('Theme', Html::codeBlock($payload['theme'] ?? []));
        $html .= Html::section('Enabled Bundles (' . count($bundleRows) . ')', Html::table(['Name', 'Class'], $bundleRows));
        $html .= Html::section('Recently Included Files', Html::codeBlock($payload['included_files'] ?? []));

        return $this->panel(
            'kernel',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', count($this->bundles($payload))),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{package: string, type: string, entry: string, bundle: string}>
     */
    private function bundles(array $payload): array
    {
        $bundles = $payload['bundles'] ?? [];

        if (!is_array($bundles)) {
            return [];
        }

        $normalized = [];

        foreach ($bundles as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }

            $normalized[] = [
                'package' => $this->stringValue($bundle, 'package'),
                'type' => $this->stringValue($bundle, 'type'),
                'entry' => $this->stringValue($bundle, 'entry'),
                'bundle' => $this->stringValue($bundle, 'bundle'),
            ];
        }

        return $normalized;
    }

    private function shortClassName(string $className): string
    {
        $position = strrpos($className, '\\');

        return $position === false ? $className : substr($className, $position + 1);
    }

    private function contextLabel(): string
    {
        return match (true) {
            $this->context->isAjax() => WpContext::AJAX,
            $this->context->isRest() => WpContext::REST,
            $this->context->isBackoffice() => WpContext::BACKOFFICE,
            $this->context->isFrontoffice() => WpContext::FRONTOFFICE,
            $this->context->isLogin() => WpContext::LOGIN,
            default => WpContext::CORE,
        };
    }

    private function wordpressVersion(): string
    {
        if (function_exists('get_bloginfo')) {
            $version = (string) get_bloginfo('version');

            if ($version !== '') {
                return $version;
            }
        }

        $version = $GLOBALS['wp_version'] ?? '';

        return is_scalar($version) || $version instanceof \Stringable
            ? (string) $version
            : '';
    }
}
