<?php

namespace Henderkes\ParallelFork;

/**
 * @param  array<mixed>  $argv
 */
function run(\Closure $task, array $argv = []): Future
{
    return (new Runtime)->run($task, $argv);
}
