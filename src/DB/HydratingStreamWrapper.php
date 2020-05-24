<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use App\Stream\ReadableStreamWrapperTrait;
use React\Stream\ReadableStreamInterface;

class HydratingStreamWrapper implements ReadableStreamInterface
{
    use ReadableStreamWrapperTrait;

    private HydrationStrategyInterface $hydrationStrategy;

    /**
     * HydratingStream constructor.
     *
     * @param ReadableStreamInterface    $innerStream
     * @param HydrationStrategyInterface $hydrationStrategy
     */
    public function __construct(ReadableStreamInterface $innerStream, HydrationStrategyInterface $hydrationStrategy)
    {
        $this->hydrationStrategy = $hydrationStrategy;
        $this->initWrapper($innerStream);
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed ...$args
     */
    protected function filterData(...$args): ?array
    {
        $row = array_shift($args);

        return [$this->hydrationStrategy->hydrate($row)];
    }
}
