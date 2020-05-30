<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\MutexRun;

use React\EventLoop\LoopInterface;

class Factory
{
    private LoopInterface $eventLoop;

    public function __construct(LoopInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
    }

    public function createQueue(): Queue
    {
        return new Queue($this->eventLoop);
    }

    public function createCallableOnce(callable $callback, bool $allowAfterResolve = false): CallableOnce
    {
        return new CallableOnce($callback, $allowAfterResolve);
    }
}
