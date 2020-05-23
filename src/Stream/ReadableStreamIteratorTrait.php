<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

/** @noinspection PhpUnused */

namespace App\Stream;

use React\Promise\PromiseInterface;

trait ReadableStreamIteratorTrait
{
    use ReadableStreamPromisorTrait;

    private int $key = 0;
    private PromiseInterface $current;
    private bool $valid = true;

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface
     */
    public function current()
    {
        if (!isset($this->current)) {
            $this->next();
        }

        return $this->current;
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        $this->valid = $this->hasData();
        $this->current = $this->promise();
        if ($this->valid) {
            ++$this->key;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return $this->valid;
    }

    /**
     * {@inheritdoc}
     *
     * @return int
     */
    public function key()
    {
        return $this->key;
    }

    /**
     * Does nothing.
     */
    public function rewind()
    {
    }
}
