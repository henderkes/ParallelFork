<?php

declare(strict_types=1);

namespace Tests;

/**
 * Tests for Sync — verifies BOTH \parallel\Sync (ext-parallel, thread-based)
 * and \Henderkes\ParallelFork\Sync (fork-based, shared memory) in the same
 * test methods.
 *
 * Cross-fork tests use \Henderkes\ParallelFork\Runtime (fork) and
 * \parallel\Runtime (threads) respectively.
 */
class SyncTest extends ParallelTestCase
{
    private bool $hasForkSync = false;

    protected function setUp(): void
    {
        $this->hasForkSync = class_exists(\Henderkes\ParallelFork\Sync::class);
    }

    private function skipIfNoForkSync(): void
    {
        if (! $this->hasForkSync) {
            $this->markTestSkipped('Fork Sync requires ext-shmop and ext-sysvsem');
        }
    }

    // =========================================================================
    // Basic get/set — both implementations
    // =========================================================================

    public function test_construct_with_value(): void
    {
        $extSync = new \parallel\Sync(42);
        $this->assertSame(42, $extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync(42);
        $this->assertSame(42, $forkSync->get());
    }

    public function test_construct_without_value(): void
    {
        $extSync = new \parallel\Sync;
        $this->assertNull($extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        $this->assertNull($forkSync->get());
    }

    public function test_set(): void
    {
        $extSync = new \parallel\Sync;
        $extSync->set(99);
        $this->assertSame(99, $extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        $forkSync->set(99);
        $this->assertSame(99, $forkSync->get());
    }

    public function test_set_multiple_times(): void
    {
        $extSync = new \parallel\Sync(0);
        $extSync->set(1);
        $extSync->set(2);
        $extSync->set(3);
        $this->assertSame(3, $extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync(0);
        $forkSync->set(1);
        $forkSync->set(2);
        $forkSync->set(3);
        $this->assertSame(3, $forkSync->get());
    }

    public function test_value_string(): void
    {
        $extSync = new \parallel\Sync;
        $extSync->set('hello world');
        $this->assertSame('hello world', $extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        $forkSync->set('hello world');
        $this->assertSame('hello world', $forkSync->get());
    }

    public function test_value_float(): void
    {
        $extSync = new \parallel\Sync;
        $extSync->set(3.14);
        $this->assertSame(3.14, $extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        $forkSync->set(3.14);
        $this->assertSame(3.14, $forkSync->get());
    }

    public function test_value_bool(): void
    {
        $extSync = new \parallel\Sync;
        $extSync->set(true);
        $this->assertTrue($extSync->get());
        $extSync->set(false);
        $this->assertFalse($extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        $forkSync->set(true);
        $this->assertTrue($forkSync->get());
        $forkSync->set(false);
        $this->assertFalse($forkSync->get());
    }

    public function test_value_null(): void
    {
        $extSync = new \parallel\Sync(42);
        $extSync->set(null);
        $this->assertNull($extSync->get());

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync(42);
        $forkSync->set(null);
        $this->assertNull($forkSync->get());
    }

    // =========================================================================
    // Scalar-only enforcement — both throw on array/object
    // =========================================================================

    public function test_value_array_throws(): void
    {
        $extSync = new \parallel\Sync;
        try {
            $extSync->set(['a' => 1]);
            $this->fail('ext-parallel Sync did not throw on array');
        } catch (\Error $e) {
            $this->assertStringContainsString('non-scalar', $e->getMessage());
        }

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        try {
            $forkSync->set(['a' => 1]);
            $this->fail('fork Sync did not throw on array');
        } catch (\Error $e) {
            $this->assertStringContainsString('non-scalar', $e->getMessage());
        }
    }

    public function test_value_object_throws(): void
    {
        $extSync = new \parallel\Sync;
        try {
            $extSync->set(new \stdClass);
            $this->fail('ext-parallel Sync did not throw on object');
        } catch (\Error $e) {
            $this->assertStringContainsString('non-scalar', $e->getMessage());
        }

        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync;
        try {
            $forkSync->set(new \stdClass);
            $this->fail('fork Sync did not throw on object');
        } catch (\Error $e) {
            $this->assertStringContainsString('non-scalar', $e->getMessage());
        }
    }

    public function test_construct_with_array_throws(): void
    {
        try {
            new \parallel\Sync([1, 2, 3]);
            $this->fail('ext-parallel Sync did not throw on array constructor');
        } catch (\Error $e) {
            $this->assertStringContainsString('non-scalar', $e->getMessage());
        }

        $this->skipIfNoForkSync();
        try {
            new \Henderkes\ParallelFork\Sync([1, 2, 3]);
            $this->fail('fork Sync did not throw on array constructor');
        } catch (\Error $e) {
            $this->assertStringContainsString('non-scalar', $e->getMessage());
        }
    }

    // =========================================================================
    // Cross-process shared state — ext-parallel via argv, fork via use()
    // =========================================================================

    public function test_cross_process_set(): void
    {
        // ext-parallel: pass Sync as argv to thread
        $extSync = new \parallel\Sync(0);
        $extRt = new \parallel\Runtime;
        $extFuture = $extRt->run(function (\parallel\Sync $s) {
            $s->set(123);

            return true;
        }, [$extSync]);
        $this->assertTrue($extFuture->value());
        $this->assertSame(123, $extSync->get());

        // fork: capture Sync via use()
        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync(0);
        $forkRt = new \Henderkes\ParallelFork\Runtime;
        try {
            $forkFuture = $forkRt->run(function () use ($forkSync) {
                $forkSync->set(123);

                return true;
            });
            $this->assertTrue($forkFuture->value());
            $this->assertSame(123, $forkSync->get());
        } finally {
            $forkRt->close();
        }
    }

    public function test_cross_process_get(): void
    {
        // ext-parallel: pass Sync as argv to thread
        $extSync = new \parallel\Sync('parent-value');
        $extRt = new \parallel\Runtime;
        $extFuture = $extRt->run(function (\parallel\Sync $s) {
            return $s->get();
        }, [$extSync]);
        $this->assertSame('parent-value', $extFuture->value());

        // fork: capture Sync via use()
        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync('parent-value');
        $forkRt = new \Henderkes\ParallelFork\Runtime;
        try {
            $forkFuture = $forkRt->run(function () use ($forkSync) {
                return $forkSync->get();
            });
            $this->assertSame('parent-value', $forkFuture->value());
        } finally {
            $forkRt->close();
        }
    }

    // =========================================================================
    // Wait/Notify — fork-only (ext-parallel's Sync::wait() uses pthread
    // condition variables which behave differently from semaphore-based wait)
    // =========================================================================

    public function test_wait_notify(): void
    {
        $this->skipIfNoForkSync();

        $forkSync = new \Henderkes\ParallelFork\Sync(0);
        $forkRt = new \Henderkes\ParallelFork\Runtime;
        try {
            $forkFuture = $forkRt->run(function () use ($forkSync) {
                usleep(20_000);
                $forkSync->set(42);
                $forkSync->notify();

                return true;
            });
            $result = $forkSync->wait();
            $this->assertTrue($result);
            $this->assertSame(42, $forkSync->get());
            $forkFuture->value();
        } finally {
            $forkRt->close();
        }
    }

    public function test_wait_notify_timing(): void
    {
        $this->skipIfNoForkSync();

        $forkSync = new \Henderkes\ParallelFork\Sync(0);
        $forkRt = new \Henderkes\ParallelFork\Runtime;
        try {
            $forkFuture = $forkRt->run(function () use ($forkSync) {
                usleep(50_000);
                $forkSync->notify();

                return true;
            });
            $start = microtime(true);
            $forkSync->wait();
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThan(0.025, $elapsed, 'fork wait() did not block');
            $this->assertLessThan(2.0, $elapsed, 'fork wait() blocked too long');
            $forkFuture->value();
        } finally {
            $forkRt->close();
        }
    }

    // =========================================================================
    // Mutex via __invoke — single-process mode (no fork needed), both impls
    // =========================================================================

    public function test_invoke_mutex(): void
    {
        // ext-parallel
        $extSync = new \parallel\Sync(0);
        $extSync(function () use ($extSync) {
            $val = $extSync->get();
            $extSync->set($val + 1);
        });
        $this->assertSame(1, $extSync->get());

        $extSync(function () use ($extSync) {
            $val = $extSync->get();
            $extSync->set($val + 10);
        });
        $this->assertSame(11, $extSync->get());

        // fork
        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync(0);
        $forkSync(function () use ($forkSync) {
            $val = $forkSync->get();
            $forkSync->set($val + 1);
        });
        $this->assertSame(1, $forkSync->get());

        $forkSync(function () use ($forkSync) {
            $val = $forkSync->get();
            $forkSync->set($val + 10);
        });
        $this->assertSame(11, $forkSync->get());
    }

    // =========================================================================
    // Concurrent mutex — cross-process, both impls
    // =========================================================================

    public function test_invoke_mutex_concurrent(): void
    {
        // ext-parallel: pass Sync as argv to threads
        $extSync = new \parallel\Sync(0);
        $extFutures = [];
        for ($i = 0; $i < 5; $i++) {
            $rt = new \parallel\Runtime;
            $extFutures[] = $rt->run(function (\parallel\Sync $s) {
                $s(function () use ($s) {
                    $val = $s->get();
                    usleep(10_000);
                    $s->set($val + 1);
                });

                return true;
            }, [$extSync]);
        }
        foreach ($extFutures as $f) {
            $this->assertTrue($f->value());
        }
        $this->assertSame(5, $extSync->get());

        // fork: capture Sync via use()
        $this->skipIfNoForkSync();
        $forkSync = new \Henderkes\ParallelFork\Sync(0);
        $forkRt = new \Henderkes\ParallelFork\Runtime;
        try {
            $forkFutures = [];
            for ($i = 0; $i < 5; $i++) {
                $forkFutures[] = $forkRt->run(function () use ($forkSync) {
                    $forkSync(function () use ($forkSync) {
                        $val = $forkSync->get();
                        usleep(10_000);
                        $forkSync->set($val + 1);
                    });

                    return true;
                });
            }
            foreach ($forkFutures as $f) {
                $this->assertTrue($f->value());
            }
            $this->assertSame(5, $forkSync->get());
        } finally {
            $forkRt->close();
        }
    }
}
