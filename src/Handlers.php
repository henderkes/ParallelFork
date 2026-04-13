<?php

namespace Henderkes\ParallelFork;

/**
 * Ready-made atFork handler factories for common connection types.
 *
 * Each method returns a Closure suitable for Runtime::atFork(). The returned
 * handler abandons the inherited connection and opens a fresh one so the
 * child process gets its own socket.
 *
 * Usage:
 *     Runtime::atFork('doctrine', Handlers::doctrine($em));
 *     Runtime::atFork('redis', Handlers::redis($redis));
 *
 * Override a default by registering with the same name:
 *     Runtime::atFork('doctrine', function () use ($em) { ... });
 *
 * Remove a default:
 *     Runtime::removeAtFork('doctrine');
 */
final class Handlers
{
    /**
     * Doctrine ORM EntityManager or DBAL Connection.
     *
     * Abandons the inherited driver connection via reflection (nulls the
     * internal property, stashes the old object) then calls connect() to
     * get a fresh socket. Only reconnects if the parent had an active
     * connection; otherwise the child connects lazily on first use.
     */
    public static function doctrine(object $emOrConnection): \Closure
    {
        return static function () use ($emOrConnection) {
            // Unwrap EntityManager → Connection if needed
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
                Runtime::$abandonedConnections[] = $old;
                $prop->setValue($conn, null);
                // Don't call connect() — it's protected in DBAL 4.x.
                // Doctrine connects lazily on the next query.
            }
        };
    }

    /**
     * phpredis \Redis client.
     *
     * Closes the inherited connection. The child reconnects on next command
     * if pconnect was used, or must call connect() explicitly.
     */
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

    /**
     * Predis client.
     *
     * Disconnects the inherited connection. Predis reconnects automatically
     * on the next command.
     */
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

    /**
     * AMQP connection.
     *
     * Disconnects the inherited connection.
     */
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

    /**
     * Symfony HttpClient (CurlHttpClient).
     *
     * Unsets the inherited curl_multi and curl_share handles so they get
     * recreated lazily in the child. The old handles are stashed to prevent
     * their destructors from interfering with the parent's connections.
     *
     * Works with decorator stacks (TraceableHttpClient, ScopingHttpClient,
     * UriTemplateHttpClient, etc.) — unwraps to the inner CurlHttpClient.
     */
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

        // CurlHttpClient: has a $multi property of type CurlClientState
        if ($ref->hasProperty('multi')) {
            $multiProp = $ref->getProperty('multi');
            $multi = $multiProp->getValue($client);

            if (\is_object($multi)) {
                $multiRef = new \ReflectionClass($multi);

                // Stash and unset handle (curl_multi) — recreated lazily via __get()
                if ($multiRef->hasProperty('handle') && isset($multi->handle)) {
                    $handle = $multi->handle;
                    unset($multi->handle);
                    if (\is_object($handle)) {
                        Runtime::$abandonedConnections[] = $handle;
                    }
                }

                // Stash and unset share (curl_share) — recreated lazily via __get()
                if ($multiRef->hasProperty('share') && isset($multi->share)) {
                    $share = $multi->share;
                    unset($multi->share);
                    if (\is_object($share)) {
                        Runtime::$abandonedConnections[] = $share;
                    }
                }
            }

            return;
        }

        // Decorator: unwrap via $client property (TraceableHttpClient,
        // ScopingHttpClient, RetryableHttpClient, UriTemplateHttpClient, etc.)
        if ($ref->hasProperty('client')) {
            $innerProp = $ref->getProperty('client');
            $inner = $innerProp->getValue($client);
            if (\is_object($inner)) {
                self::resetCurlState($inner, $depth + 1);
            }
        }
    }
}
