# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.0.8 — 2026-11-13

- Show collected diagnostics verbatim instead of `[redacted]` placeholders. The key-based redaction also masked harmless values such as the WordPress version (`wordpress_` prefix) or any key containing `key`, `session` or `token`. Cookies, authorization headers, request parameters, URLs and credential-like payload fields are now stored and shown as collected.
- Show the full `$_SERVER` array (including environment variables) instead of eight allowlisted keys, keep request input, headers and log context without the 500-character/50-item/depth-4 truncation, and stop passing request values such as the URI, referer, user agent, AJAX action and REST route through `sanitize_text_field()`, which removed percent-encoded characters, tags and whitespace.
- Profiler access is unchanged: it stays limited to `manage_options` users in local/development environments unless `profiler.enable_outside_development` is enabled.

## 1.0.7 — 2026-11-06

- Use the Symfony 8.1.11 security patches released on November 4.

## 1.0.6 — 2026-10-22

- Maintenance release without functional changes compared to 1.0.5; only the plugin header version and release notes change.

## 1.0.5 — 2026-10-22

- Store profiles below the kernel cache directory (`%kernel.cache_dir%/profiler`) by default so `APP_CACHE_DIR` is honored and filesystems that cannot enforce private Unix modes, such as NTFS project mounts, no longer fail with "Profiler storage permissions could not be restricted". Explicit `profiler.storage_dir` overrides keep precedence; profiles under the previous default `var/cache/<env>/profiler` are not migrated.

## 1.0.4 — 2026-10-03

- Give the archive caller the permissions required by the shared workflow, pin shared workflows to 1.2.2, and exclude development files from WordPress artifacts. Profile privacy and cookie masking remain as released in 1.0.3.
- Use explicit PHP echo syntax in templates and document narrow native diagnostic exceptions so the shipped archive passes the default WordPress Plugin Check standard.

## 1.0.3 — 2026-10-03

- Store profiles and search indexes with mode 0600 in a 0700 private directory; tighten legacy files before use and reject unsafe storage links or permissions.
- Mask custom WordPress authentication/recovery cookies and PHP session cookies while preserving preference and authorization diagnostics.

## Earlier releases

- Initial profiler package documentation.
