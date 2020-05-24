<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\SmtpVerifier;

use App\Mutex;
use App\SmtpVerifier\ConnectionInterface as VerifierConnectionInterface;
use Evenement\EventEmitterTrait;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use Throwable;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

class Connection implements LoggerAwareInterface, VerifierConnectionInterface
{
    use LoggerAwareTrait;
    use EventEmitterTrait;

    const REPLY_SEPARATOR = "\r\n";
    const MESSAGE_LINE_SEPARATOR = "\n";
    const MULTILINE_REPLY_MARKER = '-';

    private ConnectionInterface $connection;
    private Mutex $mutex;
    private string $fromEmail;
    private bool $initialized = false;
    private bool $eventListenersSet = false;
    private int $enqueuedCount = 0;
    private int $resetAfterVerifications;
    private int $closeAfterVerifications;
    private bool $busy = false;
    /**
     * @var Deferred[] awaited replies
     *
     * @todo Tested possibility to send multiple commands without waiting for replies.
     *     Though it is working for HELO and MAIL FROM commands, sometimes servers send not all of the replies.
     *     Also, replies is often sent in random order.
     */

    /**
     * @var Deferred[]
     */
    private array $replyDeferred = [];
    private string $replyBuffer = '';
    private string $fromHost;
    private string $randomUser;
    private string $hostname;
    private bool $reliable;
    private bool $resetOnVerify = false;
    private bool $closed = false;
    private ?Exception $closedException = null;
    private string $remoteAddress;
    private string $localAddress;

    /**
     * SmtpVerifierConnection constructor.
     *
     * @param ConnectionInterface $connection
     * @param Mutex               $mutex
     * @param string              $hostname
     * @param mixed[]             $settings
     */
    public function __construct(ConnectionInterface $connection, Mutex $mutex, string $hostname, array $settings)
    {
        $this->connection = $connection;
        $this->mutex = $mutex;
        $this->hostname = $hostname;
        $this->logger = new NullLogger();
        $this->fromEmail = $settings['fromEmail'] ?? 'test@example.com';
        $this->fromHost = $settings['fromHost'] ?? 'localhost';
        $this->resetAfterVerifications = $settings['resetAfterVerifications'] ?? 25;
        $this->closeAfterVerifications = $settings['closeAfterVerifications'] ?? 0;
        /* @noinspection SpellCheckingInspection */
        $this->randomUser = $settings['randomUser']
            ?? 'mo4rahpheix8ti7ohT0eoku0oisien6ohKaenuutareiCei3ad9Ibedoogh6quie';
        $this->remoteAddress = $this->connection->getRemoteAddress();
        $this->localAddress = $this->connection->getLocalAddress();
    }

    /**
     * @return bool
     */
    public function isBusy(): bool
    {
        return $this->busy;
    }

    /**
     * Checks if server replies ok on non-existent emails.
     *
     * @return PromiseInterface resolves to bool
     */
    public function isReliable(): PromiseInterface
    {
        return $this->mutex->runOnce([$this, __FUNCTION__], function () {
            if (isset($this->reliable)) {
                return resolve($this->reliable);
            }

            return $this->sendVerifyRecipient("$this->randomUser@$this->hostname")
                ->then(function (Message $message) {
                    $this->reliable = Message::RCODE_OK !== $message->rcode;

                    return $this->reliable;
                });
        });
    }

    /**
     * Sends verification message to server, returns promise resolving with server reply message.
     *
     * @param string $email
     *
     * @return PromiseInterface resolves to Message
     */
    public function sendVerifyRecipient(string $email): PromiseInterface
    {
        if ($this->closedException) {
            return reject($this->closedException);
        }
        $result = $this->mutex->enqueue([$this, __FUNCTION__], function () use ($email) {
            if ($this->closedException) {
                return reject($this->closedException);
            }
            $promises = [];
            if ($this->closeAfterVerifications && $this->enqueuedCount >= $this->closeAfterVerifications) {
                $promises[] = $this->sendCommand('QUIT');
            }
            if (!$this->initialized) {
                $promises[] = $this->init();
            }
            if ($this->resetOnVerify) {
                $promises[] = $this->reset();
                $this->resetOnVerify = false;
            }
            ++$this->enqueuedCount;
            if (!$this->resetOnVerify) {
                $this->resetOnVerify = ($this->resetAfterVerifications
                    && 0 === ($this->enqueuedCount % $this->resetAfterVerifications));
            }

            return all($promises)
                ->then(function () use ($email) {
                    if ($this->closedException) {
                        return reject($this->closedException);
                    }
                    $this->busy = true;
                    $this->logger->debug("Verifying $email (to {$this->getRemoteAddress()}"
                        ." from {$this->getLocalAddress()}).");
                    if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException("Invalid email '$email'.");
                    }

                    return $this->sendCommand('RCPT TO:', "<$email>")
                        ->then(function (Message $message) {
                            $this->busy = false;

                            return $this->validateReply(
                                $message,
                                Message::RCODE_OK,
                                Message::RCODE_ACTION_NOT_TAKEN,
                                Message::RCODE_ADDRESS_INACTIVE
                            );
                        }, function (Throwable $e) {
                            $this->busy = false;

                            throw $e;
                        });
                });
        });

