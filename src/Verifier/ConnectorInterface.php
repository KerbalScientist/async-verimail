<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use React\Promise\PromiseInterface;

interface ConnectorInterface
{
    /**
     * @param string $hostname
     *
     * @return PromiseInterface resolves to \App\Verifier\ConnectionInterface
     */
    public function connect(string $hostname): PromiseInterface;
}
