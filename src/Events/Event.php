<?php

namespace Henderkes\ParallelFork\Events;

final class Event
{
    public int $type;

    public string $source;

    public object $object;

    public mixed $value = null;
}
