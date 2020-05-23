<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\SmtpVerifier;

use RuntimeException;
use Throwable;

class UnexpectedReplyException extends RuntimeException
{
    private Message $smtpMessage;

    public function __construct(Message $smtpMessage, array $expectedCodes, $code = 0, Throwable $previous = null)
    {
        $this->smtpMessage = $smtpMessage;
        parent::__construct("Unexpected server reply: $smtpMessage->data.".
            ' Expected codes: '.implode(' ,', $expectedCodes).'.', $code, $previous);
    }

    public function getSmtpMessage(): Message
    {
        return $this->smtpMessage;
    }
}
