<?php

namespace Henderkes\ParallelFork;

/**
 * Polls multiple Futures and Channels for readiness.
 *
 * @implements \IteratorAggregate<int, Events\Event>
 */
final class Events implements \Countable, \IteratorAggregate
{
    /** @var array<string, Future> */
    private array $futures = [];

    /** @var array<string, Channel> */
    private array $channels = [];

    private bool $blocking = true;

    private ?int $timeout = null;

    private ?Events\Input $input = null;

    private ?\Closure $blocker = null;

    public function addFuture(string $name, Future $future): void
    {
        if (isset($this->futures[$name])) {
            throw new Events\Error\Existence("Target '$name' already exists");
        }
        $this->futures[$name] = $future;
    }

    public function addChannel(Channel $channel): void
    {
        $name = 'ch_'.\spl_object_id($channel);
        $this->channels[$name] = $channel;
    }

    public function remove(string $target): void
    {
        unset($this->futures[$target], $this->channels[$target]);
    }

    public function setInput(Events\Input $input): void
    {
        $this->input = $input;
    }

    public function setBlocking(bool $blocking): void
    {
        $this->blocking = $blocking;
    }

    public function setBlocker(callable $blocker): void
    {
        $this->blocker = $blocker(...);
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function poll(): ?Events\Event
    {
        $start = \microtime(true);

        do {
            foreach ($this->futures as $name => $future) {
                if ($future->done()) {
                    $event = new Events\Event;
                    $event->source = $name;
                    $event->object = $future;
                    $event->type = Events\Event\Type::Read;
                    try {
                        $event->value = $future->value();
                    } catch (\Throwable $e) {
                        $event->type = Events\Event\Type::Error;
                        $event->value = $e;
                    }
                    unset($this->futures[$name]);

                    return $event;
                }
            }

            // Write events: send input data to monitored channels
            if ($this->input !== null) {
                foreach ($this->channels as $name => $channel) {
                    if ($this->input->has($name)) {
                        $event = new Events\Event;
                        $event->source = $name;
                        $event->object = $channel;
                        $event->type = Events\Event\Type::Write;
                        try {
                            $channel->send($this->input->get($name));
                            $event->value = $this->input->get($name);
                        } catch (\Throwable $e) {
                            $event->type = Events\Event\Type::Error;
                            $event->value = $e;
                        }
                        $this->input->remove($name);
                        unset($this->channels[$name]);

                        return $event;
                    }
                }
            }

            if (! $this->blocking) {
                return null;
            }

            if ($this->timeout !== null) {
                $elapsed = (\microtime(true) - $start) * 1_000_000;
                if ($elapsed >= $this->timeout) {
                    throw new Events\Error\Timeout('Timeout exceeded');
                }
            }

            if ($this->blocker !== null) {
                ($this->blocker)();
            } else {
                \usleep(1000); // 1ms poll interval
            }
        } while (! empty($this->futures) || ! empty($this->channels));

        return null;
    }

    public function count(): int
    {
        return \count($this->futures) + \count($this->channels);
    }

    public function getIterator(): \Traversable
    {
        while ($this->count() > 0) {
            $event = $this->poll();
            if ($event !== null) {
                yield $event;
            }
        }
    }
}
