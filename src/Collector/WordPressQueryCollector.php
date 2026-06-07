<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class WordPressQueryCollector extends AbstractCollector implements DataCollectorInterface
{
    public function key(): string
    {
        return 'wp_query';
    }

    public function label(): string
    {
        return 'WordPress Query';
    }

    public function icon(): string
    {
        return 'routing';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $query = $GLOBALS['wp_query'] ?? null;
        $wp = $GLOBALS['wp'] ?? null;

        return [
            'request' => is_object($wp) ? $this->stringFromMixed($wp->request ?? '') : '',
            'matched_rule' => is_object($wp) ? $this->stringFromMixed($wp->matched_rule ?? '') : '',
            'matched_query' => is_object($wp) ? $this->stringFromMixed($wp->matched_query ?? '') : '',
            'query_vars' => is_object($query) && is_array($query->query_vars ?? null) ? $query->query_vars : [],
            'request_sql' => is_object($query) ? $this->stringFromMixed($query->request ?? '') : '',
            'post_count' => is_object($query) && is_numeric($query->post_count ?? null) ? (int) $query->post_count : 0,
            'found_posts' => is_object($query) && is_numeric($query->found_posts ?? null) ? (int) $query->found_posts : 0,
            'max_num_pages' => is_object($query) && is_numeric($query->max_num_pages ?? null) ? (int) $query->max_num_pages : 0,
            'is_main_query' => is_object($query) && method_exists($query, 'is_main_query') ? (bool) $query->is_main_query() : false,
            'queried_object' => $this->queriedObjectSummary(),
            'conditionals' => $this->conditionals(),
            'posts' => $this->posts($query),
            'pagination' => [
                'paged' => $this->intFromMixed(function_exists('get_query_var') ? get_query_var('paged') : 0),
                'page' => $this->intFromMixed(function_exists('get_query_var') ? get_query_var('page') : 0),
                'posts_per_page' => $this->intFromMixed(function_exists('get_query_var') ? get_query_var('posts_per_page') : 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $foundPosts = $this->intValue($payload, 'found_posts');
        $postCount = $this->intValue($payload, 'post_count');

        return new ToolbarBlock(
            'wp_query',
            'WordPress Query',
            sprintf('%d posts', $postCount),
            $foundPosts > 0 ? sprintf('%d found', $foundPosts) : 'no pagination total',
            $this->profileUrl($profile, 'wp_query'),
            'cyan',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $queryVars = is_array($payload['query_vars'] ?? null) ? $payload['query_vars'] : [];
        $queryRows = [];

        foreach ($queryVars as $name => $value) {
            $queryRows[] = [(string) $name, Html::dumpValue($value)];
        }

        $conditionRows = [];

        foreach ((array) ($payload['conditionals'] ?? []) as $name => $value) {
            $conditionRows[] = [(string) $name, Html::dumpValue((bool) $value)];
        }

        $postRows = [];

        foreach ($this->postRows($payload) as $post) {
            $postRows[] = [$post['id'], $post['type'], $post['status'], $post['title'], $post['name']];
        }

        $html = '<h2>WordPress Query</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Posts returned', 'value' => (string) $this->intValue($payload, 'post_count')],
            ['label' => 'Found posts', 'value' => (string) $this->intValue($payload, 'found_posts')],
            ['label' => 'Max pages', 'value' => (string) $this->intValue($payload, 'max_num_pages')],
            ['label' => 'Main query', 'value' => $this->boolValue($payload, 'is_main_query') ? 'yes' : 'no'],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Overview', 'active' => true, 'target' => '#wp-query-overview'],
            ['label' => 'Query Vars', 'badge' => count($queryRows), 'target' => '#wp-query-vars'],
            ['label' => 'Conditionals', 'target' => '#wp-query-conditionals'],
            ['label' => 'Posts', 'badge' => count($postRows), 'target' => '#wp-query-posts'],
            ['label' => 'SQL', 'target' => '#wp-query-sql'],
        ]);
        $html .= '<div id="wp-query-overview" class="profiler-tab-target">';
        $html .= Html::keyValueTable([
            'Request' => Html::dumpValue($this->stringValue($payload, 'request', '/')),
            'Matched rule' => Html::dumpValue($this->stringValue($payload, 'matched_rule')),
            'Matched query' => Html::dumpValue($this->stringValue($payload, 'matched_query')),
            'Queried object' => Html::dumpValue($payload['queried_object'] ?? []),
            'Pagination' => Html::dumpValue($payload['pagination'] ?? []),
        ], 'Name', 'Value');
        $html .= '</div>';
        $html .= '<div id="wp-query-vars" class="profiler-tab-target">'
            . Html::table(['Name', 'Value'], $queryRows)
            . '</div>';
        $html .= '<div id="wp-query-conditionals" class="profiler-tab-target">'
            . Html::table(['Conditional', 'Value'], $conditionRows)
            . '</div>';
        $html .= '<div id="wp-query-posts" class="profiler-tab-target">'
            . Html::table(['ID', 'Type', 'Status', 'Title', 'Slug'], $postRows)
            . '</div>';
        $html .= '<div id="wp-query-sql" class="profiler-tab-target">'
            . ($this->stringValue($payload, 'request_sql') !== ''
                ? Html::codeBlock($this->stringValue($payload, 'request_sql'))
                : Html::emptyPanel('The main query SQL was not available for this request.'))
            . '</div>';

        return $this->panel(
            'wp_query',
            $this->label(),
            $this->icon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'post_count')),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function conditionals(): array
    {
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
                'is_tax',
                'is_search',
                'is_404',
                'is_feed',
                'is_paged',
                'is_preview',
                'is_admin',
            ] as $conditional
        ) {
            $conditionals[$conditional] = (bool) $conditional();
        }

        return $conditionals;
    }

    /**
     * @return array<string, mixed>
     */
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
            'id' => $object->ID ?? $object->term_id ?? $object->user_id ?? null,
            'name' => $object->post_name ?? $object->slug ?? $object->user_login ?? '',
            'title' => $object->post_title ?? $object->name ?? '',
            'taxonomy' => $object->taxonomy ?? '',
            'post_type' => $object->post_type ?? '',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array{id: int, type: string, status: string, title: string, name: string}>
     */
    private function posts(mixed $query): array
    {
        if (!is_object($query) || !is_array($query->posts ?? null)) {
            return [];
        }

        $posts = [];

        foreach (array_slice($query->posts, 0, 100) as $post) {
            if (!is_object($post)) {
                continue;
            }

            $posts[] = [
                'id' => is_numeric($post->ID ?? null) ? (int) $post->ID : 0,
                'type' => $this->stringFromMixed($post->post_type ?? ''),
                'status' => $this->stringFromMixed($post->post_status ?? ''),
                'title' => $this->stringFromMixed($post->post_title ?? ''),
                'name' => $this->stringFromMixed($post->post_name ?? ''),
            ];
        }

        return $posts;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{id: int, type: string, status: string, title: string, name: string}>
     */
    private function postRows(array $payload): array
    {
        $posts = $payload['posts'] ?? [];

        if (!is_array($posts)) {
            return [];
        }

        $rows = [];

        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $rows[] = [
                'id' => $this->intValue($post, 'id'),
                'type' => $this->stringValue($post, 'type'),
                'status' => $this->stringValue($post, 'status'),
                'title' => $this->stringValue($post, 'title'),
                'name' => $this->stringValue($post, 'name'),
            ];
        }

        return $rows;
    }

    private function stringFromMixed(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    private function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
