<?php

namespace Henderkes\ParallelFork\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers default atFork handlers for services in the container.
 *
 * Discovered automatically via composer.json extra.symfony.bundles.
 * Handlers are registered by name so users can override or remove them:
 *
 *     Runtime::atFork('doctrine', fn () => ...);   // override
 *     Runtime::removeAtFork('doctrine');            // remove
 *     Runtime::atFork('my-redis', fn () => ...);   // add your own
 */
final class ParallelForkBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        $args = [null, null];

        if ($container->has('doctrine.orm.entity_manager')) {
            $args[0] = new Reference('doctrine.orm.entity_manager');
        }

        if ($container->has('http_client')) {
            $args[1] = new Reference('http_client');
        }

        $container->register('parallel_fork.registrar', AtForkRegistrar::class)
            ->setArguments($args)
            // Tagged as event listener so the event dispatcher instantiates it.
            // The constructor registers atFork handlers; the listener itself is a no-op.
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 2048]);
    }
}
