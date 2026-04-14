<?php

namespace Henderkes\ParallelFork\Symfony;

use Henderkes\ParallelFork\Runtime;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ParallelForkBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->register('parallel_fork.runtime', Runtime::class)
            ->setPublic(true);

        $args = [new Reference('parallel_fork.runtime'), null, null];

        if ($container->has('doctrine.orm.entity_manager')) {
            $args[1] = new Reference('doctrine.orm.entity_manager');
        }

        if ($container->has('http_client')) {
            $args[2] = new Reference('http_client');
        }

        $container->register('parallel_fork.registrar', AtForkRegistrar::class)
            ->setArguments($args)
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 2048]);
    }
}
