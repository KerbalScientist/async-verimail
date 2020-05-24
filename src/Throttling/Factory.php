<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Throttling;

use Closure;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableStreamInterface;

class Factory
{
    private LoopInterface $eventLoop;

    /**
     * Factory constructor.
     *
     * @param LoopInterface $eventLoop
     */
    public function __construct(LoopInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
    }

    public function closure(Closure $closure, float $minIntervalSeconds): ClosureWrapper
    {
        return new ClosureWrapper($closure, $this->eventLoop, $minIntervalSeconds);
    }

    public function readableStream(
        ReadableStreamInterface $innerStream,
        float $minIntervalSeconds,
        int $bufferSize = 1
    ): ThrottlingReadStreamWrapper {
        return new ThrottlingReadStreamWrapper($innerStream, $this, [
            'minIntervalSeconds' => $minIntervalSeconds,
            'bufferSize' => $bufferSize,
        ]);
    }
}
