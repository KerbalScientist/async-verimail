<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\SmtpVerifier;

use App\Config\HostsSettingsCollection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

class ConnectionPool implements ConnectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ConnectorInterface $connector;

    /**
     * @var array[]
     */
    private array $connectionPool = [];
    /**
     * @var bool[]
     */
    private array $unreliableHosts = [];
    private ConnectionInterface $unreliableConnection;
    private LoopInterface $eventLoop;
    private HostsSettingsCollection $settings;

    /**
     * ConnectionPool constructor.
     *
     * @param ConnectorInterface      $connector
     * @param LoopInterface           $eventLoop
     * @param HostsSettingsCollection $settings
     */
    public function __construct(ConnectorInterface $connector, LoopInterface $eventLoop, HostsSettingsCollection $settings)
    {
        $this->connector = $connector;
        $this->eventLoop = $eventLoop;
        $this->settings = $settings;
        $this->logger = new NullLogger();
        foreach ($settings->getAll() as $hostSettings) {
            if ($hostSettings->isUnreliable()) {
                $this->unreliableHosts[$hostSettings->getHostname()] = true;
            }
        }
        $this->unreliableConnection = new UnreliableConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $hostname): PromiseInterface
    {
        $hostname = mb_strtolower($hostname);
        $maxConnections = $this->settings
            ->findForHostname($hostname)
            ->getMaxConnections();
        if (empty($this->connectionPool[$hostname])
            || count($this->connectionPool[$hostname]) < $maxConnections) {
            $this->logger->debug("Connection - create for $hostname.");

            return $this->getConnection($hostname);
        }
        /*
         * @todo Round-robin instead of random.
         */
        $this->logger->debug("Connection - reuse busy for $hostname.");

        return $this->connectionPool[$hostname][array_rand($this->connectionPool[$hostname])];
    }

    private function getConnection(string $hostname): PromiseInterface
    {
        /*
         * @todo Cache reliability by hostname and by MX host.
         */
        if (isset($this->unreliableHosts[$hostname])) {
            return resolve($this->unreliableConnection);
        }
        if (!isset($this->connectionPool[$hostname])) {
            $this->connectionPool[$hostname] = [];
        }

        $key = array_key_last($this->connectionPool[$hostname]) + 1;
        $this->connectionPool[$hostname][$key]
            = $this->connector->connect($hostname)
            ->then(function (ConnectionInterface $connection) use ($hostname) {
                $reconnectingConnection = new ReconnectingConnection($connection, $this->connector, $hostname);
                $reconnectingConnection->setMaxReconnects(
                    $this->settings->findForHostname($hostname)
                        ->getMaxReconnects());
                $reconnectingConnection->setLogger($this->logger);

                return all([
                    'connection' => $reconnectingConnection,
                    'isReliable' => $reconnectingConnection->isReliable(),
                ]);
            })
            ->then(function ($result) use ($hostname, $key) {
                if (!$result['isReliable']) {
                    $this->unreliableHosts[$hostname] = true;
                    $this->logger->debug("Unreliable connection for host $hostname.");

                    return $this->unreliableConnection;
                }
                /* @var $connection ConnectionInterface */
                $connection = $result['connection'];
                $closeCallback = function () use ($hostname, $key) {
                    unset($this->connectionPool[$hostname][$key]);
                    $this->logger->debug("$hostname MX connection closed.");
                };
                $connection->on('error', $closeCallback);
                $connection->on('close', $closeCallback);
                $timeout = $this->settings->findForHostname($hostname)
                    ->getInactiveTimeout();
                if (!$timeout) {
                    return $connection;
                }
                $timer = null;
                $connection->on('active', function () use (&$timer, $timeout, $connection, $hostname) {
                    if ($timer) {
                        $this->eventLoop->cancelTimer($timer);
                    }
                    $timer = $this->eventLoop->addTimer(
                        $timeout,
                        function () use ($connection, $hostname) {
                            $this->logger->debug("Connection activity timeout for host $hostname.");
                            $connection->close();
                        });
                });
                $connection->emit('active');

                return $connection;
            });

        return $this->connectionPool[$hostname][$key];
    }
}
