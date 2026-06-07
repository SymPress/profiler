# Contributing

Thanks for taking the time to improve SymPress Profiler.

## Local Setup

```bash
composer install
composer test
composer cs:analyze
composer cs
```

The package uses PHP 8.5, PHPUnit, PHPStan, PHP CS Fixer, and PHPCS with the
Inpsyde coding standards.

## Pull Requests

- Keep pull requests focused on one behavior or documentation change.
- Add or update tests for behavioral changes.
- Run the available checks before opening a pull request.
- Use Conventional Commits for commit messages, for example
  `feat(profiler): add collector metadata`.

## Coding Guidelines

- Prefer small, explicit services that fit the existing package structure.
- Keep WordPress integration behind hooks, context objects, or collectors.
- Escape output in view templates unless the value is already trusted HTML.
- Avoid collecting sensitive data unless it is sanitized or summarized.
