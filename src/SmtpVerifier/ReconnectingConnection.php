<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\SmtpVerifier;

use Evenement\EventEmitterTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use React\Promise\PromiseInterface;
use React\Stream\Util;
use Throwable;
use function React\Promise\resolve;

class ReconnectingConnection implements ConnectionInterface, LoggerAwareInterface
{
    use EventEmitterTrait;
    use LoggerAwareTrait;

    /**
     * @var PromiseInterface resolves to ConnectionInterface
     */
    private PromiseInterface $innerConnectionPromise;
    private ?ConnectionInterface $innerConnection;
    private ConnectorInterface $connector;
    private string $hostname;
    private int $maxReconnects = 10;
    private int $reconnects = 0;
    private bool $reconnectLocked = false;
    private int $successfulCalls = 0;
    private bool $closed = false;

    /**
     * ReconnectingConnection constructor.
     *
     * @param ConnectionInterface $connection
     * @param ConnectorInterface  $connector
     * @param string              $hostname
     */
    public function __construct(
        ConnectionInterface $connection,
        ConnectorInterface $connector,
        string $hostname
    ) {
        $this->innerConnection = $connection;
        /* @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->setInnerConnectionPromise(resolve($connection));
        $this->connector = $connector;
        $this->hostname = $hostname;
    }

    /**
     * @param int $maxReconnects
     */
    public function setMaxReconnects(int $maxReconnects): void
    {
        $this->maxReconnects = $maxReconnects;
    }

    /**
     * {@inheritdoc}
     */
    public function isBusy(): bool
    {
        if (!$this->innerConnection) {
            return false;
        }

        return $this->innerConnection->isBusy();
    }

    /**
     * {@inheritdoc}
     */
    public function sendVerifyRecipient(string $email): PromiseInterface
    {
        return $this->callConnectionPromiseMethod('sendVerifyRecipient', $email);
    }

    /**
     * @param string $methodName
     * @param mixed  ...$args
     *
     * @return PromiseInterface
     */
    private function callConnectionPromiseMethod(string $methodName, ...$args): PromiseInterface
    {
        $result = $this->innerConnectionPromise
            ->then(function (ConnectionInterface $connection) use ($methodName, $args) {
                return $connection->$methodName(...$args);
            });
        if ($this->isOverReconnectLimit() || $this->closed) {
            return $result;
        }

        return $result
            ->then(function ($result) {
                ++$this->successfulCalls;
                $this->reconnects = 0;

                return $result;
            }, function (Throwable $e) use ($methodName, $args) {
                if ($e instanceof ConnectionClosedException) {
                    $this->reconnect();
                    $this->successfulCalls = 0;

                    return $this->$methodName(...$args);
                }

                throw $e;
            });
    }

    private function isOverReconnectLimit(): bool
    {
        return $this->maxReconnects && $this->reconnects > $this->maxReconnects;
    }

    private function reconnect(): void
    {
        if ($this->reconnectLocked) {
            return;
        }
        $this->reconnectLocked = true;
        ++$this->reconnects;
        $this->logger->debug("MX connection - reconnect to $this->hostname after $this->successfulCalls calls.");
        $this->setInnerConnectionPromise($this->connector->connect($this->hostname));
    }

    /**
     * @param PromiseInterface $innerConnectionPromise
     */
    private function setInnerConnectionPromise(PromiseInterface $innerConnectionPromise): void
    {
        $this->innerConnection = null;
        $this->innerConnectionPromise = $innerConnectionPromise
            ->then(function (ConnectionInterface $connection) {
                if ($connection instanceof self) {
                    return $connection->getInnerConnectionPromise();
                }

                return $connection;
            })
            ->then(function (ConnectionInterface $connection) {
                $this->innerConnection = $connection;
                Util::forwardEvents($this->innerConnection, $this, ['active']);
                $this->reconnectLocked = false;
                if ($this->isOverReconnectLimit()) {
                    $this->logger->debug("Connection to $this->hostname is over reconnect limit.");
                    Util::forwardEvents($this->innerConnection, $this, ['error']);
                    $this->innerConnection->on('close', [$this, 'close']);
                }

                return $connection;
            })
            ->then(null, function (Throwable $e) {
                $this->logger->error($e->getMessage());
                $this->logger->debug("$e");

                throw $e;
            });
    }

    /**
     * @return PromiseInterface
     */
    public function getInnerConnectionPromise(): PromiseInterface
    {
        return $this->innerConnectionPromise;
    }

    /**
     * {@inheritdoc}
     */
    public function isReliable(): PromiseInterface
    {
        return $this->callConnectionPromiseMethod('isReliable');
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->innerConnectionPromise
            ->then(function (ConnectionInterface $connection) {
                if ($this->closed) {
                    return;
                }
                $this->closed = true;
                $connection->close();
                $this->emit('close');
            });
    }
}
