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
    public ?int $i_id = null;

    public string $m_mail;

    public VerifyStatus $s_status;

    public DateTimeInterface $dt_updated;

    public function __construct()
    {
        $this->s_status = new VerifyStatus();
        $this->dt_updated = new DateTimeImmutable();
    }
}
