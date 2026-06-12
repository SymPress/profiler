<?php

declare(strict_types=1);

namespace SymPress\Profiler\View;

use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use SymPress\Profiler\Value\ToolbarBlock;

final class ProfilerPageRenderer
{
    public function __construct(
        private readonly PhpViewRenderer $views,
        private readonly WebProfilerAssets $assets,
        private readonly ProfilerUrlGenerator $urls,
    ) {
    }

    /**
     * @param list<ToolbarBlock> $blocks
     * @param list<CollectorPanel> $panels
     */
    public function renderProfile(ProfileRecord $profile, array $blocks, array $panels, string $selectedPanel): string
    {
        $selected = $this->resolveSelectedPanel($panels, $selectedPanel);
        $menuItems = $this->menuItems($profile, $panels, $blocks, $selected->id);
        $bodyHtml = $this->views->render(
            'profiler/layout',
            [
                'header_html'   => $this->views->render('profiler/header', [
                    'home_url'      => $this->urls->home(),
                    'search_url'    => $this->urls->search(),
                    'search_query'  => '',
                    'profiler_icon' => $this->assets->icon('symfony'),
                ]),
                'summary_html'  => $this->views->render('profiler/summary', [
                    'profile'       => $profile,
                    'status_class'  => $this->statusClass($this->statusCode($profile)),
                    'status_code'   => $this->statusCode($profile),
                    'status_text'   => $this->statusText($this->statusCode($profile)),
                    'alert_icon'    => $this->assets->icon('alert-circle'),
                    'redirect_icon' => $this->assets->icon('redirect'),
                    'referrer_icon' => $this->assets->icon('referrer'),
                ]),
                'menu_items'    => $menuItems,
                'settings_html' => $this->views->render('profiler/settings', [
                    'settings_icon'              => $this->assets->icon('settings'),
                    'settings_theme_system_icon' => $this->assets->icon('settings-theme-system'),
                    'settings_theme_light_icon'  => $this->assets->icon('settings-theme-light'),
                    'settings_theme_dark_icon'   => $this->assets->icon('settings-theme-dark'),
                    'settings_width_fixed_icon'  => $this->assets->icon('settings-width-fixed'),
                    'settings_width_fitted_icon' => $this->assets->icon('settings-width-fitted'),
                ]),
                'content_html'  => $selected->html,
                'base_js'       => $this->assets->baseJs(),
                'shortcuts'     => [
                    [
                        'label' => 'Search profiles',
                        'url'   => $this->urls->search(),
                        'icon'  => $this->assets->icon('search'),
                    ],
                    [
                        'label' => 'Latest',
                        'url'   => $this->urls->latest(),
                        'icon'  => '',
                    ],
                ],
            ],
        );

        return $this->renderBase('Profiler', $bodyHtml);
    }

    /** @param list<ProfileRecord> $profiles */
    public function renderResults(array $profiles, ProfileSearchCriteria $criteria, ?string $missingToken = null): string
    {
        $resultsHtml = $this->resultsHtml($profiles, $criteria, $missingToken);
        $bodyHtml = $this->views->render('profiler/layout', [
            'header_html'   => $this->headerHtml($criteria->text),
            'summary_html'  => '<div class="status"><h2>Profile Search</h2><p>Search and inspect recorded requests.</p></div>',
            'menu_items'    => [],
            'settings_html' => $this->settingsHtml(),
            'content_html'  => $resultsHtml,
            'base_js'       => $this->assets->baseJs(),
            'shortcuts'     => $this->shortcuts(),
        ]);

        return $this->renderBase('Profiler', $bodyHtml);
    }

