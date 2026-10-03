# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.0.4 — 2026-10-03

- Give the archive caller the permissions required by the shared workflow, pin shared workflows to 1.2.2, and exclude development files from WordPress artifacts. Profile privacy and cookie masking remain as released in 1.0.3.
- Use explicit PHP echo syntax in templates and document narrow native diagnostic exceptions so the shipped archive passes the default WordPress Plugin Check standard.

## 1.0.3 — 2026-10-03

- Store profiles and search indexes with mode 0600 in a 0700 private directory; tighten legacy files before use and reject unsafe storage links or permissions.
- Mask custom WordPress authentication/recovery cookies and PHP session cookies while preserving preference and authorization diagnostics.

## Earlier releases

- Initial profiler package documentation.
