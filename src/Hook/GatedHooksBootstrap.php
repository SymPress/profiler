<?php

declare(strict_types=1);

namespace SymPress\Profiler\Hook;

use SymPress\Kernel\Hook\HookLoader;
use SymPress\Profiler\Application\ProfileGate;

final readonly class GatedHooksBootstrap
{
    public function __construct(private ProfileGate $gate, private HookLoader $loader)
    {
    }

    public function register(): void
    {
        if (!$this->gate->shouldCollect()) {
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
