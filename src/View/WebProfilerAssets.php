<?php

declare(strict_types=1);

namespace SymPress\Profiler\View;

final class WebProfilerAssets
{
    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function icon(string $name): string
    {
        $normalized = preg_replace('/[^a-z0-9_-]/i', '', $name) ?: '';

        if ($normalized === '') {
            return '';
        }

        return $this->cached('icon:' . $normalized, fn (): string => $this->contents('views/Icon/' . $normalized . '.svg'));
    }

    public function profilerCss(string $fontUrl): string
    {
        return $this->cached(
            'profiler-css:' . $fontUrl,
            function () use ($fontUrl): string {
                $css = $this->contents('views/Profiler/profiler.css.twig');
                $css = str_replace(
                    "{{ url('_profiler_font', {fontName: 'JetBrainsMono'}) }}",
                    $fontUrl,
                    $css,
                );

                return $this->cleanTwigComments($css)
                    . "\n\n"
                    . $this->contents('views/Profiler/wordpress-profiler.css');
            },
        );
    }

    public function toolbarCss(): string
    {
        return $this->cached(
            'toolbar-css',
            fn (): string => $this->cleanTwigComments($this->contents('views/Profiler/toolbar.css.twig')),
        );
    }

    public function baseJs(): string
    {
        return $this->cached(
            'base-js',
            function (): string {
                $script = $this->extractScriptBody($this->contents('views/Profiler/base_js.html.twig'));

                return str_replace('SymfonyProfiler', 'ProfilerApp', $script) . "\n\n" . $this->tabNavigationJs();
            },
        );
    }

