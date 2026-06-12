<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class LocalizationCollector extends AbstractCollector implements DataCollectorInterface
{
    public function getKey(): string
    {
        return 'localization';
    }

    public function getLabel(): string
    {
        return 'Translation';
    }

    public function getIcon(): string
    {
        return 'translation';
    }

    public function collect(ProfileContext $context): array
    {
        $domains = array_values(array_filter(array_map(
            static fn (int|string $domain): string => (string) $domain,
            array_keys((array) ($GLOBALS['l10n'] ?? [])),
        )));
        sort($domains);

        return [
            'locale'          => function_exists('get_locale') ? (string) get_locale() : '',
            'fallback_locale' => '',
            'is_rtl'          => function_exists('is_rtl') ? is_rtl() : false,
            'timezone'        => function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '',
            'loaded_domains'  => $domains,
            'domain_count'    => count($domains),
            'captured_at'     => $context->finishedAtIso(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $count = $this->intValue($payload, 'domain_count');

        return new ToolbarBlock(
            'localization',
            'Translation',
            sprintf('%d', $count),
            $this->stringValue($payload, 'locale', 'n/a'),
            $this->profileUrl($profile, 'localization'),
            $count > 0 ? 'green' : 'cyan',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $domainRows = [];
        $domains = is_array($payload['loaded_domains'] ?? null) ? $payload['loaded_domains'] : [];

        foreach ($domains as $domain) {
            if (!is_scalar($domain) && !$domain instanceof \Stringable) {
                continue;
            }

            $domainRows[] = [
                $this->stringValue($payload, 'locale'),
                (string) $domain,
                1,
                (string) $domain,
                'Loaded text domain',
            ];
        }

        $fallbackLocale = $this->stringValue($payload, 'fallback_locale');

        $html = '<h2>Translation</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Default locale', 'value' => $this->stringValue($payload, 'locale', 'n/a')],
            ['label' => 'Fallback locale', 'value' => $fallbackLocale !== '' ? $fallbackLocale : 'n/a'],
        ]);
        $html .= Html::section('Messages', Html::tabNavigation([
            ['label' => 'Defined', 'badge' => $this->intValue($payload, 'domain_count'), 'active' => true, 'target' => '#translation-defined-messages'],
            ['label' => 'Fallback', 'badge' => 0, 'target' => '#translation-fallback-messages'],
            ['label' => 'Missing', 'badge' => 0, 'target' => '#translation-missing-messages'],
        ]));
        $html .= '<div id="translation-defined-messages" class="profiler-tab-target">';
        $html .= '<p class="help">These text domains were loaded for the profiled request.</p>';
        $html .= Html::table(['Locale', 'Domain', 'Times used', 'Message ID', 'Message Preview'], $domainRows, 'translation-table');
        $html .= '</div>';
        $html .= '<div id="translation-fallback-messages" class="profiler-tab-target">'
            . Html::emptyPanel('No fallback translation messages were captured during this request.')
            . '</div>';
        $html .= '<div id="translation-missing-messages" class="profiler-tab-target">'
            . Html::emptyPanel('No missing translation messages were captured during this request.')
            . '</div>';

        return $this->panel(
            'localization',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'domain_count')),
        );
    }
}
