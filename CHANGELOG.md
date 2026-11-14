# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.4.0 — 2026-11-14

- Configuration: "View full PHP configuration" opens `/_profiler/phpinfo` (it pointed to `#`), as in Symfony's profiler. Same access check as every profiler route.
- Configuration: bundles are read from the compiled container (`kernel.bundles`, `kernel.bundles_metadata`). The collector called `discoverBundles()`, which scanned all packages again on every profiled request and replaced the kernel's bundle registry.
- Configuration: every included file is listed, not the last 80.
- Request: the redirect note is only left for redirects that stay on this site. After a redirect to another site, the next page profiled here claimed to be its target.
- WordPress Query: search query vars such as `search_orderby_title` show `%` instead of wpdb's `{hash}` stand-ins.
- Blocks: `core/comment-template` lists every comment's blocks inside it and is timed until its real end. Since 1.3.6 the first comment's render closed it, the following comments were listed beside it and two untimed template entries followed.

## 1.3.7 — 2026-11-14

- Request: a page reached through a redirect shows "redirect from" with a link to the redirecting request's profile. The summary already rendered that note, but nothing filled it. `wp_redirect()` now hands the token to the next request in a short-lived `sf_redirect` cookie, as Symfony does. New `request.redirect` payload key.
- Hooks: listeners registered through the kernel are named by their service and method (they all read "Closure" in `HookLoader.php`). The tab is called "Listeners" and says that WordPress records which callbacks are registered, not which ran.
- Assets: a dependency registered with version `false` shows the WordPress version that core appends to its URL instead of an empty cell.
- WordPress Query: the request SQL shows `%` in LIKE patterns instead of wpdb's `{hash}` stand-ins.
- Cache: the Nginx Cache section also finds `sympress/nginx-cache` (installed as `ns-nginx-cache`); `persistent` is always a boolean.
- Database: the caller breakdown groups by the function that ran the query instead of by the whole backtrace, which made nearly every query its own row.
- Logs: PHP notices are level `notice` and appear with the warnings; as `info` they were only in the "All" tab.
- The toolbar stylesheet and the font are sent with `Cache-Control: private, max-age=3600`, so page views no longer boot WordPress twice more for them. The stylesheet URL carries the plugin file's time and changes after an update.
- Request panel: the table search field no longer covers the "Value" header of the narrow parameter boxes.

## 1.3.6 — 2026-11-14

- Blocks: synced patterns (`core/block`) no longer leave their entry open. Core replaces the pattern's inner blocks between the two filters the recorder pairs on, so the pattern never ended and every following block was listed one level too deep and without timing. Blocks are now paired by name, attributes and markup length, against the nearest open block.
- Blocks: `core/comment-template` and `core/comments`, which render the same block again for each comment, are timed until their last render instead of their first.
- Blocks: a block rendered directly by its parent (for example a navigation submenu) is listed before the blocks it contains instead of after them as their sibling.

## 1.3.5 — 2026-11-14

- Toolbar: requests are named after what they resolved to. The posts index is `@blog` and a search is `@search` (both were `@homepage`), a 404 is `@404` (it was named after the URL slug).
- Block themes: the Request panel title and the timeline's template row show the resolved block template (for example `sympress//page-package`) instead of `template-canvas.php`. New `request.template_name` payload key.
- Templates: the hierarchy is the one WordPress tried, recorded from its `{type}_template_hierarchy` filters. It now includes custom page templates, no longer adds `singular.php` to every request, and is empty for requests that load no template (feeds, REST, AJAX).
- 404 requests: the WordPress Query toolbar block and panel say that nothing matched instead of presenting the 404 template's loop as page data, and the Blocks panel no longer lists parsed blocks of an unrelated post.
- Routing: "no rewrite rule matched" instead of repeating the request path as the matched route.

## 1.3.4 — 2026-11-14

- Collect each profile once. The page now gets a toolbar placeholder whose script loads the toolbar from `/_wdt/{token}` (it did that already and replaced the inline copy), so HTML pages no longer run all collectors and write the profile twice.
- Duration and memory are fixed when `shutdown` begins, for every kind of request. Timeline rows no longer run past the end of the request, the template row no longer includes the profiler's own collection, and the stored profile no longer contains the first pass's cache hits, cache groups and file loads.
- A buffer that is discarded with `ob_end_clean()` no longer triggers work.

## 1.3.3 — 2026-11-14

- Fix a fatal error on production sites whose `profiler.enable_outside_development` callback uses pluggable functions such as `current_user_can()`: since 1.2.8 the filter also ran at `muplugins_loaded`, before WordPress loads them. Early error capture is now limited to local and development environments and never asks the filter.
- The error recorder keeps the result of the handler that was installed before it (a handler returning nothing stays "handled" instead of making PHP report the error again), leaves the handler stack alone when it never installed a handler, and keeps only a deprecation as the error seen before recording started.
- A fatal error is read first at shutdown, so a warning or deprecation raised by a later shutdown callback no longer empties the Exceptions panel.
- The HTTP client recorder accepts any value WordPress passes as URL; `wp_remote_get(false)` ended in a `TypeError` instead of WordPress's `WP_Error`.
- Exceptions outside the collectors (toolbar rendering, profile assembly) no longer escape the output handler or the last shutdown callback. Long-value previews no longer need `ext-mbstring`.

