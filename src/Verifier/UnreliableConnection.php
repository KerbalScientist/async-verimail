<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use Evenement\EventEmitterTrait;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

class UnreliableConnection implements ConnectionInterface
{
    use EventEmitterTrait;

    /**
     * {@inheritdoc}
     */
    public function sendVerifyRecipient(string $email): PromiseInterface
    {
        return resolve(true);
    }

    /**
     * {@inheritdoc}
     */
    public function isReliable(): PromiseInterface
    {
        return resolve(false);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        // Do nothing.
    }
}
