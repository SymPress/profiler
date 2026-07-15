# Profiler lifecycle and payload flow

## Request lifecycle

`Resources/config/services.yaml` is the executable wiring contract. Kernel hooks
delegate to `ProfilerHooks`, which keeps the application lifecycle in
`Application/Profiler`:

| Hook | Method | Contract |
|---|---|---|
| `muplugins_loaded` at 25 | `start` | Gate the request, initialize timing/token, emit debug headers. |
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
