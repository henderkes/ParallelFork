# henderkes/parallel-fork

Fork-based parallel execution for PHP using `pcntl_fork()`. Child processes inherit full parent state via OS copy-on-write.

Namespace: `Henderkes\ParallelFork`

## File structure
```
src/
  bootstrap.php      — loads all classes + functions
  functions.php      — run(), bootstrap(), count() convenience functions
  Runtime.php        — pcntl_fork based Runtime + DB reconnection logic
  Future.php         — reads result from stream socket pair
  Channel.php        — stream_socket_pair based channels
  Events.php         — polls futures/channels for readiness
  Sync.php           — shared memory + semaphore synchronization
```

## API

### Runtime
- `__construct(?string $bootstrap = null)` — bootstrap arg accepted for API compat, ignored
- `run(\Closure $task, array $argv = []): Future`
- `close(): void` / `kill(): void`
- `static afterFork(callable $callback): void` — register post-fork callbacks

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

## Connection reconnection after fork

Inherited TCP sockets are shared with the parent and will deadlock or corrupt if reused. The child scans captured closure variables via `ReflectionFunction::getStaticVariables()` and handles each connection type:

- **PDO** — abandoned (stashed to prevent destructor from sending Terminate)
- **Doctrine DBAL Connection / EntityManager** — driver connection nulled via reflection, `connect()` called for fresh socket
- **Laravel DB** — `DB::purge()` called
- **Redis (phpredis)** — `close()` called
- **Predis** — `disconnect()` called
- **AMQPConnection** — `disconnect()` called
- **Generic** — objects with `getConnection()` are unwrapped and checked recursively

We abandon (leak) rather than close() because close() sends a protocol-level Terminate that kills the parent's shared socket. SIGKILL at exit cleans up without running destructors.

For anything not auto-detected, use `Runtime::afterFork(callable)`.

## Running tests
```bash
vendor/bin/phpunit              # fork implementation
php-zts vendor/bin/phpunit      # ext-parallel (parity check)
```
