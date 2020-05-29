<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\MutexRun;

use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SplQueue;
use Throwable;
use function React\Promise\resolve;

class Queue
{
    private LoopInterface $eventLoop;

    /**
     * @var SplQueue<array>
     */
    private SplQueue $queue;

    private int $maxConcurrent;
    private int $currentConcurrent = 0;

    private bool $paused = true;

    /**
     * Queue constructor.
     *
     * @param LoopInterface $eventLoop
     * @param int           $maxConcurrent
     */
    public function __construct(LoopInterface $eventLoop, int $maxConcurrent = 1)
    {
        if ($maxConcurrent < 1) {
            throw new InvalidArgumentException('Invalid maxConcurrent value.');
        }
        $this->maxConcurrent = $maxConcurrent;
        $this->eventLoop = $eventLoop;
    }

    /**
     * @param callable|null $callback
     * @param mixed         ...$args
     *
     * @return PromiseInterface
     */
    public function enqueue(callable $callback = null, ...$args): PromiseInterface
    {
        $deferred = new Deferred();
        if (!isset($this->queue)) {
            $this->queue = new SplQueue();
        }
        $this->queue->enqueue([$callback, $args, $deferred]);
        if ($this->paused) {
            $this->paused = false;
            $this->onTick();
        }

        return $deferred->promise();
    }

    /**
     * @internal
     */
    public function onTick(): void
    {
        while (($this->maxConcurrent > $this->currentConcurrent) && !$this->paused) {
            ++$this->currentConcurrent;
            $this->runConcurrent();
        }
    }

    private function runConcurrent(): void
    {
        if (!$this->queue->count()) {
            $this->paused = true;
            --$this->currentConcurrent;

            return;
        }
        $current = $this->queue->dequeue();
        /* @var $callback callable */
        /* @var $args     array */
        /* @var $deferred Deferred */
        list($callback, $args, $deferred) = $current;

        try {
            $result = $callback(...$args);
        } catch (Throwable $e) {
            $this->futureTick();
            $deferred->reject($e);

            return;
        }
        if (!$result instanceof PromiseInterface) {
            $result = resolve($result);
        }
        $result
            ->then(function ($result) use ($deferred) {
                $this->futureTick();
                $deferred->resolve($result);
            }, function ($e) use ($deferred) {
                $this->futureTick();
                $deferred->reject($e);
            });
    }

    private function futureTick(): void
    {
        --$this->currentConcurrent;
        if (!$this->paused) {
            $this->eventLoop->futureTick([$this, 'onTick']);
        }
    }
}
