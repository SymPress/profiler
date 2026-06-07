<?php

declare(strict_types=1);

namespace SymPress\Profiler\Support;

final readonly class HtmlString
{
    public function __construct(
        public string $html,
    ) {
    }
}
