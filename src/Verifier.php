<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\Entity\Email;
use App\Entity\VerifyStatus;
use App\SmtpVerifier\AuthenticationRequiredException;
use App\SmtpVerifier\ConnectionClosedException;
use App\SmtpVerifier\ConnectionInterface;
use App\SmtpVerifier\ConnectorInterface;
use App\SmtpVerifier\Message;
use App\SmtpVerifier\NoMxRecordsException;
use App\SmtpVerifier\OverQuotaException;
use App\SmtpVerifier\SenderBlockedException;
use App\SmtpVerifier\UnexpectedReplyException;
use App\Stream\CollectingThroughStream;
use App\Stream\ResolvingThroughStream;
use App\Stream\ThroughStream;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Stream\CompositeStream;
use React\Stream\DuplexStreamInterface;
use Throwable;
use function React\Promise\all;
use function React\Promise\resolve;

class Verifier implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ConnectorInterface $verifierConnector;
    private int $maxConcurrent;
    private Mutex $mutex;
    private array $handledExceptions;

    /**
     * Verifier constructor.
     *
     * @param ConnectorInterface $verifierConnector
     * @param Mutex              $mutex
     * @param array              $settings
     *                                              $settings['maxConcurrent'] - maximum concurrent verifications
     */
    public function __construct(ConnectorInterface $verifierConnector, Mutex $mutex, array $settings = [])
    {
        $this->verifierConnector = $verifierConnector;
        $this->mutex = $mutex;
        $this->logger = new NullLogger();
        $this->maxConcurrent = $settings['maxConcurrent'] ?? 100;
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
                'status' => null,
            ],
        ];
    }

    /**
     * Creates stream, that verifies, sets `Email::$s_status` property and passes `Email` entities through.
     *
     * @param LoopInterface $loop
     * @param array         $pipeOptions Options, passed to `App::pipeThrough()`
     *
     * @return DuplexStreamInterface<Email>
     */
    public function createVerifyingStream(LoopInterface $loop, $pipeOptions = []): DuplexStreamInterface
    {
        $through = function (Email $email) {
            return $this->verify($email, function (Email $email, ?VerifyStatus $status) {
                if (!is_null($status)) {
                    $email->s_status = $status;
                }

                return resolve($email);
            });
        };
        /*
         * @todo Remove debug $through.
         */
//        $through = function (Email $email) {
//            global $app;
//            $deferred = new Deferred();
//            $app->getEventLoop()->addTimer(rand(100, 1500) / 100, function () use ($email, $deferred) {
//                $deferred->resolve($email);
//            });
//            return $deferred->promise();
//        };
        pipeThrough(
            $collectingStream = new CollectingThroughStream($loop),
            [
                $verifyingStream = new ThroughStream($through),
            ],
            $resolvingStream = new ResolvingThroughStream($loop, $this->maxConcurrent),
            $pipeOptions
        );
        $result = new CompositeStream($resolvingStream, $collectingStream);
        $collectingStream->removeListener('close', [$result, 'close']);

        return $result;
    }

    public function verify(Email $email, callable $statusCallback): PromiseInterface
    {
        $this->logger->info("Verifying $email->m_mail");
        if (false === filter_var($email->m_mail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->info("$email->m_mail is invalid.");

            return $statusCallback($email, VerifyStatus::INVALID());
        }
        $hostname = explode('@', $email->m_mail)[1];

        return $this->verifierConnector->connect($hostname)
            ->then(function (ConnectionInterface $connection) {
                return all([
                    'connection' => $connection,
                    'isReliable' => $connection->isReliable(),
                ]);
            })
            ->then(function ($results) use ($statusCallback, $email) {
                if (!$results['isReliable']) {
                    $this->logger->info("Cannot verify $email->m_mail. Unreliable server.");

                    return $statusCallback($email, VerifyStatus::SMTP_CHECK_IMPOSSIBLE());
                }

                return $results['connection'];
            })
            ->then(function ($result) use ($email) {
                if (!$result instanceof ConnectionInterface) {
                    return $result;
                }

                return $result->sendVerifyRecipient($email->m_mail);
            })
            ->then(function ($message) use ($statusCallback, $email) {
                if (!$message instanceof Message) {
                    return $message;
                }
                if (Message::RCODE_OK === $message->rcode) {
                    $this->logger->info("$email->m_mail verified.");

                    return $statusCallback($email, VerifyStatus::SMTP_VERIFIED());
                } else {
                    $this->logger->info("$email->m_mail NOT verified.");

                    return $statusCallback($email, VerifyStatus::SMTP_USER_NOT_FOUND());
                }
            })
            ->then(null, function (Throwable $e) use ($statusCallback, $email) {
                return $this->handleThrowable($e, $email, $statusCallback);
            });
    }

    /**
     * @param Throwable $e
     * @param Email     $email
     * @param callable  $statusCallback
     *
     * @return PromiseInterface
     *
     * @throws Throwable
     */
    private function handleThrowable(Throwable $e, Email $email, callable $statusCallback): PromiseInterface
    {
        foreach ($this->handledExceptions as $class => $data) {
            if (!$e instanceof $class) {
                continue;
            }
            if ($data['error']) {
                $this->logger->error("{$data['text']} $email->m_mail. {$e->getMessage()}");
            } else {
                $this->logger->info("{$data['text']} $email->m_mail.");
            }
            /*
             * Clue\React\Socks\Client Exception manipulation bug workaround.
             */
            if ($e->getPrevious()
                && false !== strpos($e->getPrevious()->getMessage(), 'connection to proxy failed')) {
                throw $e;
            }
            $this->logger->debug("$e");

            return $statusCallback($email, $data['status']);
        }

        throw $e;
    }
}
