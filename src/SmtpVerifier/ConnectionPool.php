<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\SmtpVerifier;

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
    private array $connectionPool = [];
    /**
     * @var int[]
     */
    private array $maxConnectionsPerHost;
    private array $unreliableHosts;
    private ConnectionInterface $unreliableConnection;
    private float $inactiveTimeout;
    private LoopInterface $eventLoop;

    /**
     * ConnectionPool constructor.
     *
     * @param ConnectorInterface $connector
     * @param LoopInterface      $eventLoop
     * @param array              $settings
     */
    public function __construct(ConnectorInterface $connector, LoopInterface $eventLoop, array $settings)
    {
        $this->connector = $connector;
        $this->eventLoop = $eventLoop;
        $settings['maxConnectionsPerHost'] = $settings['maxConnectionsPerHost'] ?? 1;
        if (is_array($settings['maxConnectionsPerHost'])) {
            $this->maxConnectionsPerHost = $settings['maxConnectionsPerHost'];
        } else {
            $this->maxConnectionsPerHost = [
                '*' => intval($settings['maxConnectionsPerHost']),
            ];
        }
        $this->logger = new NullLogger();
        $this->unreliableHosts = array_flip($settings['unreliableHosts'] ?? []);
        $this->inactiveTimeout = $settings['inactiveTimeout'] ?? 5;
        $this->unreliableConnection = new UnreliableConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $hostname): PromiseInterface
    {
        if (empty($this->connectionPool[$hostname])
            || count($this->connectionPool[$hostname]) < $this->getMaxConnections($hostname)) {
            $this->logger->debug("Connection - create for $hostname.");

            return $this->getConnection($hostname);
        }
        /*
         * @todo Round-robin instead of random.
         */
        $this->logger->debug("Connection - reuse busy for $hostname.");

        return $this->connectionPool[$hostname][array_rand($this->connectionPool[$hostname])];
    }

    private function getMaxConnections(string $hostname): int
    {
        return $this->maxConnectionsPerHost[$hostname] ?? $this->maxConnectionsPerHost['*'] ?? 1;
    }

    private function getConnection(string $hostname): PromiseInterface
    {
        /*
         * @todo Check MX hosts.
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
                /**
                 * @todo Settings.
                 */
                $reconnectingConnection = new ReconnectingConnection($connection, $this->connector, $hostname);
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
                $timer = $this->eventLoop->addTimer(
                    $this->inactiveTimeout,
                    function () use ($connection, $hostname) {
                        $this->logger->debug("Connection activity timeout for host $hostname.");
                        $connection->close();
                    });
                $connection->on('active', function () use ($timer) {
                    $this->eventLoop->cancelTimer($timer);
                });

                return $connection;
            });

        return $this->connectionPool[$hostname][$key];
    }
}
