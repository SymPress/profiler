<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

final class CollectorPanel
{
    private readonly string $idValue;
    private readonly string $titleValue;
    private readonly string $iconValue;
    private readonly string $htmlValue;
    private readonly string $metricValue;
    private readonly bool $enabledValue;

    public string $id {
        get => $this->idValue;
    }

    public string $title {
        get => $this->titleValue;
    }

    public string $icon {
        get => $this->iconValue;
    }

    public string $html {
        get => $this->htmlValue;
    }

    public string $metric {
        get => $this->metricValue;
    }

    public bool $enabled {
        get => $this->enabledValue;
    }

    public function __construct(
        string $id,
        string $title,
        string $icon,
        string $html,
        string $metric = '',
        bool $enabled = true,
    ) {
        $this->idValue = trim($id);
        $this->titleValue = trim($title);
        $this->iconValue = trim($icon);
        $this->htmlValue = $html;
        $this->metricValue = trim($metric);
        $this->enabledValue = $enabled;
    }
}
