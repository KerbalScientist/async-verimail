<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    private LoggerInterface $innerLogger;

    /**
     * LoggerDecorator constructor.
     *
     * @param LoggerInterface $innerLogger
     */
    public function __construct(LoggerInterface $innerLogger)
    {
        $this->innerLogger = $innerLogger;
    }

    /**
     * {@inheritdoc}
     */
    public function emergency($message, array $context = array())
    {
        $this->innerLogger->emergency($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function alert($message, array $context = array())
    {
        $this->innerLogger->alert($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function critical($message, array $context = array())
    {
        $this->innerLogger->critical($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error($message, array $context = array())
    {
        $this->innerLogger->error($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message, array $context = array())
    {
        $this->innerLogger->warning($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function notice($message, array $context = array())
    {
        $this->innerLogger->notice($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function info($message, array $context = array())
    {
        $this->innerLogger->info($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function debug($message, array $context = array())
    {
        $this->innerLogger->debug($message, $context);
    }

    public function debugQuery(string $sql, array $bindValues = [], string $append = '', $context = array())
    {
        $this->innerLogger->log(
            LogLevel::DEBUG,
            "Query:\n$sql\n".
            'Bind: '.json_encode($bindValues, JSON_UNESCAPED_UNICODE)."\n  ".
            $append, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        $this->innerLogger->log($level, $message, $context);
    }
}
