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

        if ($this->isDevelopmentEnvironment()) {
            return true;
        }

        return $this->canRunOutsideDevelopment() && $this->currentUserCanManageOptions();
    }

    public function shouldCollect(): bool
    {
        if (
            $this->isAuditUserAgent()
            || $this->isProfilerPageRequest()
            || $this->context->isCron()
            || $this->context->isWpCli()
            || $this->context->isXmlRpc()
            || $this->context->isInstalling()
            || $this->context->isBackoffice()
            || $this->context->isLogin()
        ) {
            return false;
        }

        if (!$this->isDevelopmentEnvironment() && !$this->canRunOutsideDevelopment()) {
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

        $accept = $this->serverValue('HTTP_ACCEPT');

        if ($accept === '') {
            return true;
        }

        return str_contains($accept, 'text/html')
            || str_contains($accept, 'application/xhtml+xml')
            || str_contains($accept, '*/*');
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
        if ($this->serverValue('HTTP_X_NEWSROOM_AUDIT') === '1') {
            return true;
        }

        if (str_contains($this->serverValue('QUERY_STRING'), 'newsroom_audit=1')) {
            return true;
        }

        return preg_match(
            '/(Chrome-Lighthouse|HeadlessChrome|Lighthouse|PageSpeed|WebPageTest)/i',
            $this->serverValue('HTTP_USER_AGENT'),
        ) === 1;
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

        return current_user_can('manage_options');
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
