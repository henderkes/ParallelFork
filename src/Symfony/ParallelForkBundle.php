<?php

namespace Henderkes\ParallelFork\Symfony;

use Henderkes\ParallelFork\ForkAwareInterface;
use Henderkes\ParallelFork\Runtime;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ParallelForkBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        // Auto-tag services implementing ForkAwareInterface
        $container->registerForAutoconfiguration(ForkAwareInterface::class)
            ->addTag('parallel_fork.reset');

        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        $factoryArgs = [null, null];

        if ($container->has('doctrine.orm.entity_manager')) {
            $factoryArgs[0] = new Reference('doctrine.orm.entity_manager');
        }

        if ($container->has('http_client')) {
            $factoryArgs[1] = new Reference('http_client');
        }

        // Collect all services tagged with 'parallel_fork.reset'.
        // Two ways to get tagged:
        //   1. Implement ForkAwareInterface (auto-tagged above) — calls resetForFork()
        //   2. Manually tag in services.yaml with a 'method' attribute:
        //      tags: [{ name: parallel_fork.reset, method: reconnect }]
        $tagged = [];
        foreach ($container->findTaggedServiceIds('parallel_fork.reset') as $id => $tags) {
            $method = $tags[0]['method'] ?? 'resetForFork';
            $tagged[] = ['ref' => new Reference($id), 'method' => $method];
        }

        $factoryArgs[2] = $tagged;

        $container->register(RuntimeFactory::class, RuntimeFactory::class)
            ->setArguments($factoryArgs);

        $container->register(Runtime::class, Runtime::class)
            ->setFactory([new Reference(RuntimeFactory::class), 'create'])
            ->setShared(false);
    }
}
