<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class LevelFilteringLogger extends AbstractLogger implements LoggerInterface
{
    private LoggerInterface $innerLogger;
    private array $showLevels;

    /**
     * LevelFilteringLogger constructor.
     *
     * @param LoggerInterface $innerLogger
     * @param array           $showLevels
     */
    public function __construct(LoggerInterface $innerLogger, ?array $showLevels = null)
    {
        $this->innerLogger = $innerLogger;
        if (is_null($showLevels)) {
            $showLevels = [
                LogLevel::EMERGENCY,
                LogLevel::ALERT,
                LogLevel::CRITICAL,
                LogLevel::ERROR,
                LogLevel::WARNING,
                LogLevel::NOTICE,
                LogLevel::INFO,
                LogLevel::DEBUG,
            ];
        }
        $this->showLevels = array_flip($showLevels);
    }

    public function withHideLevels(array $levels): self
    {
        $logger = clone $this;
        $logger->showLevels = array_diff_key($this->showLevels, array_flip($levels));

        return $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        if (!isset($this->showLevels[$level])) {
            return;
        }
        $this->innerLogger->log($level, $message, $context);
    }
}
