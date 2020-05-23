<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use Psr\Log\LoggerInterface;

trait LoggerAwareTrait
{
    /**
     * The logger instance.
     */
    protected Logger $logger;

    /**
     * Sets a logger.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        if ($logger instanceof Logger) {
            $this->logger = $logger;
        } else {
            $this->logger = new Logger($logger);
        }
    }
}
