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
        $domains = [];

        // WordPress stores a NOOP_Translations object for every domain it looked up without finding a file.
        foreach ((array) ($GLOBALS['l10n'] ?? []) as $domain => $translations) {
            $domains[] = ['domain' => (string) $domain, 'loaded' => !$translations instanceof \NOOP_Translations];
        }

        usort($domains, static fn (array $left, array $right): int => $left['domain'] <=> $right['domain']);

        return [
            'locale'       => function_exists('get_locale') ? (string) get_locale() : '',
            'user_locale'  => function_exists('get_user_locale') ? (string) get_user_locale() : '',
            'is_rtl'       => function_exists('is_rtl') ? is_rtl() : false,
            'timezone'     => function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '',
            'domains'      => $domains,
            'domain_count' => count(array_filter(array_column($domains, 'loaded'))),
            'captured_at'  => $context->finishedAtIso(),
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
        $locale = $this->stringValue($payload, 'locale');
        $domainRows = [];

        foreach (is_array($payload['domains'] ?? null) ? $payload['domains'] : [] as $domain) {
            if (!is_array($domain)) {
                continue;
            }

            $domainRows[] = [
                $this->stringValue($domain, 'domain'),
                $this->boolValue($domain, 'loaded') ? 'loaded' : sprintf('no translation file for %s', $locale !== '' ? $locale : 'the locale'),
            ];
        }

        $html = '<h2>Translation</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Site locale', 'value' => $locale !== '' ? $locale : 'n/a'],
            ['label' => 'User locale', 'value' => $this->stringValue($payload, 'user_locale', 'n/a')],
            ['label' => 'Loaded domains', 'value' => (string) $this->intValue($payload, 'domain_count')],
        ]);
        $html .= Html::section('Messages', Html::tabNavigation([
            ['label' => 'Text domains', 'badge' => count($domainRows), 'active' => true, 'target' => '#translation-defined-messages'],
            ['label' => 'Fallback', 'disabled' => true],
            ['label' => 'Missing', 'disabled' => true],
        ]));
        $html .= '<div id="translation-defined-messages" class="profiler-tab-target">';
        $html .= '<p class="help">Text domains WordPress looked up during the request. Translated messages are not tracked.</p>';
        $html .= Html::table(['Domain', 'Status'], $domainRows, 'translation-table');
        $html .= '</div>';

        return $this->panel(
            'localization',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'domain_count')),
        );
    }
}
