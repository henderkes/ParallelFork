<?php

namespace Henderkes\ParallelFork;

/**
 * Ready-made before(child:) handler factories for common connection types.
 *
 * Usage:
 *     $rt->before(name: 'doctrine', child: Handlers::doctrine($em));
 *     $rt->before(name: 'redis', child: Handlers::redis($redis));
 */
final class Handlers
{
    /**
     * Stash an object so PHP's GC doesn't close its underlying fd.
     * Only called in child processes which exit immediately — no permanent leak.
     */
    private static function abandon(object $obj): void
    {
        // A static local in a private method — invisible to users, can't leak
        // across requests because children exit after their task.
        static $stash = [];
        $stash[] = $obj;
    }

    public static function doctrine(object $emOrConnection): \Closure
    {
        return static function () use ($emOrConnection) {
            $conn = $emOrConnection;
            if (\method_exists($emOrConnection, 'getConnection')) {
                $result = $emOrConnection->getConnection();
                if (\is_object($result)) {
                    $conn = $result;
                }
            }

            $ref = new \ReflectionClass($conn);

            $prop = null;
            foreach (['_conn', 'connection'] as $name) {
                if ($ref->hasProperty($name)) {
                    $prop = $ref->getProperty($name);
                    break;
                }
            }
            if (! $prop) {
                return;
            }

            $old = $prop->getValue($conn);
            if (\is_object($old)) {
                Handlers::abandon($old);
                $prop->setValue($conn, null);
            }
        };
    }

    public static function pdo(\PDO $pdo): \Closure
    {
        return static function () use ($pdo) {
            Handlers::abandon($pdo);
        };
    }

    public static function redis(object $redis): \Closure
    {
        return static function () use ($redis) {
            if (\method_exists($redis, 'close')) {
                try {
                    $redis->close();
                } catch (\Throwable) {
                }
            }
        };
    }

    public static function predis(object $client): \Closure
    {
        return static function () use ($client) {
            if (\method_exists($client, 'disconnect')) {
                try {
                    $client->disconnect();
                } catch (\Throwable) {
                }
            }
        };
    }

    public static function amqp(object $connection): \Closure
    {
        return static function () use ($connection) {
            if (\method_exists($connection, 'disconnect')) {
                try {
                    $connection->disconnect();
                } catch (\Throwable) {
                }
            }
        };
    }

    public static function httpClient(object $client): \Closure
    {
        return static function () use ($client) {
            try {
                self::resetCurlState($client);
            } catch (\Throwable) {
            }
        };
    }

    private static function resetCurlState(object $client, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }

        $ref = new \ReflectionClass($client);

        if ($ref->hasProperty('multi')) {
            $multiProp = $ref->getProperty('multi');
            $multi = $multiProp->getValue($client);

            if (\is_object($multi)) {
                $multiRef = new \ReflectionClass($multi);

                if ($multiRef->hasProperty('handle') && isset($multi->handle)) {
                    $handle = $multi->handle;
                    unset($multi->handle);
                    if (\is_object($handle)) {
                        self::abandon($handle);
                    }
                }

                if ($multiRef->hasProperty('share') && isset($multi->share)) {
                    $share = $multi->share;
                    unset($multi->share);
                    if (\is_object($share)) {
                        self::abandon($share);
                    }
                }
            }

            return;
        }

        if ($ref->hasProperty('client')) {
            $innerProp = $ref->getProperty('client');
            $inner = $innerProp->getValue($client);
            if (\is_object($inner)) {
                self::resetCurlState($inner, $depth + 1);
            }
        }
    }
}
