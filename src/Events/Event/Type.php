<?php

namespace Henderkes\ParallelFork\Events\Event;

final class Type
{
    const Read = 1;

    const Write = 2;

    const Close = 3;

    const Error = 4;

    const Cancel = 5;

    const Kill = 6;
}
