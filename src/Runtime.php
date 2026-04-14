<?php

namespace Henderkes\ParallelFork;

final class Runtime
{
    private bool $closed = false;

    /** @var array<int, Future> */
    private array $children = [];

    /** @var array<string, callable> */
    private array $beforeChildNamed = [];

    /** @var list<callable> */
    private array $beforeChildAnon = [];

    /** @var array<string, callable> */
    private array $beforeParentNamed = [];

    /** @var list<callable> */
    private array $beforeParentAnon = [];

    /** @var array<string, callable> */
    private array $afterChildNamed = [];

    /** @var list<callable> */
    private array $afterChildAnon = [];

    /** @var array<string, callable> */
    private array $afterParentNamed = [];

    /** @var list<callable> */
    private array $afterParentAnon = [];


    public function before(?callable $child = null, ?callable $parent = null, ?string $name = null): self
    {
        if ($name !== null) {
            if ($child !== null) {
                $this->beforeChildNamed[$name] = $child;
            }
            if ($parent !== null) {
                $this->beforeParentNamed[$name] = $parent;
            }
        } else {
            if ($child !== null) {
                $this->beforeChildAnon[] = $child;
            }
            if ($parent !== null) {
                $this->beforeParentAnon[] = $parent;
            }
        }

        return $this;
    }

    public function after(?callable $child = null, ?callable $parent = null, ?string $name = null): self
    {
        if ($name !== null) {
            if ($child !== null) {
                $this->afterChildNamed[$name] = $child;
            }
            if ($parent !== null) {
                $this->afterParentNamed[$name] = $parent;
            }
        } else {
            if ($child !== null) {
                $this->afterChildAnon[] = $child;
            }
            if ($parent !== null) {
                $this->afterParentAnon[] = $parent;
            }
        }

        return $this;
    }

    public function removeBefore(string $name): self
    {
        unset($this->beforeChildNamed[$name], $this->beforeParentNamed[$name]);

        return $this;
    }

    public function removeAfter(string $name): self
    {
        unset($this->afterChildNamed[$name], $this->afterParentNamed[$name]);

        return $this;
    }

    /**
     * @param  array<mixed>  $argv
     */
    public function run(\Closure $task, array $argv = []): Future
    {
        if ($this->closed) {
            throw new Runtime\Error\Closed('Runtime has been closed');
        }

        foreach ($this->beforeParentNamed as $cb) {
            try {
                $cb();
            } catch (\Throwable) {
            }
        }
        foreach ($this->beforeParentAnon as $cb) {
            try {
                $cb();
            } catch (\Throwable) {
            }
        }

        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (! $pair) {
            throw new \RuntimeException('stream_socket_pair failed');
        }

        $pid = \pcntl_fork();
        if ($pid < 0) {
            \fclose($pair[0]);
            \fclose($pair[1]);
            throw new \RuntimeException('pcntl_fork() failed');
        }

        if ($pid === 0) {
            // Prevent child's destructors from interfering with parent's futures
            Future::$inChild = true;
            $this->children = [];
            $this->closed = true;

            \fclose($pair[0]);

            foreach ($this->beforeChildNamed as $cb) {
                try {
                    $cb();
                } catch (\Throwable) {
                }
            }
            foreach ($this->beforeChildAnon as $cb) {
                try {
                    $cb();
                } catch (\Throwable) {
                }
            }

            $exitCode = 0;
            $payload = '';
            try {
                $result = empty($argv) ? $task() : $task(...$argv);
                $payload = \serialize(['ok' => true, 'v' => $result]);
            } catch (\Throwable $e) {
                $exitCode = 1;
                $payload = \serialize([
                    'ok' => false,
                    'e' => $e->getMessage(),
                    'c' => \get_class($e),
                    't' => $e->getTraceAsString(),
                ]);
            } finally {
                foreach ($this->afterChildNamed as $cb) {
                    try {
                        $cb();
                    } catch (\Throwable) {
                    }
                }
                foreach ($this->afterChildAnon as $cb) {
                    try {
                        $cb();
                    } catch (\Throwable) {
                    }
                }
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

            exit($exitCode);
        }

        \fclose($pair[1]);

        $future = new Future($pid, $pair[0], $this);
        $this->children[$pid] = $future;

        return $future;
    }

    public function childCompleted(int $pid, mixed $result, int $status): void
    {
        unset($this->children[$pid]);

        foreach ($this->afterParentNamed as $cb) {
            try {
                $cb($result, $status);
            } catch (\Throwable) {
            }
        }
        foreach ($this->afterParentAnon as $cb) {
            try {
                $cb($result, $status);
            } catch (\Throwable) {
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            throw new Runtime\Error\Closed('Runtime has been closed');
        }

        $this->closed = true;

        // Copy to avoid issues with childCompleted() mutating $this->children
        $children = $this->children;
        foreach ($children as $pid => $future) {
            try {
                $future->value();
            } catch (\Throwable) {
                // value() threw (cancelled, error, etc.) — ensure child is reaped
                \pcntl_waitpid($pid, $status);
            }
        }
        $this->children = [];
    }

    public function kill(): void
    {
        if ($this->closed) {
            throw new Runtime\Error\Closed('Runtime has been closed');
        }

        $this->closed = true;

        foreach ($this->children as $pid => $future) {
            $future->markKilled();
            \posix_kill($pid, SIGKILL);
        }

        foreach ($this->children as $pid => $future) {
            \pcntl_waitpid($pid, $status);
        }

        $this->children = [];
    }

    public function __destruct()
    {
        if (! $this->closed) {
            try {
                $this->close();
            } catch (\Throwable) {
            }
        }
    }
}
