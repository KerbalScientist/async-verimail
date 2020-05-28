<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Smtp;

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;

interface ConnectionInterface extends EventEmitterInterface
{
    /**
     * @return PromiseInterface server opening message. Resolves to \App\SmtpVerifier\Message
     *
     * @see \App\Smtp\Message
     */
    public function getOpeningMessage(): PromiseInterface;

    /**
     * @param string $name
     * @param string $data
     *
     * @return PromiseInterface server reply. Resolves to \App\SmtpVerifier\Message
     *
     * @see \App\Smtp\Message
     */
    public function sendCommand(string $name, string $data = ''): PromiseInterface;

    public function getRemoteAddress(): string;

    public function getLocalAddress(): string;

    /**
     * Closes connection.
     */
    public function close(): void;
}
