<?php

declare(strict_types=1);

namespace Tests;

/**
 * Tests for Events — verifies both ext-parallel and our fork implementation
 * produce matching behavior.
 *
 * All closures are static with no use() captures.
 */
class EventsTest extends ParallelTestCase
{
    // =========================================================================
    // Basic poll
    // =========================================================================

    public function test_poll_single_future(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extFuture = $extRt->run(static function () {
            return 42;
        });
        $forkFuture = $forkRt->run(static function () {
            return 42;
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('task1', $extFuture);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('task1', $forkFuture);

        $extEvent = $extEvents->poll();
        $forkEvent = $forkEvents->poll();

        $this->assertNotNull($extEvent);
        $this->assertNotNull($forkEvent);
        $this->assertSame('task1', $extEvent->source);
        $this->assertSame('task1', $forkEvent->source);
        $this->assertSame(42, $extEvent->value);
        $this->assertSame(42, $forkEvent->value);

        $extRt->close();
        $forkRt->close();
    }

    public function test_poll_return_event_type(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extFuture = $extRt->run(static function () {
            return 'success';
        });
        $forkFuture = $forkRt->run(static function () {
            return 'success';
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('ok', $extFuture);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('ok', $forkFuture);

        $extEvent = $extEvents->poll();
        $forkEvent = $forkEvents->poll();

        $this->assertNotNull($extEvent);
        $this->assertNotNull($forkEvent);
        $this->assertSame(\parallel\Events\Event\Type::Read, $extEvent->type);
        $this->assertSame(\Henderkes\ParallelFork\Events\Event\Type::Read, $forkEvent->type);
        // Both should have same integer value
        $this->assertSame($extEvent->type, $forkEvent->type);

        $extRt->close();
        $forkRt->close();
    }

    public function test_poll_multiple_futures(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extEvents = new \parallel\Events;
        $forkEvents = new \Henderkes\ParallelFork\Events;

        for ($i = 0; $i < 3; $i++) {
            $extFuture = $extRt->run(static function (int $n) {
                return $n * 10;
            }, [$i]);
            $forkFuture = $forkRt->run(static function (int $n) {
                return $n * 10;
            }, [$i]);

            $extEvents->addFuture("t{$i}", $extFuture);
            $forkEvents->addFuture("t{$i}", $forkFuture);
        }

        $extCollected = [];
        $forkCollected = [];
        for ($j = 0; $j < 3; $j++) {
            $extEvent = $extEvents->poll();
            $forkEvent = $forkEvents->poll();
            $this->assertNotNull($extEvent);
            $this->assertNotNull($forkEvent);
            $extCollected[$extEvent->source] = $extEvent->value;
            $forkCollected[$forkEvent->source] = $forkEvent->value;
        }

        $this->assertCount(3, $extCollected);
        $this->assertCount(3, $forkCollected);

        // Both should have the same set of values regardless of order
        $extValues = array_values($extCollected);
        $forkValues = array_values($forkCollected);
        sort($extValues);
        sort($forkValues);
        $this->assertSame([0, 10, 20], $extValues);
        $this->assertSame([0, 10, 20], $forkValues);

        $extRt->close();
        $forkRt->close();
    }

    public function test_event_type_error(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extFuture = $extRt->run(static function () {
            throw new \RuntimeException('child error');
        });
        $forkFuture = $forkRt->run(static function () {
            throw new \RuntimeException('child error');
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('err', $extFuture);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('err', $forkFuture);

        $extEvent = $extEvents->poll();
        $forkEvent = $forkEvents->poll();

        $this->assertNotNull($extEvent);
        $this->assertNotNull($forkEvent);
        $this->assertSame(\parallel\Events\Event\Type::Error, $extEvent->type);
        $this->assertSame(\Henderkes\ParallelFork\Events\Event\Type::Error, $forkEvent->type);
        $this->assertSame($extEvent->type, $forkEvent->type);

        $extRt->close();
        $forkRt->close();
    }

    public function test_event_source_name(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extFuture = $extRt->run(static function () {
            return 1;
        });
        $forkFuture = $forkRt->run(static function () {
            return 1;
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('my-custom-name', $extFuture);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('my-custom-name', $forkFuture);

        $extEvent = $extEvents->poll();
        $forkEvent = $forkEvents->poll();

        $this->assertNotNull($extEvent);
        $this->assertNotNull($forkEvent);
        $this->assertSame('my-custom-name', $extEvent->source);
        $this->assertSame('my-custom-name', $forkEvent->source);

        $extRt->close();
        $forkRt->close();
    }

    public function test_event_value(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extFuture = $extRt->run(static function () {
            return [1, 2, 3];
        });
        $forkFuture = $forkRt->run(static function () {
            return [1, 2, 3];
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('arr', $extFuture);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('arr', $forkFuture);

        $extEvent = $extEvents->poll();
        $forkEvent = $forkEvents->poll();

        $this->assertNotNull($extEvent);
        $this->assertNotNull($forkEvent);
        $this->assertSame([1, 2, 3], $extEvent->value);
        $this->assertSame([1, 2, 3], $forkEvent->value);

        $extRt->close();
        $forkRt->close();
    }

    // =========================================================================
    // Non-blocking / timeout
    // =========================================================================

    public function test_non_blocking_poll_on_empty(): void
    {
        $extEvents = new \parallel\Events;
        $extEvents->setBlocking(false);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->setBlocking(false);

        $extEvent = $extEvents->poll();
        $forkEvent = $forkEvents->poll();

        $this->assertNull($extEvent);
        $this->assertNull($forkEvent);
    }

    // =========================================================================
    // Count / remove
    // =========================================================================

    public function test_count_reflects_futures(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $extEvents = new \parallel\Events;
        $forkEvents = new \Henderkes\ParallelFork\Events;

        for ($i = 0; $i < 3; $i++) {
            $ef = $extRt->run(static function () {
                return 1;
            });
            $ff = $forkRt->run(static function () {
                return 1;
            });
            $extEvents->addFuture("f{$i}", $ef);
            $forkEvents->addFuture("f{$i}", $ff);
        }

        $this->assertSame(3, $extEvents->count());
        $this->assertSame(3, $forkEvents->count());

        $extRt->close();
        $forkRt->close();
    }

    public function test_count_decreases_after_poll(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $ef1 = $extRt->run(static function () {
            return 1;
        });
        $ef2 = $extRt->run(static function () {
            return 2;
        });
        $ff1 = $forkRt->run(static function () {
            return 1;
        });
        $ff2 = $forkRt->run(static function () {
            return 2;
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('a', $ef1);
        $extEvents->addFuture('b', $ef2);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('a', $ff1);
        $forkEvents->addFuture('b', $ff2);

        $this->assertSame(2, $extEvents->count());
        $this->assertSame(2, $forkEvents->count());

        $extEvents->poll();
        $forkEvents->poll();

        $this->assertSame(1, $extEvents->count());
        $this->assertSame(1, $forkEvents->count());

        $extRt->close();
        $forkRt->close();
    }

    public function test_remove(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $ef1 = $extRt->run(static function () {
            return 1;
        });
        $ef2 = $extRt->run(static function () {
            return 2;
        });
        $ff1 = $forkRt->run(static function () {
            return 1;
        });
        $ff2 = $forkRt->run(static function () {
            return 2;
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('a', $ef1);
        $extEvents->addFuture('b', $ef2);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('a', $ff1);
        $forkEvents->addFuture('b', $ff2);

        $this->assertSame(2, $extEvents->count());
        $this->assertSame(2, $forkEvents->count());

        $extEvents->remove('a');
        $forkEvents->remove('a');

        $this->assertSame(1, $extEvents->count());
        $this->assertSame(1, $forkEvents->count());

        $extRt->close();
        $forkRt->close();
    }

    public function test_add_future_duplicate_name_throws(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $ef1 = $extRt->run(static function () {
            return 1;
        });
        $ef2 = $extRt->run(static function () {
            return 2;
        });
        $ff1 = $forkRt->run(static function () {
            return 1;
        });
        $ff2 = $forkRt->run(static function () {
            return 2;
        });

        // ext-parallel
        $extEvents = new \parallel\Events;
        $extEvents->addFuture('dup', $ef1);
        $extThrew = false;
        try {
            $extEvents->addFuture('dup', $ef2);
        } catch (\Error $e) {
            $extThrew = true;
        }
        $this->assertTrue($extThrew, 'ext-parallel should throw on duplicate name');

        // fork
        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('dup', $ff1);
        $forkThrew = false;
        try {
            $forkEvents->addFuture('dup', $ff2);
        } catch (\Error $e) {
            $forkThrew = true;
        }
        $this->assertTrue($forkThrew, 'fork should throw on duplicate name');

        $extRt->close();
        $forkRt->close();
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function test_event_type_constants_match(): void
    {
        // ext-parallel constants
        $this->assertSame(1, \parallel\Events\Event\Type::Read);
        $this->assertSame(2, \parallel\Events\Event\Type::Write);
        $this->assertSame(3, \parallel\Events\Event\Type::Close);
        $this->assertSame(4, \parallel\Events\Event\Type::Error);
        $this->assertSame(5, \parallel\Events\Event\Type::Cancel);
        $this->assertSame(6, \parallel\Events\Event\Type::Kill);

        // fork constants
        $this->assertSame(1, \Henderkes\ParallelFork\Events\Event\Type::Read);
        $this->assertSame(2, \Henderkes\ParallelFork\Events\Event\Type::Write);
        $this->assertSame(3, \Henderkes\ParallelFork\Events\Event\Type::Close);
        $this->assertSame(4, \Henderkes\ParallelFork\Events\Event\Type::Error);
        $this->assertSame(5, \Henderkes\ParallelFork\Events\Event\Type::Cancel);
        $this->assertSame(6, \Henderkes\ParallelFork\Events\Event\Type::Kill);

        // Both must have identical values
        $this->assertSame(\parallel\Events\Event\Type::Read, \Henderkes\ParallelFork\Events\Event\Type::Read);
        $this->assertSame(\parallel\Events\Event\Type::Write, \Henderkes\ParallelFork\Events\Event\Type::Write);
        $this->assertSame(\parallel\Events\Event\Type::Close, \Henderkes\ParallelFork\Events\Event\Type::Close);
        $this->assertSame(\parallel\Events\Event\Type::Error, \Henderkes\ParallelFork\Events\Event\Type::Error);
        $this->assertSame(\parallel\Events\Event\Type::Cancel, \Henderkes\ParallelFork\Events\Event\Type::Cancel);
        $this->assertSame(\parallel\Events\Event\Type::Kill, \Henderkes\ParallelFork\Events\Event\Type::Kill);
    }

    // =========================================================================
    // Iterator
    // =========================================================================

    public function test_iterator(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $ef1 = $extRt->run(static function () {
            return 'x';
        });
        $ef2 = $extRt->run(static function () {
            return 'y';
        });
        $ff1 = $forkRt->run(static function () {
            return 'x';
        });
        $ff2 = $forkRt->run(static function () {
            return 'y';
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('a', $ef1);
        $extEvents->addFuture('b', $ef2);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('a', $ff1);
        $forkEvents->addFuture('b', $ff2);

        $extCollected = [];
        foreach ($extEvents as $event) {
            $extCollected[$event->source] = $event->value;
        }

        $forkCollected = [];
        foreach ($forkEvents as $event) {
            $forkCollected[$event->source] = $event->value;
        }

        $this->assertCount(2, $extCollected);
        $this->assertCount(2, $forkCollected);
        $this->assertSame('x', $extCollected['a']);
        $this->assertSame('y', $extCollected['b']);
        $this->assertSame('x', $forkCollected['a']);
        $this->assertSame('y', $forkCollected['b']);

        $extRt->close();
        $forkRt->close();
    }

    public function test_poll_after_all_consumed(): void
    {
        $extRt = $this->createExtRuntime();
        $forkRt = $this->createForkRuntime();

        $ef = $extRt->run(static function () {
            return 1;
        });
        $ff = $forkRt->run(static function () {
            return 1;
        });

        $extEvents = new \parallel\Events;
        $extEvents->addFuture('only', $ef);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkEvents->addFuture('only', $ff);

        // Consume the single future
        $this->assertNotNull($extEvents->poll());
        $this->assertNotNull($forkEvents->poll());

        // Now poll again with blocking off
        $extEvents->setBlocking(false);
        $forkEvents->setBlocking(false);

        $this->assertNull($extEvents->poll());
        $this->assertNull($forkEvents->poll());

        $extRt->close();
        $forkRt->close();
    }

    // =========================================================================
    // Countable interface
    // =========================================================================

    public function test_events_is_countable(): void
    {
        $extEvents = new \parallel\Events;
        $forkEvents = new \Henderkes\ParallelFork\Events;

        $this->assertInstanceOf(\Countable::class, $extEvents);
        $this->assertInstanceOf(\Countable::class, $forkEvents);

        $this->assertSame(0, count($extEvents));
        $this->assertSame(0, count($forkEvents));
    }

    // =========================================================================
    // Input
    // =========================================================================

    public function test_input_create(): void
    {
        $extInput = new \parallel\Events\Input;
        $forkInput = new \Henderkes\ParallelFork\Events\Input;

        $this->assertInstanceOf(\parallel\Events\Input::class, $extInput);
        $this->assertInstanceOf(\Henderkes\ParallelFork\Events\Input::class, $forkInput);
    }

    public function test_input_add_remove_clear(): void
    {
        $extInput = new \parallel\Events\Input;
        $forkInput = new \Henderkes\ParallelFork\Events\Input;

        // These should not throw on either implementation
        $extInput->add('key1', 'value1');
        $extInput->add('key2', 'value2');
        $extInput->remove('key1');
        $extInput->clear();

        $forkInput->add('key1', 'value1');
        $forkInput->add('key2', 'value2');
        $forkInput->remove('key1');
        $forkInput->clear();

        $this->assertTrue(true);
    }

    public function test_set_input_does_not_throw(): void
    {
        $extEvents = new \parallel\Events;
        $extInput = new \parallel\Events\Input;
        $extInput->add('some-target', 42);
        $extEvents->setInput($extInput);

        $forkEvents = new \Henderkes\ParallelFork\Events;
        $forkInput = new \Henderkes\ParallelFork\Events\Input;
        $forkInput->add('some-target', 42);
        $forkEvents->setInput($forkInput);

        $this->assertTrue(true);
    }
}
