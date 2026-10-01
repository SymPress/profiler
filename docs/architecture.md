# Profiler lifecycle and payload flow

## Request lifecycle

`Resources/config/services.yaml` is the executable wiring contract. Kernel hooks
delegate to `ProfilerHooks`, which keeps the application lifecycle in
`Application/Profiler`:

| Hook | Method | Contract |
|---|---|---|
| `plugins_loaded` at -1000 | `GatedHooksBootstrap::register` | After pluggables load, authenticate and require `manage_options`; register collection hooks only when the gate opens. |
| `plugins_loaded` at 25 | `start` | Initialize timing/token and emit debug headers for authorized requests. |
| `template_redirect` at -1000 | `beginFrontendBuffer` | Start toolbar buffering only for accessible HTML GET requests. |
| `template_include` at 1000 | `captureTemplate` | Preserve and record the selected template path. |
| `kernel.error` (`App::ACTION_ERROR`) | `recordThrowable` | Add a normalized throwable summary to the request context. |
| `shutdown` at 999999 | `finish` | Collect once, persist the profile, and finalize it. |

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
- `FilesystemProfileStorage` writes JSON below the environment cache directory.
- The gate prevents collection in excluded contexts and controls profiler access;
  collectors must still minimize and sanitize sensitive data.
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
`manage_options`. Production additionally requires the explicit
`profiler.enable_outside_development` opt-in; that filter cannot authorize an
anonymous visitor. Endpoints, toolbar and collection use the same authorization
boundary. The route hook remains registered to return 403 for denied requests;
collection/recorder hooks are registered only after the gate opens at
`plugins_loaded`, after WordPress pluggables become available.

Request server diagnostics use an explicit allowlist and never include the
process environment. WordPress authentication cookies, authorization/set-cookie
headers and nested credential, key and DSN fields are redacted before storage.
URL userinfo is redacted by the sanitizer. Stored data remains privileged debug
data and the cache directory must remain outside HTTP access.

Every collector, including extension collectors, crosses a final recursive
redaction boundary before storage and rendering. It masks URL userinfo (also
username-only and empty-password forms), sensitive URL query values and named
credential fields in HTTP/error/exception payloads. Diagnostic arrays, counts,
booleans and ordinary long messages are retained without the request input's
50-item/500-character truncation; malformed nesting beyond 64 levels is closed
with a placeholder. Custom collectors must still avoid collecting unlabeled
secret text that no generic redactor can identify.

HTML insertion uses callback replacements in both the output buffer and toolbar
template, preserving literal dollar sequences. The first collection finalizes
and saves a profile; shutdown and subsequent buffer callbacks reuse it.
Small `.json.index` sidecars hold search metadata; search opens full collector
JSON only for matching result profiles. Existing profiles without sidecars are decoded once and backfilled for subsequent
searches. Read-only legacy stores keep a compatible full-file fallback. Sidecars
expire with their profiles.

## Stopwatch service compatibility

`SymPress\Profiler\Stopwatch\ProfilerStopwatch` supplies the custom timing payload consumed by `PerformanceCollector`. It does not replace `debug.stopwatch`, whose Symfony consumers require `Symfony\Component\Stopwatch\Stopwatch`. An existing native service remains intact; without one, Twig's profiler uses its optional null stopwatch and still records Twig profile timing. Custom SymPress events continue to use their own service and payload.

Run `php tests/Integration/stopwatch-twig.php /absolute/consumer/vendor/autoload.php` against a consumer with Symfony Twig Bridge and Twig installed. If its optional Stopwatch component is absent, supply a second test autoloader that provides the real component (for example this package's development vendor/autoload.php). It compiles actual service wiring and renders nested Twig templates with the native stopwatch present and absent, asserting that Twig and custom events survive. No production dependency on Twig or Stopwatch is added solely for profiling.
