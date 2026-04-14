<?php

namespace Henderkes\ParallelFork\Symfony;

use Henderkes\ParallelFork\Handlers;
use Henderkes\ParallelFork\Runtime;

/**
 * @internal Creates Runtime instances with all registered before(child:) handlers.
 */
final class RuntimeFactory
{
    /** @var array<string, callable> */
    private array $beforeChild = [];

    /**
     * @param list<array{ref: object, method: string}> $taggedServices
     */
    public function __construct(?object $entityManager = null, ?object $httpClient = null, array $taggedServices = [])
    {
        if ($entityManager !== null) {
            $this->beforeChild['doctrine'] = Handlers::doctrine($entityManager);
        }

        if ($httpClient !== null) {
            $this->beforeChild['http_client'] = Handlers::httpClient($httpClient);
        }

        foreach ($taggedServices as $entry) {
            $service = $entry['ref'];
            $method = $entry['method'];
            $this->beforeChild[$service::class] = $service->$method(...);
        }
    }

    public function create(): Runtime
    {
        $runtime = new Runtime();

        foreach ($this->beforeChild as $name => $handler) {
            $runtime->before(name: $name, child: $handler);
        }

        return $runtime;
    }
}
