<?php

namespace Tests;

use Henderkes\ParallelFork\Future;
use Henderkes\ParallelFork\Runtime;
use Henderkes\ParallelFork\Runtime\Error\Closed;
use PHPUnit\Framework\TestCase;

class RuntimeTest extends TestCase
{
    public function test_run_returns_future(): void
    {
        $runtime = new Runtime;
        $future = $runtime->run(static function (): int {
            return 42;
        });

        $this->assertInstanceOf(Future::class, $future);
        $this->assertSame(42, $future->value());
        $runtime->close();
    }

    public function test_run_with_argv(): void
    {
        $runtime = new Runtime;
        $future = $runtime->run(static function (int $a, int $b): int {
            return $a + $b;
        }, [10, 32]);

        $this->assertSame(42, $future->value());
        $runtime->close();
    }

    public function test_run_after_close_throws(): void
    {
        $runtime = new Runtime;
        $runtime->close();

        $this->expectException(Closed::class);
        $runtime->run(static function (): int {
            return 1;
        });
    }

    public function test_run_after_kill_throws(): void
    {
        $runtime = new Runtime;
        $runtime->kill();

        $this->expectException(Closed::class);
        $runtime->run(static function (): int {
            return 1;
        });
    }

    public function test_close_waits_for_children(): void
    {
        $runtime = new Runtime;
        $runtime->run(static function (): bool {
            usleep(50_000);

            return true;
        });

        $start = microtime(true);
        $runtime->close();
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.04, $elapsed);
    }

    public function test_kill_terminates_children(): void
    {
        $runtime = new Runtime;
        $future = $runtime->run(static function (): void {
            sleep(2);
        });

        $start = microtime(true);
        $runtime->kill();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed);

        $this->expectException(\Henderkes\ParallelFork\Future\Error\Killed::class);
        $future->value();
    }

    public function test_before_child_handler(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->before(child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'before-child-ran');
        });

        $future = $runtime->run(static function (): int {
            return 1;
        });
        $future->value();
        $runtime->close();

        $this->assertSame('before-child-ran', file_get_contents($tmpFile));
        @unlink($tmpFile);
    }

    public function test_before_parent_handler(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->before(parent: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'before-parent-ran');
        });

        $runtime->run(static function (): int {
            return 1;
        });
        $runtime->close();

        $this->assertSame('before-parent-ran', file_get_contents($tmpFile));
        @unlink($tmpFile);
    }

    public function test_after_child_handler(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->after(child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'after-child-ran');
        });

        $future = $runtime->run(static function (): int {
            return 1;
        });
        $future->value();
        $runtime->close();

        $this->assertSame('after-child-ran', file_get_contents($tmpFile));
        @unlink($tmpFile);
    }

    public function test_after_parent_handler(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->after(parent: function (mixed $result, int $status) use ($tmpFile): void {
            file_put_contents($tmpFile, serialize(['result' => $result, 'status' => $status]));
        });

        $future = $runtime->run(static function (): string {
            return 'hello';
        });
        $future->value();
        $runtime->close();

        $data = unserialize(file_get_contents($tmpFile));
        $this->assertSame('hello', $data['result']);
        $this->assertIsInt($data['status']);
        @unlink($tmpFile);
    }

    public function test_after_parent_receives_exception_on_failure(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->after(parent: function (mixed $result, int $status) use ($tmpFile): void {
            file_put_contents($tmpFile, serialize(['is_throwable' => $result instanceof \Throwable, 'message' => $result instanceof \Throwable ? $result->getMessage() : null]));
        });

        $future = $runtime->run(static function (): void {
            throw new \RuntimeException('child-error');
        });

        try {
            $future->value();
        } catch (\Throwable) {
        }
        $runtime->close();

        $data = unserialize(file_get_contents($tmpFile));
        $this->assertTrue($data['is_throwable']);
        $this->assertStringContainsString('child-error', $data['message']);
        @unlink($tmpFile);
    }

    public function test_after_child_runs_on_exception(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->after(child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'after-child-on-error');
        });

        $future = $runtime->run(static function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            $future->value();
        } catch (\Throwable) {
        }
        $runtime->close();

        $this->assertSame('after-child-on-error', file_get_contents($tmpFile));
        @unlink($tmpFile);
    }

    public function test_named_handler_override(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        $runtime = new Runtime;

        $runtime->before(name: 'x', child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'fn1');
        });

        $runtime->before(name: 'x', child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'fn2');
        });

        $future = $runtime->run(static function (): int {
            return 1;
        });
        $future->value();
        $runtime->close();

        $this->assertSame('fn2', file_get_contents($tmpFile));
        @unlink($tmpFile);
    }

    public function test_remove_before(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        @unlink($tmpFile);
        $runtime = new Runtime;

        $runtime->before(name: 'rem', child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'should-not-exist');
        });
        $runtime->removeBefore('rem');

        $future = $runtime->run(static function (): string {
            return 'done';
        });

        $this->assertSame('done', $future->value());
        $runtime->close();

        $this->assertFileDoesNotExist($tmpFile);
    }

    public function test_remove_after(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        @unlink($tmpFile);
        $runtime = new Runtime;

        $runtime->after(name: 'rem', child: function () use ($tmpFile): void {
            file_put_contents($tmpFile, 'should-not-exist');
        });
        $runtime->removeAfter('rem');

        $future = $runtime->run(static function (): string {
            return 'done';
        });

        $this->assertSame('done', $future->value());
        $runtime->close();

        $this->assertFileDoesNotExist($tmpFile);
    }

    public function test_close_fires_after_parent(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rt_');
        file_put_contents($tmpFile, '');
        $runtime = new Runtime;

        $runtime->after(parent: function (mixed $result, int $status) use ($tmpFile): void {
            file_put_contents($tmpFile, 'after-parent-fired');
        });

        $runtime->run(static function (): string {
            return 'result';
        });

        // close() calls value() internally, which triggers childCompleted -> after(parent:)
        $runtime->close();

        $this->assertSame('after-parent-fired', file_get_contents($tmpFile));
        @unlink($tmpFile);
    }

    public function test_multiple_children(): void
    {
        $runtime = new Runtime;
        $futures = [];

        for ($i = 0; $i < 5; $i++) {
            $futures[] = $runtime->run(static function (int $n): int {
                usleep(10_000 * $n);

                return $n * $n;
            }, [$i]);
        }

        $results = [];
        foreach ($futures as $future) {
            $results[] = $future->value();
        }

        $this->assertSame([0, 1, 4, 9, 16], $results);
        $runtime->close();
    }

    public function test_fluent_api(): void
    {
        $runtime = new Runtime;

        $result = $runtime->before(child: static function (): void {});
        $this->assertSame($runtime, $result);

        $result = $runtime->after(child: static function (): void {});
        $this->assertSame($runtime, $result);

        $runtime->close();
    }
}
