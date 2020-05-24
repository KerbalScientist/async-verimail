<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Stream;

use Evenement\EventEmitter;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Stream\DuplexStreamInterface;
use function React\Promise\all;

class ResolvingThroughStream extends EventEmitter implements DuplexStreamInterface
{
    use BufferedThroughStreamTrait {
        __construct as traitConstruct;
    }

    /**
     * @var PromiseInterface[]
     */
    private array $promiseBuffer = [];
    private int $softBufferSize;

    /**
     * ResolvingThroughStream constructor.
     *
     * @param LoopInterface $eventLoop
     * @param int           $softBufferSize
     */
    public function __construct(LoopInterface $eventLoop, int $softBufferSize)
    {
        $this->softBufferSize = $softBufferSize;
        $this->traitConstruct($eventLoop);
    }

    public function end($data = null)
    {
        if (!is_null($data)) {
            $this->write($data);
        }
        $end = function () {
            $this->flush(true);
            $this->emit('end');
            $this->close();
        };
        all($this->promiseBuffer)
            ->then($end, $end);
    }

    /**
     * @param mixed $data
     *
     * @return bool
     */
    protected function writeToBuffer($data): bool
    {
        if (!$data instanceof PromiseInterface) {
            $this->emit('error', [
                new InvalidArgumentException('Invalid promise given.'),
            ]);
            $this->close();

            return false;
        }
        $key = array_key_last($this->promiseBuffer) + 1;
        $this->promiseBuffer[$key] = $data;
        $data->then(function ($result) use ($key) {
            unset($this->promiseBuffer[$key]);
            $this->buffer[] = $result;
            $this->flush();
        }, function ($error) use ($key) {
            unset($this->promiseBuffer[$key]);
            $this->emit('error', [$error]);
            $this->close();
        });

        return (count($this->promiseBuffer) + count($this->buffer)) < $this->softBufferSize;
    }

//    public function close()
//    {
//        echo (new Exception())->__toString();
//        if ($this->closed) {
//            return;
//        }
//        $this->flush(true);
//
//        $this->readable = false;
//        $this->writable = false;
//        $this->closed = true;
//        $this->paused = true;
//        $this->drain = false;
//        $this->buffer = [];
//
//        $this->emit('close');
//        $this->removeAllListeners();
//    }

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
        $this->buffer = [];
        $this->flush(true);
        $this->emit('close');
        $this->removeAllListeners();
    }
}
