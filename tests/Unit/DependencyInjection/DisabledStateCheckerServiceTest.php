<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SymPress\Profiler\Application\ProfilerDisabledStateChecker;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class DisabledStateCheckerServiceTest extends TestCase
{
    public function testDisabledStateCheckerIsAClosureAsSymfonyDebugServicesExpect(): void
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 3) . '/Resources/config')))->load('services.yaml');

        $definition = $container->getDefinition('profiler.is_disabled_state_checker');

        self::assertSame(\Closure::class, $definition->getClass());
        self::assertSame([\Closure::class, 'fromCallable'], $definition->getFactory());
        $argument = $definition->getArgument(0);
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame(ProfilerDisabledStateChecker::class, (string) $argument);
    }
}
