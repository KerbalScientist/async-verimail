<?php declare(strict_types=1);

/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use LogicException;
use SplPriorityQueue;

class MovingAverage
{
    private SplPriorityQueue $buffer;
    private float $sum = 0;
    private float $maxX = PHP_FLOAT_MIN;
    private float $windowWidth;

    public function __construct(float $windowWidth)
    {
        $this->windowWidth = $windowWidth;
    }

    public function insertValue(float $x, float $y): void
    {
        if (!isset($this->buffer)) {
            $this->buffer = new SplPriorityQueue();
        }
        $this->buffer->insert($y, -$x);
        $this->sum += $y;
        $this->maxX = max($x, $this->maxX);
        $windowStart = $this->maxX - $this->windowWidth;
        $this->buffer->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
        while ($this->buffer->count() && (-$this->buffer->top()) <= $windowStart) {
            $this->buffer->setExtractFlags(SplPriorityQueue::EXTR_DATA);
            $this->sum -= $this->buffer->extract();
            $this->buffer->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
        }
    }

    /**
     * Get moving average value.
     *
     * @return float
     */
    public function get(): float
    {
        if (!isset($this->buffer)) {
            throw new LogicException('get() must not be called before insert().');
        }

        return $this->sum / $this->buffer->count();
    }

    /**
     * Returns values count in window.
     *
     * @return int
     */
    public function getCount(): int
    {
        return $this->buffer->count();
    }

    public function getWindowStart(): ?float
    {
        if (!isset($this->buffer)) {
            return null;
        }

        return $this->maxX - $this->windowWidth;
    }

    public function getWindowEnd(): ?float
    {
        if (!isset($this->buffer)) {
            return null;
        }

        return $this->maxX;
    }
}
