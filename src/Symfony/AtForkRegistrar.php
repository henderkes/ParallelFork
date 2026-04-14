<?php

namespace Henderkes\ParallelFork\Symfony;

use Henderkes\ParallelFork\Handlers;
use Henderkes\ParallelFork\Runtime;

/**
 * @internal Wired by ParallelForkBundle.
 */
final class AtForkRegistrar
{
    public function __construct(Runtime $runtime, ?object $entityManager = null, ?object $httpClient = null)
    {
        if ($entityManager !== null) {
            $runtime->before(name: 'doctrine', child: Handlers::doctrine($entityManager));
        }

        if ($httpClient !== null) {
            $runtime->before(name: 'http_client', child: Handlers::httpClient($httpClient));
        }
    }

    public function __invoke(): void {}
}
