<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Entity;

use App\Verifier\VerifyStatus;
use DateTimeImmutable;
use DateTimeInterface;

class Email
{
    public ?int $id = null;

    public string $email;

    public VerifyStatus $status;

    public DateTimeInterface $updated;

    public function __construct()
    {
        $this->status = new VerifyStatus();
        $this->updated = new DateTimeImmutable();
    }
}
