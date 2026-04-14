<?php

namespace Henderkes\ParallelFork;

final class Future
{
    /** @internal Set by Runtime in child process to prevent destructors from reaping siblings */
    public static bool $inChild = false;

    private bool $resolved = false;

    private bool $isError = false;

    private bool $isCancelled = false;

    private bool $isKilled = false;

    private mixed $cached = null;

    private ?\Throwable $cachedError = null;

    /** @var resource|null */
    private mixed $stream;

    private ?int $waitStatus = null;

    /**
     * @internal
     *
     * @param  resource  $stream
     */
    public function __construct(
        private int $pid,
        mixed $stream,
        private Runtime $runtime,
    ) {
        if (! \is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a valid resource for stream');
        }
        $this->stream = $stream;
    }

    /**
     * @internal Called by Runtime::kill()
     */
    public function markKilled(): void
    {
        $this->isKilled = true;
        $this->resolved = true;
        $this->isError = true;
        $this->cachedError = new Future\Error\Killed('task was killed');

        if (\is_resource($this->stream)) {
            \fclose($this->stream);
            $this->stream = null;
        }
    }

    public function value(): mixed
    {
        if ($this->isKilled && $this->cachedError !== null) {
            throw $this->cachedError;
        }

        if ($this->isCancelled) {
            $this->reap();
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

        $status = $this->reap();
        $this->resolved = true;

        if ($data === '') {
            // Check if child was signaled (killed externally, segfault, etc.)
            if (\pcntl_wifsignaled($status)) {
                $this->isError = true;
                $sig = \pcntl_wtermsig($status);
                $this->cachedError = new Future\Error\Killed("child was killed by signal $sig");
                $this->runtime->childCompleted($this->pid, $this->cachedError, $status);
                throw $this->cachedError;
            }

            $this->cached = null;
            $this->runtime->childCompleted($this->pid, null, $status);

            return null;
        }

        $result = @\unserialize($data, ['allowed_classes' => true]);
        if (! \is_array($result) || ! isset($result['ok'])) {
            $this->isError = true;
            $this->cachedError = new Future\Error\Foreign('Invalid data from child process');
            $this->runtime->childCompleted($this->pid, $this->cachedError, $status);
            throw $this->cachedError;
        }

        if (! $result['ok']) {
            $this->isError = true;
            $errorMessage = \is_string($result['e'] ?? null) ? $result['e'] : 'Unknown error';
            $errorClass = \is_string($result['c'] ?? null) ? $result['c'] : \RuntimeException::class;
            $childTrace = \is_string($result['t'] ?? null) ? $result['t'] : '';
            $this->cachedError = $this->createException($errorClass, $errorMessage, $childTrace);
            $this->runtime->childCompleted($this->pid, $this->cachedError, $status);
            throw $this->cachedError;
        }

        $this->cached = $result['v'];
        $this->runtime->childCompleted($this->pid, $this->cached, $status);

        return $this->cached;
    }

    public function done(): bool
    {
        if ($this->resolved || $this->isCancelled || $this->isKilled) {
            return true;
        }

        $res = \pcntl_waitpid($this->pid, $status, WNOHANG);
        if ($res > 0) {
            /** @var int $status */
            $this->waitStatus = $status;
        }

        return $res > 0 || $res === -1;
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

        if (\is_resource($this->stream)) {
            \fclose($this->stream);
            $this->stream = null;
        }

        return true;
    }

    public function cancelled(): bool
    {
        return $this->isCancelled;
    }

    private function createException(string $class, string $message, string $childTrace): \Throwable
    {
        $fullMessage = $childTrace !== ''
            ? $message."\n\nChild stack trace:\n".$childTrace
            : $message;

        if (\class_exists($class) && \is_a($class, \Throwable::class, true)) {
            $ref = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() <= 1) {
                return new $class($fullMessage);
            }
        }

        return new \RuntimeException($fullMessage);
    }

    /**
     * Reap the child process. Uses saved status from done() if available.
     */
    private function reap(): int
    {
        if ($this->waitStatus !== null) {
            return $this->waitStatus;
        }

        \pcntl_waitpid($this->pid, $status);
        /** @var int $status */
        $this->waitStatus = $status;

        return $status;
    }

    public function __destruct()
    {
        if (self::$inChild) {
            return;
        }
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        if (! $this->resolved && ! $this->isKilled && $this->pid > 0) {
            \pcntl_waitpid($this->pid, $status);
        }
    }
}
