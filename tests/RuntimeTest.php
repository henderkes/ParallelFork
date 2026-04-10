<?php

declare(strict_types=1);

namespace Tests;

class RuntimeTest extends ParallelTestCase
{
    // ── Basic execution ─────────────────────────────────────────

    public function test_run_returns_value(): void
    {
        $this->assertBothReturn(42, static function () {
            return 42;
        });
    }

    public function test_run_with_arguments(): void
    {
        $this->assertBothReturn(10, static function (int $a, int $b) {
            return $a + $b;
        }, [3, 7]);
    }

    public function test_run_with_single_argument(): void
    {
        $this->assertBothReturn('HELLO', static function (string $s) {
            return strtoupper($s);
        }, ['hello']);
    }

    public function test_run_no_return_value(): void
    {
        $this->assertBothReturn(null, static function () {
            // void
        });
    }

    public function test_run_returns_future_instance(): void
    {
        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $this->assertInstanceOf(\parallel\Future::class, $ext->run(static function () {
            return 1;
        }));
        $this->assertInstanceOf(\Henderkes\ParallelFork\Future::class, $fork->run(static function () {
            return 1;
        }));

        $ext->close();
        $fork->close();
    }

    // ── Return types ────────────────────────────────────────────

    public function test_return_string(): void
    {
        $this->assertBothReturn('hello', static function () {
            return 'hello';
        });
    }

    public function test_return_int(): void
    {
        $this->assertBothReturn(123, static function () {
            return 123;
        });
    }

    public function test_return_float(): void
    {
        $this->assertBothReturn(3.14, static function () {
            return 3.14;
        });
    }

    public function test_return_bool_true(): void
    {
        $this->assertBothReturn(true, static function () {
            return true;
        });
    }

    public function test_return_bool_false(): void
    {
        $this->assertBothReturn(false, static function () {
            return false;
        });
    }

    public function test_return_null(): void
    {
        $this->assertBothReturn(null, static function () {
            return null;
        });
    }

    public function test_return_array(): void
    {
        $this->assertBothReturn([1, 2, 3], static function () {
            return [1, 2, 3];
        });
    }

    public function test_return_nested_array(): void
    {
        $this->assertBothReturn(
            ['a' => ['b' => ['c' => [1, 2, 3]]]],
            static function () {
                return ['a' => ['b' => ['c' => [1, 2, 3]]]];
            }
        );
    }

    public function test_return_object(): void
    {
        $task = static function () {
            $o = new \stdClass;
            $o->x = 1;

            return $o;
        };
        $futures = $this->runBoth($task);

        $extResult = $futures['ext']->value();
        $forkResult = $futures['fork']->value();

        $this->assertInstanceOf(\stdClass::class, $extResult);
        $this->assertInstanceOf(\stdClass::class, $forkResult);
        $this->assertSame(1, $extResult->x);
        $this->assertSame(1, $forkResult->x);
    }

    public function test_return_large_data(): void
    {
        $task = static function () {
            return str_repeat('x', 1_000_000);
        };
        $futures = $this->runBoth($task);

        $this->assertSame(1_000_000, strlen($futures['ext']->value()));
        $this->assertSame(1_000_000, strlen($futures['fork']->value()));
    }

    // ── Concurrent tasks ────────────────────────────────────────

    public function test_multiple_concurrent_runs(): void
    {
        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $extFutures = [];
        $forkFutures = [];
        for ($i = 0; $i < 10; $i++) {
            $extFutures[$i] = $ext->run(static function (int $n) {
                return $n * 2;
            }, [$i]);
            $forkFutures[$i] = $fork->run(static function (int $n) {
                return $n * 2;
            }, [$i]);
        }

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($i * 2, $extFutures[$i]->value());
            $this->assertSame($i * 2, $forkFutures[$i]->value());
        }

