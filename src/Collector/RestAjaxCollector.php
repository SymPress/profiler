<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class RestAjaxCollector extends AbstractCollector implements DataCollectorInterface
{
    public function getKey(): string
    {
        return 'rest_ajax';
    }

    public function getLabel(): string
    {
        return 'REST / AJAX';
    }

    public function getIcon(): string
    {
        return 'http-client';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $route = $this->restRoute();

        return [
            'is_rest'           => function_exists('wp_is_serving_rest_request')
                ? (bool) wp_is_serving_rest_request()
                : (defined('REST_REQUEST') && REST_REQUEST),
            'is_json'           => function_exists('wp_is_json_request') && (bool) wp_is_json_request(),
            'is_ajax'           => function_exists('wp_doing_ajax') && (bool) wp_doing_ajax(),
            'rest_route'        => $route,
            'rest_namespace'    => $this->routeNamespace($route),
            'ajax_action'       => $this->requestValue('action'),
            'method'            => $this->serverValue('REQUEST_METHOD', 'GET'),
            'registered_routes' => $this->registeredRestRoutes($route),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $label = $this->boolValue($payload, 'is_rest')
            ? $this->stringValue($payload, 'rest_route', 'REST')
            : ($this->boolValue($payload, 'is_ajax') ? $this->stringValue($payload, 'ajax_action', 'AJAX') : 'web');

        return new ToolbarBlock(
            'rest_ajax',
            'REST / AJAX',
            $label,
            $this->stringValue($payload, 'method', 'GET'),
            $this->profileUrl($profile, 'rest_ajax'),
            $this->boolValue($payload, 'is_rest') || $this->boolValue($payload, 'is_ajax') ? 'cyan' : 'green',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $routeRows = [];

        foreach ($this->routeRows($payload) as $route) {
            $routeRows[] = [
                $route['route'],
                implode(', ', $route['methods']),
                $route['namespace'],
                $route['matched'] ? 'yes' : 'no',
            ];
        }

        $html = '<h2>REST / AJAX</h2>';
        $html .= Html::metricTiles([
            ['label' => 'REST request', 'value' => $this->boolValue($payload, 'is_rest') ? 'yes' : 'no'],
            ['label' => 'AJAX request', 'value' => $this->boolValue($payload, 'is_ajax') ? 'yes' : 'no'],
            ['label' => 'JSON request', 'value' => $this->boolValue($payload, 'is_json') ? 'yes' : 'no'],
            ['label' => 'REST routes', 'value' => (string) count($routeRows)],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Request', 'active' => true, 'target' => '#rest-ajax-request'],
            ['label' => 'REST Routes', 'badge' => count($routeRows), 'target' => '#rest-ajax-routes'],
        ]);
        $html .= '<div id="rest-ajax-request" class="profiler-tab-target">';
        $html .= Html::keyValueTable([
            'Method'         => $this->stringValue($payload, 'method'),
            'REST route'     => Html::dumpValue($this->stringValue($payload, 'rest_route')),
            'REST namespace' => Html::dumpValue($this->stringValue($payload, 'rest_namespace')),
            'AJAX action'    => Html::dumpValue($this->stringValue($payload, 'ajax_action')),
            'REST request'   => Html::dumpValue($this->boolValue($payload, 'is_rest')),
            'AJAX request'   => Html::dumpValue($this->boolValue($payload, 'is_ajax')),
        ], 'Name', 'Value');
        $html .= '</div>';
        $html .= '<div id="rest-ajax-routes" class="profiler-tab-target">'
            . Html::table(['Route', 'Methods', 'Namespace', 'Matched'], $routeRows)
            . '</div>';

        return $this->panel(
            'rest_ajax',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            $this->boolValue($payload, 'is_rest') ? 'REST' : ($this->boolValue($payload, 'is_ajax') ? 'AJAX' : ''),
        );
    }

    /** @return list<array{route: string, namespace: string, methods: list<string>, matched: bool}> */
    private function registeredRestRoutes(string $currentRoute): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }

        $server = rest_get_server();

        $routes = $server->get_routes();

        $rows = [];

        foreach ($routes as $route => $handlers) {
            $route = (string) $route;
            $methods = [];

            if (is_array($handlers)) {
                foreach ($handlers as $handler) {
                    if (!is_array($handler) || !is_array($handler['methods'] ?? null)) {
                        continue;
                    }

                    $methods = array_merge($methods, array_map('strval', array_keys($handler['methods'])));
                }
            }

            $rows[] = [
                'route'     => $route,
                'namespace' => $this->routeNamespace($route),
                'methods'   => array_values(array_unique($methods)),
                'matched'   => $currentRoute !== '' && $this->routeMatches($route, $currentRoute),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $right['matched'] <=> $left['matched'] ?: $left['route'] <=> $right['route']);

        return array_slice($rows, 0, 120);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{route: string, namespace: string, methods: list<string>, matched: bool}>
     */
    private function routeRows(array $payload): array
    {
        $routes = $payload['registered_routes'] ?? [];

        if (!is_array($routes)) {
            return [];
        }

        $rows = [];

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $methods = $route['methods'] ?? [];
            $rows[] = [
                'route'     => $this->stringValue($route, 'route'),
                'namespace' => $this->stringValue($route, 'namespace'),
                'methods'   => $this->stringList($methods),
                'matched'   => $this->boolValue($route, 'matched'),
            ];
        }

        return $rows;
    }

    private function restRoute(): string
    {
        global $wp;

        if (is_object($wp) && is_array($wp->query_vars ?? null) && isset($wp->query_vars['rest_route'])) {
            return $this->normalizeRoute($wp->query_vars['rest_route']);
        }

        $route = $this->requestValue('rest_route');

        if ($route !== '') {
            return $this->normalizeRoute($route);
        }

        $path = (string) (parse_url($this->serverValue('REQUEST_URI'), PHP_URL_PATH) ?? '');
        $prefix = function_exists('rest_get_url_prefix') ? '/' . trim(rest_get_url_prefix(), '/') . '/' : '/wp-json/';
        $position = strpos($path, $prefix);

        if ($position === false) {
            return '';
        }

        return $this->normalizeRoute(substr($path, $position + strlen($prefix)));
    }

    private function routeMatches(string $registeredRoute, string $currentRoute): bool
    {
        $currentRoute = '/' . trim($currentRoute, '/');

        if ($registeredRoute === $currentRoute) {
            return true;
        }

        return @preg_match('~^' . $registeredRoute . '$~', $currentRoute) === 1;
    }

    private function normalizeRoute(mixed $route): string
    {
        if (!is_scalar($route) && !$route instanceof \Stringable) {
            return '';
        }

        $route = trim((string) $route);

        return $route !== '' ? '/' . trim($route, '/') : '';
    }

    private function routeNamespace(string $route): string
    {
        $parts = explode('/', trim($route, '/'));

        return trim($parts[0], '/');
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

    private function requestValue(string $key): string
    {
        if (!array_key_exists($key, $_REQUEST)) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only profiler snapshot.
        $value = $_REQUEST[$key];

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $string = (string) $value;
        $unslashed = function_exists('wp_unslash') ? wp_unslash($string) : $string;

        return function_exists('sanitize_text_field') ? sanitize_text_field($unslashed) : $unslashed;
    }

    private function serverValue(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $_SERVER)) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only profiler snapshot.
        $value = $_SERVER[$key];

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $default;
    }
}
