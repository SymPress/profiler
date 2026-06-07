<?php

/**
 * Plugin Name: Profiler
 * Description: Profiler and web debug toolbar for the WordPress kernel.
 * Version: 1.0.0
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

$environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : null;
$debugEnvironment = defined('WP_DEBUG') && WP_DEBUG;
$saveQueriesEnabled = defined('SYMPRESS_PROFILER_SAVEQUERIES')
    ? (bool) constant('SYMPRESS_PROFILER_SAVEQUERIES')
    : ($debugEnvironment && $environment === 'local');

if (
    !defined('SAVEQUERIES')
    && $saveQueriesEnabled
) {
    define('SAVEQUERIES', true);
}

if (!class_exists(ProfilerBundle::class)) {
    foreach (
        [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
            __DIR__ . '/vendor/autoload.php',
        ] as $autoloader
    ) {
        if (!is_readable($autoloader)) {
            continue;
        }

        require_once $autoloader;
        break;
    }
}
