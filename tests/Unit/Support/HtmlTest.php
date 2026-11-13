<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SymPress\Profiler\Support\Html;

final class HtmlTest extends TestCase
{
    public function testTableKeepsShortValuesOnOneLineAndLetsWideColumnsGiveWay(): void
    {
        $html = Html::table(['Hook', 'Priority', 'File'], [['plugins_loaded', 10, '/var/www/html/wp-includes/plugin.php'], [str_repeat('x', 41), 10, '']]);

        self::assertStringContainsString('<td class="nowrap">plugins_loaded</td>', $html);
        self::assertStringContainsString('<td class="break-long-words">' . str_repeat('x', 41) . '</td>', $html);
        self::assertStringContainsString('<td class="break-long-words profiler-wide-col">/var/www/html/wp-includes/plugin.php</td>', $html);
        self::assertStringContainsString('<th class="break-long-words profiler-wide-col">File</th>', $html);
    }

    public function testLongValuesAreCollapsedButComplete(): void
    {
        $long = str_repeat('a:1:{s:3:"<b>";}', 40);
        $html = Html::dumpValue($long)->html;

        self::assertStringStartsWith('<details class="profiler-long-value">', $html);
        self::assertStringContainsString(sprintf('(%d bytes)', strlen($long)), $html);
        self::assertStringContainsString(htmlspecialchars($long, ENT_QUOTES), $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame('<span class="sf-dump-str">"short"</span>', Html::dumpValue('short')->html);
    }
}
