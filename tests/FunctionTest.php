<?php

declare(strict_types=1);

namespace Tests;

/**
 * Tests for the run() convenience function — verifies both ext-parallel
 * and our fork implementation produce matching behavior.
 *
 * All closures are static with no use() captures.
 */
class FunctionTest extends ParallelTestCase
{
    public function test_run_returns_future(): void
    {
        $extResult = \parallel\run(static function () {
            return 1;
        });
        $forkResult = \Henderkes\ParallelFork\run(static function () {
            return 1;
        });

        $this->assertInstanceOf(\parallel\Future::class, $extResult);
        $this->assertInstanceOf(\Henderkes\ParallelFork\Future::class, $forkResult);
    }

    public function test_run_returns_correct_value(): void
    {
        $extFuture = \parallel\run(static function () {
            return 'hello from run';
        });
        $forkFuture = \Henderkes\ParallelFork\run(static function () {
            return 'hello from run';
        });

        $this->assertSame('hello from run', $extFuture->value());
        $this->assertSame('hello from run', $forkFuture->value());
    }

    public function test_run_with_arguments(): void
    {
        $extFuture = \parallel\run(static function (int $a, int $b) {
            return $a * $b;
        }, [6, 7]);
        $forkFuture = \Henderkes\ParallelFork\run(static function (int $a, int $b) {
            return $a * $b;
        }, [6, 7]);

        $this->assertSame(42, $extFuture->value());
        $this->assertSame(42, $forkFuture->value());
    }

    public function test_run_multiple_calls(): void
    {
        $extF1 = \parallel\run(static function () {
            return 'first';
        });
        $extF2 = \parallel\run(static function () {
            return 'second';
        });
        $forkF1 = \Henderkes\ParallelFork\run(static function () {
            return 'first';
        });
        $forkF2 = \Henderkes\ParallelFork\run(static function () {
            return 'second';
        });

        $this->assertSame('first', $extF1->value());
        $this->assertSame('second', $extF2->value());
        $this->assertSame('first', $forkF1->value());
        $this->assertSame('second', $forkF2->value());
    }

    public function test_run_concurrent(): void
    {
        $extFutures = [];
        $forkFutures = [];
        for ($i = 0; $i < 5; $i++) {
            $extFutures[$i] = \parallel\run(static function (int $n) {
                return $n + 100;
            }, [$i]);
            $forkFutures[$i] = \Henderkes\ParallelFork\run(static function (int $n) {
                return $n + 100;
            }, [$i]);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($i + 100, $extFutures[$i]->value());
            $this->assertSame($i + 100, $forkFutures[$i]->value());
        }
    }

    public function test_run_void_closure(): void
    {
        $extFuture = \parallel\run(static function () {
            // no return
        });
        $forkFuture = \Henderkes\ParallelFork\run(static function () {
            // no return
        });

        $this->assertNull($extFuture->value());
        $this->assertNull($forkFuture->value());
    }

    public function test_fork_count_returns_positive_int(): void
    {
        $count = \Henderkes\ParallelFork\count();
        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    public function test_fork_bootstrap_throws_after_run(): void
    {
        // run() was already called by earlier tests in this class
        $this->expectException(\Error::class);
        \Henderkes\ParallelFork\bootstrap(__FILE__);
    }
}
