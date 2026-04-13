<?php

namespace Henderkes\ParallelFork\Events;

final class Input
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function add(string $target, mixed $value): void
    {
        $this->data[$target] = $value;
    }

    public function remove(string $target): void
    {
        unset($this->data[$target]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function has(string $target): bool
    {
        return isset($this->data[$target]);
    }

    public function get(string $target): mixed
    {
        return $this->data[$target] ?? null;
    }
}
