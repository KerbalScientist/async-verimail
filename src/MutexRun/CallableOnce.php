<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\MutexRun;

use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\resolve;

class CallableOnce
{
    /**
     * @var callable $callable
     */
    private $callable;

    private bool $allowAfterResolve;

    private ?PromiseInterface $lock = null;

    /**
     * CallableOnce constructor.
     *
     * @param callable $callable
     * @param bool     $allowAfterResolve
     */
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
        $result = ($this->callable)(...$args);
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
        }, function (Throwable $e) {
            $this->lock = null;

            throw $e;
        });
    }
}
