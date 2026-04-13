<?php

namespace Henderkes\ParallelFork\Symfony;

use Henderkes\ParallelFork\Handlers;
use Henderkes\ParallelFork\Runtime;

/**
 * Wired by ParallelForkBundle. Registers atFork handlers for injected services.
 *
 * @internal
 */
final class AtForkRegistrar
{
    public function __construct(?object $entityManager = null, ?object $httpClient = null)
    {
        if ($entityManager !== null) {
            Runtime::atFork('doctrine', Handlers::doctrine($entityManager));
        }

        if ($httpClient !== null) {
            Runtime::atFork('http_client', Handlers::httpClient($httpClient));
        }
    }

    public function __invoke(): void {}
}
