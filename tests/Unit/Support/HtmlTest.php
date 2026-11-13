<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\View\WebProfilerAssets;

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
        self::assertStringStartsWith('<details class="profiler-long-value"><summary><span class="sf-dump-note">array</span> <span class="text-muted">(60 items)</span>', Html::dumpValue(range(1, 60))->html);
        self::assertStringStartsWith('<pre', Html::dumpValue(['a' => 1])->html);
    }

    public function testFontResolvesWithoutFileExtensionInTheUrl(): void
    {
        $assets = new WebProfilerAssets();

        self::assertNotNull($assets->fontPath('JetBrainsMono'));
        self::assertSame($assets->fontPath('JetBrainsMono'), $assets->fontPath('JetBrainsMono.woff2'));
        self::assertNull($assets->fontPath('../../wp-config'));
    }
}
