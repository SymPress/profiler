<?php

declare(strict_types=1);

namespace SymPress\Profiler\View;

use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class ToolbarRenderer
{
    public function __construct(
        private readonly WebProfilerAssets $assets,
        private readonly ProfilerUrlGenerator $urls,
    ) {
    }

    /**
     * @param list<ToolbarBlock> $blocks
     */
    public function renderBootstrap(ProfileRecord $profile, array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }

        return $this->assets->toolbarBootstrap(
            $this->renderContent($profile, $blocks),
            $profile->token,
            $this->urls->toolbarStylesheet(),
            $this->urls->toolbarPlaceholder(),
            $this->urls->home(),
            $this->urls->basePath(),
            $this->urls->absoluteBasePath(),
            $this->excludedAjaxPaths(),
        );
    }

    /**
     * @param list<ToolbarBlock> $blocks
     */
    public function renderContent(ProfileRecord $profile, array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }

        $itemsHtml = $this->symfonyEightItems($profile);

        if ($itemsHtml === []) {
            foreach ($blocks as $block) {
                $itemsHtml[] = $this->renderGenericBlock($profile, $block);
            }
        }

        return $this->renderContentWrapper(
            token: $profile->token,
            itemsHtml: $itemsHtml,
            closeIcon: $this->assets->icon('close'),
            toggleIcon: $this->assets->icon('wp'),
        );
    }

    /**
     * @return list<string>
     */
    private function symfonyEightItems(ProfileRecord $profile): array
    {
        $request = $profile->collector('request');
        $performance = $profile->collector('performance');

        if (!$this->hasSymfonyEightData($profile)) {
            return [];
        }

        return array_values(array_filter([
            $this->requestItem($profile),
            $this->ajaxItem($profile),
            $this->timeItem($profile, $performance),
            $this->memoryItem($profile, $request, $performance),
            $this->databaseItem($profile),
            $this->localizationItem($profile),
            $this->securityItem($profile),
            $this->templateItem($profile),
            $this->wordpressVersionItem($profile),
        ]));
    }

    private function hasSymfonyEightData(ProfileRecord $profile): bool
    {
        foreach (['request', 'performance', 'database', 'localization', 'security', 'template', 'kernel'] as $collector) {
            if ($profile->collector($collector) !== []) {
                return true;
            }
        }

        return false;
    }

    private function requestItem(ProfileRecord $profile): string
    {
        $request = $profile->collector('request');
        $statusCode = $this->intValue($request, 'status_code', 200);
        $status = $this->statusForResponseCode($statusCode);
        $routeLabel = $this->routeLabel($profile);
        $statusText = $this->stringValue($request, 'status_text', 'OK');
        $url = $this->stringValue($request, 'url', $this->stringValue($request, 'uri', '/'));
        $context = $this->stringValue($request, 'context', 'frontoffice');

        $iconHtml = sprintf(
            '<span class="sf-toolbar-status sf-toolbar-status-%1$s">%2$s</span><span class="sf-toolbar-request-icon">%3$s</span><span class="sf-toolbar-label">%4$s</span>',
            $this->escape($status),
            $this->escape((string) $statusCode),
            $this->assets->icon('referrer'),
            $this->escape($routeLabel),
        );

        return $this->renderItem(
            name: 'request',
            link: $this->panelUrl($profile, 'request'),
            token: $profile->token,
            accessibleLabel: 'Request',
            iconHtml: $iconHtml,
            infoHtml: $this->infoPieces(
                [
                    ['Response', sprintf('%d %s', $statusCode, $statusText)],
                    ['Method', $this->stringValue($request, 'method', 'GET')],
                    ['Context', $context],
                    ['Route', $routeLabel],
                    ['URL', $url],
                    ['Token', $profile->token],
                ],
                $this->panelUrl($profile, 'request'),
            ),
            status: $status,
            additionalClasses: 'sf-toolbar-block-request',
        );
    }

    private function ajaxItem(ProfileRecord $profile): string
    {
        $iconHtml = sprintf(
            '%s<span class="sf-toolbar-value sf-toolbar-ajax-request-counter">0</span><span class="sf-toolbar-label">AJAX</span>',
            $this->assets->icon('http-client'),
        );

        $infoHtml = '<div class="sf-toolbar-info-piece"><b class="sf-toolbar-ajax-info">0 AJAX requests</b><span>Tracked XMLHttpRequest and fetch calls for this page.</span></div>';
        $infoHtml .= '<div class="sf-toolbar-info-piece"><b>Actions</b><span><button type="button" class="sf-toolbar-ajax-clear">Clear</button></span></div>';
        $infoHtml .= '<table class="sf-toolbar-ajax-requests"><thead><tr><th>#</th><th>Profile</th><th>Method</th><th>Type</th><th>Status</th><th>URL</th><th>Time</th></tr></thead><tbody class="sf-toolbar-ajax-request-list"></tbody></table>';
        $infoHtml .= sprintf(
            '<div class="sf-toolbar-info-piece"><a href="%s">Open latest profile</a></div>',
            $this->escape($this->panelUrl($profile, 'request')),
        );

        return $this->renderItem(
            name: 'ajax',
            link: false,
            token: $profile->token,
            accessibleLabel: 'AJAX requests',
            iconHtml: $iconHtml,
            infoHtml: $infoHtml,
            status: 'normal',
            additionalClasses: 'sf-toolbar-block-ajax',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function timeItem(ProfileRecord $profile, array $payload): string
    {
        return $this->metricItem(
            name: 'time',
            token: $profile->token,
            label: 'Performance',
            value: $this->formatMilliseconds($this->floatValue($payload, 'total_duration_ms')),
            detailPieces: [
                ['Total execution time', $this->formatMilliseconds($this->floatValue($payload, 'total_duration_ms'))],
                ['Bootstrap', $this->formatMilliseconds($this->floatValue($payload, 'bootstrap_ms'))],
                ['Runtime', $this->formatMilliseconds($this->floatValue($payload, 'runtime_ms'))],
                ['Render', $this->formatMilliseconds($this->floatValue($payload, 'render_ms'))],
            ],
            link: $this->panelUrl($profile, 'performance'),
        );
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $performance
     */
    private function memoryItem(ProfileRecord $profile, array $request, array $performance): string
    {
        $peakMemory = $this->floatValue($performance, 'peak_memory_mb', $this->floatValue($request, 'peak_memory_mb'));

        return $this->metricItem(
            name: 'memory',
            token: $profile->token,
            label: 'Memory',
            value: $this->formatMemory($peakMemory),
            detailPieces: [
                ['Peak memory', $this->formatMemory($peakMemory)],
                ['Final memory', $this->formatMemory($this->floatValue($request, 'memory_mb'))],
            ],
            link: $this->panelUrl($profile, 'performance'),
        );
    }

    private function databaseItem(ProfileRecord $profile): string
    {
        $payload = $profile->collector('database');
        $count = $this->intValue($payload, 'count');
        $duration = $this->floatValue($payload, 'total_duration_ms');

        $iconHtml = sprintf(
            '%1$s<span class="sf-toolbar-value">%2$s</span><span class="sf-toolbar-class-separator">in</span><span class="sf-toolbar-label">%3$s</span>',
            $this->assets->icon('cache'),
            $this->escape((string) $count),
            $this->escape($this->formatMilliseconds($duration, 2)),
        );

        return $this->renderItem(
            name: 'database',
            link: $this->panelUrl($profile, 'database'),
            token: $profile->token,
            accessibleLabel: 'Database',
            iconHtml: $iconHtml,
            infoHtml: $this->infoPieces(
                [
                    ['Queries', (string) $count],
                    ['Total time', $this->formatMilliseconds($duration, 2)],
                    ['SAVEQUERIES', $this->boolValue($payload, 'enabled') ? 'enabled' : 'disabled'],
                    ['Last error', $this->stringValue($payload, 'last_error', 'none')],
                ],
                $this->panelUrl($profile, 'database'),
            ),
            status: $count > 50 || $duration > 100.0 ? 'yellow' : 'green',
        );
    }

    private function localizationItem(ProfileRecord $profile): string
    {
        $payload = $profile->collector('localization');
        $count = $this->intValue($payload, 'domain_count');
        $locale = $this->stringValue($payload, 'locale', 'n/a');

        $iconHtml = sprintf(
            '%1$s<span class="sf-toolbar-value">%2$s</span>',
            $this->assets->icon('locale'),
            $this->escape((string) $count),
        );

        return $this->renderItem(
            name: 'localization',
            link: $this->panelUrl($profile, 'localization'),
            token: $profile->token,
            accessibleLabel: 'Localization',
            iconHtml: $iconHtml,
            infoHtml: $this->infoPieces(
                [
                    ['Locale', $locale],
                    ['Text domains', (string) $count],
                    ['RTL', $this->boolValue($payload, 'is_rtl') ? 'yes' : 'no'],
                    ['Timezone', $this->stringValue($payload, 'timezone', 'n/a')],
                ],
                $this->panelUrl($profile, 'localization'),
            ),
            status: $count > 0 ? 'green' : 'normal',
        );
    }

    private function securityItem(ProfileRecord $profile): string
    {
        $payload = $profile->collector('security');
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $login = $this->stringValue($user, 'login', 'n/a');

        $iconHtml = sprintf(
            '%1$s<span class="sf-toolbar-value">%2$s</span>',
            $this->assets->icon('user'),
            $this->escape($login),
        );

        return $this->renderItem(
            name: 'security',
            link: $this->panelUrl($profile, 'security'),
            token: $profile->token,
            accessibleLabel: 'Security',
            iconHtml: $iconHtml,
            infoHtml: $this->infoPieces(
                [
                    ['Logged in', $this->boolValue($payload, 'logged_in') ? 'yes' : 'no'],
                    ['User', $login],
                    ['Roles', $this->stringListValue($user, 'roles')],
                    ['SSL', $this->boolValue($payload, 'ssl') ? 'yes' : 'no'],
                    ['Auth cookie', $this->boolValue($payload, 'has_auth_cookie') ? 'yes' : 'no'],
                ],
                $this->panelUrl($profile, 'security'),
            ),
            status: $this->boolValue($payload, 'logged_in') ? 'green' : 'normal',
        );
    }

    private function templateItem(ProfileRecord $profile): string
    {
        $payload = $profile->collector('template');
        $renderMs = $this->floatValue($payload, 'render_ms');
        $templateName = $this->stringValue($payload, 'template_name', 'n/a');

        $iconHtml = sprintf(
            '%1$s<span class="sf-toolbar-value">%2$s</span>',
            $this->assets->icon('template'),
            $this->escape($this->formatMilliseconds($renderMs, $renderMs >= 100.0 ? 0 : 1)),
        );

        return $this->renderItem(
            name: 'template',
            link: $this->panelUrl($profile, 'template'),
            token: $profile->token,
            accessibleLabel: 'Template',
            iconHtml: $iconHtml,
            infoHtml: $this->infoPieces(
                [
                    ['Template', $templateName],
                    ['Render time', $this->formatMilliseconds($renderMs)],
                    ['Path', $this->stringValue($payload, 'template', 'n/a')],
                ],
                $this->panelUrl($profile, 'template'),
            ),
            status: $renderMs > 100.0 ? 'yellow' : 'green',
        );
    }

    private function wordpressVersionItem(ProfileRecord $profile): string
    {
        $payload = $profile->collector('kernel');
        $wordpressVersion = $this->stringValue($payload, 'wordpress_version', 'n/a');

        $iconHtml = sprintf(
            '%1$s<span class="sf-toolbar-value">%2$s</span>',
            $this->assets->icon('wp'),
            $this->escape($wordpressVersion),
        );

        return $this->renderItem(
            name: 'runtime',
            link: false,
            token: $profile->token,
            accessibleLabel: 'WordPress Runtime',
            iconHtml: $iconHtml,
            infoHtml: $this->infoPieces(
                [
                    ['WordPress', $wordpressVersion],
                    ['Environment', $this->stringValue($payload, 'environment', 'unknown')],
                    ['Theme', $this->stringValue((array) ($payload['theme'] ?? []), 'name', 'n/a')],
                    ['PHP', $this->stringValue($payload, 'php_version', PHP_VERSION)],
                ],
                $this->panelUrl($profile, 'kernel'),
            ),
            status: 'normal',
            additionalClasses: 'sf-toolbar-block-right sf-toolbar-block-runtime',
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $detailPieces
     */
    private function metricItem(
        string $name,
        string $token,
        string $label,
        string $value,
        array $detailPieces,
        string $link,
    ): string {

        return $this->renderItem(
            name: $name,
            link: $link,
            token: $token,
            accessibleLabel: $label,
            iconHtml: sprintf('<span class="sf-toolbar-value">%s</span>', $this->escape($value)),
            infoHtml: $this->infoPieces($detailPieces, $link),
            status: 'normal',
        );
    }

    private function renderGenericBlock(ProfileRecord $profile, ToolbarBlock $block): string
    {
        return $this->renderItem(
            name: $block->id,
            link: $block->link,
            token: $profile->token,
            accessibleLabel: $block->label,
            iconHtml: sprintf(
                '%s<span class="sf-toolbar-value">%s</span>',
                $this->iconForCollector($block->id),
                $this->escape($block->value),
            ),
            infoHtml: $this->infoPieces(
                [
                    [$block->label, $block->value],
                    ['Details', $block->detail],
                ],
                $block->link,
            ),
            status: $this->status($block),
        );
    }

    private function renderItem(
        string $name,
        string|false $link,
        string $token,
        string $accessibleLabel,
        string $iconHtml,
        string $infoHtml,
        string $status,
        string $additionalClasses = '',
    ): string {

        $classes = trim(sprintf(
            'sf-toolbar-block sf-toolbar-block-%s sf-toolbar-status-%s %s',
            $this->escape($name),
            $this->escape($status),
            $this->escape($additionalClasses),
        ));

        if ($link === false) {
            return sprintf(
                '<div class="%1$s" data-accessible-label="%2$s"><div class="sf-toolbar-icon" aria-controls="sf-toolbar-info-%3$s-%4$s" aria-haspopup="dialog" aria-keyshortcuts="ArrowDown">%5$s</div><div class="sf-toolbar-info" id="sf-toolbar-info-%3$s-%4$s" role="dialog" aria-roledescription="details" aria-label="%2$s" tabindex="-1">%6$s</div></div>',
                $classes,
                $this->escape($accessibleLabel),
                $this->escape($name),
                $this->escape($token),
                $iconHtml,
                $infoHtml,
            );
        }

        return sprintf(
            '<div class="%1$s" data-accessible-label="%2$s"><a href="%3$s" aria-controls="sf-toolbar-info-%4$s-%5$s" aria-haspopup="dialog" aria-keyshortcuts="ArrowDown"><div class="sf-toolbar-icon">%6$s</div></a><div class="sf-toolbar-info" id="sf-toolbar-info-%4$s-%5$s" role="dialog" aria-roledescription="details" aria-label="%2$s" tabindex="-1">%7$s</div></div>',
            $classes,
            $this->escape($accessibleLabel),
            $this->escape($link),
            $this->escape($name),
            $this->escape($token),
            $iconHtml,
            $infoHtml,
        );
    }

    /**
     * @param list<string> $itemsHtml
     */
    private function renderContentWrapper(
        string $token,
        array $itemsHtml,
        string $closeIcon,
        string $toggleIcon,
    ): string {

        return sprintf(
            '<div id="sfToolbarClearer-%1$s" class="sf-toolbar-clearer"></div><div id="sfToolbarMainContent-%1$s" class="sf-toolbarreset notranslate clear-fix" data-no-turbolink data-turbo="false">%2$s<button class="sf-toolbar-toggle-button" type="button" id="sfToolbarToggleButton-%1$s" accesskey="D" aria-expanded="true" aria-controls="sfToolbarMainContent-%1$s" aria-label="Toggle Profiler Toolbar"><i class="sf-toolbar-icon-opened" title="Close Toolbar">%3$s</i><i class="sf-toolbar-icon-closed" title="Open Toolbar">%4$s</i></button></div>',
            $this->escape($token),
            implode('', $itemsHtml),
            $closeIcon,
            $toggleIcon,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $pieces
     */
    private function infoPieces(array $pieces, ?string $link = null): string
    {
        $html = '';

        foreach ($pieces as [$title, $value]) {
            $html .= sprintf(
                '<div class="sf-toolbar-info-piece"><b>%1$s</b><span>%2$s</span></div>',
                $this->escape($title),
                $this->escape($value),
            );
        }

        if (is_string($link) && $link !== '') {
            $html .= sprintf(
                '<div class="sf-toolbar-info-piece"><a href="%s">Open panel</a></div>',
                $this->escape($link),
            );
        }

        return $html;
    }

    private function routeLabel(ProfileRecord $profile): string
    {
        $routing = $profile->collector('routing');
        $conditionals = is_array($routing['conditionals'] ?? null) ? $routing['conditionals'] : [];

        if (($conditionals['is_front_page'] ?? false) === true || ($conditionals['is_home'] ?? false) === true) {
            return '@homepage';
        }

        $queriedObject = is_array($routing['queried_object'] ?? null) ? $routing['queried_object'] : [];

        foreach (['name', 'title'] as $key) {
            $value = $this->stringValue($queriedObject, $key);

            if ($value !== '') {
                return '@' . $this->normalizeRouteToken($value);
            }
        }

        $request = $this->stringValue($routing, 'request');

        if ($request !== '') {
            return '@' . $this->normalizeRouteToken($request);
        }

        $uri = $this->stringValue($profile->collector('request'), 'uri', '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        if ($path === '/' || $path === '') {
            return '@homepage';
        }

        return '@' . $this->normalizeRouteToken(trim($path, '/'));
    }

    private function normalizeRouteToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'request';
    }

    private function panelUrl(ProfileRecord $profile, string $panel): string
    {
        $profilerUrl = $profile->meta['profiler_url'] ?? '#';
        $baseUrl = is_string($profilerUrl) ? $profilerUrl : '#';

        return $baseUrl === '#'
            ? '#'
            : preg_replace('/([?&]panel=)[^&]+/', '$1' . rawurlencode($panel), $baseUrl, 1) ?? $baseUrl;
    }

    private function iconForCollector(string $collector): string
    {
        return match ($collector) {
            'request', 'routing' => $this->assets->icon('request'),
            'performance' => $this->assets->icon('performance'),
            'database', 'cache' => $this->assets->icon('cache'),
            'hooks' => $this->assets->icon('event'),
            'kernel' => $this->assets->icon('config'),
            'localization' => $this->assets->icon('locale'),
            'security' => $this->assets->icon('user'),
            'template' => $this->assets->icon('template'),
            default => $this->assets->icon('wp'),
        };
    }

    private function status(ToolbarBlock $block): string
    {
        return match ($block->accent) {
            'red' => 'red',
            'yellow', 'orange' => 'yellow',
            'green' => 'green',
            default => 'normal',
        };
    }

    private function statusForResponseCode(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 500 => 'red',
            $statusCode >= 300 => 'yellow',
            default => 'green',
        };
    }

    private function formatMilliseconds(float $duration, int $precision = 0): string
    {
        if ($duration <= 0.0) {
            return '0 ms';
        }

        return sprintf('%s ms', number_format($duration, $precision, '.', ''));
    }

    private function formatMemory(float $memory): string
    {
        if ($memory <= 0.0) {
            return '0.0 MiB';
        }

        return sprintf('%s MiB', number_format($memory, 1, '.', ''));
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function stringValue(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? $default;

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function intValue(array $payload, string $key, int $default = 0): int
    {
        $value = $payload[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function floatValue(array $payload, string $key, float $default = 0.0): float
    {
        $value = $payload[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function boolValue(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function stringListValue(array $payload, string $key): string
    {
        $values = $payload[$key] ?? [];

        if (!is_array($values) || $values === []) {
            return 'n/a';
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) || $value instanceof \Stringable
                ? (string) $value
                : '',
            $values,
        )));

        return $normalized === [] ? 'n/a' : implode(', ', $normalized);
    }

    private function excludedAjaxPaths(): string
    {
        $basePath = $this->urls->basePath();
        $prefix = $basePath !== '' ? $basePath . '/' : '/';

        return '^' . preg_quote($prefix, '#') . '(?:_wdt|_profiler)';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
