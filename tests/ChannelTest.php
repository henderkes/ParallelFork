<?php

declare(strict_types=1);

namespace Tests;

use Henderkes\ParallelFork\Channel as ForkChannel;
use parallel\Channel as ExtChannel;

class ChannelTest extends ParallelTestCase
{
    /** @var list<ExtChannel|ForkChannel> Channels to clean up in tearDown */
    private array $channels = [];

    protected function tearDown(): void
    {
        foreach ($this->channels as $ch) {
            try {
                $ch->close();
            } catch (\Throwable) {
            }
        }
        $this->channels = [];
    }

    /**
     * Generate a unique channel name for the current test method.
     */
    private function channelName(string $prefix, string $suffix = ''): string
    {
        $name = $prefix.'_'.$this->name().'_'.\getmypid();
        if ($suffix !== '') {
            $name .= '_'.$suffix;
        }

        return $name;
    }

    // ─── Constants ──────────────────────────────────────────────

    public function test_infinite_constant(): void
    {
        $this->assertSame(-1, ExtChannel::Infinite);
        $this->assertSame(-1, ForkChannel::Infinite);
        $this->assertSame(ExtChannel::Infinite, ForkChannel::Infinite);
    }

    // ─── Named channel lifecycle ────────────────────────────────

    public function test_make_returns_channel(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $this->assertInstanceOf(ExtChannel::class, $extCh);
        $this->assertInstanceOf(ForkChannel::class, $forkCh);
    }

    public function test_open_returns_channel(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extCh2 = ExtChannel::open($extName);
        $forkCh2 = ForkChannel::open($forkName);

        $this->assertInstanceOf(ExtChannel::class, $extCh2);
        $this->assertInstanceOf(ForkChannel::class, $forkCh2);
    }

    public function test_duplicate_make_throws(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extThrew = false;
        try {
            ExtChannel::make($extName, ExtChannel::Infinite);
        } catch (\Error $e) {
            $extThrew = true;
        }
        $this->assertTrue($extThrew, 'ext-parallel should throw on duplicate make');

        $forkThrew = false;
        try {
            ForkChannel::make($forkName, ForkChannel::Infinite);
        } catch (\Error $e) {
            $forkThrew = true;
        }
        $this->assertTrue($forkThrew, 'fork should throw on duplicate make');
    }

    public function test_open_nonexistent_throws(): void
    {
        $rand = \mt_rand();

        $extThrew = false;
        try {
            ExtChannel::open('nonexistent_ext_'.\getmypid().'_'.$rand);
        } catch (\Error $e) {
            $extThrew = true;
        }
        $this->assertTrue($extThrew, 'ext-parallel should throw on open nonexistent');

        $forkThrew = false;
        try {
            ForkChannel::open('nonexistent_fork_'.\getmypid().'_'.$rand);
        } catch (\Error $e) {
            $forkThrew = true;
        }
        $this->assertTrue($forkThrew, 'fork should throw on open nonexistent');
    }

    public function test_make_after_close(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $extCh->close();
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $forkCh->close();

        // Name should be freed — make again should work
        $extCh2 = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh2;
        $forkCh2 = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh2;

        $this->assertInstanceOf(ExtChannel::class, $extCh2);
        $this->assertInstanceOf(ForkChannel::class, $forkCh2);
    }

    public function test_multiple_named_channels(): void
    {
        $extA = ExtChannel::make($this->channelName('ext', 'a'), ExtChannel::Infinite);
        $extB = ExtChannel::make($this->channelName('ext', 'b'), ExtChannel::Infinite);
        $extC = ExtChannel::make($this->channelName('ext', 'c'), ExtChannel::Infinite);
        $this->channels[] = $extA;
        $this->channels[] = $extB;
        $this->channels[] = $extC;

        $forkA = ForkChannel::make($this->channelName('fork', 'a'), ForkChannel::Infinite);
        $forkB = ForkChannel::make($this->channelName('fork', 'b'), ForkChannel::Infinite);
        $forkC = ForkChannel::make($this->channelName('fork', 'c'), ForkChannel::Infinite);
        $this->channels[] = $forkA;
        $this->channels[] = $forkB;
        $this->channels[] = $forkC;

        $this->assertInstanceOf(ExtChannel::class, $extA);
        $this->assertInstanceOf(ExtChannel::class, $extB);
        $this->assertInstanceOf(ExtChannel::class, $extC);
        $this->assertInstanceOf(ForkChannel::class, $forkA);
        $this->assertInstanceOf(ForkChannel::class, $forkB);
        $this->assertInstanceOf(ForkChannel::class, $forkC);
    }

