<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use React\Promise\PromiseInterface;
use React\Stream\WritableStreamInterface;

interface PersistingStreamInterface extends WritableStreamInterface
{
    public function flush(): PromiseInterface;

    public function setInsertBufferSize(int $insertBufferSize): void;

    public function setUpdateBufferSize(int $updateBufferSize): void;
}
