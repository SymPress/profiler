<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerErrorRecorder
{
    private const int E_STRICT_VALUE = 2048;

    private const int MAX_ENTRIES = 500;

    private bool $enabled = false;
    private bool $capturing = false;
    private int $dropped = 0;

    /** @var callable|null */
    private mixed $previousHandler = null;

    private ?\Closure $handler = null;

    /** @var list<array{type: int, label: string, level: string, message: string, file: string, line: int, captured_at: string}> */
    private array $entries = [];

    /** @var array{type: int, label: string, level: string, message: string, file: string, line: int, captured_at: string}|null */
    private ?array $fatalError = null;

    public function __construct(
        private readonly ProfileGate $gate,
    ) {
    }

    public function enable(): void
    {
        if ($this->enabled || !$this->gate->shouldCollect()) {
            return;
        }

        $this->enabled = true;
        $this->capture();
    }

    /**
     * Starts recording before plugins load, when the environment allows profiling but the gate cannot
     * decide yet (it needs the current user). stopEarly() drops the buffer if the gate stays closed.
     */
    public function startEarly(): void
    {
        $this->capture();
    }

    public function stopEarly(): void
    {
        if ($this->enabled) {
            return;
        }

        $this->entries = [];
        $this->dropped = 0;
        $this->uninstall();
    }

    private function capture(): void
    {
        if ($this->capturing) {
            return;
        }

        $this->capturing = true;

        // The kernel boots before any handler can be installed. Keep the last deprecation PHP saw until now;
        // other error types may have been silenced with @, which cannot be told afterwards.
        $last = error_get_last();

        if (is_array($last) && in_array($last['type'], [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            $this->store($last['type'], $last['message'], $last['file'], $last['line']);
        }

        $this->handler = $this->record(...);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- profiler-only runtime recorder for request-scoped log capture.
        $this->previousHandler = set_error_handler($this->handler);
    }

    private function uninstall(): void
    {
        $this->capturing = false;

        if ($this->handler === null) {
            return;
        }

        // Peek at the active handler: restoring blindly would remove one a plugin installed after ours.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- read-only peek, restored on the next line.
        $active = set_error_handler(null);
        restore_error_handler();

        if ($active !== $this->handler) {
            return;
        }

        restore_error_handler();
    }

    /**
     * Runs first at shutdown. Any later warning or deprecation replaces error_get_last(), so the fatal
     * error that ended the request has to be read before other shutdown callbacks run.
     */
    public function captureFatal(): void
    {
        if (!$this->enabled || $this->fatalError !== null) {
            return;
        }

        $lastError = error_get_last();

        if (!is_array($lastError) || !$this->isFatal($lastError['type'])) {
            return;
        }

        $this->fatalError = $this->normalizeEntry($lastError['type'], $lastError['message'], $lastError['file'], $lastError['line']);
    }

    public function captureShutdown(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->captureFatal();
        $this->uninstall();
    }

    public function record(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Errors silenced with @ are not log entries, except deprecations: libraries trigger those with
        // @trigger_error() so they stay quiet in production and show up in the profiler, as in Symfony.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reads the level, changes nothing.
        if ($this->capturing && ((error_reporting() & $severity) !== 0 || in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true))) {
            $this->store($severity, $message, $file, $line);
        }

        // Keep the handler that was installed before the profiler (Sentry, Query Monitor) working. PHP treats
        // every result but false as "handled", so a handler that returns nothing must stay handled.
        return is_callable($this->previousHandler)
            ? ($this->previousHandler)($severity, $message, $file, $line) !== false
            : false;
    }

    private function store(int $severity, string $message, string $file, int $line): void
    {
        // A deprecation inside a loop must not grow the profile without limit.
        if (count($this->entries) < self::MAX_ENTRIES) {
            $this->entries[] = $this->normalizeEntry($severity, $message, $file, $line);
        } else {
            $this->dropped++;
        }
    }

    /** Entries not stored because the per-request limit was reached. */
    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /** @return list<array{type: int, label: string, level: string, message: string, file: string, line: int, captured_at: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array{type: int, label: string, level: string, message: string, file: string, line: int, captured_at: string}|null */
    public function fatalError(): ?array
    {
        return $this->fatalError;
    }

    /** @return array{type: int, label: string, level: string, message: string, file: string, line: int, captured_at: string} */
    private function normalizeEntry(int $severity, string $message, string $file, int $line): array
    {
        return [
            'type'        => $severity,
            'label'       => $this->labelForSeverity($severity),
            'level'       => $this->levelForSeverity($severity),
            'message'     => $message,
            'file'        => $file,
            'line'        => $line,
            'captured_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_RFC3339_EXTENDED),
        ];
    }

    private function labelForSeverity(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            self::E_STRICT_VALUE => 'Strict',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
            default => 'Log',
        };
    }

    private function levelForSeverity(int $severity): string
    {
        return match ($severity) {
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR => 'error',
            E_WARNING,
            E_CORE_WARNING,
            E_COMPILE_WARNING,
            E_USER_WARNING => 'warning',
            E_DEPRECATED,
            E_USER_DEPRECATED => 'deprecation',
            // The Logs panel counts notices with warnings; as "info" they were in no tab but "All".
            E_NOTICE,
            E_USER_NOTICE => 'notice',
            default => 'info',
        };
    }

    private function isFatal(int $severity): bool
    {
        return in_array(
            $severity,
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true,
        );
    }
}
