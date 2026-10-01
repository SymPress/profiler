<?php

declare(strict_types=1);

namespace SymPress\Profiler;

use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Profiler\DependencyInjection\GatedHooksPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProfilerBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new GatedHooksPass());
    }
}
