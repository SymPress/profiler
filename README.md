# SymPress Profiler

[![Checks](https://img.shields.io/github/actions/workflow/status/SymPress/profiler/qa.yml?branch=main&label=checks)](https://github.com/SymPress/profiler/actions/workflows/qa.yml) [![Release](https://img.shields.io/packagist/v/sympress/profiler.svg?label=release)](https://packagist.org/packages/sympress/profiler) [![PHP](https://img.shields.io/packagist/dependency-v/sympress/profiler/php.svg?label=php)](https://packagist.org/packages/sympress/profiler) [![Downloads](https://img.shields.io/packagist/dt/sympress/profiler.svg?label=downloads)](https://packagist.org/packages/sympress/profiler/stats) [![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE) [![Security Policy](https://img.shields.io/badge/security-policy-2ea44f.svg)](SECURITY.md)

Profiler and web debug toolbar for SymPress WordPress kernel applications.

The package records request, runtime, WordPress, and rendering data during
development and exposes it through an in-browser toolbar and profiler pages. It
is distributed as a Composer-powered WordPress MU plugin and integrates with the
SymPress kernel service container.

## Installation

```bash
composer require sympress/profiler
```

The package requires PHP 8.5, WordPress 6.9 or newer, and `sympress/kernel`.

## Features

- Symfony-style web debug toolbar for WordPress frontoffice requests
- Profiler pages with searchable stored profiles
- Request, response, performance, memory, and PHP error collection
- WordPress data collectors for hooks, templates, blocks, assets, REST/AJAX,
  options, cache, cron, plugins, themes, queries, and localization
- HTTP client, database, security, kernel, and runtime diagnostics
- Filesystem profile storage under the kernel cache directory
- Development-only access by default with filter-based overrides
- Optional `SAVEQUERIES` activation for local debug environments
- Bundled toolbar icons, styles, templates, and font assets

## Usage

When the SymPress kernel discovers the package, it registers
`SymPress\Profiler\ProfilerBundle` and loads `profiler/profiler.php` as the MU
plugin entry point.

The profiler collects frontoffice requests in local and development
environments by default. The toolbar links each request to a stored profile with
all collector panels.

```php
<?php

add_filter('profiler.collect', static fn (bool $collect): bool => $collect);
```

Collection can also be controlled through the service parameters in
`Resources/config/services.yaml`:

```yaml
parameters:
    profiler.storage_dir: '%kernel.project_dir%/var/cache/%kernel.environment%/profiler'
    profiler.collect: true
    profiler.collect_parameter: ''
    profiler.toolbar.ajax_replace: false
```

## Access Control

Profiler access is enabled automatically for local and development
environments. To enable access outside development, return `true` from the
`profiler.enable_outside_development` filter. Users must still be able to manage
WordPress options.

```php
<?php

add_filter(
    'profiler.enable_outside_development',
    static fn (bool $enabled, string $environment): bool => $environment === 'staging',
    10,
    2,
);
```

## Development

```bash
composer install
composer test
composer cs:analyze
composer cs
```

Use `composer cs:fix` to apply automatic style fixes.

## License

This package is licensed under `GPL-2.0-or-later`.