        $ext->close();
        $fork->close();
    }

    public function test_truly_parallel_execution(): void
    {
        $task = static function () {
            usleep(50_000);

            return true;
        };

        // ext-parallel
        $ext = $this->createExtRuntime();
        $start = microtime(true);
        $ef = [];
        for ($i = 0; $i < 3; $i++) {
            $ef[] = $ext->run($task);
        }
        foreach ($ef as $f) {
            $f->value();
        }
        $extElapsed = microtime(true) - $start;
        $ext->close();

        // fork
        $fork = $this->createForkRuntime();
        $start = microtime(true);
        $ff = [];
        for ($i = 0; $i < 3; $i++) {
            $ff[] = $fork->run($task);
        }
        foreach ($ff as $f) {
            $f->value();
        }
        $forkElapsed = microtime(true) - $start;
        $fork->close();

        $this->assertLessThan(1.0, $extElapsed);
        $this->assertLessThan(1.0, $forkElapsed);
    }

    // ── Lifecycle ───────────────────────────────────────────────

    public function test_close_prevents_further_runs(): void
    {
        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ext->close();
        $fork->close();

        try {
            $ext->run(static function () {
                return 1;
            });
            $this->fail('ext-parallel should throw');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        try {
            $fork->run(static function () {
                return 1;
            });
            $this->fail('fork should throw');
        } catch (\Error) {
            $this->assertTrue(true);
        }
    }

    public function test_kill_prevents_further_runs(): void
    {
        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ext->kill();
        $fork->kill();

        try {
            $ext->run(static function () {
                return 1;
            });
            $this->fail('ext-parallel should throw');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        try {
            $fork->run(static function () {
                return 1;
            });
            $this->fail('fork should throw');
        } catch (\Error) {
            $this->assertTrue(true);
        }
    }

    public function test_close_twice_throws(): void
    {
        $ext = $this->createExtRuntime();
        $fork = $this->createForkRuntime();

        $ext->close();
        $fork->close();

        try {
            $ext->close();
            $this->fail('ext-parallel should throw on double close');
        } catch (\Error) {
            $this->assertTrue(true);
        }

        try {
            $fork->close();
            $this->fail('fork should throw on double close');
        } catch (\Error) {
            $this->assertTrue(true);
        }
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
            throw new \RuntimeException('specific message');
        };
        $futures = $this->runBoth($task);

        try {
            $futures['ext']->value();
        } catch (\Throwable $e) {
            $this->assertSame('specific message', $e->getMessage());
        }

        try {
            $futures['fork']->value();
        } catch (\Throwable $e) {
            $this->assertSame('specific message', $e->getMessage());
        }
    }

    public function test_exception_class_preserved(): void
    {
        $this->assertBothThrow(
            \InvalidArgumentException::class,
            static function () {
                throw new \InvalidArgumentException('bad arg');
            }
        );
    }

    // ── Argument types ──────────────────────────────────────────

    public function test_arg_string(): void
    {
        $this->assertBothReturn('HELLO', static function (string $s) {
            return strtoupper($s);
        }, ['hello']);
    }

    public function test_arg_int(): void
    {
        $this->assertBothReturn(100, static function (int $n) {
            return $n * 10;
        }, [10]);
    }

    public function test_arg_float(): void
    {
        $this->assertBothReturn(6.28, static function (float $n) {
            return $n * 2;
        }, [3.14]);
    }

    public function test_arg_bool(): void
    {
        $this->assertBothReturn(false, static function (bool $b) {
            return ! $b;
        }, [true]);
    }

    public function test_arg_array(): void
    {
        $this->assertBothReturn(6, static function (array $a) {
            return array_sum($a);
        }, [[1, 2, 3]]);
    }

    public function test_arg_object(): void
    {
        $task = static function (\stdClass $o) {
            return $o->x;
        };
        $obj = (object) ['x' => 42];

        $futures = $this->runBoth($task, [$obj]);

        $this->assertSame(42, $futures['ext']->value());
        $this->assertSame(42, $futures['fork']->value());
    }
}
