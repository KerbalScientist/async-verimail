<?php
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Tests\MutexRun;

use App\MutexRun\Queue;
use App\Tests\TestCase;
use Exception;
use InvalidArgumentException;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use function React\Promise\resolve;

class QueueTest extends TestCase
{
    private Queue $queue;
    private LoopInterface $loop;

    protected function setUp(): void
    {
        $this->loop = LoopFactory::create();
        $this->queue = new Queue($this->loop);
    }

    public function testThrowsExceptionIfInvalidMaxConcurrentPassed()
    {
        $this->expectException(InvalidArgumentException::class);
        new Queue($this->loop, 0);
    }

    public function testQueuedCallbacksAreCalled()
    {
        $this->queue->enqueue($this->expectCallableOnce());
        $this->queue->enqueue($this->expectCallableOnce());
        $this->beforeTestCaseReturn();
    }

    public function testArgumentsArePassedToCallback()
    {
        $this->queue->enqueue($this->expectCallableOnceWith('arg1', 'arg2'), 'arg1', 'arg2');
        $this->beforeTestCaseReturn();
    }

    public function testPromiseResolvesWithCallbackResult()
    {
        $result = 'result';
        $this->queue->enqueue(function () use ($result) {
            return $result;
        })
            ->then($this->expectCallableOnce($result));

        $this->queue->enqueue(function () use ($result) {
            return resolve($result);
        })
            ->then($this->expectCallableOnce($result));
        $this->beforeTestCaseReturn();
    }

    public function testNextCallbackIsNotCalledBeforePreviousResultIsResolved()
    {
        $this->queue->enqueue(function () {
            return (new Deferred())->promise();
        });
        $this->queue->enqueue($this->expectCallableNever());
        $this->beforeTestCaseReturn();
    }

    public function testNextCallbackIsCalledAfterPreviousResultIsResolved()
    {
        $deferred1 = new Deferred();
        $this->queue->enqueue(function () use ($deferred1) {
            return $deferred1->promise();
        });
        $deferred2 = new Deferred();
        $this->queue->enqueue(function () use ($deferred2) {
            return $deferred2->promise();
        });
        $this->queue->enqueue(function () {
            throw new Exception();
        });
        $this->queue->enqueue($this->expectCallableOnce());
        $deferred1->resolve();
        $deferred2->reject();
        $this->beforeTestCaseReturn();
    }

    public function testMaxConcurrentIsRespected()
    {
        $this->queue = new Queue($this->loop, 3);
        $this->queue->enqueue(function () {
            return (new Deferred())->promise();
        });
        $deferred = new Deferred();
        $this->queue->enqueue(function () use ($deferred) {
            return $deferred->promise();
        });
        $this->queue->enqueue(function () {
            return (new Deferred())->promise();
        });
        $called = 0;
        $this->queue->enqueue(function () use (&$called) {
            ++$called;
        });
        $this->assertEquals(0, $called, 'Callback must not be called before deferred resolves.');
        $this->queue->enqueue($this->expectCallableOnce());
        $this->queue->enqueue(function () use (&$called) {
            $this->assertEquals(1, $called, 'Callback must be called after deferred resolves.');
            return (new Deferred())->promise();
        });
        $this->queue->enqueue($this->expectCallableNever());
        $deferred->resolve();
        $this->beforeTestCaseReturn();
    }

    private function beforeTestCaseReturn()
    {
        $this->loop->run();
    }
}
