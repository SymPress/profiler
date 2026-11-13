<?php

/**
 * Plugin Name: Profiler
 * Description: Profiler and web debug toolbar for the WordPress kernel.
 * Version: 1.3.2
 * Requires at least: 6.9
 * Requires PHP: 8.5
 * Author: Brian Schäffner
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SymPress\Profiler;

if (!defined('ABSPATH')) {
    return;
}

// Project installs autoload through the root Composer autoloader; only a standalone copy ships its own.
if (!class_exists(ProfilerBundle::class) && is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
