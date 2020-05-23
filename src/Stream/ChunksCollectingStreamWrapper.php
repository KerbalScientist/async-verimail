<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\Stream;


use Exception;
use Iterator;
use React\Promise\PromisorInterface;
use React\Stream\ReadableStreamInterface;

class ChunksCollectingStreamWrapper implements ReadableStreamInterface, PromisorInterface, Iterator
{
    use ReadableStreamWrapperTrait;
    use ReadableStreamIteratorTrait;

    private array $buffer = [];
    private int $chunkSize;
    private bool $singleArg;

    /**
     * CollectsChunksReadableTrait constructor.
     *
     * @param ReadableStreamInterface $innerStream
     * @param array $settings
     */
    public function __construct(ReadableStreamInterface $innerStream, array $settings = [])
    {
        $this->chunkSize = $settings['chunkSize'] ?? 100;
        $this->singleArg = $settings['singleArg'] ?? false;
        $this->initWrapper($innerStream);
        $this->initPromisor($settings['bufferSize'] ?? 2, true);
    }

    /**
     * @inheritDoc
     */
    public function isReadable()
    {
        return $this->innerStream->isReadable() || $this->buffer;
    }

    /**
     * @inheritDoc
     */
    protected function filterData(...$args): ?array
    {
        if ($this->singleArg) {
            $this->buffer[] = $args[0];
        } else {
            $this->buffer[] = $args;
        }

        if (count($this->buffer) >= $this->chunkSize) {
            $this->flush();
        }
        return null;
    }

    /**
     * Flush stream.
     */
    public function flush(): void
    {
        if ($this->buffer) {
            $data = $this->buffer;
            $this->buffer = [];
            $this->emit('data', [$data]);
        }
    }

    /**
     * @inheritDoc
     */
    protected function forwardReadEvents()
    {
        $this->innerStream->on('close', function () {
            $this->flush();
            $this->emit('close');
        });
        $this->innerStream->on('end', function () {
            $this->flush();
            $this->emit('end');
        });
        $this->innerStream->on('error', function (Exception $e) {
            $this->flush();
            $this->emit('error', [$e]);
        });
    }
}
