<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Support;

/** Records whether a stored option value was unserialized into a live object. */
final class WakeupSpy
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
