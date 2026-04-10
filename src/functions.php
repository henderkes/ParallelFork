<?php

namespace Henderkes\ParallelFork;

/** @var bool */
$_bootstrapCalled = false;
/** @var bool */
$_runCalled = false;

/**
 * @param  array<mixed>  $argv
 */
function run(\Closure $task, array $argv = []): Future
{
    global $_runCalled;
    $_runCalled = true;

    /** @var ?Runtime $runtime */
    static $runtime = null;
    if ($runtime === null) {
        $runtime = new Runtime;
    }

    return $runtime->run($task, $argv);
}

function bootstrap(string $file): void
{
    global $_bootstrapCalled, $_runCalled;

    if ($_bootstrapCalled) {
        throw new Runtime\Error\Bootstrap('bootstrap already called');
    }
    if ($_runCalled) {
        throw new Runtime\Error\Bootstrap('bootstrap must be called before run');
    }
    $_bootstrapCalled = true;
}

function count(): int
{
    if (\is_readable('/proc/cpuinfo')) {
        $cpuinfo = \file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            return \substr_count($cpuinfo, 'processor');
        }
    }
    $nproc = @\shell_exec('nproc 2>/dev/null');
    if (\is_string($nproc) && ($n = (int) \trim($nproc)) > 0) {
        return $n;
    }
    $sysctl = @\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
    if (\is_string($sysctl) && ($n = (int) \trim($sysctl)) > 0) {
        return $n;
    }

    return 1;
}
