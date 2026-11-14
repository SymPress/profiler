# Profiler lifecycle and payload flow

## Request lifecycle

`Resources/config/services.yaml` is the executable wiring contract. Kernel hooks
delegate to `ProfilerHooks`, which keeps the application lifecycle in
`Application/Profiler`:

| Hook | Method | Contract |
|---|---|---|
| `muplugins_loaded` at -1000 | `GatedHooksBootstrap::captureEarlyErrors` | In local and development environments, start recording PHP errors before plugins load; the gate cannot decide yet because no user is known. The production opt-in filter is not asked here: pluggable functions do not exist yet. |
| `plugins_loaded` at -1000 | `GatedHooksBootstrap::register` | After pluggables load, authenticate and require `manage_options`; register collection hooks only when the gate opens, otherwise drop the early error buffer. |
| `plugins_loaded` at 25 | `start` | Initialize timing/token and emit debug headers for authorized requests. |
| `template_redirect` at -1000 | `beginFrontendBuffer` | Start toolbar buffering only for accessible GET page navigations (`Accept` names HTML). |
| `template_include` at 1000 | `captureTemplate` | Preserve and record the selected template path. |
| `kernel.error` (`App::ACTION_ERROR`) | `recordThrowable` | Add a normalized throwable summary to the request context. |
| `shutdown` at -999999 | `measure` | Fix duration and memory at the start of shutdown. |
| `shutdown` at 999999 | `finish` | Collect once and persist the profile. |

`ProfilerRouteHooks` handles `/_profiler` and `/_wdt` endpoints on `init` before
normal application routing.

## Recorder and collector flow

Recorders subscribe to WordPress hooks during the request and retain bounded
request-local data. At finalization, each service tagged `profiler.collector`
receives one immutable `ProfileContext` and returns an array payload:

```text
WordPress/kernel hooks -> recorder or runtime globals
                       -> DataCollectorInterface::collect()
                       -> ProfileRecord.collectors[collector key]
                       -> ProfileStorageInterface
                       -> toolbar block and profiler panel
```

Collector order is priority-defined in `Resources/config/services.yaml`.
Hook services, recorder-to-collector links, classes, stable keys, top-level
payload keys, toolbar/panel availability, sources, and sensitive fields are
listed in the machine-readable [`collectors.json`](collectors.json). Its contract
test compares hook wiring, collector priority, payload keys, and view metadata
with the implementation.

The log collector combines PHP errors with entries supplied by
`sympress/monolog-bundle` through the `sympress_profiler_log_entries` filter. A
filter entry is normalized to type, label, level, message, file, line,
timestamp, source, channel, context, and extra before persistence.

## Storage, rendering, and trust boundaries

- `ProfileRecord` stores request metadata separately from collector payloads.
- `FilesystemProfileStorage` writes JSON and search indexes with mode `0600` in a
  `0700` private directory below the kernel cache directory
  (`%kernel.cache_dir%/profiler`, which follows `APP_CACHE_DIR`). Atomic writes
  remain private with a permissive process umask. On first read/write it tightens
  existing profile and index permissions; a permission failure or symbolic link
  closes the storage boundary. Ancestor cache directories and unrelated files
  are not changed. Already-private read-only stores retain legacy search fallback.
  Storage tokens use the route's existing ASCII alphanumeric/underscore/hyphen
  contract so file operations cannot escape the private directory.
- Retention is enforced on every save: at most `profiler.max_profiles` (100)
  newest profiles younger than `profiler.ttl` (48 hours) are kept. Search and
  `latest()` return index summaries; only `load()` decodes a full profile.
- wp-admin heartbeat requests and `favicon.ico` are never profiled.
- The gate prevents collection in excluded contexts and controls profiler access;
  it is the only protection for collected data.
- PHP views escape untrusted values through `Support/Html`. Use `HtmlString` only
  for HTML already constructed and escaped by trusted renderer code.
- `Resources/profiler/` contains SymPress overrides. `Resources/symfony/` is
  upstream-derived fallback material; preserve provenance when syncing it.
- `views/` are the local PHP profiler and toolbar templates.

Changing a collector key is a persisted-data compatibility change. Changing a
payload key requires updating its renderer, behavior tests, and catalog entry in
the same commit.

## Authorization and persisted data

Local/development access still requires an authenticated WordPress user with
`manage_options` (on multisite: a super admin, because the store is shared by
the whole network). Production additionally requires the explicit
`profiler.enable_outside_development` opt-in; that filter cannot authorize an
anonymous visitor. Endpoints, toolbar and collection use the same authorization
boundary. The route hook remains registered to return 403 for denied requests;
collection/recorder hooks are registered only after the gate opens at
`plugins_loaded`, after WordPress pluggables become available.

Request data is stored and shown verbatim: the full `$_SERVER` array (including
environment variables), cookies (including WordPress authentication and PHP
session cookies), request and response headers, GET/POST/file parameters, URL
userinfo and query strings, and credential-like fields in any collector payload
are not redacted, truncated or passed through `sanitize_text_field()`; only
WordPress' magic-quote slashes are removed. Profiles are privileged debug data
for development environments; the cache directory must remain outside HTTP
access.

Every collector, including extension collectors, is normalized before storage
and rendering: objects become `[object Class]` (or their string value when
`Stringable`), resources become `[resource]` and malformed nesting beyond 64
levels is closed with a placeholder.

The toolbar is inserted before the last `</body>` of the final output chunk.
Only a placeholder with the token is inserted: its script loads the toolbar from
`/_wdt/{token}` and retries while the profile does not exist yet, as in Symfony.
Nothing is collected while the page is sent. `measure()` fixes duration and
memory when `shutdown` begins, for every kind of request; `finish()` at the end
of `shutdown` collects once and stores the profile, so fatal errors, late log
entries and work done by other shutdown callbacks are included without counting
as response time.
Small `.json.index` sidecars hold
search metadata; search and `latest()` return these summaries without opening
the full collector JSON. Existing profiles without sidecars are decoded once
and backfilled for subsequent searches. Read-only legacy stores keep a
compatible full-file fallback. Sidecars expire with their profiles.

## Stopwatch service compatibility

`SymPress\Profiler\Stopwatch\ProfilerStopwatch` supplies the custom timing payload consumed by `PerformanceCollector`. It does not replace `debug.stopwatch`, whose Symfony consumers require `Symfony\Component\Stopwatch\Stopwatch`. An existing native service remains intact; without one, Twig's profiler uses its optional null stopwatch and still records Twig profile timing. Custom SymPress events continue to use their own service and payload.

Run `php tests/Integration/stopwatch-twig.php /absolute/consumer/vendor/autoload.php` against a consumer with Symfony Twig Bridge and Twig installed. If its optional Stopwatch component is absent, supply a second test autoloader that provides the real component (for example this package's development vendor/autoload.php). It compiles actual service wiring and renders nested Twig templates with the native stopwatch present and absent, asserting that Twig and custom events survive. No production dependency on Twig or Stopwatch is added solely for profiling.
