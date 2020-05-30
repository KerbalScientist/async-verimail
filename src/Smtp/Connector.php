<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Smtp;

use App\MutexRun\Factory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Dns\Model\Message;
use React\Dns\RecordNotFoundException;
use React\Dns\Resolver\ResolverInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\Socket\ConnectorInterface as SocketConnectorInterface;
use RuntimeException;
use Throwable;

class Connector implements LoggerAwareInterface, ConnectorInterface
{
    use LoggerAwareTrait;

    private const MX_RECORD_HOSTNAME_COLUMN = 'target';
    private const MX_RECORD_PRIORITY_COLUMN = 'priority';
    private const MX_PORT = 25;
    private ResolverInterface $resolver;
    private SocketConnectorInterface $connector;
    private Factory $mutex;

    public function __construct(
        ResolverInterface $resolver,
        SocketConnectorInterface $connector,
        Factory $mutex
    ) {
        $this->resolver = $resolver;
        $this->connector = $connector;
        $this->mutex = $mutex;
        $this->logger = new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $hostname): PromiseInterface
    {
        return $this->createSocketConnection($hostname)
            ->then(function (SocketConnectionInterface $socketConnection) use ($hostname) {
                $this->logger->debug("$hostname MX - connected to host".
                    " {$socketConnection->getRemoteAddress()} from {$socketConnection->getLocalAddress()}.");
                $connection = new Connection($socketConnection, $this->mutex->createQueue());
                $connection->setLogger($this->logger);

                return $connection;
            });
    }

    private function createSocketConnection(string $hostname): PromiseInterface
    {
        return $this->resolveMxRecords($hostname)
            ->then(function ($mxHosts) use ($hostname) {
                $this->logger->debug("MX hosts found for $hostname: ".implode(', ', $mxHosts));
                $socketConnection = null;
                $result = null;
                foreach ($mxHosts as $mxHost) {
                    if ($result) {
                        $result = $result->then(null, function (Throwable $e) use ($hostname, $mxHost) {
                            $this->logger->debug("$hostname MX - unable to connect to $mxHost".':'.self::MX_PORT
                                .". {$e->getMessage()}");

                            return $this->connector->connect($mxHost.':'.self::MX_PORT);
                        });
                    } else {
                        $result = $this->connector->connect($mxHost.':'.self::MX_PORT);
                    }
                }

                return $result;
            })
            ->then(null, function (Throwable $e) use ($hostname) {
                if ($e instanceof NoMxRecordsException) {
                    throw $e;
                }

                throw new RuntimeException("Unable to connect to any MX server for $hostname.", 0, $e);
            });
    }

    private function resolveMxRecords(string $hostname): PromiseInterface
    {
        $callback = function ($result) use ($hostname) {
            $error = null;
            if ($result instanceof Throwable) {
                $error = $result;
            }
            if (!$result || ($error instanceof RecordNotFoundException)) {
                throw new NoMxRecordsException(
                    "No MX records for hostname '$hostname'.",
                    0,
                    $error
                );
            }
            if ($error) {
                throw $error;
            }
            usort($result, function ($item1, $item2) {
                return ($item1[self::MX_RECORD_PRIORITY_COLUMN] ?? 0) - ($item2[self::MX_RECORD_PRIORITY_COLUMN] ?? 0);
            });

            return array_column($result, self::MX_RECORD_HOSTNAME_COLUMN);
        };

        return $this->resolver->resolveAll($hostname, Message::TYPE_MX)
            ->then($callback, $callback);
    }
}
