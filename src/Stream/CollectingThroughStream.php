<?php declare(strict_types=1);

/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Stream;

use React\Stream\DuplexStreamInterface;

/**
 * Acts like a collecting tank
 * with `CollectingThroughStream::pause()` and `CollectingThroughStream::resume()`
 * acting like exit valve open/close.
 *
 * Tries to minimise buffer usage by returning false from `write()` if buffer is not empty.
 * Buffer volume is limited by memory size.
 *
 * @see \React\Stream\ThroughStream
 */
class CollectingThroughStream implements DuplexStreamInterface
{
    use BufferedThroughStreamTrait;

    /**
     * {@inheritdoc}
     *
     * @param mixed $data
     */
    protected function writeToBuffer($data): bool
    {
        $this->buffer[] = $data;
        $this->flush();

        return !count($this->buffer);
    }
}