## 1.3.2 — 2026-11-13

- Internal: `ProfilerEndpointController::response()` builds the response for `/_profiler` and `/_wdt` routes without output or `exit`; `handle()` sends it. Every route is now covered by tests (access denial, toolbar, styles, font, profile, search, missing profile, latest redirect). No behavior change.

## 1.3.1 — 2026-11-13

- Translation: text domains WordPress only looked up (no translation file for the locale) are no longer reported as loaded; each domain shows its status, and the toolbar counts loaded domains. The fake "Times used", "Message ID" and "Message Preview" columns and the always-empty fallback locale are replaced by the user locale. Payload keys `domains` and `user_locale` replace `loaded_domains` and `fallback_locale`.

## 1.3.0 — 2026-11-13

- Assets: new "Script modules" tab listing enqueued script modules and the modules they import, with source, version and dependencies (WordPress 7.0+; WordPress exposes no module list before).

## 1.2.8 — 2026-11-13

- Logs: errors raised while plugins load are recorded. In environments that allow profiling, recording starts at `muplugins_loaded` and the entries are dropped if the gate stays closed for the request; the last error from before (for example a kernel boot deprecation) is kept as well. Previously recording started at `plugins_loaded` priority 35.
- Logs: deprecations triggered with `@trigger_error()` are recorded again, as in Symfony; other silenced errors are still skipped. Log times carry milliseconds.
- The error recorder removes its handler only when it is still the active one, so a handler another plugin installed later is not removed.

## 1.2.7 — 2026-11-13

- Profile and request timestamps carry milliseconds, so profiles of the same second sort in request order and `/_profiler/latest` opens the newest one. Older profiles without milliseconds keep working.

## 1.2.6 — 2026-11-13

- Inject the toolbar only into browser page navigations (`Accept` names `text/html` or `application/xhtml+xml`). Requests with `Accept: */*` or no `Accept` header (fetch, XHR, curl) are still profiled but their output is no longer buffered, so streamed responses and downloads are not held in memory.
- `profiler.collect` is asked again before a profile is stored, so a filter added by a theme or a late hook still prevents storage. The README documents which filters must be added before `plugins_loaded`.

## 1.2.5 — 2026-11-13

- Less work per profiled request: plugin lists, option and transient data, cron data, the kernel's bundle discovery and the nginx cache scan run once per request instead of once per collection pass. Cache size estimates no longer fail on closures or unserializable objects.
- Removed leftovers: the `X-Newsroom-Audit` header and `newsroom_audit=1` query switch that disabled collection, the dead "View formatted query / Explain query" links, two unused toolbar views and an unused matcher method.
- Packaging: `symfony/filesystem` is declared as a requirement (it was only available through the kernel), and `profiler.php` loads a local `vendor/autoload.php` only when it exists.

## 1.2.4 — 2026-11-13

- Events: applied filters are counted next to fired actions (`$wp_actions` holds actions only). The listener list holds up to 1000 entries instead of 250, which ended inside `init` and never reached `wp_head` or `wp_footer`; hook lists hold 500 instead of 100. The panel says when a list is cut.
- Logs: the error recorder calls the error handler that was installed before it (Sentry, Query Monitor) instead of replacing it, ignores errors silenced with `@` or excluded by `error_reporting()`, and stores at most 500 entries per request, reporting how many were dropped.

## 1.2.3 — 2026-11-13

- Database: queries are listed in execution order (they were sorted by duration, so `#` was a rank). "Duplicate queries" are identical statements; grouping by query shape reported 22 lookups of 21 different options as 22 duplicates. Totals, duplicates and callers cover all queries, not only the first 150 stored. The profiler's own collection queries are excluded from the list and the count.
- Performance: the timeline keeps the first mark of each hook. `parse_query` fires for every `WP_Query`, which split template rendering into several rows named `parse_query` (85 ms and 46 ms on the test page).

## 1.2.2 — 2026-11-13

- Blocks: start and finish of a block are now paired by identity. Blocks such as `core/navigation` render their children directly (`render_block` without `render_block_data`), which shifted every following name, duration, depth and output size: a header template part showed 306 bytes and 6.8 ms instead of 25.5 KiB and 21.6 ms. Directly rendered children are listed as untimed children of their parent.
- Blocks are listed in document order, "Render time" counts top-level blocks only (it was 2.6× the request time), "Dynamic blocks" counts blocks with a render callback, markup outside block comments is named `core/freeform`, and the parsed-content tab says when it shows the first 200 of more blocks.
- Templates: block themes show the resolved block template (for example `sympress//page`) instead of `template-canvas.php`, the hierarchy finds `templates/*.html`, and template parts list the rendered `core/template-part` blocks.
- Templates: requests without a template (REST, AJAX) report no render time instead of the whole request duration.

