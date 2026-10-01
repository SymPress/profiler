<?php

declare(strict_types=1);

namespace SymPress\Profiler\DependencyInjection;

use SymPress\Kernel\Hook\HookLoader;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class GatedHooksPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $services = [];
        $hooks = [];

        foreach ($container->findTaggedServiceIds('profiler.hook') as $id => $tags) {
            $services[$id] = new Reference($id);

            foreach ($tags as $tag) {
                if (!is_array($tag) || !is_string($tag['hook'] ?? null) || !is_string($tag['method'] ?? null)) {
                    throw new \InvalidArgumentException('Profiler hook tags require string hook and method values.');
                }

                $hooks[] = [
                    'service'       => $id,
                    'hook'          => $tag['hook'],
                    'method'        => $tag['method'],
                    'type'          => $tag['type'] ?? 'action',
                    'priority'      => $tag['priority'] ?? 10,
                    'accepted_args' => $tag['accepted_args'] ?? 1,
                ];
            }
        }

        $container->register('profiler.hook_loader', HookLoader::class)
            ->setArguments([new ServiceLocatorArgument($services), $hooks]);
    }
}
