<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use App\Smtp\ConnectionClosedException;
use App\Smtp\Message;
use App\Smtp\NoMxRecordsException;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;
use function React\Promise\resolve;

class Verifier implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ConnectorInterface $verifierConnector;
    /**
     * @var array[]
     */
    private array $handledExceptions;

    public function __construct(ConnectorInterface $verifierConnector)
    {
        $this->verifierConnector = $verifierConnector;
        $this->logger = new NullLogger();
        $this->handledExceptions = [
            AuthenticationRequiredException::class => [
                'text' => 'Cannot verify (authentication required)',
                'error' => false,
                'status' => VerifyStatus::SMTP_CHECK_IMPOSSIBLE(),
            ],
            OverQuotaException::class => [
                'text' => 'Over quota while verifying',
                'error' => false,
                'status' => VerifyStatus::SMTP_RETRY_LATER(),
            ],
            SenderBlockedException::class => [
                'text' => 'Sender blocked while verifying',
                'error' => false,
                'status' => VerifyStatus::SMTP_RETRY_LATER(),
            ],
            ConnectionClosedException::class => [
                'text' => 'Connection closed while verifying',
                'error' => false,
                'status' => VerifyStatus::SMTP_RETRY_LATER(),
            ],
            NoMxRecordsException::class => [
                'text' => 'No MX records for',
                'error' => false,
                'status' => VerifyStatus::NO_MX_RECORDS(),
            ],
            UnexpectedReplyException::class => [
                'text' => 'Unexpected reply while verifying',
                'error' => false,
                'status' => VerifyStatus::SMTP_UNEXPECTED_REPLY(),
            ],
            Exception::class => [
                'text' => 'Error while verifying',
                'error' => true,
                'status' => VerifyStatus::UNKNOWN(),
            ],
        ];
    }

    /**
     * @param string $email
     *
     * @return PromiseInterface PromiseInterface<VerifyStatus, Throwable>
     */
    public function verify(string $email): PromiseInterface
    {
        $this->logger->info("Verifying $email");
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->info("$email is invalid.");

            return resolve(VerifyStatus::INVALID());
        }
        $hostname = explode('@', $email)[1];

        return $this->verifierConnector->connect($hostname)
            ->then(function (ConnectionInterface $connection) {
                return all([
                    'connection' => $connection,
                    'isReliable' => $connection->isReliable(),
                ]);
            })
            ->then(function ($results) use ($email) {
                if (!$results['isReliable']) {
                    $this->logger->info("Cannot verify $email. Unreliable server.");

                    return VerifyStatus::SMTP_CHECK_IMPOSSIBLE();
                }
                if (!$results['connection'] instanceof ConnectionInterface) {
                    throw new InvalidArgumentException('Expected instance of '
                        .ConnectionInterface::class.'.');
                }

                return $results['connection']->sendVerifyRecipient($email);
            })
            ->then(function ($message) use ($email) {
                if (!$message instanceof Message) {
                    return $message;
                }
                if (Message::RCODE_OK === $message->rcode) {
                    $this->logger->info("$email verified.");

                    return VerifyStatus::SMTP_VERIFIED();
                } else {
                    $this->logger->info("$email NOT verified.");

                    return VerifyStatus::SMTP_USER_NOT_FOUND();
                }
            })
            ->then(null, function (Throwable $e) use ($email) {
                return $this->handleThrowable($e, $email);
            });
    }

    /**
     * @param Throwable $e
     * @param string    $email
     *
     * @return VerifyStatus
     *
     * @throws Throwable
     */
    private function handleThrowable(Throwable $e, string $email): VerifyStatus
    {
        foreach ($this->handledExceptions as $class => $data) {
            if (!$e instanceof $class) {
                continue;
            }
            if ($data['error']) {
                $this->logger->error("{$data['text']} $email. {$e->getMessage()}");
            } else {
                $this->logger->info("{$data['text']} $email.");
            }
            /*
             * Clue\React\Socks\Client Exception manipulation bug workaround.
             */
            if ($e->getPrevious()
                && false !== strpos($e->getPrevious()->getMessage(), 'connection to proxy failed')) {
                throw $e;
            }
            $this->logger->debug("$e");

            return $data['status'];
        }

        throw $e;
    }
}
