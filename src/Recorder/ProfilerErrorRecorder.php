<?php

declare(strict_types=1);

namespace SymPress\Profiler\Recorder;

use SymPress\Profiler\Application\ProfileGate;

final class ProfilerErrorRecorder
{
    private const int E_STRICT_VALUE = 2048;

    private bool $enabled = false;

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
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- profiler-only runtime recorder for request-scoped log capture.
        set_error_handler($this->record(...));
    }

    public function captureShutdown(): void
    {
        if (!$this->enabled) {
            return;
        }

        $lastError = error_get_last();

        if (is_array($lastError) && $this->isFatal($lastError['type'])) {
            $this->fatalError = $this->normalizeEntry(
                $lastError['type'],
                $lastError['message'],
                $lastError['file'],
                $lastError['line'],
            );
        }

        restore_error_handler();
        $this->enabled = false;
    }

    public function record(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->entries[] = $this->normalizeEntry($severity, $message, $file, $line);

        return false;
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
            'captured_at' => gmdate(DATE_ATOM),
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
