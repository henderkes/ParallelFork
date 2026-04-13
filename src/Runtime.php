<?php

namespace Henderkes\ParallelFork;

/** Fork-based Runtime using pcntl_fork(). Child inherits full parent state via OS copy-on-write. */
final class Runtime
{
    private bool $closed = false;

    /** @var array<string, callable> Named handlers (string key = name) */
    private static array $namedCallbacks = [];

    /** @var list<callable> Anonymous handlers */
    private static array $anonymousCallbacks = [];

    /**
     * Stashed connections to prevent destructors from closing inherited sockets.
     * atFork handlers should stash old connection objects here instead of closing
     * them, because close() sends a protocol-level Terminate that kills the
     * parent's shared socket. The child exits and the OS cleans up.
     *
     * @var list<object>
     */
    public static array $abandonedConnections = [];

    private ?string $bootstrap;

    public function __construct(?string $bootstrap = null)
    {
        $this->bootstrap = $bootstrap;
    }

    /**
     * Register a callback to run in every child process after fork but before
     * task execution. Use this to reset connections, clear caches, or
     * reinitialize any state that should not be shared across the fork boundary.
     *
     * Named handlers (string first argument) can be overridden by registering
     * another handler with the same name. Anonymous handlers are always additive.
     *
     *     Runtime::atFork('doctrine', Handlers::doctrine($em));   // named
     *     Runtime::atFork(function () { ... });                    // anonymous
     */
    public static function atFork(string|callable $nameOrCallback, ?callable $callback = null): void
    {
        if (\is_string($nameOrCallback)) {
            if ($callback !== null) {
                self::$namedCallbacks[$nameOrCallback] = $callback;
            }
        } else {
            self::$anonymousCallbacks[] = $nameOrCallback;
        }
    }

    /**
     * Remove a named atFork handler.
     */
    public static function removeAtFork(string $name): void
    {
        unset(self::$namedCallbacks[$name]);
    }

    /**
     * @param  array<mixed>  $argv
     */
    public function run(\Closure $task, array $argv = []): Future
    {
        if ($this->closed) {
            throw new Runtime\Error\Closed('Runtime has been closed');
        }

        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (! $pair) {
            throw new \RuntimeException('stream_socket_pair failed');
        }

        // Reap any zombie children from previous forks
        /** @noinspection PhpStatementHasEmptyBodyInspection */
        while (\pcntl_waitpid(-1, $status, WNOHANG) > 0);

        $pid = \pcntl_fork();
        if ($pid < 0) {
            \fclose($pair[0]);
            \fclose($pair[1]);
            throw new \RuntimeException('fork() failed');
        }

        if ($pid === 0) {
            \fclose($pair[0]);

            if ($this->bootstrap !== null) {
                require_once $this->bootstrap;
            }

            foreach (self::$namedCallbacks as $cb) {
                try {
                    $cb();
                } catch (\Throwable) {
                }
            }
            foreach (self::$anonymousCallbacks as $cb) {
                try {
                    $cb();
                } catch (\Throwable) {
                }
            }

            try {
                $result = empty($argv) ? $task() : $task(...$argv);
                $payload = \serialize(['ok' => true, 'v' => $result]);
            } catch (\Throwable $e) {
                $payload = \serialize([
                    'ok' => false,
                    'e' => $e->getMessage(),
                    'c' => \get_class($e),
                ]);
            }

            $len = \strlen($payload);
            $offset = 0;
            while ($offset < $len) {
                $written = @\fwrite($pair[1], \substr($payload, $offset));
                if ($written === false || $written === 0) {
                    break;
                }
                $offset += $written;
            }
            \fclose($pair[1]);

            exit(0);
        }

        \fclose($pair[1]);

        return new Future($pid, $pair[0]);
    }

    public function close(): void
    {
        if ($this->closed) {
            throw new Runtime\Error\Closed('Runtime closed');
        }
        $this->closed = true;
    }

    public function kill(): void
    {
        if ($this->closed) {
            throw new Runtime\Error\Closed('Runtime closed');
        }
        $this->closed = true;
    }
}
