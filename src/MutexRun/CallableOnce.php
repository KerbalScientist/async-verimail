<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\MutexRun;

use Exception;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\reject;
use function React\Promise\resolve;

class CallableOnce
{
    /**
     * @var callable $callable
     */
    private $callable;

    private bool $allowAfterResolve;

    private ?PromiseInterface $lock = null;

    public function __construct(callable $callable, bool $allowAfterResolve = false)
    {
        $this->callable = $callable;
        $this->allowAfterResolve = $allowAfterResolve;
    }

    /**
     * @param mixed ...$args
     *
     * @return PromiseInterface
     */
    public function __invoke(...$args): PromiseInterface
    {
        if ($this->lock) {
            return $this->lock;
        }

        try {
            $result = ($this->callable)(...$args);
        } catch (Exception $exception) {
            $result = reject($exception);
        }
        if (!$result instanceof PromiseInterface) {
            $result = resolve($result);
        }
        $this->lock = $result;
        if (!$this->allowAfterResolve) {
            return $this->lock;
        }

        return $result->then(function ($result) {
            $this->lock = null;

            return $result;
        }, function ($e) {
            $this->lock = null;
            if ($e instanceof Throwable) {
                throw $e;
            }
        });
    }
}
