<?php

namespace Tests;

use Henderkes\ParallelFork\Future;
use Henderkes\ParallelFork\Runtime;
use PHPUnit\Framework\TestCase;

class FutureTest extends TestCase
{
    private Runtime $rt;

    protected function setUp(): void
    {
        $this->rt = new Runtime;
    }

    protected function tearDown(): void
    {
        $this->rt->close();
    }

    public function test_value_returns_result(): void
    {
        $future = $this->rt->run(static function (): int {
            return 42;
        });

        $this->assertSame(42, $future->value());
    }

    public function test_value_returns_array(): void
    {
        $future = $this->rt->run(static function (): array {
            return ['key' => 'value', 'nested' => [1, 2, 3]];
        });

        $this->assertSame(['key' => 'value', 'nested' => [1, 2, 3]], $future->value());
    }

    public function test_value_returns_null(): void
    {
        $future = $this->rt->run(static function (): mixed {
            return null;
        });

        $this->assertNull($future->value());
    }

    public function test_value_caches_result(): void
    {
        $future = $this->rt->run(static function (): string {
            return 'cached';
        });

        $first = $future->value();
        $second = $future->value();

        $this->assertSame('cached', $first);
        $this->assertSame($first, $second);
    }

    public function test_value_throws_on_child_exception(): void
    {
        $future = $this->rt->run(static function (): void {
            throw new \RuntimeException('child failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('child failed');

        $future->value();
    }

    public function test_exception_includes_child_stack_trace(): void
    {
        $future = $this->rt->run(static function (): void {
            throw new \RuntimeException('trace test');
        });

        try {
            $future->value();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Child stack trace:', $e->getMessage());
        }
    }

    public function test_exception_preserves_class(): void
    {
        $future = $this->rt->run(static function (): void {
            throw new \InvalidArgumentException('bad argument');
        });

        $this->expectException(\InvalidArgumentException::class);

        $future->value();
    }

    public function test_done_returns_false_while_running(): void
    {
        $future = $this->rt->run(static function (): bool {
            usleep(200_000);

            return true;
        });

        $this->assertFalse($future->done());
    }

    public function test_done_returns_true_after_completion(): void
    {
        $future = $this->rt->run(static function (): int {
            return 1;
        });

        $future->value();

        $this->assertTrue($future->done());
    }

    public function test_cancel_sends_sigterm(): void
    {
        $future = $this->rt->run(static function (): void {
            sleep(10);
        });

        $this->assertTrue($future->cancel());
        $this->assertTrue($future->cancelled());
    }

    public function test_cancel_on_resolved_returns_false(): void
    {
        $future = $this->rt->run(static function (): int {
            return 1;
        });

        $future->value();

        $this->assertFalse($future->cancel());
    }

    public function test_value_on_cancelled_throws(): void
    {
        $future = $this->rt->run(static function (): void {
            sleep(10);
        });

        $future->cancel();

        $this->expectException(Future\Error\Cancelled::class);

        $future->value();
    }
}
