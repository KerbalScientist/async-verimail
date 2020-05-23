<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\SmtpVerifier;

use React\Promise\PromiseInterface;

interface ConnectorInterface
{
    /**
     * @param string $hostname
     * @return PromiseInterface<Connection>
     */
    public function connect(string $hostname): PromiseInterface;
}
