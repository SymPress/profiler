<?php

declare(strict_types=1);

namespace SymPress\Profiler\Hook;

use SymPress\Kernel\Hook\HookLoader;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Recorder\ProfilerErrorRecorder;

final readonly class GatedHooksBootstrap
{
    public function __construct(
        private ProfileGate $gate,
        private HookLoader $loader,
        private ProfilerErrorRecorder $errors,
    ) {
    }

    /** Errors raised while plugins load would otherwise be lost: the gate can only decide afterwards. */
    public function captureEarlyErrors(): void
    {
        if (!$this->gate->environmentAllowsProfiling()) {
            return;
        }

        $this->errors->startEarly();
    }

    public function register(): void
    {
        if (!$this->gate->shouldCollect()) {
            $this->errors->stopEarly();

            return;
        }

        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : null;
        $saveQueries = defined('SYMPRESS_PROFILER_SAVEQUERIES')
            ? (bool) constant('SYMPRESS_PROFILER_SAVEQUERIES')
            : (defined('WP_DEBUG') && WP_DEBUG && $environment === 'local');

        if (!defined('SAVEQUERIES') && $saveQueries) {
            define('SAVEQUERIES', true);
        }

        $this->loader->register();
    }
}
