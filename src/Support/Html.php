<?php

declare(strict_types=1);

namespace SymPress\Profiler\Support;

final class Html
{
    private const int LONG_VALUE_LENGTH = 400;
    private const int SHORT_VALUE_LENGTH = 40;
    private const array WIDE_COLUMNS = [
        'Args', 'Attributes', 'Callback', 'Candidates', 'Class', 'Dependencies', 'Description', 'File', 'Handles',
        'Info', 'Listener', 'Log', 'Message', 'Message Preview', 'Path', 'Request options', 'Route', 'Source', 'Status / Error',
        'Title', 'Transport', 'URL', 'Value',
    ];

    public static function escape(mixed $value): string
    {
        return htmlspecialchars(
            self::stringifyScalar($value),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }

    public static function attr(mixed $value): string
    {
        return self::escape($value);
    }

    /** @param array<string, mixed> $rows */
    public static function definitionTable(array $rows): string
    {
        $html = '<table class="metadata-table"><tbody>';

        foreach ($rows as $label => $value) {
            $html .= '<tr>';
            $html .= '<th class="font-normal key">' . self::escape($label) . '</th>';
            $html .= '<td class="font-normal">' . self::renderValue($value) . '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    public static function table(array $headers, array $rows, string $class = ''): string
    {
        $tableClass = $class !== '' ? ' class="' . self::attr($class) . '"' : '';
        $html = '<div class="table-with-search-field"><table' . $tableClass . '><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th' . self::columnClass($header) . '>' . self::escape($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        if ($rows === []) {
            $html .= '<tr><td colspan="' . count($headers) . '">' . self::escape('No data available.') . '</td></tr>';
        }

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($row as $index => $cell) {
                $html .= '<td' . self::columnClass($headers[$index] ?? '', $cell) . '>' . self::renderValue($cell) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    public static function codeBlock(mixed $value): string
    {
        return sprintf(
            '<pre class="prewrap break-long-words"><code>%s</code></pre>',
            self::escape(self::stringify($value)),
        );
    }

    public static function codeCell(mixed $value): HtmlString
    {
        return new HtmlString(
            sprintf('<pre><code>%s</code></pre>', self::escape(self::stringify($value))),
        );
    }

    public static function badge(string $label, string $statusClass = 'status-success'): string
    {
        return sprintf(
            '<span class="label %s">%s</span>',
            self::attr($statusClass),
            self::escape($label),
        );
    }

    public static function emptyPanel(string $message): string
    {
        return sprintf(
            '<div class="empty empty-panel"><p>%s</p></div>',
            self::escape($message),
        );
    }

    /** @param list<array{label: string, value: string, detail?: string}> $metrics */
    public static function metricTiles(array $metrics): string
    {
        if ($metrics === []) {
            return self::emptyPanel('No metrics available.');
        }

        $html = '<div class="metrics">';

        foreach ($metrics as $metric) {
            $detail = $metric['detail'] ?? '';
            $html .= '<article class="metric">';
            $html .= '<span class="value">' . self::escape($metric['value']) . '</span>';
            $html .= '<span class="label">' . self::escape($metric['label']) . '</span>';

            if ($detail !== '') {
                $html .= '<small class="text-muted">' . self::escape($detail) . '</small>';
            }

            $html .= '</article>';
        }

        return $html . '</div>';
    }

    /** @param list<array{label: string, content: string, active?: bool, disabled?: bool, badge?: int|string}> $tabs */
    public static function tabs(array $tabs): string
    {
        if ($tabs === []) {
            return '';
        }

        $activeIndex = 0;

        foreach ($tabs as $index => $tab) {
            if ((bool) ($tab['active'] ?? false)) {
                $activeIndex = $index;
                break;
            }
        }

        $html = '<div class="sf-tabs">';

        foreach ($tabs as $index => $tab) {
            $active = $index === $activeIndex;
            $disabled = (bool) ($tab['disabled'] ?? false);

            $classes = ['tab'];

            if ($active) {
                $classes[] = 'active';
            }

            if ($disabled) {
                $classes[] = 'disabled';
            }

            $html .= '<div class="' . self::attr(implode(' ', $classes)) . '">';
            $html .= '<div class="tab-title">' . self::escape($tab['label']);

            if (isset($tab['badge'])) {
                $html .= ' <span class="badge">' . self::escape($tab['badge']) . '</span>';
            }

            $html .= '</div>';
            $html .= '<div class="tab-content">' . ($disabled ? '' : $tab['content']) . '</div>';
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /** @param list<array{label: string, active?: bool, disabled?: bool, badge?: int|string, target?: string}> $items */
    public static function tabNavigation(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $html = '<div class="tab-navigation" role="tablist">';

        foreach ($items as $item) {
            $classes = ['tab-control'];
            $active = (bool) ($item['active'] ?? false);
            $disabled = (bool) ($item['disabled'] ?? false);
            $target = (string) ($item['target'] ?? '');

            if ($active) {
                $classes[] = 'active';
            }

            if ($disabled) {
                $classes[] = 'disabled';
            }

            $html .= '<button type="button" class="' . self::attr(implode(' ', $classes)) . '" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '"';

            if ($disabled) {
                $html .= ' disabled aria-disabled="true"';
            }

            if ($target !== '') {
                $targetId = str_starts_with($target, '#') ? substr($target, 1) : $target;
                $html .= ' data-tab-target="' . self::attr($target) . '" aria-controls="' . self::attr($targetId) . '"';
            }

            $html .= '>';
            $html .= self::escape($item['label']);

            if (isset($item['badge'])) {
                $html .= ' <span class="badge">' . self::escape($item['badge']) . '</span>';
            }

            $html .= '</button>';
        }

        return $html . '</div>';
    }

    /** @param array<string, mixed> $rows */
    public static function keyValueTable(array $rows, string $keyHeader = 'Key', string $valueHeader = 'Value'): string
    {
        $tableRows = [];

        foreach ($rows as $key => $value) {
            $tableRows[] = [$key, $value];
        }

        return self::table([$keyHeader, $valueHeader], $tableRows);
    }

    /** @param array<string, mixed> $values */
    public static function parameterBox(array $values): string
    {
        if ($values === []) {
            return '<div class="empty profiler-empty-box"><p>None</p></div>';
        }

        return self::keyValueTable($values, 'Name', 'Value');
    }

    public static function dumpValue(mixed $value): HtmlString
    {
        if ($value === null) {
            return new HtmlString('<span class="sf-dump-const">null</span>');
        }

        if (is_bool($value)) {
            return new HtmlString('<span class="sf-dump-const">' . ($value ? 'true' : 'false') . '</span>');
        }

        if (is_int($value) || is_float($value)) {
            return new HtmlString('<span class="sf-dump-num">' . self::escape($value) . '</span>');
        }

        if (is_string($value) || $value instanceof \Stringable) {
            $string = (string) $value;
            $dump = '<span class="sf-dump-str">"' . self::escape($string) . '"</span>';

            if (strlen($string) <= self::LONG_VALUE_LENGTH) {
                return new HtmlString($dump);
            }

            // Nothing is cut: long values stay complete but start collapsed so one row cannot fill the page.
            return new HtmlString(sprintf(
                '<details class="profiler-long-value"><summary><span class="sf-dump-str">"%s…"</span> <span class="text-muted">(%d bytes)</span></summary>%s</details>',
                // substr() may cut inside a multi-byte character; escape() substitutes the broken tail.
                self::escape(substr($string, 0, 120)),
                strlen($string),
                $dump,
            ));
        }

        $code = self::codeBlock($value);

        if (!is_array($value) || strlen($code) <= self::LONG_VALUE_LENGTH) {
            return new HtmlString($code);
        }

        return new HtmlString(sprintf(
            '<details class="profiler-long-value"><summary><span class="sf-dump-note">array</span> <span class="text-muted">(%d items)</span></summary>%s</details>',
            count($value),
            $code,
        ));
    }

    public static function link(string $url, string $label = ''): HtmlString
    {
        $text = $label !== '' ? $label : $url;

        return new HtmlString(sprintf(
            '<a href="%s">%s</a>',
            self::attr($url),
            self::escape($text),
        ));
    }

    public static function section(string $title, string $content): string
    {
        return sprintf(
            '<section class="profiler-section"><h2>%s</h2>%s</section>',
            self::escape($title),
            $content,
        );
    }

    private static function renderValue(mixed $value): string
    {
        if ($value instanceof HtmlString) {
            return $value->html;
        }

        if (is_array($value)) {
            return self::codeBlock($value);
        }

        if (is_bool($value)) {
            return self::escape($value ? 'Yes' : 'No');
        }

        if ($value === null || $value === '') {
            return '<span class="label status-warning">n/a</span>';
        }

        return self::escape($value);
    }

    private static function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return self::stringifyScalar($value);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        try {
            return json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            return '[unserializable value]';
        }
    }

    private static function stringifyScalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => self::stringify($value),
        };
    }

    private static function columnClass(string $header, mixed $cell = null): string
    {
        $classes = match (true) {
            in_array($header, ['#', 'Count', 'Queries', 'Duration (ms)'], true) => ['num-col', 'nowrap'],
            // Columns that hold paths, URLs and dumps give way first when the table runs out of room.
            in_array($header, self::WIDE_COLUMNS, true) => ['break-long-words', 'profiler-wide-col'],
            // Short names (header names, handles, hooks) stay on one line instead of wrapping at hyphens.
            is_scalar($cell) && strlen((string) $cell) <= self::SHORT_VALUE_LENGTH => ['nowrap'],
            default => ['break-long-words'],
        };

        return ' class="' . self::attr(implode(' ', $classes)) . '"';
    }
}
