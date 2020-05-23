<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\SmtpVerifier;

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;

interface ConnectionInterface extends EventEmitterInterface
{
    /**
     * @return bool
     */
    public function isBusy(): bool;

    /**
     * @param string $email
     * @return PromiseInterface<bool>
     */
    public function sendVerifyRecipient(string $email): PromiseInterface;

    /**
     * Checks if server replies ok on non-existent emails.
     *
     * @return PromiseInterface<bool>
     */
    public function isReliable(): PromiseInterface;

    /**
     * Closes connection.
     */
    public function close(): void;
}
