<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use InvalidArgumentException;

/**
 * Represents email verification result.
 *
 * @method static VerifyStatus UNKNOWN()
 * @method static VerifyStatus INVALID()
 * @method static VerifyStatus NO_MX_RECORDS()
 * @method static VerifyStatus SMTP_VERIFIED()
 * @method static VerifyStatus SMTP_USER_NOT_FOUND()
 * @method static VerifyStatus SMTP_CHECK_IMPOSSIBLE()
 * @method static VerifyStatus SMTP_RETRY_LATER()
 * @method static VerifyStatus SMTP_UNEXPECTED_REPLY()
 */
class VerifyStatus
{
    const UNKNOWN = 'unknown';
    const INVALID = 'invalid';
    const NO_MX_RECORDS = 'no_mx_records';
    const SMTP_VERIFIED = 'smtp_user_exists';
    const SMTP_USER_NOT_FOUND = 'smtp_user_not_found';
    const SMTP_CHECK_IMPOSSIBLE = 'smtp_check_impossible';
    const SMTP_RETRY_LATER = 'smtp_retry_later';
    const SMTP_UNEXPECTED_REPLY = 'smtp_unexpected_reply';

    private string $value;

    public function __construct(string $value = self::UNKNOWN)
    {
        if (!isset(self::getDescriptions()[$value])) {
            throw new InvalidArgumentException("'$value' is not a valid verify status.");
        }
        $this->value = $value;
    }

    private static function getDescriptions(): array
    {
        return [
            self::UNKNOWN => 'No data',
            self::INVALID => 'Email address is invalid',
            self::SMTP_CHECK_IMPOSSIBLE => 'Not possible to check email using SMTP RCPT TO reply.'.
                ' Usually this means that server accepts any email, sent by RCPT TO',
            self::NO_MX_RECORDS => 'Email domain has no MX records',
            self::SMTP_VERIFIED => 'Email account exists',
            self::SMTP_USER_NOT_FOUND => 'Email account not found',
            self::SMTP_RETRY_LATER => 'Too many SMTP messages or connections from IP address'.
                ' or IP address is banned by server',
            self::SMTP_UNEXPECTED_REPLY => 'Server reply has unexpected reply code. Run verify command'.
                ' with --filter for this email and -vvv option to see server reply',
        ];
    }

    /**
     * @return VerifyStatus[]
     */
    public static function all(): array
    {
        return array_map(function ($item) {
            return new self($item);
        }, array_keys(self::getDescriptions()));
    }

    public static function __callStatic(string $name, array $arguments): self
    {
        return new self(\constant(self::class."::$name"));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function getDescription(): string
    {
        return self::getDescriptions()[$this->value];
    }

    public function isUnknown(): bool
    {
        return self::UNKNOWN === $this->value;
    }
}
