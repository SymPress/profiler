<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;
use SymPress\Profiler\View\ToolbarRenderer;

final class ProfileViewBuilder
{
    public const string COLLECTOR_ERROR_KEY = 'collector_error';

    /** @param iterable<DataCollectorInterface> $collectors */
    public function __construct(
        private readonly iterable $collectors,
        private readonly ToolbarRenderer $toolbarRenderer,
    ) {
    }

    public function renderToolbarBootstrap(string $token): string
    {
        return $this->toolbarRenderer->renderBootstrap($token);
    }

    public function renderToolbarContent(ProfileRecord $profile): string
    {
        return $this->toolbarRenderer->renderContent($profile, $this->toolbarBlocks($profile));
    }

    /** @return list<ToolbarBlock> */
    public function toolbarBlocks(ProfileRecord $profile): array
    {
        $blocks = [];

        foreach ($this->collectors as $collector) {
            $payload = $profile->collector($collector->getKey());

            if (isset($payload[self::COLLECTOR_ERROR_KEY])) {
                continue;
            }

            try {
                $block = $collector->createToolbarBlock($payload, $profile);
            } catch (\Throwable) {
                // The toolbar is injected into the page: skip a broken block instead of failing the response.
                continue;
            }

            if (!$block instanceof ToolbarBlock) {
                continue;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /** @return list<CollectorPanel> */
    public function panels(ProfileRecord $profile): array
    {
        $panels = [];

        foreach ($this->collectors as $collector) {
            $payload = $profile->collector($collector->getKey());
            $error = $payload[self::COLLECTOR_ERROR_KEY] ?? null;

            if ($error === null) {
                try {
                    $panels[] = $collector->renderPanel($payload, $profile);

                    continue;
                } catch (\Throwable $throwable) {
                    $error = ['class' => $throwable::class, 'message' => $throwable->getMessage(), 'file' => $throwable->getFile(), 'line' => $throwable->getLine()];
                }
            }

            $panels[] = $this->errorPanel($collector, $error);
        }

        return $panels;
    }

    private function errorPanel(DataCollectorInterface $collector, mixed $error): CollectorPanel
    {
        $rows = [];

        foreach (['class', 'message', 'file', 'line'] as $key) {
            $rows[ucfirst($key)] = is_array($error) && is_scalar($error[$key] ?? null) ? (string) $error[$key] : '';
        }

        return new CollectorPanel(
            $collector->getKey(),
            $collector->getLabel(),
            $collector->getIcon(),
            Html::section('This collector failed', Html::definitionTable($rows)),
            'error',
        );
    }
}
