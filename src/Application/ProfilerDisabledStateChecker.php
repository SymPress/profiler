<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

final class ProfilerDisabledStateChecker
{
    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    public function __invoke(): bool
    {
        return $this->profiler->isDisabled();
    }
}
