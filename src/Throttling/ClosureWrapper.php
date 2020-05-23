<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Throttling;

use Closure;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

class ClosureWrapper
{
    private Closure $closure;
    private LoopInterface $eventLoop;
    private float $minIntervalSeconds;
    private float $nextCallTime = 0;

    /**
     * ThrottlingWrapper constructor.
     *
     * @param Closure       $closure
     * @param LoopInterface $eventLoop
     * @param float         $minIntervalSeconds
     */
    public function __construct(Closure $closure, LoopInterface $eventLoop, float $minIntervalSeconds)
    {
        $this->closure = $closure;
        $this->eventLoop = $eventLoop;
        $this->minIntervalSeconds = $minIntervalSeconds;
    }

    public function __invoke(...$args): PromiseInterface
    {
        $time = microtime(true);
        if ($time > $this->nextCallTime) {
            $this->nextCallTime = $time + $this->minIntervalSeconds;

            return resolve(($this->closure)(...$args));
        }
        $deferred = new Deferred();
        $this->eventLoop->addTimer(
            $this->nextCallTime - $time,
            function () use ($deferred, $args) {
                $deferred->resolve(($this->closure)(...$args));
            }
        );
        $this->nextCallTime = $this->nextCallTime + $this->minIntervalSeconds;

        return $deferred->promise();
    }
}
