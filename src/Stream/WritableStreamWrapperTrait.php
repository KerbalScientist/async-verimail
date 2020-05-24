<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Stream;

use Evenement\EventEmitterTrait;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

trait WritableStreamWrapperTrait
{
    use EventEmitterTrait;

    private WritableStreamInterface $innerStream;
    private bool $closed = false;

    public function __construct(WritableStreamInterface $innerStream)
    {
        $this->initWrapper($innerStream);
    }

    protected function initWrapper(WritableStreamInterface $innerStream): void
    {
        if (!$innerStream->isWritable()) {
            $this->close();

            return;
        }
        $this->innerStream = $innerStream;
        $this->forwardWriteEvents();
    }

    /**
     * @see WritableStreamInterface
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->innerStream->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * Forwards events from innerStream to this stream.
     */
    protected function forwardWriteEvents()
    {
        Util::forwardEvents($this->innerStream, $this, array('drain', 'error', 'pipe'));
        $this->innerStream->on('close', array($this, 'close'));
    }

    /**
     * @see WritableStreamInterface
     */
    public function isWritable()
    {
        return $this->innerStream->isWritable();
    }

    /**
     * @param mixed $data
     *
     * @return bool
     *
     * @see WritableStreamInterface
     */
    public function write($data)
    {
        return $this->innerStream->write($this->filterData($data));
    }

    /**
     * Consumes data passed to stream, returns data to be written to inner stream.
     *
     * @param mixed $data
     *
     * @return mixed Data to be written;
     */
    abstract protected function filterData($data);

    /**
     * @param mixed $data
     *
     * @see WritableStreamInterface
     */
    public function end($data = null)
    {
        if (!is_null($data)) {
            $data = $this->filterData($data);
        }
        $this->innerStream->end($this->filterData($data));
    }
}
