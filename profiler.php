<?php

/**
 * Plugin Name: Profiler
 * Description: Profiler and web debug toolbar for the WordPress kernel.
 * Version: 1.0.1
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

if (!class_exists(ProfilerBundle::class)) {
    require_once __DIR__ . '/vendor/autoload.php';
}