    // ─── Close behavior ─────────────────────────────────────────

    public function test_send_after_close_throws(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $extCh->close();
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $forkCh->close();

        $extThrew = false;
        try {
            $extCh->send('hello');
        } catch (\Error $e) {
            $extThrew = true;
        }
        $this->assertTrue($extThrew, 'ext-parallel should throw on send after close');

        $forkThrew = false;
        try {
            $forkCh->send('hello');
        } catch (\Error $e) {
            $forkThrew = true;
        }
        $this->assertTrue($forkThrew, 'fork should throw on send after close');
    }

    public function test_recv_after_close_throws(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $extCh->close();
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $forkCh->close();

        $extThrew = false;
        try {
            $extCh->recv();
        } catch (\Error $e) {
            $extThrew = true;
        }
        $this->assertTrue($extThrew, 'ext-parallel should throw on recv after close');

        $forkThrew = false;
        try {
            $forkCh->recv();
        } catch (\Error $e) {
            $forkThrew = true;
        }
        $this->assertTrue($forkThrew, 'fork should throw on recv after close');
    }

    public function test_double_close_throws(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $extCh->close();
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $forkCh->close();

        $extThrew = false;
        try {
            $extCh->close();
        } catch (\Error $e) {
            $extThrew = true;
        }
        $this->assertTrue($extThrew, 'ext-parallel should throw on double close');

        $forkThrew = false;
        try {
            $forkCh->close();
        } catch (\Error $e) {
            $forkThrew = true;
        }
        $this->assertTrue($forkThrew, 'fork should throw on double close');
    }

    // ─── Communication ──────────────────────────────────────────

    public function test_parent_to_child_communication(): void
    {
        // ext-parallel: pass channel name as argv
        $extName = $this->channelName('ext');
        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $extCh->send('hello from parent');
        $this->assertSame('hello from parent', $extFuture->value(), 'ext-parallel parent-to-child');

        // fork: pass channel name as argv
        $forkName = $this->channelName('fork');
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $forkCh->send('hello from parent');
        $this->assertSame('hello from parent', $forkFuture->value(), 'fork parent-to-child');
    }

    public function test_child_to_parent_communication(): void
    {
        // ext-parallel
        $extName = $this->channelName('ext');
        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);
            $ch->send('hello from child');

