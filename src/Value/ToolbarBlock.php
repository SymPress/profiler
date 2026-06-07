<?php

declare(strict_types=1);

namespace SymPress\Profiler\Value;

final class ToolbarBlock
{
    private readonly string $idValue;
    private readonly string $labelValue;
    private readonly string $valueValue;
    private readonly string $detailValue;
    private readonly string $linkValue;
    private readonly string $accentValue;

    public string $id {
        get => $this->idValue;
    }

    public string $label {
        get => $this->labelValue;
    }

    public string $value {
        get => $this->valueValue;
    }

    public string $detail {
        get => $this->detailValue;
    }

    public string $link {
        get => $this->linkValue;
    }

    public string $accent {
        get => $this->accentValue;
    }

    public function __construct(
        string $id,
        string $label,
        string $value,
        string $detail,
        string $link,
        string $accent = 'cyan',
    ) {
        $this->idValue = trim($id);
        $this->labelValue = trim($label);
        $this->valueValue = trim($value);
        $this->detailValue = trim($detail);
        $this->linkValue = trim($link);
        $this->accentValue = trim($accent);
    }
}
