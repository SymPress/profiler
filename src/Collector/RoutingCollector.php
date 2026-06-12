<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class RoutingCollector extends AbstractCollector implements DataCollectorInterface
{
    public function getKey(): string
    {
        return 'routing';
    }

    public function getLabel(): string
    {
        return 'Routing';
    }

    public function getIcon(): string
    {
        return 'routing';
    }

    public function collect(ProfileContext $context): array
    {
        global $wp;

        $conditionals = [];

        foreach (
            [
                'is_home',
                'is_front_page',
                'is_singular',
                'is_page',
                'is_single',
                'is_archive',
                'is_category',
                'is_tag',
                'is_search',
                'is_404',
                'is_feed',
                'is_paged',
                'is_preview',
            ] as $conditional
        ) {
            $conditionals[$conditional] = (bool) $conditional();
        }

        return [
            'request'        => is_object($wp) ? $this->mixedToString($wp->request ?? '') : '',
            'matched_rule'   => is_object($wp) ? $this->mixedToString($wp->matched_rule ?? '') : '',
            'matched_query'  => is_object($wp) ? $this->mixedToString($wp->matched_query ?? '') : '',
            'query_vars'     => is_object($wp) && is_array($wp->query_vars ?? null) ? $wp->query_vars : [],
            'template'       => $context->template(),
            'queried_object' => $this->queriedObjectSummary(),
            'conditionals'   => $conditionals,
            'rewrite'        => $this->rewriteSummary(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock
    {
        return null;
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $requestPath = $this->stringValue($payload, 'request');
        $matchedRule = $this->stringValue($payload, 'matched_rule');
        $matchedQuery = $this->stringValue($payload, 'matched_query');
        $routeLabel = $matchedRule !== '' ? $matchedRule : ($requestPath !== '' ? $requestPath : '/');
        $queryVars = is_array($payload['query_vars'] ?? null) ? $payload['query_vars'] : [];
        $routeRows = [];

        foreach ($queryVars as $name => $value) {
            $routeRows[] = [(string) $name, Html::dumpValue($value)];
        }

        $logRows = [
            [1, 'WordPress request', $requestPath !== '' ? '/' . ltrim($requestPath, '/') : '/', $matchedRule !== '' ? 'Route matches!' : 'Path resolved without a rewrite rule'],
        ];

        if ($matchedRule !== '') {
            $logRows[] = [2, 'Matched rewrite rule', $matchedRule, $matchedQuery];
        }

        $html = '<h2>Routing</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Matched route', 'value' => $routeLabel],
        ]);
        $html .= Html::section('Route Parameters', Html::table(['Name', 'Value'], $routeRows));
        $html .= Html::section('Route Matching Logs', '<div class="card"><strong>Path to match:</strong> '
            . Html::escape($requestPath !== '' ? '/' . ltrim($requestPath, '/') : '/')
            . '</div>'
            . Html::table(['#', 'Route name', 'Path', 'Log'], $logRows));
        $html .= Html::section('Queried Object', Html::codeBlock($payload['queried_object'] ?? []));
        $html .= Html::section('Conditionals', Html::codeBlock($payload['conditionals'] ?? []));
        $html .= Html::section('Rewrite', Html::codeBlock($payload['rewrite'] ?? []));

        return $this->panel('routing', $this->getLabel(), $this->getIcon(), $html);
    }

    /** @return array<string, mixed> */
    private function queriedObjectSummary(): array
    {
        if (!function_exists('get_queried_object')) {
            return [];
        }

        $object = get_queried_object();

        if (!is_object($object)) {
            return [];
        }

        return array_filter([
            'class' => $object::class,
            'id'    => $object->ID ?? $object->term_id ?? $object->user_id ?? null,
            'name'  => $object->post_name ?? $object->slug ?? $object->user_login ?? '',
            'title' => $object->post_title ?? $object->name ?? '',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    private function rewriteSummary(): array
    {
        $rewrite = $GLOBALS['wp_rewrite'] ?? null;

        if (!is_object($rewrite)) {
            return [];
        }

        return [
            'permalink_structure'  => $rewrite->permalink_structure ?? '',
            'root'                 => $rewrite->root ?? '',
            'use_trailing_slashes' => $rewrite->use_trailing_slashes ?? false,
            'front'                => $rewrite->front ?? '',
        ];
    }

    private function mixedToString(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
