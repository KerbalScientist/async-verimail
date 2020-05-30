<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Stream;

use Exception;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

trait ReadableStreamPromisorTrait
{
    /**
     * @var Deferred[]
     */
    private array $resolveQueue = [];

    /**
     * @var Deferred[]
     */
    private array $returnQueue = [];
    private int $bufferSoftSize;

    /**
     * @noinspection PhpUnused
     */
    public function __construct(int $bufferSize, $singleDataArg = false)
    {
        $this->initPromisor($bufferSize, $singleDataArg);
    }

    /**
     * Initialises functionality of this trait. Should be called from constructor.
     *
     * @param int  $bufferSoftSize
     * @param bool $singleDataArg
     */
    protected function initPromisor(int $bufferSoftSize, $singleDataArg = false): void
    {
        $this->bufferSoftSize = $bufferSoftSize;
        $this->on('data', function (...$args) use ($singleDataArg) {
            if (!$this->resolveQueue) {
                $this->returnQueue[] = $this->resolveQueue[] = new Deferred();
            }
            $old = array_shift($this->resolveQueue);
            $old->resolve($singleDataArg ? $args[0] : $args);
            if (count($this->returnQueue) == $this->bufferSoftSize) {
                $this->pause();
            }
        });
        $endClose = function () {
            while ($deferred = array_shift($this->resolveQueue)) {
                $deferred->reject(new NoDataException());
            }
        };
        $this->on('end', $endClose);
        $this->on('close', $endClose);
        $this->on('error', function (Exception $e) {
            while ($deferred = array_shift($this->resolveQueue)) {
                $deferred->reject($e);
            }
        });
    }

    /**
     * Returns promise, resolved by data event with value of event listener arguments array.
     *
     * @return PromiseInterface
     */
    public function promise(): PromiseInterface
    {
        if (!$this->hasData()) {
            return reject(new NoDataException());
        }
        $oldCount = count($this->returnQueue);
        if (!$this->returnQueue) {
            $this->returnQueue[] = $this->resolveQueue[] = new Deferred();
        }
        if ($oldCount >= $this->bufferSoftSize) {
            $this->resume();
        }
        $deferred = array_shift($this->returnQueue);

        return $deferred->promise();
    }

    /**
     * @return bool
     */
    public function hasData(): bool
    {
        return $this->returnQueue || $this->isReadable();
    }
}
