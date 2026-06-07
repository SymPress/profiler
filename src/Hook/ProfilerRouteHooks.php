<?php

declare(strict_types=1);

namespace SymPress\Profiler\Hook;

use SymPress\Profiler\Application\ProfilerEndpointController;

final class ProfilerRouteHooks
{
    public function __construct(
        private readonly ProfilerEndpointController $controller,
    ) {
    }

    public function handle(): void
    {
        $this->controller->handle();
    }
}
