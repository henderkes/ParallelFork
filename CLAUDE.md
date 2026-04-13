# henderkes/parallel-fork

Fork-based parallel execution for PHP using `pcntl_fork()`. Child processes inherit full parent state via OS copy-on-write.

Namespace: `Henderkes\ParallelFork`

## File structure
```
src/
  bootstrap.php      — loads all classes + functions
  functions.php      — run(), bootstrap(), count() convenience functions
  Runtime.php        — pcntl_fork based Runtime
  Handlers.php       — ready-made atFork handler factories (Doctrine, PDO, Redis, etc.)
  Future.php         — reads result from stream socket pair
  Channel.php        — stream_socket_pair based channels
  Events.php         — polls futures/channels for readiness
  Sync.php           — shared memory + semaphore synchronization
  Symfony/
    ForkResetSubscriber.php — auto-registers Doctrine atFork handler in Symfony apps
```

## API

### Runtime
- `__construct(?string $bootstrap = null)` — bootstrap arg accepted for API compat, ignored
- `run(\Closure $task, array $argv = []): Future`
- `close(): void` / `kill(): void`
- `static atFork(string $name, callable $callback): void` — register named handler (overrides existing with same name)
- `static atFork(callable $callback): void` — register anonymous handler (always additive)
- `static removeAtFork(string $name): void` — remove a named handler
- `static array $abandonedConnections` — stash old connection objects here to prevent destructors from closing inherited sockets

### Handlers
Ready-made atFork handler factories. Each returns a Closure for `Runtime::atFork()`.
- `static doctrine(object $emOrConnection): \Closure` — Doctrine EntityManager or DBAL Connection
- `static pdo(\PDO $pdo): \Closure` — PDO (abandon only)
- `static redis(object $redis): \Closure` — phpredis
- `static predis(object $client): \Closure` — Predis
- `static amqp(object $connection): \Closure` — AMQP

### Future
- `value(): mixed` — blocks until result, throws on child exception
- `done(): bool` / `cancel(): bool` / `cancelled(): bool`

### Channel
- `__construct(int $capacity = 0)` / `static make(string $name, ...): self` / `static open(string $name): self`
- `send(mixed $value): void` / `recv(): mixed` / `close(): void`

### Events
- `addFuture(string $name, Future $future): void` / `addChannel(Channel $channel): void`
- `remove(string $target): void` / `poll(): ?Events\Event`
- `setBlocking(bool)` / `setTimeout(int)` / `setBlocker(callable)` / `setInput(Events\Input)`
- Implements `Countable`, `IteratorAggregate`

### Sync
- `__construct(mixed $value = null)` — creates shared memory + semaphores
- `get(): mixed` / `set(mixed $value): void`
- `wait(): bool` / `notify(bool $all = false): bool`
- `__invoke(callable $block): void` — execute while holding mutex

## Connection handling after fork

The library ships ready-made handlers via `Handlers` and a Symfony integration
(`Symfony\ForkResetSubscriber`) that auto-registers the Doctrine handler when
Symfony + Doctrine are present. No application-side configuration needed.

Users can override a framework handler by registering with the same name,
or remove one entirely with `Runtime::removeAtFork('doctrine')`.

To properly abandon an inherited connection without sending a protocol-level
Terminate that would kill the parent's shared socket, stash the old object in
`Runtime::$abandonedConnections` instead of calling `close()`.

## Running tests
```bash
vendor/bin/phpunit              # fork implementation
php-zts vendor/bin/phpunit      # ext-parallel (parity check)
```
