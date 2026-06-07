<?php

declare(strict_types=1);

namespace SymPress\Profiler\Hook;

use SymPress\Profiler\Application\Profiler;

final class ProfilerHooks
{
    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    public function start(): void
    {
        $this->profiler->start();
    }

    public function beginFrontendBuffer(): void
    {
        $this->profiler->beginHtmlBuffer();
    }

    public function captureTemplate(string $template): string
    {
        return $this->profiler->captureTemplate($template);
    }

    public function recordThrowable(\Throwable $throwable): void
    {
        $this->profiler->recordThrowable($throwable);
    }

    public function finish(): void
    {
        $this->profiler->finish();
    }
}
