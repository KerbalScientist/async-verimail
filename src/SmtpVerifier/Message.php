<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\SmtpVerifier;


use InvalidArgumentException;

final class Message
{
    /**
     * SMTP Reply codes.
     */
    const RCODE_OK = 250;
    const RCODE_SERVICE_READY = 220;
    const RCODE_ACTION_NOT_TAKEN = 550;
    const RCODE_ADDRESS_INACTIVE = 540;
    const RCODE_CLOSE = 221;
    const RCODE_UNKNOWN = 0;

    const RCODE_LENGTH = 3;

    const STATE_OK = 'ok';
    const STATE_SENDER_BLOCKED = 'sender_blocked';
    const STATE_AUTH_NEEDED = 'auth_needed';
    const STATE_OVER_QUOTA = 'over_quota';
    const STATE_TOO_MANY_RECIPIENTS = 'too_many_recipients';
    const STATE_ABOUT_TO_CLOSE = 'about_to_close';

    public int $rcode = self::RCODE_UNKNOWN;

    public string $text = '';

    public string $data = '';

    public string $connectionState = self::STATE_OK;

    public static function createForData(string $data): self
    {
        $message = new self();
        $code = substr($data, 0, self::RCODE_LENGTH);
        if (strlen($code) !== self::RCODE_LENGTH || !is_numeric($code)) {
            throw new InvalidArgumentException("Malformed SMTP reply.");
        }
        $message->rcode = (int)$code;
        $message->text = substr($data, self::RCODE_LENGTH);
        $message->data = $data;
        if ($message->isOverQuota()) {
            $message->connectionState = self::STATE_OVER_QUOTA;
        }
        if ($message->isSenderBlocked()) {
            $message->connectionState = self::STATE_SENDER_BLOCKED;
            return $message;
        }
        if ($message->isAuthNeeded()) {
            $message->connectionState = self::STATE_AUTH_NEEDED;
            return $message;
        }
        if ($message->isTooManyRecipients()) {
            $message->connectionState = self::STATE_TOO_MANY_RECIPIENTS;
        }
        if ($message->isAboutToClose()) {
            $message->connectionState = self::STATE_ABOUT_TO_CLOSE;
        }
        return $message;
    }

    private function isOverQuota(): bool
    {
        if ($this->rcode < 400) {
            return false;
        }
        return (bool)preg_match('/\b(over quota|OverQuotaTemp|' .
            'too many concurrent|try again later)\b/i', $this->data);
    }

    private function isSenderBlocked(): bool
    {
        if ($this->rcode < 400) {
            return false;
        }
        return (bool)preg_match(
            '/\b(spamhaus|blocked|abuseat|refused|' .
            'you are not allowed|black listed|not permitted)\b/i', $this->data);
    }

    private function isAuthNeeded(): bool
    {
        if ($this->rcode < 400) {
            return false;
        }
        if (preg_match('/\bsender verify failed\b/i', $this->data)) {
            return true;
        }
        if ($this->rcode >= 500) {
            return false;
        }
        return (bool)preg_match('/\bunknown user account|should log in\b/i', $this->data);
    }

    private function isTooManyRecipients(): bool
    {
        if ($this->rcode < 400) {
            return false;
        }
        return (bool)preg_match(
            '/\b(too many recipients)\b/i', $this->data);
    }

    private function isAboutToClose(): bool
    {
        if ($this->rcode === self::RCODE_CLOSE) {
            return true;
        }
        if ($this->rcode < 400) {
            return false;
        }
        return (bool)preg_match(
            '/\b(not accepting network messages|timeout)\b/i',
            $this->data
        );
    }

    /**
     * @throws SenderBlockedException|OverQuotaException|AuthenticationRequiredException|UnexpectedReplyException|TooManyRecipientsException
     */
    public function throwSenderStatusException()
    {
        if ($this->connectionState !== self::STATE_OK) {
            $message = 'Server reply: ' . $this->data;
        } else {
            $message = '';
        }
        switch ($this->connectionState) {
            case Message::STATE_AUTH_NEEDED:
                throw new AuthenticationRequiredException($message);
            case Message::STATE_OVER_QUOTA:
                throw new OverQuotaException($message);
            case Message::STATE_SENDER_BLOCKED:
                throw new SenderBlockedException($message);
            case Message::STATE_TOO_MANY_RECIPIENTS:
                throw new TooManyRecipientsException($message);
            case Message::STATE_ABOUT_TO_CLOSE:
                throw new ConnectionClosedException($message);
        }
    }

}
