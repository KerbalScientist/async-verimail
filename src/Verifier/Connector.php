<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use App\Config\HostsSettingsCollection;
use App\MutexRun\Factory;
use App\Smtp\ConnectionInterface as SmtpConnectionInterface;
use App\Smtp\ConnectorInterface as SmtpConnectorInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;

class Connector implements LoggerAwareInterface, ConnectorInterface
{
    use LoggerAwareTrait;

    private SmtpConnectorInterface $connector;
    private Factory $mutex;
    private HostsSettingsCollection $settings;

    /**
     * Connector constructor.
     *
     * @param SmtpConnectorInterface  $connector
     * @param Factory                 $mutex
     * @param HostsSettingsCollection $settings
     */
    public function __construct(
        SmtpConnectorInterface $connector,
        Factory $mutex,
        HostsSettingsCollection $settings
    ) {
        $this->connector = $connector;
        $this->mutex = $mutex;
        $this->logger = new NullLogger();
        $this->settings = $settings;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $hostname): PromiseInterface
    {
        return $this->connector->connect($hostname)
            ->then(function (SmtpConnectionInterface $connection) use ($hostname) {
                return new Connection($connection, $this->mutex, $hostname, $this->settings->findForHostname($hostname));
            });
    }
}
