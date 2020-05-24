<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Stream;

use Evenement\EventEmitterTrait;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

trait ReadableStreamWrapperTrait
{
    use EventEmitterTrait;

    protected ReadableStreamInterface $innerStream;

    /**
     * HydratingStream constructor.
     *
     * @param ReadableStreamInterface $innerStream
     */
    public function __construct(ReadableStreamInterface $innerStream)
    {
        $this->initWrapper($innerStream);
    }

    protected function initWrapper(ReadableStreamInterface $innerStream): void
    {
        $this->innerStream = $innerStream;
        $this->forwardReadEvents();
        $innerStream->on('data', function (...$args) {
            $emitArgs = $this->filterData(...$args);
            if (is_null($emitArgs)) {
                return;
            }
            $this->emit('data', $emitArgs);
        });
    }

    /**
     * Forwards events from innerStream to this stream.
     */
    protected function forwardReadEvents(): void
    {
        Util::forwardEvents($this->innerStream, $this, ['end', 'error', 'close']);
    }

    /**
     * Consumes data event callback args, returns emit data event args.
     * If returns null, emit will not be called.
     *
     * @param mixed ...$args
     *
     * @return mixed[]|null
     */
    abstract protected function filterData(...$args): ?array;

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->innerStream->isReadable();
    }

    /**
     * {@inheritdoc}
     */
    public function pause()
    {
        $this->innerStream->pause();
    }

    /**
     * {@inheritdoc}
     */
    public function resume()
    {
        $this->innerStream->resume();
    }

    /**
     * {@inheritdoc}
     */
    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        /* @noinspection PhpParamsInspection */
        return Util::pipe($this, $dest, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->innerStream->close();
    }
}