    /**
     * @param list<CollectorPanel> $panels
     * @param list<ToolbarBlock> $blocks
     * @return list<array{id: string, label: string, link: string, metric: string, icon: string, selected: bool, enabled: bool}>
     */
    private function menuItems(ProfileRecord $profile, array $panels, array $blocks, string $selectedPanel): array
    {
        $blockMetrics = [];

        foreach ($blocks as $block) {
            $blockMetrics[$block->id] = $block->value;
        }

        $panelsById = [];

        foreach ($panels as $panel) {
            $panelsById[$panel->id] = $panel;
        }

        $items = [];

        foreach ($this->nativeMenuOrder() as $id => $menu) {
            $panel = $panelsById[$id] ?? null;

            if (!$panel instanceof CollectorPanel && !($menu['placeholder'] ?? false)) {
                continue;
            }

            $label = $menu['label'];
            $icon = $menu['icon'];
            $enabled = $panel instanceof CollectorPanel && $panel->enabled;
            $metric = $panel instanceof CollectorPanel
                ? ($panel->metric !== '' ? $panel->metric : ($blockMetrics[$panel->id] ?? ''))
                : '';

            $items[] = [
                'id'       => $id,
                'label'    => $label,
                'link'     => $panel instanceof CollectorPanel ? $this->urls->profile($profile->token, $panel->id) : '#',
                'metric'   => $metric,
                'icon'     => $this->assets->icon($icon),
                'selected' => $id === $selectedPanel,
                'enabled'  => $enabled,
            ];

            unset($panelsById[$id]);
        }

        foreach ($panelsById as $panel) {
            $items[] = [
                'id'       => $panel->id,
                'label'    => $this->nativePanelLabel($panel->id, $panel->title),
                'link'     => $this->urls->profile($profile->token, $panel->id),
                'metric'   => $panel->metric !== '' ? $panel->metric : ($blockMetrics[$panel->id] ?? ''),
                'icon'     => $this->assets->icon($panel->icon),
                'selected' => $panel->id === $selectedPanel,
                'enabled'  => $panel->enabled,
            ];
        }

        return $items;
    }

    /** @return array<string, array{label: string, icon: string, placeholder?: bool}> */
    private function nativeMenuOrder(): array
    {
        return [
            'request'         => ['label' => 'Request / Response', 'icon' => 'request'],
            'performance'     => ['label' => 'Performance', 'icon' => 'performance'],
            'logs'            => ['label' => 'Logs', 'icon' => 'logger'],
            'hooks'           => ['label' => 'Events', 'icon' => 'event'],
            'routing'         => ['label' => 'Routing', 'icon' => 'routing'],
            'wp_query'        => ['label' => 'WordPress Query', 'icon' => 'routing'],
            'rest_ajax'       => ['label' => 'REST / AJAX', 'icon' => 'http-client'],
            'cache'           => ['label' => 'Cache', 'icon' => 'cache'],
            'options'         => ['label' => 'Options', 'icon' => 'config'],
            'localization'    => ['label' => 'Translation', 'icon' => 'translation'],
            'template'        => ['label' => 'Templates', 'icon' => 'template'],
            'blocks'          => ['label' => 'Blocks', 'icon' => 'twig-components'],
            'assets'          => ['label' => 'Assets', 'icon' => 'template'],
            'database'        => ['label' => 'Doctrine', 'icon' => 'database'],
            'plugins'         => ['label' => 'Plugins / Theme', 'icon' => 'wordpress'],
            'cron'            => ['label' => 'Cron', 'icon' => 'event'],
            'kernel'          => ['label' => 'Configuration', 'icon' => 'config'],
            'validator'       => ['label' => 'Validator', 'icon' => 'validator', 'placeholder' => true],
            'forms'           => ['label' => 'Forms', 'icon' => 'forms', 'placeholder' => true],
            'exceptions'      => ['label' => 'Exception', 'icon' => 'alert-circle'],
            'security'        => ['label' => 'Security', 'icon' => 'user'],
            'twig_components' => ['label' => 'Template Components', 'icon' => 'twig-components', 'placeholder' => true],
            'http_client'     => ['label' => 'HTTP Client', 'icon' => 'http-client'],
            'debug'           => ['label' => 'Debug', 'icon' => 'debug', 'placeholder' => true],
            'emails'          => ['label' => 'Emails', 'icon' => 'emails', 'placeholder' => true],
        ];
    }

