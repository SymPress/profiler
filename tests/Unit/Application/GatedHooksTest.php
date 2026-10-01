<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use SymPress\Profiler\Hook\GatedHooksBootstrap;
use SymPress\Profiler\DependencyInjection\GatedHooksPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GatedHooksTest extends TestCase
{
    public function testEarlyClosedGateRegistersNothingAndCanOpenAfterAuthentication(): void
    {
        \ProfilerTestFilters::reset();
        $config = $this->createStub(SiteConfig::class);
        $config->method('envIs')->willReturnCallback(static fn (string $environment): bool => $environment === EnvConfig::LOCAL);
        $gate = new ProfileGate($config, WpContext::new()->force(WpContext::FRONTOFFICE), new ProfilerRequestMatcher());
        $container = new ContainerBuilder();
        $container->register('probe', GatedTemplateProbe::class)->addTag('profiler.hook', ['hook' => 'template_include', 'method' => 'capture', 'type' => 'filter']);
        (new GatedHooksPass())->process($container);
        $definition = $container->getDefinition('profiler.hook_loader')->setPublic(true);
        self::assertSame(1, $definition->getArgument(1)[0]['accepted_args']);
        $container->compile();
        $loader = $container->get('profiler.hook_loader');
        self::assertInstanceOf(HookLoader::class, $loader);
        $bootstrap = new GatedHooksBootstrap($gate, $loader);
        try {
            $GLOBALS['profiler_test_logged_in'] = false;
            $bootstrap->register();
            self::assertSame([], \ProfilerTestFilters::$callbacks);
            $GLOBALS['profiler_test_logged_in'] = true;
            $bootstrap->register();
            self::assertArrayHasKey('template_include', \ProfilerTestFilters::$callbacks);
            self::assertSame('template.php', apply_filters('template_include', 'template.php'));
        } finally {
            unset($GLOBALS['profiler_test_logged_in']);
            \ProfilerTestFilters::reset();
        }
    }
}

final class GatedTemplateProbe
{
    public function capture(string $template): string
    {
        return $template;
    }
}