            return true;
        }, [$extName]);

        $this->assertSame('hello from child', $extCh->recv(), 'ext-parallel child-to-parent');
        $this->assertTrue($extFuture->value());

        // fork
        $forkName = $this->channelName('fork');
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);
            $ch->send('hello from child');

            return true;
        }, [$forkName]);

        $this->assertSame('hello from child', $forkCh->recv(), 'fork child-to-parent');
        $this->assertTrue($forkFuture->value());
    }

    public function test_bidirectional(): void
    {
        // ext-parallel
        $extToChild = $this->channelName('ext', 'toChild');
        $extToParent = $this->channelName('ext', 'toParent');
        $extChToChild = ExtChannel::make($extToChild, ExtChannel::Infinite);
        $extChToParent = ExtChannel::make($extToParent, ExtChannel::Infinite);
        $this->channels[] = $extChToChild;
        $this->channels[] = $extChToParent;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $inName, string $outName) {
            $chIn = \parallel\Channel::open($inName);
            $chOut = \parallel\Channel::open($outName);
            $msg = $chIn->recv();
            $chOut->send('child got: '.$msg);

            return true;
        }, [$extToChild, $extToParent]);

        $extChToChild->send('ping');
        $this->assertSame('child got: ping', $extChToParent->recv(), 'ext-parallel bidirectional');
        $this->assertTrue($extFuture->value());

        // fork
        $forkToChild = $this->channelName('fork', 'toChild');
        $forkToParent = $this->channelName('fork', 'toParent');
        $forkChToChild = ForkChannel::make($forkToChild, ForkChannel::Infinite);
        $forkChToParent = ForkChannel::make($forkToParent, ForkChannel::Infinite);
        $this->channels[] = $forkChToChild;
        $this->channels[] = $forkChToParent;

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $inName, string $outName) {
            $chIn = \Henderkes\ParallelFork\Channel::open($inName);
            $chOut = \Henderkes\ParallelFork\Channel::open($outName);
            $msg = $chIn->recv();
            $chOut->send('child got: '.$msg);

            return true;
        }, [$forkToChild, $forkToParent]);

        $forkChToChild->send('ping');
        $this->assertSame('child got: ping', $forkChToParent->recv(), 'fork bidirectional');
        $this->assertTrue($forkFuture->value());
    }

    public function test_multiple_values_in_sequence(): void
    {
        // ext-parallel
        $extName = $this->channelName('ext');
        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);
            $results = [];
            for ($i = 0; $i < 5; $i++) {
                $results[] = $ch->recv();
            }

            return $results;
        }, [$extName]);

        for ($i = 1; $i <= 5; $i++) {
            $extCh->send($i * 10);
        }
        $this->assertSame([10, 20, 30, 40, 50], $extFuture->value(), 'ext-parallel sequence');

        // fork
        $forkName = $this->channelName('fork');
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);
            $results = [];
            for ($i = 0; $i < 5; $i++) {
                $results[] = $ch->recv();
            }

            return $results;
        }, [$forkName]);

        for ($i = 1; $i <= 5; $i++) {
            $forkCh->send($i * 10);
        }
        $this->assertSame([10, 20, 30, 40, 50], $forkFuture->value(), 'fork sequence');
    }

    // ─── Type preservation ──────────────────────────────────────

    public function test_send_string(): void
    {
        $extName = $this->channelName('ext', 'str');
        $forkName = $this->channelName('fork', 'str');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send('hello world');
        $forkCh->send('hello world');

        $this->assertSame('hello world', $extFuture->value(), 'ext-parallel string');
        $this->assertSame('hello world', $forkFuture->value(), 'fork string');
    }

    public function test_send_int(): void
    {
        $extName = $this->channelName('ext', 'int');
        $forkName = $this->channelName('fork', 'int');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send(42);
        $forkCh->send(42);

        $this->assertSame(42, $extFuture->value(), 'ext-parallel int');
        $this->assertSame(42, $forkFuture->value(), 'fork int');
    }

    public function test_send_float(): void
    {
        $extName = $this->channelName('ext', 'float');
        $forkName = $this->channelName('fork', 'float');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send(3.14);
        $forkCh->send(3.14);

        $this->assertSame(3.14, $extFuture->value(), 'ext-parallel float');
        $this->assertSame(3.14, $forkFuture->value(), 'fork float');
    }

    public function test_send_bool(): void
    {
        $extName = $this->channelName('ext', 'bool');
        $forkName = $this->channelName('fork', 'bool');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send(true);
        $forkCh->send(true);

        $this->assertSame(true, $extFuture->value(), 'ext-parallel bool');
        $this->assertSame(true, $forkFuture->value(), 'fork bool');
    }

    public function test_send_null(): void
    {
        $extName = $this->channelName('ext', 'null');
        $forkName = $this->channelName('fork', 'null');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return ['received' => true, 'value' => $ch->recv()];
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return ['received' => true, 'value' => $ch->recv()];
        }, [$forkName]);

        $extCh->send(null);
        $forkCh->send(null);

        $extResult = $extFuture->value();
        $forkResult = $forkFuture->value();

        $this->assertTrue($extResult['received'], 'ext-parallel null received');
        $this->assertNull($extResult['value'], 'ext-parallel null value');
        $this->assertTrue($forkResult['received'], 'fork null received');
        $this->assertNull($forkResult['value'], 'fork null value');
    }

    public function test_send_array(): void
    {
        $data = ['foo' => 'bar', 'baz' => 123];

        $extName = $this->channelName('ext', 'arr');
        $forkName = $this->channelName('fork', 'arr');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send($data);
        $forkCh->send($data);

        $this->assertSame($data, $extFuture->value(), 'ext-parallel array');
        $this->assertSame($data, $forkFuture->value(), 'fork array');
    }

    public function test_send_nested_array(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [1, 2, 3],
                ],
                'sibling' => 'value',
            ],
        ];

        $extName = $this->channelName('ext', 'nested');
        $forkName = $this->channelName('fork', 'nested');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send($data);
        $forkCh->send($data);

        $this->assertSame($data, $extFuture->value(), 'ext-parallel nested array');
        $this->assertSame($data, $forkFuture->value(), 'fork nested array');
    }

    public function test_send_object(): void
    {
        $obj = new \stdClass;
        $obj->name = 'test';
        $obj->count = 42;
        $obj->active = true;

        $extName = $this->channelName('ext', 'obj');
        $forkName = $this->channelName('fork', 'obj');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send($obj);
        $forkCh->send($obj);

        $extResult = $extFuture->value();
        $forkResult = $forkFuture->value();

        $this->assertInstanceOf(\stdClass::class, $extResult);
        $this->assertSame('test', $extResult->name, 'ext-parallel object name');
        $this->assertSame(42, $extResult->count, 'ext-parallel object count');
        $this->assertTrue($extResult->active, 'ext-parallel object active');

        $this->assertInstanceOf(\stdClass::class, $forkResult);
        $this->assertSame('test', $forkResult->name, 'fork object name');
        $this->assertSame(42, $forkResult->count, 'fork object count');
        $this->assertTrue($forkResult->active, 'fork object active');
    }

    public function test_large_message(): void
    {
        $largeString = \str_repeat('A', 100 * 1024); // 100KB

        $extName = $this->channelName('ext', 'large');
        $forkName = $this->channelName('fork', 'large');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);

            return $ch->recv();
        }, [$extName]);

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(static function (string $chName) {
            $ch = \Henderkes\ParallelFork\Channel::open($chName);

            return $ch->recv();
        }, [$forkName]);

        $extCh->send($largeString);
        $forkCh->send($largeString);

        $extResult = $extFuture->value();
        $forkResult = $forkFuture->value();

        $this->assertSame(100 * 1024, \strlen($extResult), 'ext-parallel large message length');
        $this->assertSame($largeString, $extResult, 'ext-parallel large message content');
        $this->assertSame(100 * 1024, \strlen($forkResult), 'fork large message length');
        $this->assertSame($largeString, $forkResult, 'fork large message content');
    }

    // ─── Anonymous channel (fork-only, ext-parallel has no fork CoW) ──

    public function test_anonymous_channel(): void
    {
        // Anonymous channels rely on fork to share socket pairs via CoW.
        // ext-parallel threads cannot share anonymous channels this way.
        // We test ext-parallel named channel as equivalent, and fork anonymous.

        // ext-parallel: use a named channel as the equivalent communication test
        $extName = $this->channelName('ext', 'anon_equiv');
        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;

        $extRt = $this->createExtRuntime();
        $extFuture = $extRt->run(static function (string $chName) {
            $ch = \parallel\Channel::open($chName);
            $ch->send('from child via ext named');

            return true;
        }, [$extName]);

        $this->assertSame('from child via ext named', $extCh->recv(), 'ext-parallel named equivalent');
        $this->assertTrue($extFuture->value());

        // fork: use actual anonymous channel
        $forkCh = new ForkChannel;
        $this->channels[] = $forkCh;

        $forkRt = $this->createForkRuntime();
        $forkFuture = $forkRt->run(function () use ($forkCh) {
            $forkCh->send('from child via anonymous');

            return true;
        });

        $this->assertSame('from child via anonymous', $forkCh->recv(), 'fork anonymous channel');
        $this->assertTrue($forkFuture->value());
    }

    // ─── Multiple channels simultaneous ─────────────────────────

    public function test_multiple_channels_simultaneous(): void
    {
        // ext-parallel
        $extNames = [
            $this->channelName('ext', 'ch1'),
            $this->channelName('ext', 'ch2'),
            $this->channelName('ext', 'ch3'),
        ];
        foreach ($extNames as $name) {
            $ch = ExtChannel::make($name, ExtChannel::Infinite);
            $this->channels[] = $ch;
        }

        $extRt = $this->createExtRuntime();
        $extFutures = [];
        foreach ($extNames as $i => $name) {
            $extFutures[] = $extRt->run(static function (string $chName, int $idx) {
                $ch = \parallel\Channel::open($chName);
                $ch->send('worker_'.$idx);

                return true;
            }, [$name, $i]);
        }

        $extResults = [];
        foreach ($extNames as $name) {
            $ch = ExtChannel::open($name);
            $extResults[] = $ch->recv();
        }

        // fork
        $forkNames = [
            $this->channelName('fork', 'ch1'),
            $this->channelName('fork', 'ch2'),
            $this->channelName('fork', 'ch3'),
        ];
        foreach ($forkNames as $name) {
            $ch = ForkChannel::make($name, ForkChannel::Infinite);
            $this->channels[] = $ch;
        }

        $forkRt = $this->createForkRuntime();
        $forkFutures = [];
        foreach ($forkNames as $i => $name) {
            $forkFutures[] = $forkRt->run(static function (string $chName, int $idx) {
                $ch = \Henderkes\ParallelFork\Channel::open($chName);
                $ch->send('worker_'.$idx);

                return true;
            }, [$name, $i]);
        }

        $forkResults = [];
        foreach ($forkNames as $name) {
            $ch = ForkChannel::open($name);
            $forkResults[] = $ch->recv();
        }

        // Assert matching behavior
        $this->assertSame('worker_0', $extResults[0], 'ext-parallel worker_0');
        $this->assertSame('worker_1', $extResults[1], 'ext-parallel worker_1');
        $this->assertSame('worker_2', $extResults[2], 'ext-parallel worker_2');

        $this->assertSame('worker_0', $forkResults[0], 'fork worker_0');
        $this->assertSame('worker_1', $forkResults[1], 'fork worker_1');
        $this->assertSame('worker_2', $forkResults[2], 'fork worker_2');

        foreach ($extFutures as $f) {
            $this->assertTrue($f->value());
        }
        foreach ($forkFutures as $f) {
            $this->assertTrue($f->value());
        }
    }

    // ─── __toString ─────────────────────────────────────────────

    public function test_to_string_returns_name(): void
    {
        $extName = $this->channelName('ext');
        $forkName = $this->channelName('fork');

        $extCh = ExtChannel::make($extName, ExtChannel::Infinite);
        $this->channels[] = $extCh;
        $forkCh = ForkChannel::make($forkName, ForkChannel::Infinite);
        $this->channels[] = $forkCh;

        $this->assertSame($extName, (string) $extCh, 'ext-parallel __toString');
        $this->assertSame($forkName, (string) $forkCh, 'fork __toString');
    }

    public function test_to_string_anonymous_returns_empty(): void
    {
        // ext-parallel segfaults on anonymous Channel::__toString, so only test fork
        $forkCh = new ForkChannel;
        $this->channels[] = $forkCh;
        $this->assertSame('', (string) $forkCh, 'fork anonymous __toString');
    }
}
