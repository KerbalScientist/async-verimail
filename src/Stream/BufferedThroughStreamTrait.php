<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Stream;

use Evenement\EventEmitterTrait;
use React\EventLoop\LoopInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use Throwable;

trait BufferedThroughStreamTrait
{
    use EventEmitterTrait;

    private bool $readable = true;
    private bool $writable = true;
    private bool $closed = false;
    private bool $paused = false;
    private bool $drain = false;
    private array $buffer = [];
    private LoopInterface $eventLoop;

    /**
     * BufferedThroughStreamTrait constructor.
     *
     * @param LoopInterface $eventLoop
     */
    public function __construct(LoopInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
    }

    public function pause()
    {
        $this->paused = true;
    }

    public function resume()
    {
        $this->paused = false;
        $this->flush();
    }

    public function flush($ignorePaused = false)
    {
        while (count($this->buffer) && ($ignorePaused || !$this->paused)) {
            try {
                $this->emit('data', [array_shift($this->buffer)]);
            } catch (Throwable $e) {
                $this->emit('error', [$e]);
                $this->close();

                return;
            }
        }
        if (!count($this->buffer) && $this->drain) {
            $this->drain = false;
            $this->emit('drain');
        }
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->flush();

        $this->readable = false;
        $this->writable = false;
        $this->closed = true;
        $this->paused = true;
        $this->drain = false;
        $this->buffer = [];

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        /* @noinspection PhpParamsInspection */
        return Util::pipe($this, $dest, $options);
    }

    public function isReadable()
    {
        return $this->readable;
    }

    public function isWritable()
    {
        return $this->writable;
    }

    public function end($data = null)
    {
        if (!$this->writable) {
            return;
        }

        if (null !== $data) {
            $this->write($data);

            // return if write() already caused the stream to close
            if (!$this->writable) {
                return;
            }
        }
        $this->flush(true);

        $this->readable = false;
        $this->writable = false;
        $this->paused = true;
        $this->drain = false;

        $this->emit('end');
        $this->close();
    }

    public function write($data)
    {
        if (!$this->writable) {
            return false;
        }
        $result = $this->writeToBuffer($data);
        $this->flush();
        if ($this->paused || !$result) {
            $this->drain = true;

            return false;
        }

        return true;
    }

    /**
     * Writes data to buffer.
     *
     * @param $data
     *
     * @return bool true if buffer is not full
     */
    abstract protected function writeToBuffer($data): bool;
}
