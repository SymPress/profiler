<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use SymPress\Profiler\Collector\OptionsCollector;
use SymPress\Profiler\Value\ProfileRecord;

final class OptionsCollectorTest extends TestCase
{
    public function testPanelShowsOptionAndTransientValues(): void
    {
        $payload = [
            'largest_autoloaded' => [['name' => 'service_api_key', 'bytes' => 10, 'type' => 'string', 'value' => 'raw-option']],
            'transients' => [['name' => '_transient_token', 'bytes' => 13, 'autoload' => 'off', 'value' => 'raw-transient']],
        ];

        $html = (new OptionsCollector())->renderPanel($payload, new ProfileRecord('token', '2026-11-13T10:00:00+00:00', [], []))->html;

        self::assertStringContainsString('raw-option', $html);
        self::assertStringContainsString('raw-transient', $html);
    }
}