## 1.2.1 — 2026-11-13

- REST / AJAX: no longer build the REST server during collection. That fired `rest_api_init` for every plugin on page and AJAX requests and made the kernel report AJAX requests as `rest` (11 of 13 stored admin-ajax profiles). Routes are listed on REST requests, all of them instead of the first 120.
- Response headers sent more than once (`Link`, `Set-Cookie`, `Vary`) keep every value; `ProfileContext::responseHeaders()` returns a list for those.
- Request headers come from the server API where available, so `HTTP_*` environment variables (ddev's `HTTP_EXPOSE`) are not listed as headers and `Content-Length` is.
- Cron: next run is shown in the site timezone with its abbreviation (it was unlabelled UTC); single events are named `single`.
- Cache: `global_groups` lists group names (it was a list of `true`) and the Global column is correct.
- Options: values and types are those `get_option()` returns instead of raw serialized strings; large arrays start collapsed. The transient list includes site transients, excludes `_transient_timeout_*` rows and no longer scans the whole options table through an unescaped `_` wildcard.

## 1.2.0 — 2026-11-13

- The profiler is now branded "SymPress Profiler" with its own icon. The header search finds profiles instead of opening symfony.com; "Symfony initialization" and "Symfony Configuration" read "Kernel".
- Symfony-only tabs without a WordPress data source (Session, Flashes, Database Connections, Entity Managers, Managed Entities, Entities Mapping, Not Called Listeners, Orphaned Events, Translation Fallback/Missing) are shown disabled instead of with invented `0` values; the "Invalid entities" and "Managed entities" tiles and the dead "Group similar statements" link are removed.
- The profile search form has visible field labels in an even grid.

## 1.1.5 — 2026-11-13

- Fix table layout in all panels: columns no longer collapse to a few pixels and wrap letter by letter when one cell holds a long value (Events, Options, Assets, Blocks, REST routes, request headers). Short values stay on one line, columns with paths, URLs and dumps give way first, and narrow windows scroll the table inside its box.
- Values over 400 bytes (option values, serialized data) start collapsed and expand in place; nothing is cut.
- Load the bundled JetBrains Mono font: its URL has no `.woff2` extension any more (web servers answered that as a static 404) and the stylesheet now uses the bundled family name.
- The sidebar's Request / Response entry has no status badge, as in Symfony; long labels no longer push badges out of their entry.

## 1.1.4 — 2026-11-13

- HTML pages: the profile rendered into the toolbar (when WordPress flushes the page at `shutdown` priority 1) is now provisional. The profile is collected again and stored at the end of `shutdown`, so fatal errors, late log entries and the `shutdown` timeline event reach the profile. Duration and memory stay those of the response.
- Insert the toolbar before the last `</body>` instead of the first, and only into the final output chunk, so a `</body>` inside an inline script or an explicit `ob_flush()` no longer misplaces it.

## 1.1.3 — 2026-11-13

- Multisite: only super admins can use the profiler, because one store holds the profiles (cookies included) of every site in the network.
- With the gate closed (production without opt-in) the current user is no longer resolved at `plugins_loaded` priority −1000; the environment is checked first.
- Define `DONOTCACHEPAGE` on profiled pages and profiler endpoints so page caches that store logged-in responses never keep toolbar or profiler HTML.
- Match `/_profiler` and `/_wdt` as whole path segments; a site page such as `/_profiler-guide/` is no longer taken over.
- Link the stored referer in the profile header only when it is an `http(s)` URL.

## 1.1.2 — 2026-11-13

- Bound disk use: keep at most `profiler.max_profiles` (default 100) newest profiles younger than `profiler.ttl` (default 48 hours) and enforce this on every save instead of a random 1-in-20 sweep. Existing stores are trimmed on the next profiled request.
- Do not profile wp-admin heartbeat requests and `favicon.ico`; an idle admin tab no longer writes a profile every 15–60 seconds.
- `ProfileStorageInterface::search()` and `latest()` (and therefore `Profiler::find()`) return index summaries (token, date, meta) instead of fully decoded profiles; call `load()` for collector payloads. The profile list no longer decodes every matching profile into memory.
- Store profiles as compact JSON.

## 1.1.1 — 2026-11-13

- A failing collector or an unwritable profile store no longer breaks the profiled page: the collector's panel shows the error, the other collectors are kept, and storage failures are logged.
- Keep profiles that contain invalid UTF-8, `NAN` or `INF` (e.g. `/?x=%FF`, binary option values) instead of losing the profile and the toolbar.
- Work on musl-based images (Alpine), where `GLOB_BRACE` is undefined.
- Pass non-string `template_include` and `render_block` filter values through instead of failing with a `TypeError`.

## 1.1.0 — 2026-11-13

- Show option values in the Options panel (largest autoloaded options and transients) instead of names and sizes only.
- Record and show all `wp_remote_*()` request options in the HTTP Client panel, including headers, body and cookies, instead of method, timeout, redirection, HTTP version and blocking only.

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