    private function nativePanelLabel(string $id, string $fallback): string
    {
        return $this->nativeMenuOrder()[$id]['label'] ?? $fallback;
    }

    /** @param list<CollectorPanel> $panels */
    private function resolveSelectedPanel(array $panels, string $selectedPanel): CollectorPanel
    {
        foreach ($panels as $panel) {
            if ($panel->id === $selectedPanel) {
                return $panel;
            }
        }

        return $panels[0] ?? new CollectorPanel(
            'request',
            'Request / Response',
            'request',
            '<div class="empty"><p>No panel data available.</p></div>',
        );
    }

    private function renderBase(string $title, string $bodyHtml): string
    {
        return $this->views->render(
            'profiler/base',
            [
                'title'        => $title,
                'profiler_css' => $this->assets->profilerCss($this->urls->font('JetBrainsMono.woff2')),
                'body_html'    => $bodyHtml,
            ],
        );
    }

    private function headerHtml(string $searchQuery = ''): string
    {
        return $this->views->render('profiler/header', [
            'home_url'      => $this->urls->home(),
            'search_url'    => $this->urls->search(),
            'search_query'  => $searchQuery,
            'profiler_icon' => $this->assets->icon('symfony'),
        ]);
    }

    private function settingsHtml(): string
    {
        return $this->views->render('profiler/settings', [
            'settings_icon'              => $this->assets->icon('settings'),
            'settings_theme_system_icon' => $this->assets->icon('settings-theme-system'),
            'settings_theme_light_icon'  => $this->assets->icon('settings-theme-light'),
            'settings_theme_dark_icon'   => $this->assets->icon('settings-theme-dark'),
            'settings_width_fixed_icon'  => $this->assets->icon('settings-width-fixed'),
            'settings_width_fitted_icon' => $this->assets->icon('settings-width-fitted'),
        ]);
    }

    /** @param list<ProfileRecord> $profiles */
    private function resultsHtml(array $profiles, ProfileSearchCriteria $criteria, ?string $missingToken): string
    {
        return $this->views->render('profiler/results_panel', [
            'profiles'        => $profiles,
            'criteria'        => $criteria,
            'missing_token'   => $missingToken,
            'search_icon'     => $this->assets->icon('search'),
            'search_url'      => $this->urls->search(),
            'latest_url'      => $this->urls->latest(),
            'last_url'        => $this->urls->last(),
            'context_options' => $this->contextOptions(),
        ]);
    }

    /** @return list<string> */
    private function contextOptions(): array
    {
        return ['ajax', 'backoffice', 'core', 'frontoffice', 'login', 'rest'];
    }

    /** @return list<array{label: string, url: string, icon: string}> */
    private function shortcuts(): array
    {
        return [
            [
                'label' => 'Search profiles',
                'url'   => $this->urls->search(),
                'icon'  => $this->assets->icon('search'),
            ],
            [
                'label' => 'Latest',
                'url'   => $this->urls->latest(),
                'icon'  => '',
            ],
        ];
    }

    private function statusCode(ProfileRecord $profile): int
    {
        $statusCode = $profile->meta['status_code'] ?? 200;

        return is_numeric($statusCode) ? (int) $statusCode : 200;
    }

    private function statusClass(int $statusCode): string
    {
        return $statusCode >= 400
            ? 'status-error'
            : ($statusCode >= 300 ? 'status-warning' : 'status-success');
    }

    private function statusText(int $statusCode): string
    {
        if (function_exists('get_status_header_desc')) {
            $description = (string) get_status_header_desc($statusCode);

            if ($description !== '') {
                return $description;
            }
        }

        return match ($statusCode) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown',
        };
    }
}
