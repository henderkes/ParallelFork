<?php

namespace Henderkes\ParallelFork;

/** Channel using Unix domain socket pairs. Must be created before fork. */
final class Channel
{
    const Infinite = -1;

    /** @var resource[] */
    private array $streams = [];

    private bool $closed = false;

    private ?string $name = null;

    /** @var array<string, bool> */
    private static array $named = [];

    /** @var array<string, array<resource>> */
    private static array $namedStreams = [];

    /** @phpstan-ignore constructor.unusedParameter ($capacity accepted for API compat with ext-parallel) */
    public function __construct(int $capacity = 0)
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (! $pair) {
            throw new \RuntimeException('Failed to create channel');
        }
        $this->streams = $pair;
    }

    public static function make(string $name, int $capacity = 0): self
    {
        if (isset(self::$named[$name])) {
            throw new Channel\Error\Existence("Channel '$name' already exists");
        }

        $ch = new self($capacity);
        $ch->name = $name;
        self::$named[$name] = true;
        self::$namedStreams[$name] = $ch->streams;

        return $ch;
    }

    public static function open(string $name): self
    {
        if (! isset(self::$named[$name])) {
            throw new Channel\Error\Existence("Channel '$name' does not exist");
        }

        $ch = new self;
        $ch->name = $name;
        /** @var array<resource> $streams */
        $streams = self::$namedStreams[$name];
        $ch->streams = $streams;

        return $ch;
    }

    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new Channel\Error\Closed('Channel is closed');
        }

        $data = \serialize($value);
        $header = \pack('N', \strlen($data));
        @\fwrite($this->streams[1], $header.$data);
        @\fflush($this->streams[1]);
    }

    public function recv(): mixed
    {
        if ($this->closed) {
            throw new Channel\Error\Closed('Channel is closed');
        }

        $header = @\fread($this->streams[0], 4);
        if ($header === false || \strlen($header) < 4) {
            throw new Channel\Error\Closed('Channel is closed');
        }

        $unpacked = \unpack('Nlen', $header);
        if ($unpacked === false || ! isset($unpacked['len']) || ! \is_int($unpacked['len'])) {
            throw new Channel\Error\Closed('Channel is closed');
        }
        $len = $unpacked['len'];
        $data = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = @\fread($this->streams[0], $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= \strlen($chunk);
        }

        return \unserialize($data);
    }

    public function close(): void
    {
        if ($this->closed) {
            throw new Channel\Error\Closed("channel({$this->name}) already closed");
        }
        $this->closed = true;

        foreach ($this->streams as $s) {
            if (\is_resource($s)) {
                @\fclose($s);
            }
        }

        if ($this->name !== null) {
            unset(self::$named[$this->name]);
            unset(self::$namedStreams[$this->name]);
        }
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    public function __destruct()
    {
        // Don't auto-close — streams may be shared via fork
    }
}

namespace Henderkes\ParallelFork\Channel;

class Error extends \Henderkes\ParallelFork\Error {}

namespace Henderkes\ParallelFork\Channel\Error;

use Henderkes\ParallelFork\Channel\Error;

class Existence extends Error {}
class IllegalValue extends Error {}
class Closed extends Error {}