        return $result->then(null, function (Throwable $e) use ($email) {
            if ($e instanceof TooManyRecipientsException) {
                $this->resetOnVerify = true;

                return $this->sendVerifyRecipient($email);
            }

            throw $e;
        });
    }

    /**
     * @param string $name
     * @param string $data
     *
     * @return PromiseInterface resolves to Message
     */
    public function sendCommand(string $name, string $data = ''): PromiseInterface
    {
        return $this->mutex->enqueue($this, function () use ($name, $data) {
            if ($this->closedException) {
                return reject($this->closedException);
            }
            if ($data) {
                $data = ' '.$data;
            }
            $this->logger->debug("To {$this->getRemoteAddress()}".
                " from {$this->getLocalAddress()}: $name$data");
            /*
             * @todo Respect return value. It will be false if buffer is full.
             *   In this case we must pause until drain event.
             */
            $this->connection->write($name.$data.self::REPLY_SEPARATOR);

            return $this->receiveReply(false);
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
        $this->replyDeferred[] = $deferred = new Deferred();
        if (!$this->eventListenersSet) {
            $this->eventListenersSet = true;
            $this->setEventListeners();
        }

        /**
         * @todo Try without onRejected.
         */
        $callback = function () use ($deferred) {
            return $deferred->promise()
                ->then(function ($data) {
                    $this->emit('active');

                    return Message::createForData($data);
                });
        };
        if ($enqueue) {
            return $this->mutex->enqueue($this, $callback);
        }

        return resolve($callback());
    }

    private function setEventListeners(): void
    {
        $this->connection->on('data', function ($data) {
            if (false === strpos($data, self::REPLY_SEPARATOR)) {
                // Reply part received.
                $this->replyBuffer .= $data;

                return;
            }
            $this->logger->debug("From {$this->getRemoteAddress()}".
                " to {$this->getLocalAddress()}: $data");
            // Reply line received.
            $separated = explode(self::REPLY_SEPARATOR,
                $this->replyBuffer.$data);
            $this->replyBuffer = array_pop($separated) ?? '';
            $replyMultiline = '';
            foreach ($separated as $reply) {
                if (self::MULTILINE_REPLY_MARKER === substr($reply, Message::RCODE_LENGTH, 1)) {
                    // Received multiline reply part.
                    $replyMultiline .= substr($reply, Message::RCODE_LENGTH + 1)
                        .self::MESSAGE_LINE_SEPARATOR;

                    continue;
                } else {
                    // Received full reply.
                    $reply = substr($reply, 0, Message::RCODE_LENGTH + 1)
                        .$replyMultiline
                        .substr($reply, Message::RCODE_LENGTH + 1);
                    $replyMultiline = '';
                }
                if (!$this->replyDeferred) {
                    $this->logger->debug("Unhandled message from {$this->getRemoteAddress()}".
                        " to {$this->getLocalAddress()}: $data");

                    continue;
                }
                array_shift($this->replyDeferred)
                    ->resolve($reply);
            }
        });
        $this->connection->on('error', function ($error) {
            $this->logger->debug("Connection error from {$this->getRemoteAddress()}".
                " to {$this->getLocalAddress()}.");
            if (!$this->closedException) {
                $this->closedException = new ConnectionClosedException('Connection error.', 0, $error);
            }
            $this->emit('error', [$error]);
            $this->close();
            while ($deferred = array_shift($this->replyDeferred)) {
                $deferred->reject($this->closedException);
            }
        });
        $this->connection->on('close', function () {
            $this->logger->debug("Connection closed from {$this->getRemoteAddress()}".
                " to {$this->getLocalAddress()}.");
            if (!$this->closedException) {
                $this->closedException = new ConnectionClosedException('Connection closed.');
            }
            $this->close();
            while ($deferred = array_shift($this->replyDeferred)) {
                $deferred->reject($this->closedException);
            }
        });
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

    /**
     * @return PromiseInterface
     */
    public function init(): PromiseInterface
    {
        if ($this->closedException) {
            return reject($this->closedException);
        }
        if ($this->initialized) {
            return resolve();
        }
        $this->initialized = true;

        return $this->receiveReply()
            ->then(function (Message $message) {
                $this->validateReply(
                    $message,
                    Message::RCODE_SERVICE_READY
                );

                return $this->reset(false)
                    ->then(function () use ($message) {
                        return $message;
                    });
            });
    }

    /**
     * @param Message $message
     * @param int     ...$expectedReplyCodes
     *
     * @return Message
     *
     * @throws SenderBlockedException|OverQuotaException|AuthenticationRequiredException|UnexpectedReplyException
     */
    private function validateReply(Message $message, ...$expectedReplyCodes): Message
    {
        if (Message::STATE_ABOUT_TO_CLOSE === $message->connectionState) {
            $this->close();
        }
        $message->throwSenderStatusException();
        if ($expectedReplyCodes && !in_array($message->rcode, $expectedReplyCodes, true)) {
            $exception = new UnexpectedReplyException($message, $expectedReplyCodes);
            $this->closedException = $exception;
            $this->emit('error', [$exception]);

            throw $exception;
        }

        return $message;
    }

    /**
     * @param bool $rset
     *
     * @return PromiseInterface
     */
    public function reset($rset = true): PromiseInterface
    {
        if ($this->closedException) {
            return reject($this->closedException);
        }
        if ($rset) {
            $promise = $this->sendCommand('RSET');
        } else {
            $promise = resolve();
        }

        return $promise
            ->then(function ($result) use ($rset) {
                if ($rset) {
                    $this->validateReply($result, Message::RCODE_OK);
                }

                return $this->sendCommand('HELO', $this->fromHost);
            })
            ->then(function ($result) {
                $this->validateReply($result, Message::RCODE_OK);

                return $this->sendCommand('MAIL FROM:', "<$this->fromEmail>");
            })->then(function ($result) {
                $this->validateReply($result, Message::RCODE_OK);
            });
    }

    public function __destruct()
    {
        $this->close();
    }
}
