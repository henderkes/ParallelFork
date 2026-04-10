<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case that runs assertions against both ext-parallel
 * and our fork implementation in the same test.
 */
abstract class ParallelTestCase extends TestCase
{
    protected function createExtRuntime(): \parallel\Runtime
    {
        return new \parallel\Runtime;
    }

    protected function createForkRuntime(): \Henderkes\ParallelFork\Runtime
    {
        return new \Henderkes\ParallelFork\Runtime;
    }

    /**
     * Run a closure on both ext-parallel and fork, return both futures.
     *
     * @param  array<mixed>  $argv
     * @return array{ext: \parallel\Future, fork: \Henderkes\ParallelFork\Future}
     */
    protected function runBoth(\Closure $task, array $argv = []): array
    {
        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $extFuture = $ext->run($task, $argv);
        $forkFuture = $fork->run($task, $argv);

        return ['ext' => $extFuture, 'fork' => $forkFuture];
    }

    /**
     * Assert both implementations return the same value for a task.
     *
     * @param  array<mixed>  $argv
     */
    protected function assertBothReturn(mixed $expected, \Closure $task, array $argv = []): void
    {
        $futures = $this->runBoth($task, $argv);

        $this->assertSame($expected, $futures['ext']->value(), 'ext-parallel returned wrong value');
        $this->assertSame($expected, $futures['fork']->value(), 'fork returned wrong value');
    }

    /**
     * Assert both implementations throw when calling value().
     *
     * @param  array<mixed>  $argv
     */
    protected function assertBothThrow(string $expectedException, \Closure $task, array $argv = []): void
    {
        $futures = $this->runBoth($task, $argv);

        $extThrew = false;
        try {
            $futures['ext']->value();
        } catch (\Throwable $e) {
            $extThrew = true;
            $this->assertInstanceOf($expectedException, $e, 'ext-parallel threw wrong exception type');
        }
        $this->assertTrue($extThrew, 'ext-parallel did not throw');

        $forkThrew = false;
        try {
            $futures['fork']->value();
        } catch (\Throwable $e) {
            $forkThrew = true;
            $this->assertInstanceOf($expectedException, $e, 'fork threw wrong exception type');
        }
        $this->assertTrue($forkThrew, 'fork did not throw');
    }
}
