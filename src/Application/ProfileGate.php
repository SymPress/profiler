<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;

final class ProfileGate
{
    private const string FILTER_ENABLE_OUTSIDE_DEVELOPMENT = 'profiler.enable_outside_development';
    private const string FILTER_COLLECT = 'profiler.collect';
    private const string FILTER_COLLECT_PARAMETER = 'profiler.collect_parameter';
    private const string FILTER_TOOLBAR_AJAX_REPLACE = 'profiler.toolbar_ajax_replace';

    public function __construct(
        private readonly SiteConfig $config,
        private readonly WpContext $context,
        private readonly ProfilerRequestMatcher $matcher,
        private readonly bool $collect = true,
        private readonly string $collectParameter = '',
        private readonly bool $toolbarAjaxReplace = false,
    ) {
    }

    public function canAccessProfiler(): bool
    {
        if ($this->context->isWpCli()) {
            return false;
        }

        // Environment first: on a closed production site this runs on every request at plugins_loaded -1000,
        // and resolving the user that early would skip determine_current_user filters plugins add later.
        return ($this->isDevelopmentEnvironment() || $this->canRunOutsideDevelopment())
            && $this->currentUserCanManageOptions();
    }

    public function shouldCollect(): bool
    {
        if (
            $this->isAuditUserAgent()
            || $this->isProfilerPageRequest()
            || $this->isBackgroundNoise()
            || $this->context->isCron()
            || $this->context->isWpCli()
            || $this->context->isXmlRpc()
            || $this->context->isInstalling()
            || $this->context->isBackoffice()
            || $this->context->isLogin()
        ) {
            return false;
        }

        if (!$this->canAccessProfiler()) {
            return false;
        }

        if ($this->collectionEnabledByDefault()) {
            return true;
        }

        return $this->hasCollectParameter();
    }

    public function shouldInjectToolbar(): bool
    {
        if (!$this->shouldCollect()) {
            return false;
        }

        if (!$this->canAccessProfiler()) {
            return false;
        }

        if (!$this->context->is(WpContext::FRONTOFFICE)) {
            return false;
        }

        $method = strtoupper($this->serverValue('REQUEST_METHOD', 'GET'));

        if ($method !== 'GET') {
            return false;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return false;
        }

        // Only browser page navigations ask for HTML. fetch, XHR and curl send */*; buffering those would hold
        // streamed responses and downloads in memory until the request ends.
        $accept = $this->serverValue('HTTP_ACCEPT');

        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }

    public function shouldReplaceToolbarAfterAjax(): bool
    {
        $enabled = $this->toolbarAjaxReplace;

        if (function_exists('apply_filters')) {
            $enabled = apply_filters(self::FILTER_TOOLBAR_AJAX_REPLACE, $enabled);
        }

        return $enabled === true;
    }

    private function isDevelopmentEnvironment(): bool
    {
        return $this->config->envIs(EnvConfig::LOCAL)
            || $this->config->envIs(EnvConfig::DEVELOPMENT);
    }

    private function isAuditUserAgent(): bool
    {
        return preg_match(
            '/(Chrome-Lighthouse|HeadlessChrome|Lighthouse|PageSpeed|WebPageTest)/i',
            $this->serverValue('HTTP_USER_AGENT'),
        ) === 1;
    }

    /** Requests browsers and wp-admin send on their own; each would cost a full profile. */
    private function isBackgroundNoise(): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs before WordPress URL helpers are safe to use.
        $path = (string) parse_url($this->serverValue('REQUEST_URI'), PHP_URL_PATH);

        if (str_ends_with($path, '/favicon.ico')) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Read-only comparison with a literal; changes no state.
        return $this->context->isAjax() && ($_REQUEST['action'] ?? '') === 'heartbeat';
    }

    private function canRunOutsideDevelopment(): bool
    {
        $enabled = false;

        if (function_exists('apply_filters')) {
            $enabled = apply_filters(self::FILTER_ENABLE_OUTSIDE_DEVELOPMENT, false, $this->config->env());
        }

        return $enabled === true;
    }

    private function collectionEnabledByDefault(): bool
    {
        $enabled = $this->collect;

        if (function_exists('apply_filters')) {
            $enabled = apply_filters(self::FILTER_COLLECT, $enabled);
        }

        return $enabled === true;
    }

    private function hasCollectParameter(): bool
    {
        $parameter = trim($this->resolvedCollectParameter());

        if ($parameter === '') {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Presence only controls gated profiling for this request; no site state is changed.
        foreach ([$_GET, $_POST] as $input) {
            if (array_key_exists($parameter, $input)) {
                return true;
            }
        }

        return false;
    }

    private function resolvedCollectParameter(): string
    {
        $parameter = $this->collectParameter;

        if (function_exists('apply_filters')) {
            $parameter = apply_filters(self::FILTER_COLLECT_PARAMETER, $parameter);
        }

        return is_scalar($parameter) || $parameter instanceof \Stringable
            ? (string) $parameter
            : '';
    }

    private function currentUserCanManageOptions(): bool
    {
        if (!function_exists('current_user_can') || !function_exists('wp_get_current_user')) {
            return false;
        }

        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        // One store holds the profiles (cookies included) of every site in the network.
        return !function_exists('is_multisite') || !is_multisite() || is_super_admin();
    }

    private function isProfilerPageRequest(): bool
    {
        return $this->matcher->isInternalProfilerRequest();
    }

    private function serverValue(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $_SERVER)) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized centrally below.
        $value = $_SERVER[$key];

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return $default;
        }

        $string = (string) $value;
        $unslashed = function_exists('wp_unslash') ? wp_unslash($string) : $string;

        return function_exists('sanitize_text_field')
            ? sanitize_text_field($unslashed)
            : $unslashed;
    }
}
