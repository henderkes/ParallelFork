<?php

namespace Henderkes\ParallelFork;

/**
 * Implement this interface on any service that holds state (connections, handles, etc.)
 * that must be reset after fork. The bundle automatically discovers tagged services
 * and registers their resetForFork() method as a before(child:) handler.
 */
interface ForkAwareInterface
{
    /**
     * Called in the child process after fork, before the task executes.
     * Reset or reconnect any resources that cannot be shared across processes.
     */
    public function resetForFork(): void;
}
