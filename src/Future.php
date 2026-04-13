<?php

namespace Henderkes\ParallelFork;

final class Future
{
    private bool $resolved = false;

    private bool $isError = false;

    private bool $isCancelled = false;

    private mixed $cached = null;

    private ?\Throwable $cachedError = null;

    /** @var resource|closed-resource|null */
    private mixed $stream;

    /**
     * @internal
     *
     * @param  resource  $stream
     */
    public function __construct(
        private int $pid,
        mixed $stream,
    ) {
        if (! \is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a valid resource for stream');
        }
        $this->stream = $stream;
    }

    public function value(): mixed
    {
        if ($this->isCancelled) {
            throw new Future\Error\Cancelled('cannot retrieve value');
        }

        if ($this->resolved) {
            if ($this->isError && $this->cachedError !== null) {
                throw $this->cachedError;
            }

            return $this->cached;
        }

        $data = '';
        $stream = $this->stream;
        if (! \is_resource($stream)) {
            throw new Future\Error\Foreign('Stream is not a valid resource');
        }
        while (! \feof($stream)) {
            $chunk = \fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        \fclose($stream);
        $this->stream = null;

        \pcntl_waitpid($this->pid, $status);
        $this->resolved = true;

        if ($data === '') {
            $this->cached = null;

            return null;
        }

        $result = @\unserialize($data);
        if (! \is_array($result) || ! isset($result['ok'])) {
            $this->isError = true;
            $this->cachedError = new Future\Error\Foreign('Invalid data from child process');
            throw $this->cachedError;
        }

        if (! $result['ok']) {
            $this->isError = true;
            $errorMessage = \is_string($result['e'] ?? null) ? $result['e'] : 'Unknown error';
            $errorClass = \is_string($result['c'] ?? null) ? $result['c'] : \RuntimeException::class;
            $this->cachedError = $this->createException($errorClass, $errorMessage);
            throw $this->cachedError;
        }

        $this->cached = $result['v'];

        return $this->cached;
    }

    public function done(): bool
    {
        if ($this->resolved || $this->isCancelled) {
            return true;
        }
        $res = \pcntl_waitpid($this->pid, $status, WNOHANG);

        return $res > 0 || $res === -1;
    }

    public function cancelled(): bool
    {
        return $this->isCancelled;
    }

    public function cancel(): bool
    {
        if ($this->resolved) {
            return false;
        }
        if ($this->isCancelled) {
            throw new Future\Error\Cancelled('task was already cancelled');
        }
        $this->isCancelled = true;
        \posix_kill($this->pid, SIGTERM);

        return true;
    }

    private function createException(string $class, string $message): \Throwable
    {
        if (\class_exists($class) && \is_a($class, \Throwable::class, true)) {
            $ref = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() <= 1) {
                return new $class($message);
            }
        }

        return new \RuntimeException($message);
    }

    public function __destruct()
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        if (! $this->resolved && $this->pid > 0) {
            \pcntl_waitpid($this->pid, $status, WNOHANG);
        }
    }
}
