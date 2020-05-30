<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Smtp;

use App\MutexRun\Queue;
use App\Smtp\ConnectionInterface as VerifierConnectionInterface;
use Evenement\EventEmitterTrait;
use Exception;
use LogicException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

class Connection implements LoggerAwareInterface, VerifierConnectionInterface
{
    use LoggerAwareTrait;
    use EventEmitterTrait;

    const MESSAGE_SEPARATOR = "\r\n";
    const MESSAGE_LINE_SEPARATOR = "\n";
    const MULTILINE_REPLY_MARKER = '-';

    private ConnectionInterface $connection;
    private bool $eventListenersSet = false;

    private ?Deferred $replyDeferred = null;
    private string $replyBuffer = '';
    private bool $closed = false;
    private ?Exception $closedException = null;
    private string $remoteAddress;
    private string $localAddress;
    private PromiseInterface $openingMessage;
    private ?Deferred $drainDeferred = null;
    private Queue $queue;

    public function __construct(ConnectionInterface $connection, Queue $queue)
    {
        $this->connection = $connection;
        $this->logger = new NullLogger();
        $this->queue = $queue;
        $this->remoteAddress = $this->connection->getRemoteAddress();
        $this->localAddress = $this->connection->getLocalAddress();
        $this->openingMessage = $this->receiveReply();
    }

    /**
     * {@inheritdoc}
     */
    public function getOpeningMessage(): PromiseInterface
    {
        return $this->openingMessage;
    }

    /**
     * @param string $name
     * @param string $data
     *
     * @return PromiseInterface resolves to Message
     */
    public function sendCommand(string $name, string $data = ''): PromiseInterface
    {
        return $this->queue->enqueue(function () use ($name, $data) {
            if ($this->closedException) {
                return reject($this->closedException);
            }
            if ($data) {
                $data = ' '.$data;
            }
            $this->logger->debug("To {$this->getRemoteAddress()}".
                " from {$this->getLocalAddress()}: $name$data");
            $onDrained = function () use ($name, $data) {
                $result = $this->connection->write($name.$data.self::MESSAGE_SEPARATOR);
                if (!$result) {
                    $this->drainDeferred = new Deferred();
                    $this->connection->once('drain', function () {
                        $this->drainDeferred->resolve();
                        $this->drainDeferred = null;
                    });
                }

                return $this->receiveReply(false);
            };
            if ($this->drainDeferred) {
                return $this->drainDeferred->promise()
                    ->then($onDrained);
            }

            return $onDrained();
        });
    }

    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function getLocalAddress(): string
    {
        return $this->localAddress;
    }

    /**
     * @param bool $enqueue
     *
     * @return PromiseInterface resolves to Message
     */
    private function receiveReply($enqueue = true): PromiseInterface
    {
        if ($this->closedException) {
            return reject($this->closedException);
        }
        $this->emit('active');
        if ($this->replyDeferred) {
            throw new LogicException('Cannot receive reply before previous reply is received.');
        }
        $this->replyDeferred = $deferred = new Deferred();
        if (!$this->eventListenersSet) {
            $this->eventListenersSet = true;
            $this->setEventListeners();
        }

        $callback = function () use ($deferred) {
            return $deferred->promise()
                ->then(function ($data) {
                    $this->emit('active');

                    return Message::createForData($data);
                });
        };
        if ($enqueue) {
            return $this->queue->enqueue($callback);
        }

        return resolve($callback());
    }

    private function setEventListeners(): void
    {
        $this->connection->on('data', function ($data) {
            if (false === strpos($data, self::MESSAGE_SEPARATOR)) {
                // Reply part received.
                $this->replyBuffer .= $data;

                return;
            }
            $this->logger->debug("From {$this->getRemoteAddress()}".
                " to {$this->getLocalAddress()}: $data");
            // Reply line received.
            $separated = explode(self::MESSAGE_SEPARATOR,
                $this->replyBuffer.$data);
            $this->replyBuffer = array_pop($separated) ?? '';
            $replyMultiline = '';
            foreach ($separated as $reply) {
                if (self::MULTILINE_REPLY_MARKER === substr($reply, Message::RCODE_LENGTH, 1)) {
                    // Received multiline reply part.
                    $replyMultiline .= substr($reply, Message::RCODE_LENGTH + 1)
                        .self::MESSAGE_LINE_SEPARATOR;

                    continue;
                }
                // Received full reply.
                $reply = substr($reply, 0, Message::RCODE_LENGTH + 1)
                    .$replyMultiline
                    .substr($reply, Message::RCODE_LENGTH + 1);
                $replyMultiline = '';
                if (!$this->replyDeferred) {
                    $this->emit('message', [Message::createForData($reply)]);
                    $this->logger->debug("Unrequested message from {$this->getRemoteAddress()}".
                        " to {$this->getLocalAddress()}: $reply");

                    continue;
                }
                $this->replyDeferred->resolve($reply);
                $this->replyDeferred = null;
            }
        });
        $onCloseError = function ($error = null) {
            $action = (null === $error) ? 'closed' : 'error';
            $this->logger->debug("Connection $action from {$this->getRemoteAddress()}".
                " to {$this->getLocalAddress()}.");
            if (!$this->closedException) {
                $this->closedException = new ConnectionClosedException("Connection $action.", 0, $error);
            }
            if (null !== $error) {
                $this->emit('error', [$error]);
            }
            $this->close();
            if ($this->drainDeferred) {
                $this->drainDeferred->reject($this->closedException);
            }
            if ($this->replyDeferred) {
                $this->replyDeferred->reject($this->closedException);
            }
        };
        $this->connection->on('error', $onCloseError);
        $this->connection->on('close', $onCloseError);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->connection->close();
        $this->emit('close');
        $this->removeAllListeners();
    }
}
