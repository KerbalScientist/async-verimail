<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Smtp;

use App\Mutex;
use InvalidArgumentException;
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
use function React\Promise\reject;

class Connector implements LoggerAwareInterface, ConnectorInterface
{
    use LoggerAwareTrait;

    private const MX_DOMAIN_COLUMN = 'target';
    private const MX_PORT = 25;
    private ResolverInterface $resolver;
    private SocketConnectorInterface $connector;
    private Mutex $mutex;

    /**
     * Connector constructor.
     *
     * @param ResolverInterface        $resolver
     * @param SocketConnectorInterface $connector
     * @param Mutex                    $mutex
     */
    public function __construct(
        ResolverInterface $resolver,
        SocketConnectorInterface $connector,
        Mutex $mutex
    ) {
        $this->resolver = $resolver;
        $this->connector = $connector;
        $this->mutex = $mutex;
        $this->logger = new NullLogger();
    }

    /**
     * {@inheritdoc}
     *
     * @todo Refactor. Extract MX server socket connection creation to method.
     */
    public function connect(string $hostname): PromiseInterface
    {
        $hostname = mb_strtolower($hostname);

        return $this->resolveMxRecords($hostname)
            ->then(function ($records) use ($hostname) {
                /**
                 * @todo Cache first alive host.
                 * @todo Respect records priority.
                 * @todo Extract method.
                 */
                $mxHosts = array_column($records, self::MX_DOMAIN_COLUMN);
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
            })
            ->then(function ($result) use ($hostname) {
                if ($result instanceof ConnectionInterface) {
                    return $result;
                }
                if (!$result instanceof SocketConnectionInterface) {
                    return reject(new InvalidArgumentException(
                        'Result must implement '.SocketConnectionInterface::class.
                        ' or '.ConnectionInterface::class.'.'));
                }
                $socketConnection = $result;
                $this->logger->debug("$hostname MX - connected to host".
                    " {$socketConnection->getRemoteAddress()} from {$socketConnection->getLocalAddress()}.");
                $connection = new Connection($socketConnection, $this->mutex);
                $connection->setLogger($this->logger);

                return $connection;
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

            return $result;
        };

        return $this->resolver->resolveAll($hostname, Message::TYPE_MX)
            ->then($callback, $callback);
    }
}
