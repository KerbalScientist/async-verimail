<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\Stream;

use Evenement\EventEmitter;
use Exception;
use InvalidArgumentException;
use React\Stream\DuplexStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use function call_user_func;
use function is_callable;

/**
 * @see \React\Stream\ThroughStream
 *
 * The position of `$this->paused = false;` in resume() is the only change from original
 * \React\Stream\ThroughStream class.
 *
 * This allows to emit `data` event from any readable stream, piped to
 * `ThroughStream` without pausing readable stream while `data` event is emitted.
 *
 * @todo Duplicated code with BufferedThroughStreamTrait
 */
final class ThroughStream extends EventEmitter implements DuplexStreamInterface
{
    private bool $readable = true;
    private bool $writable = true;
    private bool $closed = false;
    private bool $paused = false;
    private bool $drain = false;
    private $callback;

    public function __construct($callback = null)
    {
        if ($callback !== null && !is_callable($callback)) {
            throw new InvalidArgumentException('Invalid transformation callback given');
        }

        $this->callback = $callback;
    }

    public function pause()
    {
        $this->paused = true;
    }

    public function resume()
    {
        $this->paused = false;
        if ($this->drain) {
            $this->drain = false;
            $this->emit('drain');
        }
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
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

        if ($this->callback !== null) {
            try {
                $data = call_user_func($this->callback, $data);
            } catch (Exception $e) {
                $this->emit('error', array($e));
                $this->close();

                return false;
            }
        }

        $this->emit('data', array($data));

        if ($this->paused) {
            $this->drain = true;
            return false;
        }

        return true;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->readable = false;
        $this->writable = false;
        $this->closed = true;
        $this->paused = true;
        $this->drain = false;
        $this->callback = null;

        $this->emit('close');
        $this->removeAllListeners();
    }
}
