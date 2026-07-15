# SymPress Profiler

## Scope and entry points

- Read `docs/architecture.md` and `docs/collectors.json` before changing lifecycle, collectors, or payloads.
- `Resources/config/services.yaml` is the wiring contract for hooks, recorders, collector order, and priorities.
- `src/Application/Profiler.php` owns request lifecycle, storage, and toolbar injection.
- `src/Contract/DataCollectorInterface.php` is the collector/panel boundary; views consume stored payloads.

## Verification

- Fast behavior check: `composer tests`.
- Full required check: `composer qa`.
- Collector changes need a payload/escaping behavior test and an updated catalog entry.
- Hook/recorder changes need a lifecycle test or an exact services configuration update.

## Invariants

- Never collect raw secrets when a summary or sanitized value suffices; keep profiler access gated.
- Escape view output unless it is an explicitly trusted `HtmlString`.
- Collector keys are stable persisted-profile API. Keep them unique and synchronized with `docs/collectors.json`.
- `sympress_profiler_log_entries` is the log payload contract with `sympress/monolog-bundle`.
- Do not edit `Resources/symfony/` as if it were a SymPress override; document and preserve upstream-derived resources.

## Cross-repository impact and done

- Runtime integrations are intentionally hook/global based; do not add package dependencies solely for collection.
- A change is done when the catalog contract, focused behavior tests, and `composer qa` pass and sensitive fields remain documented.
