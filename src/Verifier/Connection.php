<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use App\Config\HostSettings;
use App\MutexRun\CallableOnce;
use App\MutexRun\Factory;
use App\MutexRun\Queue;
use App\Smtp\ConnectionClosedException;
use App\Smtp\ConnectionInterface as SmtpConnectionInterface;
use App\Smtp\Message;
use Evenement\EventEmitterTrait;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;
use React\Stream\Util;
use Throwable;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

class Connection implements LoggerAwareInterface, ConnectionInterface
{
    use LoggerAwareTrait;
    use EventEmitterTrait;

    private SmtpConnectionInterface $connection;
    private Factory $mutex;
    private string $fromEmail;
    private bool $initialized = false;
    private bool $eventListenersSet = false;
    private int $enqueuedCount = 0;
    private int $resetAfterVerifications;
    private int $closeAfterVerifications;
    private bool $busy = false;

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
    private Queue $verifyQueue;
    private CallableOnce $isReliableCallback;

    /**
     * SmtpVerifierConnection constructor.
     *
     * @param SmtpConnectionInterface $connection
     * @param \App\MutexRun\Factory   $mutex
     * @param string                  $hostname
     * @param HostSettings|null       $settings
     */
    public function __construct(
        SmtpConnectionInterface $connection,
        Factory $mutex,
        string $hostname,
        ?HostSettings $settings = null
    ) {
        $this->connection = $connection;
        Util::forwardEvents($connection, $this, ['active', 'error', 'close', 'message']);
        $this->mutex = $mutex;
        $this->verifyQueue = $mutex->createQueue();
        $this->hostname = $hostname;
        $this->logger = new NullLogger();
        if (!$settings) {
            $settings = new HostSettings();
        }
        $this->fromEmail = $settings->getFromEmail();
        $this->fromHost = $settings->getFromHost();
        $this->resetAfterVerifications = $settings->getResetAfterVerifications();
        $this->closeAfterVerifications = $settings->getCloseAfterVerifications();
        $this->randomUser = $settings->getRandomUser();
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
        if (!isset($this->isReliableCallback)) {
            $this->isReliableCallback = $this->mutex->createCallableOnce(function () {
                return $this->sendVerifyRecipient("$this->randomUser@$this->hostname")
                    ->then(fn (Message $message) => Message::RCODE_OK !== $message->rcode);
            });
        }

        return ($this->isReliableCallback)();
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
        $result = $this->verifyQueue->enqueue(function () use ($email) {
            if ($this->closedException) {
                return reject($this->closedException);
            }
            $this->busy = true;
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
                    $this->logger->debug("Verifying $email (to {$this->connection->getRemoteAddress()}"
                        ." from {$this->connection->getLocalAddress()}).");
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
    private function sendCommand(string $name, string $data = ''): PromiseInterface
    {
        return $this->connection->sendCommand($name, $data);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->connection->close();
        $this->removeAllListeners();
    }

    /**
     * @return PromiseInterface
     */
    private function init(): PromiseInterface
    {
        if ($this->closedException) {
            return reject($this->closedException);
        }
        if ($this->initialized) {
            return resolve();
        }
        $this->initialized = true;

        return $this->connection->getOpeningMessage()
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
        $this->throwSenderStatusException($message);
        if ($expectedReplyCodes && !in_array($message->rcode, $expectedReplyCodes, true)) {
            $exception = new UnexpectedReplyException($message, $expectedReplyCodes);
            $this->closedException = $exception;
            $this->emit('error', [$exception]);

            throw $exception;
        }

        return $message;
    }

    /**
     * @param Message $message
     */
    public function throwSenderStatusException(Message $message): void
    {
        if (Message::STATE_OK !== $message->connectionState) {
            $text = 'Server reply: '.$message->data;
        } else {
            $text = '';
        }
        switch ($message->connectionState) {
            case Message::STATE_AUTH_NEEDED:
                throw new AuthenticationRequiredException($text);
            case Message::STATE_OVER_QUOTA:
                throw new OverQuotaException($text);
            case Message::STATE_SENDER_BLOCKED:
                throw new SenderBlockedException($text);
            case Message::STATE_TOO_MANY_RECIPIENTS:
                throw new TooManyRecipientsException($text);
            case Message::STATE_ABOUT_TO_CLOSE:
                throw new ConnectionClosedException($text);
        }
    }

    /**
     * @param bool $rset
     *
     * @return PromiseInterface
     */
    private function reset($rset = true): PromiseInterface
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
}
