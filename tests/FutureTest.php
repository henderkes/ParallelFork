<?php

declare(strict_types=1);

namespace Tests;

class FutureTest extends ParallelTestCase
{
    // ── value() ─────────────────────────────────────────────────

    public function test_value_returns_result(): void
    {
        $this->assertBothReturn(42, static function () {
            return 42;
        });
    }

    public function test_value_blocks_until_complete(): void
    {
        $task = static function () {
            usleep(100_000);

            return 'done';
        };
        $futures = $this->runBoth($task);

        $start = hrtime(true);
        $this->assertSame('done', $futures['ext']->value());
        $extElapsed = (hrtime(true) - $start) / 1_000_000;

        $start = hrtime(true);
        $this->assertSame('done', $futures['fork']->value());
        $forkElapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertGreaterThan(10, $extElapsed);
        // fork future may already be resolved by now, so just check it returned
    }

    public function test_value_cached_on_second_call(): void
    {
        $task = static function () {
            return 99;
        };
        $futures = $this->runBoth($task);

        $this->assertSame(99, $futures['ext']->value());
        $this->assertSame(99, $futures['ext']->value());

        $this->assertSame(99, $futures['fork']->value());
        $this->assertSame(99, $futures['fork']->value());
    }

    public function test_value_returns_null_for_void(): void
    {
        $this->assertBothReturn(null, static function () {});
    }

    public function test_value_returns_string(): void
    {
        $this->assertBothReturn('hello', static function () {
            return 'hello';
        });
    }

    public function test_value_returns_int(): void
    {
        $this->assertBothReturn(-12345, static function () {
            return -12345;
        });
    }

    public function test_value_returns_float(): void
    {
        $this->assertBothReturn(3.14159, static function () {
            return 3.14159;
        });
    }

    public function test_value_returns_bool(): void
    {
        $this->assertBothReturn(true, static function () {
            return true;
        });
    }

    public function test_value_returns_array(): void
    {
        $this->assertBothReturn(['a', 'b', 'c'], static function () {
            return ['a', 'b', 'c'];
        });
    }

    public function test_value_returns_large_data(): void
    {
        $task = static function () {
            return str_repeat('X', 500_000);
        };
        $futures = $this->runBoth($task);

        $this->assertSame(500_000, strlen($futures['ext']->value()));
        $this->assertSame(500_000, strlen($futures['fork']->value()));
    }

    // ── done() ──────────────────────────────────────────────────

    public function test_done_false_while_running(): void
    {
        $task = static function () {
            usleep(500_000);

            return true;
        };

        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ef = $ext->run($task);
        $ff = $fork->run($task);

        $this->assertFalse($ef->done());
        $this->assertFalse($ff->done());

        $ef->cancel();
        $ff->cancel();
        $ext->close();
        $fork->close();
    }

    public function test_done_true_after_value(): void
    {
        $task = static function () {
            return 1;
        };
        $futures = $this->runBoth($task);

        $futures['ext']->value();
        $futures['fork']->value();

        $this->assertTrue($futures['ext']->done());
        $this->assertTrue($futures['fork']->done());
    }

    public function test_done_does_not_block(): void
    {
        $task = static function () {
            usleep(500_000);

            return true;
        };

        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ef = $ext->run($task);
        $ff = $fork->run($task);

        $start = hrtime(true);
        $ef->done();
        $ff->done();
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertLessThan(10, $elapsed);

        $ef->cancel();
        $ff->cancel();
        $ext->close();
        $fork->close();
    }

    // ── cancel() ────────────────────────────────────────────────

    public function test_cancel_running_task(): void
    {
        $task = static function () {
            sleep(10);
        };

        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ef = $ext->run($task);
        $ff = $fork->run($task);

        $this->assertTrue($ef->cancel());
        $this->assertTrue($ff->cancel());

        $ext->close();
        $fork->close();
    }

    public function test_cancel_completed_task(): void
    {
        $task = static function () {
            return 1;
        };
        $futures = $this->runBoth($task);

        $futures['ext']->value();
        $futures['fork']->value();

        $this->assertFalse($futures['ext']->cancel());
        $this->assertFalse($futures['fork']->cancel());
    }

    public function test_cancel_already_cancelled_throws(): void
    {
        $task = static function () {
            sleep(10);
        };

        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ef = $ext->run($task);
        $ff = $fork->run($task);

        $ef->cancel();
        $ff->cancel();

        try {
            $ef->cancel();
            $this->fail('ext-parallel should throw on double cancel');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        try {
            $ff->cancel();
            $this->fail('fork should throw on double cancel');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        $ext->close();
        $fork->close();
    }

    // ── cancelled() ─────────────────────────────────────────────

    public function test_cancelled_false_by_default(): void
    {
        $task = static function () {
            return 1;
        };
        $futures = $this->runBoth($task);

        $this->assertFalse($futures['ext']->cancelled());
        $this->assertFalse($futures['fork']->cancelled());

        $futures['ext']->value();
        $futures['fork']->value();
    }

    // ── Exception handling ──────────────────────────────────────

    public function test_exception_propagation(): void
    {
        $this->assertBothThrow(
            \RuntimeException::class,
            static function () {
                throw new \RuntimeException('boom');
            }
        );
    }

    public function test_exception_message_preserved(): void
    {
        $task = static function () {
            throw new \RuntimeException('specific');
        };
        $futures = $this->runBoth($task);

        try {
            $futures['ext']->value();
        } catch (\Throwable $e) {
            $this->assertSame('specific', $e->getMessage());
        }

        try {
            $futures['fork']->value();
        } catch (\Throwable $e) {
            $this->assertSame('specific', $e->getMessage());
        }
    }

    public function test_exception_rethrown_on_second_call(): void
    {
        $task = static function () {
            throw new \RuntimeException('twice');
        };
        $futures = $this->runBoth($task);

        for ($i = 0; $i < 2; $i++) {
            try {
                $futures['ext']->value();
                $this->fail('should throw');
            } catch (\RuntimeException $e) {
                $this->assertSame('twice', $e->getMessage());
            }
        }

        for ($i = 0; $i < 2; $i++) {
            try {
                $futures['fork']->value();
                $this->fail('should throw');
            } catch (\RuntimeException $e) {
                $this->assertSame('twice', $e->getMessage());
            }
        }
    }

    public function test_exception_class_preserved(): void
    {
        $this->assertBothThrow(
            \LogicException::class,
            static function () {
                throw new \LogicException('logic error');
            }
        );
    }

    public function test_value_after_cancel_throws(): void
    {
        $task = static function () {
            sleep(10);
        };

        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ef = $ext->run($task);
        $ff = $fork->run($task);

        $ef->cancel();
        $ff->cancel();

        try {
            $ef->value();
            $this->fail('ext should throw');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        try {
            $ff->value();
            $this->fail('fork should throw');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        $ext->close();
        $fork->close();
    }
}
