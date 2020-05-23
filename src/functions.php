<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App;

use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * @param ReadableStreamInterface $source
 * @param WritableStreamInterface $target
 * @param DuplexStreamInterface[] $through
 * @param array $options
 */
function pipeThrough(ReadableStreamInterface $source, array $through, WritableStreamInterface $target, $options = [])
{
    $current = $source;
    array_push($through, $target);
    foreach ($through as $item) {
        $current->pipe($item, $options);
        if ($options['error'] ?? false) {
            $current->on('error', function ($error) use ($item) {
                $item->emit('error', [$error]);
                $item->close();
            });
        }
        if ($options['close'] ?? false) {
            $current->on('close', function () use ($item) {
                $item->close();
            });
        }
        if ($options['closeToEnd'] ?? false) {
            $current->on('close', function () use ($item) {
                $item->end();
            });
        }
        $current = $item;
    }
}

