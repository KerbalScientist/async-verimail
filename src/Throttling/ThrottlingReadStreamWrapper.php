<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Throttling;

use App\Stream\ReadableStreamWrapperTrait;
use React\Stream\ReadableStreamInterface;
use SplObjectStorage;
use function React\Promise\all;

class ThrottlingReadStreamWrapper implements ReadableStreamInterface
{
    use ReadableStreamWrapperTrait {
        emit as parentEmit;
    }

    private float $minIntervalSeconds;
    private int $bufferSize;
    /**
     * @var SplObjectStorage
     */
    private SplObjectStorage $buffer;
    private ClosureWrapper $emitWrapper;

    /**
     * ThrottlingReadStreamWrapper constructor.
     *
     * @param ReadableStreamInterface $innerStream
     * @param Factory                 $throttlingFactory
     * @param mixed[]                 $settings
     */
    public function __construct(ReadableStreamInterface $innerStream, Factory $throttlingFactory, array $settings)
    {
        $this->emitWrapper = $throttlingFactory->closure(function ($event, array $arguments = []) {
            $this->parentEmit($event, $arguments);
        }, $settings['minIntervalSeconds'] ?? 1);
        $this->bufferSize = $settings['bufferSize'] ?? 1;
        $this->buffer = new SplObjectStorage();
        $this->initWrapper($innerStream);
    }

    /**
     * {@inheritdoc}
     *
     * @param string  $event
     * @param mixed[] $arguments
     */
    public function emit($event, array $arguments = []): void
    {
        $emit = function () use ($event, $arguments) {
            $this->parentEmit($event, $arguments);
        };
        if ('data' !== $event && !$this->buffer->count()) {
            $emit();

            return;
        }
        if ('data' !== $event) {
            all(iterator_to_array($this->buffer))
                ->then($emit, $emit);
        }
        $promise = ($this->emitWrapper)($event, $arguments);
        $this->buffer->attach($promise);
        if ($this->buffer->count() === $this->bufferSize) {
            $this->pause();
        }
        $always = function () use ($promise) {
            $this->buffer->detach($promise);
            if ($this->buffer->count() + 1 === $this->bufferSize) {
                $this->resume();
            }
        };
        $promise->then($always, $always);
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed ...$args
     *
     * @return mixed[]|null
     */
    protected function filterData(...$args): ?array
    {
        return $args;
    }
}
