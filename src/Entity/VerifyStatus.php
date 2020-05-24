<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Entity;

use InvalidArgumentException;

/**
 * Class EmailVerifyStatus.
 *
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

    /**
     * EmailVerifyStatus constructor.
     *
     * @param string $value
     */
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
            self::UNKNOWN => 'Нет данных',
            self::INVALID => 'Неверный формат адреса',
            self::SMTP_CHECK_IMPOSSIBLE => 'Невозможно проверить с помощью SMTP RCPT TO',
            self::NO_MX_RECORDS => 'Не найдены записи MX для домена',
            self::SMTP_VERIFIED => 'Пользователь существует',
            self::SMTP_USER_NOT_FOUND => 'Пользователь не найден',
            self::SMTP_RETRY_LATER => 'Исчерпана квота на количество запросов к SMTP с IP-адреса,'.
                ' либо IP-адрес заблокирован сервером',
            self::SMTP_UNEXPECTED_REPLY => 'Сервер вернул неверный код ответа',
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
        return new self(constant(self::class."::$name"));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function getDescription(): string
    {
        return self::getDescriptions()[$this->value];
    }
}
