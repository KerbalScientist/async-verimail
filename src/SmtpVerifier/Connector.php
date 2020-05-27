<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\SmtpVerifier;

use App\Config\HostsSettingsCollection;
use App\Mutex;
use App\SmtpVerifier\ConnectionInterface as VerifierConnectionInterface;
use App\SmtpVerifier\ConnectorInterface as VerifierConnectorInterface;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Dns\Model\Message;
use React\Dns\RecordNotFoundException;
use React\Dns\Resolver\ResolverInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\Socket\ConnectorInterface;
use RuntimeException;
use Throwable;
use function React\Promise\reject;

class Connector implements LoggerAwareInterface, VerifierConnectorInterface
{
    use LoggerAwareTrait;

    private const MX_DOMAIN_COLUMN = 'target';
    private const MX_PORT = 25;
    private ResolverInterface $resolver;
    private ConnectorInterface $connector;
    private Mutex $mutex;
    /**
     * @var mixed[]
     */
    private array $connectionSettings;
    private HostsSettingsCollection $settings;

    /**
     * Connector constructor.
     *
     * @param ResolverInterface       $resolver
     * @param ConnectorInterface      $connector
     * @param Mutex                   $mutex
     * @param HostsSettingsCollection $settings
     */
    public function __construct(
        ResolverInterface $resolver,
        ConnectorInterface $connector,
        Mutex $mutex,
        HostsSettingsCollection $settings
    ) {
        $this->resolver = $resolver;
        $this->connector = $connector;
        $this->mutex = $mutex;
        $this->logger = new NullLogger();
        $this->settings = $settings;
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
                if ($result instanceof VerifierConnectionInterface) {
                    return $result;
                }
                if (!$result instanceof SocketConnectionInterface) {
                    return reject(new InvalidArgumentException(
                        'Result must implement '.SocketConnectionInterface::class.
                        ' or '.VerifierConnectionInterface::class.'.'));
                }
                $socketConnection = $result;
                $this->logger->debug("$hostname MX - connected to host".
                    " {$socketConnection->getRemoteAddress()} from {$socketConnection->getLocalAddress()}.");
                $connection = new Connection($socketConnection, $this->mutex,
                    $hostname, $this->settings->findForHostname($hostname));
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
