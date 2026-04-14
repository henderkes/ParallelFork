<?php

namespace Henderkes\ParallelFork;

/**
 * @param  array<mixed>  $argv
 */
function run(\Closure $task, array $argv = []): Future
{
    /** @var ?Runtime $runtime */
    static $runtime = null;
    if ($runtime === null) {
        $runtime = new Runtime;
    }

    return $runtime->run($task, $argv);
}
