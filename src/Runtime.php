<?php

namespace Henderkes\ParallelFork;

/** Fork-based Runtime using pcntl_fork(). Child inherits full parent state via OS copy-on-write. */
final class Runtime
{
    private bool $closed = false;

    /** @var array<int, callable> */
    private static array $afterForkCallbacks = [];

    /**
     * Stashed to prevent destructors from closing inherited sockets.
     *
     * @var array<int, object>
     *
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    private static array $abandonedConnections = []; // @phpstan-ignore property.onlyWritten

    /** @var array<int, bool> */
    private static array $processedIds = [];

    private ?string $bootstrap;

    public function __construct(?string $bootstrap = null)
    {
        $this->bootstrap = $bootstrap;
    }

    public static function afterFork(callable $callback): void
    {
        self::$afterForkCallbacks[] = $callback;
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
        while (\pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        }

        $pid = \pcntl_fork();
        if ($pid < 0) {
            \fclose($pair[0]);
            \fclose($pair[1]);
            throw new \RuntimeException('fork() failed');
        }

        if ($pid === 0) {
            \fclose($pair[0]);
            self::$processedIds = [];

            // Abandon inherited connections found in captured variables.
            // We leak rather than close() because close() sends a protocol-level
            // Terminate that kills the parent's shared socket. SIGKILL at exit
            // cleans up without running destructors.
            try {
                self::reconnectCapturedConnections($task);
            } catch (\Throwable) {
            }

            if ($this->bootstrap !== null) {
                require_once $this->bootstrap;
            }

            foreach (self::$afterForkCallbacks as $cb) {
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

    /**
     * Scan closure's captured variables for connection objects and
     * abandon+reconnect them so the child gets fresh sockets.
     */
    private static function reconnectCapturedConnections(\Closure $task): void
    {
        $rf = new \ReflectionFunction($task);
        $vars = $rf->getStaticVariables();

        foreach ($vars as $var) {
            if (! \is_object($var)) {
                continue;
            }

            $id = \spl_object_id($var);
            if (isset(self::$processedIds[$id])) {
                continue;
            }
            self::$processedIds[$id] = true;

            self::handleConnection($var);
        }

        // Laravel: purge all DB connections
        if (\class_exists(\Illuminate\Support\Facades\DB::class, false)) {
            try {
                \Illuminate\Support\Facades\DB::purge();
            } catch (\Throwable) {
            }
        }
    }

    private const MAX_SCAN_DEPTH = 8;

    /**
     * Detect what kind of connection an object holds and abandon+reconnect it.
     */
    private static function handleConnection(object $obj, int $depth = 0): void
    {
        // Doctrine EntityManager → get its DBAL Connection
        if (\interface_exists(\Doctrine\ORM\EntityManagerInterface::class, false)
            && $obj instanceof \Doctrine\ORM\EntityManagerInterface) {
            $conn = $obj->getConnection();
            $connId = \spl_object_id($conn);
            if (! isset(self::$processedIds[$connId])) {
                self::$processedIds[$connId] = true;
                self::abandonAndReconnect($conn, ['_conn', 'connection']);
            }

            return;
        }

        // Doctrine DBAL Connection
        if (\class_exists(\Doctrine\DBAL\Connection::class, false)
            && $obj instanceof \Doctrine\DBAL\Connection) {
            self::abandonAndReconnect($obj, ['_conn', 'connection']);

            return;
        }

        // Direct PDO — abandon the inherited handle
        if ($obj instanceof \PDO) {
            self::$abandonedConnections[] = $obj;

            return;
        }

        // phpredis \Redis
        if ($obj instanceof \Redis) {
            try {
                $obj->close();
                // Can't reconnect without knowing host/port — afterFork callback needed
            } catch (\Throwable) {
            }

            return;
        }

        // Predis Client
        if (\class_exists(\Predis\Client::class, false)
            && $obj instanceof \Predis\Client) {
            try {
                $obj->disconnect();
            } catch (\Throwable) {
            }

            return;
        }

        // AMQP
        if ($obj instanceof \AMQPConnection) {
            try {
                $obj->disconnect();
            } catch (\Throwable) {
            }

            return;
        }

        // Generic: any object with a getConnection() that returns something we handle
        if (\method_exists($obj, 'getConnection')) {
            try {
                $inner = $obj->getConnection();
                if (\is_object($inner)) {
                    $innerId = \spl_object_id($inner);
                    if (! isset(self::$processedIds[$innerId])) {
                        self::$processedIds[$innerId] = true;
                        self::handleConnection($inner, $depth + 1);
                    }
                }
            } catch (\Throwable) {
            }

            return;
        }

        // Recursively scan object properties for nested connections
        if ($depth < self::MAX_SCAN_DEPTH) {
            self::scanObjectProperties($obj, $depth);
        }
    }

    /**
     * Reflect into an object's properties to find nested connection objects.
     */
    private static function scanObjectProperties(object $obj, int $depth): void
    {
        try {
            $ref = new \ReflectionClass($obj);
        } catch (\Throwable) {
            return;
        }

        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            try {
                if (! $prop->isInitialized($obj)) {
                    continue;
                }
                $value = $prop->getValue($obj);
            } catch (\Throwable) {
                continue;
            }

            if (! \is_object($value)) {
                continue;
            }

            $id = \spl_object_id($value);
            if (isset(self::$processedIds[$id])) {
                continue;
            }
            self::$processedIds[$id] = true;

            self::handleConnection($value, $depth + 1);
        }
    }

    /**
     * Null out the internal driver connection via reflection so the wrapper
     * thinks it's disconnected, then call connect() for a fresh socket.
     * The old driver connection is stashed to prevent its destructor from
     * sending a protocol Terminate to the server.
     *
     * @param  array<string>  $propertyNames
     */
    private static function abandonAndReconnect(object $conn, array $propertyNames): void
    {
        try {
            $ref = new \ReflectionClass($conn);
            $prop = null;
            foreach ($propertyNames as $name) {
                if ($ref->hasProperty($name)) {
                    $prop = $ref->getProperty($name);
                    break;
                }
            }
            if (! $prop) {
                return;
            }

            $old = $prop->getValue($conn);
            if ($old === null || ! \is_object($old)) {
                return;
            }

            self::$abandonedConnections[] = $old;
            $prop->setValue($conn, null);

            if (\method_exists($conn, 'connect')) {
                $conn->connect();
            }
        } catch (\Throwable) {
        }
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

namespace Henderkes\ParallelFork\Runtime;

class Error extends \Henderkes\ParallelFork\Error {}

namespace Henderkes\ParallelFork\Runtime\Error;

use Henderkes\ParallelFork\Runtime\Error;

class Bootstrap extends Error {}
class Closed extends Error {}
class Killed extends Error {}
class IllegalFunction extends Error {}
class IllegalVariable extends Error {}
class IllegalParameter extends Error {}
class IllegalInstruction extends Error {}
class IllegalReturn extends Error {}
