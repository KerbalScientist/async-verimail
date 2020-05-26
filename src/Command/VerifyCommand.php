<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\MovingAverage;
use React\Promise\Deferred;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function App\pipeThrough;

class VerifyCommand extends BaseCommand
{
    protected static $defaultName = 'verify';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Verify emails from DB.')
            ->addUsage('--filter=\'{"m_mail":["NOT LIKE","%@mail.ru"],'.
                '"s_status":  "unknown","dt_updated":["<", "2020-05-20 00:00"], "#limit": 100}\' --proxy="127.0.0.1:10000"')
            ->addOption('proxy', 'x' ,InputArgument::OPTIONAL,
                'SOCKS5 proxy IP:PORT.');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|void
     *
     * @throws \Exception
     *
     * @todo Too long. Refactor.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $loop = $this->container->getEventLoop();
        $entityManager = $this->container->getEntityManager();
        $verifier = $this->container->getVerifier();

        $pipeOptions = [
            'error' => true,
            'closeToEnd' => true,
            'end' => false,
        ];
        $queryStream = $entityManager->streamByQuery($this->container->getSelectQuery());

        pipeThrough(
            $queryStream,
            [$verifyingStream = $verifier->createVerifyingStream($loop, $pipeOptions)],
            $persistingStream = $entityManager->createPersistingStream(),
            $pipeOptions
        );

        $deferred = new Deferred();
        $persistingStream->on('error', function ($error) use ($deferred) {
            $deferred->reject($error);
        });
        $persistingStream->on('close', function () use ($deferred) {
            $deferred->resolve();
        });
        $this->on('beforeStop', function () use ($persistingStream, $queryStream, $verifyingStream) {
            $this->addResolveBeforeStop($persistingStream->flush());
            $persistingStream->close();
            $verifyingStream->close();
            $queryStream->close();
        });

        $count = 0;
        $timeStart = null;
        $timeLast = null;
        /**
         * @todo Hardcoded windowWidth.
         */
        $movingAvg = new MovingAverage(15);
        $queryStream->once('data', function () use (&$timeStart, &$timeLast) {
            $timeLast = $timeStart = microtime(true);
        });
        $verifyingStream->on('data',
            function () use (&$count, &$timeStart, &$timeLast, $movingAvg) {
                ++$count;
                $time = microtime(true);
                if (is_null($timeStart)) {
                    /**
                     * @todo Hardcoded  - 0.1.
                     */
                    $timeLast = $timeStart = $time - 0.1;
                }
                $avgSpeed = $count / ($time - $timeStart);
                $movingAvg->insertValue($time, $time - $timeLast);
                $timeLast = $time;

                $this->container->getLogger()
                    ->debug("$count emails verified.");
                $this->container->getLogger()
                    ->debug("Average speed: $avgSpeed emails per second.");
                if (0 !== $movingAvg->get()) {
                    $movingAvgSpeed = 1 / $movingAvg->get();
                    $this->container->getLogger()
                        ->debug("Current speed: $movingAvgSpeed emails per second.");
                }
            });
        $this->setExecutePromise($deferred->promise());

        return 0;
    }
}
