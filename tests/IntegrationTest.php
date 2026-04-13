<?php

declare(strict_types=1);

namespace Tests;

use Henderkes\ParallelFork\Runtime;

/**
 * Fork-only integration tests.
 *
 * These exercise fork-specific behavior: captured variables via use(),
 * atFork callbacks, arrow functions, process isolation, etc.
 * ext-parallel cannot do these things, so we use \Henderkes\ParallelFork\Runtime directly.
 */
class IntegrationTest extends ParallelTestCase
{
    private ?Runtime $runtime = null;

    protected function setUp(): void
    {
        $this->runtime = new Runtime;
    }

    protected function tearDown(): void
    {
        if ($this->runtime !== null) {
            try {
                $this->runtime->close();
            } catch (\Throwable) {
            }
            $this->runtime = null;
        }

        // Clean up any atFork callbacks registered during tests.
        try {
            $ref = new \ReflectionClass(Runtime::class);
            $ref->getProperty('namedCallbacks')->setValue(null, []);
            $ref->getProperty('anonymousCallbacks')->setValue(null, []);
        } catch (\Throwable) {
        }
    }

    // =========================================================================
    // Captured variables via use()
    // =========================================================================

    public function test_captured_variables_via_use(): void
    {
        $greeting = 'hello fork';

        $future = $this->runtime->run(function () use ($greeting) {
            return strtoupper($greeting);
        });

        $this->assertSame('HELLO FORK', $future->value());
    }

    public function test_captured_array_via_use(): void
    {
        $numbers = [10, 20, 30, 40];

        $future = $this->runtime->run(function () use ($numbers) {
            return array_sum($numbers);
        });

        $this->assertSame(100, $future->value());
    }

    public function test_captured_object_via_use(): void
    {
        $obj = new \stdClass;
        $obj->name = 'test-object';
        $obj->value = 99;

        $future = $this->runtime->run(function () use ($obj) {
            return $obj->name.':'.$obj->value;
        });

        $this->assertSame('test-object:99', $future->value());
    }

    // =========================================================================
    // File I/O
    // =========================================================================

    public function test_file_io(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fork_test_');
        $content = 'written-by-child-'.getmypid();

        $future = $this->runtime->run(function () use ($tmpFile, $content) {
            file_put_contents($tmpFile, $content);

            return true;
        });

        $this->assertTrue($future->value());
        $this->assertFileExists($tmpFile);
        $this->assertSame($content, file_get_contents($tmpFile));

        @unlink($tmpFile);
    }

    // =========================================================================
    // atFork callbacks
    // =========================================================================

    public function test_at_fork_callback_runs(): void
    {
        $markerFile = tempnam(sys_get_temp_dir(), 'fork_cb_');
        @unlink($markerFile);

        Runtime::atFork(function () use ($markerFile) {
            file_put_contents($markerFile, 'callback-ran');
        });

        $future = $this->runtime->run(static function () {
            return 'done';
        });

        $this->assertSame('done', $future->value());
        $this->assertFileExists($markerFile);
        $this->assertSame('callback-ran', file_get_contents($markerFile));

        @unlink($markerFile);
    }

    public function test_multiple_at_fork_callbacks(): void
    {
        $marker1 = tempnam(sys_get_temp_dir(), 'fork_cb1_');
        $marker2 = tempnam(sys_get_temp_dir(), 'fork_cb2_');
        @unlink($marker1);
        @unlink($marker2);

        Runtime::atFork(function () use ($marker1) {
            file_put_contents($marker1, 'cb1');
        });
        Runtime::atFork(function () use ($marker2) {
            file_put_contents($marker2, 'cb2');
        });

        $future = $this->runtime->run(static function () {
            return 'ok';
        });

        $this->assertSame('ok', $future->value());
        $this->assertFileExists($marker1);
        $this->assertFileExists($marker2);
        $this->assertSame('cb1', file_get_contents($marker1));
        $this->assertSame('cb2', file_get_contents($marker2));

        @unlink($marker1);
        @unlink($marker2);
    }

    // =========================================================================
    // Process isolation
    // =========================================================================

    public function test_child_process_isolation(): void
    {
        $future1 = $this->runtime->run(static function () {
            $local = 'modified-in-child';

            return $local;
        });

        $this->assertSame('modified-in-child', $future1->value());

        $future2 = $this->runtime->run(static function () {
            return 'parent-ok';
        });

        $this->assertSame('parent-ok', $future2->value());
    }

    // =========================================================================
    // Nested closures
    // =========================================================================

    public function test_nested_closures(): void
    {
        $future = $this->runtime->run(static function () {
            $inner = static function (int $x): int {
                return $x * $x;
            };

            return $inner(7);
        });

        $this->assertSame(49, $future->value());
    }

    // =========================================================================
    // Large payload
    // =========================================================================

    public function test_large_payload(): void
    {
        $future = $this->runtime->run(static function () {
            return str_repeat('A', 2 * 1024 * 1024); // 2MB
        });

        $result = $future->value();
        $this->assertIsString($result);
        $this->assertSame(2 * 1024 * 1024, strlen($result));
    }

    // =========================================================================
    // Stability under many forks
    // =========================================================================

    public function test_many_forks_stability(): void
    {
        $futures = [];
        for ($i = 0; $i < 30; $i++) {
            $futures[$i] = $this->runtime->run(static function (int $n) {
                return $n * $n;
            }, [$i]);
        }

        for ($i = 0; $i < 30; $i++) {
            $this->assertSame($i * $i, $futures[$i]->value());
        }
    }

    // =========================================================================
    // Arrow functions — fork-only (capture via use() implicit)
    // =========================================================================

    public function test_arrow_function_captures_scope(): void
    {
        $multiplier = 3;
        $prefix = 'result';
        $data = [1, 2, 3, 4, 5];

        $future = $this->runtime->run(
            fn () => "$prefix: ".array_sum(array_map(fn ($x) => $x * $multiplier, $data))
        );

        $this->assertSame('result: 45', $future->value());
    }

    public function test_arrow_function_captures_multiple_vars(): void
    {
        $a = 10;
        $b = 20;
        $c = 'hello';
        $arr = ['x' => 1, 'y' => 2];

        $future = $this->runtime->run(fn () => [
            'sum' => $a + $b,
            'msg' => $c,
            'keys' => array_keys($arr),
        ]);

        $result = $future->value();
        $this->assertSame(30, $result['sum']);
        $this->assertSame('hello', $result['msg']);
        $this->assertSame(['x', 'y'], $result['keys']);
    }

    public function test_arrow_function_captures_object(): void
    {
        $obj = (object) ['value' => 42];

        $future = $this->runtime->run(fn () => $obj->value * 2);

        $this->assertSame(84, $future->value());
    }

    public function test_arrow_function_parallel_with_shared_captures(): void
    {
        $items = range(1, 100);
        $chunks = array_chunk($items, 25);

        $futures = array_map(
            fn ($chunk) => $this->runtime->run(fn () => array_sum($chunk)),
            $chunks
        );

        $total = array_sum(array_map(fn ($f) => $f->value(), $futures));
        $this->assertSame(5050, $total);
    }
}
