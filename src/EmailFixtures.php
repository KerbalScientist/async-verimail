<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\DB\EmailEntityManager;
use App\DB\EmailPersistingStream;
use App\Entity\Email;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class EmailFixtures
{
    private array $domains = [
        'mail.ru' => 10,
        'bk.ru' => 5,
        'list.ru' => 5,
        'rambler.ru' => 2,
        'gmail.com' => 10,
        'mai.ru' => 1,
        'gmali.com' => 1,
        'gmail.ru' => 2,
        'yahoo.com' => 20,
        'yandex.ru' => 15,
        'outlook.com' => 15,
        'aol.com' => 5,
        'icloud.com' => 5,
        'mail.com' => 4,
    ];

    private int $minUserLength = 4;
    private int $maxUserLength = 10;
    private string $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private EmailPersistingStream $persistingStream;
    private LoopInterface $loop;

    /**
     * EmailFixtures constructor.
     *
     * @param EmailEntityManager $entityManager
     * @param LoopInterface      $loop
     */
    public function __construct(EmailEntityManager $entityManager, LoopInterface $loop)
    {
        $this->persistingStream = $entityManager->createPersistingStream();
        $this->persistingStream->setInsertBufferSize(5000);
        $this->persistingStream->setUpdateBufferSize(5000);
        $this->loop = $loop;
    }

    public function generate($count): PromiseInterface
    {
        $deferred = new Deferred();
        $paused = false;
        $this->persistingStream->on('error', function ($e) use ($deferred, &$count) {
            $count = 0;
            $deferred->reject($e);
        });
        $this->persistingStream->on('close', function () use ($deferred, &$count) {
            $count = 0;
            $deferred->resolve();
        });
        $drainCallback = function () use (&$count, $deferred, &$paused) {
            $paused = false;
            if (!$count) {
                $deferred->resolve();
            }
        };
        $tickCallback = function () use (&$count, $deferred, &$paused, &$tickCallback, $drainCallback) {
            if ($count > 0) {
                $this->loop->futureTick($tickCallback);
            } else {
                $this->persistingStream->flush()
                    ->then(
                        $drainCallback,
                        function ($error) use ($deferred) {
                            $deferred->reject($error);
                        }
                    );
            }
            if ($paused || !$count) {
                return;
            }
            $email = new Email();
            $email->m_mail = $this->generateEmail();
            --$count;
            if (!$this->persistingStream->write($email)) {
                $paused = true;
                $this->persistingStream->once('drain', $drainCallback);
            }
        };
        $tickCallback();

        return $deferred->promise();
    }

    private function generateEmail()
    {
        $length = rand($this->maxUserLength, $this->minUserLength);
        $username = '';
        for ($i = 0; $i < $length; ++$i) {
            $username .= $this->characters[rand(0, strlen($this->characters) - 1)];
        }
        $domainRandom = rand(0, 100);
        $densitySum = 0;
        $domain = '';
        foreach ($this->domains as $domain => $density) {
            $densitySum += $density;
            if ($densitySum >= $domainRandom) {
                break;
            }
        }

        return "$username@$domain";
    }
}
