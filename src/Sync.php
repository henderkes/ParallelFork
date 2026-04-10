<?php

namespace Henderkes\ParallelFork;

/** Shared memory + semaphore synchronization across forked processes. */
final class Sync
{
    private ?\Shmop $shm = null;

    private ?\SysvSemaphore $mutex = null;

    private ?\SysvSemaphore $cond = null;

    private int $shmSize = 65536;

    private int $ipcKey;

    public function __construct(mixed $value = null)
    {
        if ($value !== null && ! \is_scalar($value)) {
            throw new Sync\Error\IllegalValue(
                'sync cannot contain non-scalar '.\get_debug_type($value)
            );
        }

        $this->ipcKey = $this->generateKey();

        $shm = \shmop_open($this->ipcKey, 'c', 0600, $this->shmSize);
        if ($shm === false) {
            throw new \RuntimeException('Failed to create shared memory segment');
        }
        $this->shm = $shm;

        $mutex = \sem_get($this->ipcKey, 1, 0600, true);
        if ($mutex === false) {
            throw new \RuntimeException('Failed to create mutex semaphore');
        }
        $this->mutex = $mutex;

        // Acquire once to initialize count to 0 (no signals pending)
        $cond = \sem_get($this->ipcKey + 1, 1, 0600, true);
        if ($cond === false) {
            throw new \RuntimeException('Failed to create condition semaphore');
        }
        $this->cond = $cond;
        \sem_acquire($this->cond);

        $this->writeShm($value);
    }

    public function get(): mixed
    {
        return $this->readShm();
    }

    public function set(mixed $value): void
    {
        if ($value !== null && ! \is_scalar($value)) {
            throw new Sync\Error\IllegalValue(
                'sync cannot contain non-scalar '.\get_debug_type($value)
            );
        }
        $this->writeShm($value);
    }

    public function wait(): bool
    {
        if ($this->cond === null) {
            throw new \RuntimeException('Condition semaphore not initialized');
        }

        return \sem_acquire($this->cond);
    }

    public function notify(bool $all = false): bool
    {
        if ($this->cond === null) {
            throw new \RuntimeException('Condition semaphore not initialized');
        }

        return \sem_release($this->cond);
    }

    public function __invoke(callable $block): void
    {
        if ($this->mutex === null) {
            throw new \RuntimeException('Mutex semaphore not initialized');
        }
        \sem_acquire($this->mutex);
        try {
            $block();
        } finally {
            \sem_release($this->mutex);
        }
    }

    private function writeShm(mixed $value): void
    {
        if ($this->shm === null) {
            throw new \RuntimeException('Shared memory not initialized');
        }
        $data = \serialize($value);
        $len = \strlen($data);

        if ($len + 4 > $this->shmSize) {
            throw new \RuntimeException("Sync value too large: {$len} bytes (max ".($this->shmSize - 4).')');
        }

        $payload = \pack('N', $len).$data;
        \shmop_write($this->shm, $payload, 0);
    }

    private function readShm(): mixed
    {
        if ($this->shm === null) {
            throw new \RuntimeException('Shared memory not initialized');
        }
        $header = \shmop_read($this->shm, 0, 4);
        $unpacked = \unpack('Nlen', $header);
        if ($unpacked === false || ! isset($unpacked['len']) || ! \is_int($unpacked['len'])) {
            return null;
        }
        $len = $unpacked['len'];

        if ($len === 0) {
            return null;
        }

        $data = \shmop_read($this->shm, 4, $len);

        return \unserialize($data);
    }

    private function generateKey(): int
    {
        return (int) \abs(\crc32(\getmypid().\microtime(true).\random_int(0, PHP_INT_MAX)));
    }

    public function __destruct()
    {
        try {
            if (isset($this->shm)) {
                \shmop_delete($this->shm);
            }
        } catch (\Throwable) {
        }
        try {
            if (isset($this->mutex)) {
                \sem_remove($this->mutex);
            }
        } catch (\Throwable) {
        }
        try {
            if (isset($this->cond)) {
                \sem_remove($this->cond);
            }
        } catch (\Throwable) {
        }
    }
}

namespace Henderkes\ParallelFork\Sync;

class Error extends \Henderkes\ParallelFork\Error {}

namespace Henderkes\ParallelFork\Sync\Error;

use Henderkes\ParallelFork\Sync\Error;

class IllegalValue extends Error {}