    public function toolbarBootstrap(
        string $toolbarHtml,
        string $token,
        string $stylesheetUrl,
        string $wdtUrlPlaceholder,
        string $profilerHomeUrl,
        string $basePath,
        string $absoluteBasePath,
        string $excludedAjaxPaths,
    ): string {

        $template = $this->contents('views/Profiler/toolbar_js.html.twig');
        $template = preg_replace(
            "~\\{\\{\\s*include\\('@WebProfiler/Profiler/toolbar\\.html\\.twig'.*?\\)\\s*\\}\\}~s",
            $toolbarHtml,
            $template,
            1,
        ) ?? $template;
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- returned as profiler bootstrap HTML, not a theme stylesheet.
        $stylesheetLink = '<link rel="stylesheet" href="' . htmlspecialchars($stylesheetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- template placeholder sourced from the upstream bundle.
        $stylesheetPlaceholder = '<link rel="stylesheet"{% if csp_style_nonce %} nonce="{{ csp_style_nonce }}"{% endif %} href="{{ url(\'_wdt_stylesheet\') }}" />';

        $replacements = [
            '<div id="sfwdt{{ token }}">' => '<div id="sfwdt' . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">',
            $stylesheetPlaceholder => $stylesheetLink,
            '<script{% if csp_script_nonce is defined and csp_script_nonce %} nonce="{{ csp_script_nonce }}"{% endif %}>/*<![CDATA[*/' => '<script>/*<![CDATA[*/',
            '{{ excluded_ajax_paths|json_encode|raw }}' => json_encode($excludedAjaxPaths, JSON_THROW_ON_ERROR),
            "{{ request.basePath|e('js') }}" => $this->escapeJs($basePath),
            '{{ request.basePath|length }}' => (string) strlen($basePath),
            "{{ (request.schemeAndHttpHost ~ request.basePath)|e('js') }}" => $this->escapeJs($absoluteBasePath),
            '{{ (request.schemeAndHttpHost ~ request.basePath)|length }}' => (string) strlen($absoluteBasePath),
            "{{ url('_wdt', {token: 'xxxxxx'})|escape('js') }}" => $this->escapeJs($wdtUrlPlaceholder),
            "{{ url('_profiler_home')|escape('js') }}" => $this->escapeJs($profilerHomeUrl),
            '{{ token }}' => $token,
        ];

        $template = str_replace(array_keys($replacements), array_values($replacements), $template);
        $template = str_replace(['{% if excluded_ajax_paths is defined %}', '{% endif %}'], '', $template);
        $template = str_replace(
            ['Symfony Web Debug Toolbar', 'START of Symfony Web Debug Toolbar', 'END of Symfony Web Debug Toolbar'],
            ['Profiler Toolbar', 'START of Profiler Toolbar', 'END of Profiler Toolbar'],
            $template,
        );

        return $this->cleanTwigComments($template);
    }

    public function fontPath(string $fontName): ?string
    {
        $normalized = preg_replace('/[^A-Za-z0-9._-]/', '', $fontName) ?: '';

        if ($normalized === '') {
            return null;
        }

        foreach ($this->resourceCandidates('fonts/' . $normalized) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param callable(): string $factory
     */
    private function cached(string $key, callable $factory): string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $factory();
        $this->cache[$key] = $value;

        return $value;
    }

    private function contents(string $relativePath): string
    {
        $path = $this->resourcePath($relativePath);

        if (!is_file($path)) {
            throw new \RuntimeException('Requested bundle asset was not found.');
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException('Requested bundle asset could not be read.');
        }

        return $contents;
    }

    private function resourcePath(string $relativePath): string
    {
        foreach ($this->resourceCandidates($relativePath) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return dirname(__DIR__, 2) . '/resources/symfony/web-profiler-bundle/' . ltrim($relativePath, '/');
    }

    /**
     * @return list<string>
     */
    private function resourceCandidates(string $relativePath): array
    {
        $normalized = ltrim($relativePath, '/');

        return [
            dirname(__DIR__, 2) . '/resources/profiler/' . $normalized,
            dirname(__DIR__, 2) . '/resources/symfony/web-profiler-bundle/' . $normalized,
        ];
    }

    private function cleanTwigComments(string $contents): string
    {
        return trim((string) (preg_replace('/\{#.*?#\}/s', '', $contents) ?? $contents));
    }

    private function extractScriptBody(string $contents): string
    {
        if (preg_match('~<script[^>]*>(.*)</script>~s', $contents, $matches) !== 1) {
            return trim($contents);
        }

        return trim($this->cleanTwigComments($matches[1]));
    }

    private function tabNavigationJs(): string
    {
        return <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tab-navigation').forEach(function (navigation) {
        if (navigation.closest('.sf-tabs') || navigation.dataset.tabNavigationProcessed === 'true') {
            return;
        }

        const controls = Array.from(navigation.querySelectorAll(':scope > .tab-control'));
        const targetSelectors = controls
            .map(function (control) { return control.getAttribute('data-tab-target') || ''; })
            .filter(Boolean);

        const targetPanels = targetSelectors
            .map(function (selector) { return document.querySelector(selector); })
            .filter(Boolean);

        function activate(control) {
            if (control.disabled || control.classList.contains('disabled')) {
                return;
            }

            controls.forEach(function (item) {
                item.classList.toggle('active', item === control);
                item.setAttribute('aria-selected', item === control ? 'true' : 'false');
                item.tabIndex = item === control ? 0 : -1;
            });

            if (targetPanels.length === 0) {
                return;
            }

            targetPanels.forEach(function (panel) {
                panel.hidden = true;
            });

            const target = control.getAttribute('data-tab-target');
            const panel = target ? document.querySelector(target) : null;

            if (panel) {
                panel.hidden = false;
            }
        }

        controls.forEach(function (control) {
            control.addEventListener('click', function (event) {
                event.preventDefault();
                activate(control);
            });
        });

        const initialControl = controls.find(function (control) {
            return control.classList.contains('active') && !control.disabled && !control.classList.contains('disabled');
        }) || controls.find(function (control) {
            return !control.disabled && !control.classList.contains('disabled');
        });

        if (initialControl) {
            activate(initialControl);
        }

        navigation.dataset.tabNavigationProcessed = 'true';
    });
});
JS;
    }

    private function escapeJs(string $value): string
    {
        return substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1);
    }
}
