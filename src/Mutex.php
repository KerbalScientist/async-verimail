<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App;


use Closure;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use SplQueue;
use Throwable;
use function React\Promise\resolve;

class Mutex
{
    private LoopInterface $eventLoop;
    /**
     * @var Promise[]
     */
    private array $onceLocks;

    /**
     * @var SplQueue<callable>[]
     */
    private array $queues;

    /**
     * @var Closure[]
     */
    private array $tickCallbacks;

    /**
     * Mutex constructor.
     * @param LoopInterface $eventLoop
     */
    public function __construct(LoopInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
    }

    public function enqueue($mutexKey, callable $callback = null, ...$args): PromiseInterface
    {
        return $this->enqueueTick($mutexKey, $callback, ...$args);
    }

    private function enqueueTick($mutexKey, callable $callback = null, ...$args): PromiseInterface
    {
        $mutexKey = $this->getMutexKey($mutexKey);
        $deferred = new Deferred();
        if (!isset($this->queues[$mutexKey])) {
            $this->queues[$mutexKey] = new SplQueue();
        }
        $this->queues[$mutexKey]->enqueue([$callback, $args, $deferred]);
        $this->runTickFunction($mutexKey);
        return $deferred->promise();
    }

    /**
     * Converts any value to
     *
     * @param mixed $mixedKey
     * @return string
     */
    public function getMutexKey($mixedKey): string
    {
        if (is_string($mixedKey)) {
            return "#str:$mixedKey";
        }
        if (is_object($mixedKey)) {
            return '#object:' . spl_object_hash($mixedKey);
        }
        if (is_resource($mixedKey)) {
            return '#resource:' . get_resource_type($mixedKey) . ':' . strval(intval($mixedKey));
        }
        if (is_array($mixedKey)) {
            $result = '';
            foreach ($mixedKey as $key => $value) {
                if (strlen($result)) {
                    $result .= ',';
                }
                $result .= "$key:{$this->getMutexKey($value)}";
            }
            return '[' . $result . ']';
        }
        return '#sz:' . serialize($mixedKey);
    }

    private function runTickFunction(string $mutexKey)
    {
        if (isset($this->tickCallbacks[$mutexKey])) {
            return;
        }
        $this->tickCallbacks[$mutexKey] = function () use ($mutexKey) {
            $queue = $this->queues[$mutexKey] ?? null;
            if ($queue) {
                $queue->rewind();
            }
            if (!$queue || !$queue->count()) {
                unset($this->tickCallbacks[$mutexKey]);
                return;
            }
            $current = $queue->dequeue();
            /**
             * @var $callback callable
             * @var $args array
             * @var $deferred Deferred
             */
            list($callback, $args, $deferred) = $current;
            try {
                $result = $callback(...$args);
            } catch (Throwable $e) {
                $this->futureTick($mutexKey);
                $deferred->reject($e);
                return;
            }
            if (!$result instanceof PromiseInterface) {
                $result = resolve($result);
            }
            $result
                ->then(function ($result) use ($deferred, $mutexKey) {
                    $this->futureTick($mutexKey);
                    $deferred->resolve($result);
                }, function ($e) use ($deferred, $mutexKey) {
                    $this->futureTick($mutexKey);
                    $deferred->reject($e);
                });

        };
        ($this->tickCallbacks[$mutexKey])();
    }

    private function futureTick($mutexKey)
    {
        if (isset($this->tickCallbacks[$mutexKey])) {
            $this->eventLoop->futureTick($this->tickCallbacks[$mutexKey]);
        }
    }

    /**
     * @param Closure $closure
     * @param mixed ...$args
     * @return PromiseInterface
     */
    public function runOnceForClosure(Closure $closure, ...$args): PromiseInterface
    {
        return $this->runOnce($closure, $closure, ...$args);
    }

    /**
     * Runs callback if promise, returned by any callback,
     * passed with the same key previously is resolved.
     * If previous callback promise is unresolved, this callback
     * will be ignored and previous callback promise will be returned.
     * So, only the first callback with the same key can be run at the same
     * time and no other callbacks with that key will be run concurrently.
     *
     * @param mixed $mutexKey
     * @param callable|null $callback
     * @param mixed ...$args
     * @return PromiseInterface
     */
    public function runOnce($mutexKey, ?callable $callback = null, ...$args): PromiseInterface
    {
        $mutexKey = $this->getMutexKey($mutexKey);
        if (isset($this->onceLocks[$mutexKey])) {
            return $this->onceLocks[$mutexKey];
        }
        if (!$callback) {
            return resolve();
        }
        $result = $callback(...$args);
        if (!$result instanceof PromiseInterface) {
            return resolve($result);
        }
        $this->onceLocks[$mutexKey] = $result;
        return $result->then(function ($result) use ($mutexKey) {
            unset($this->onceLocks[$mutexKey]);
            return $result;
        }, function (Throwable $e) use ($mutexKey) {
            unset($this->onceLocks[$mutexKey]);
            throw $e;
        });
    }
}
