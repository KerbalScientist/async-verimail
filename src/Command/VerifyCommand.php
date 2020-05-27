<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\MovingAverage;
use React\Promise\Deferred;
use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function App\pipeThrough;
use function React\Promise\all;

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
            ->addOption('proxy', 'x', InputArgument::OPTIONAL,
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
        $query = $this->container->getSelectQuery();
        $countPromise = $entityManager->countByQuery($query);
        $queryStream = $entityManager->streamByQuery($query);

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

        if ($output instanceof ConsoleOutputInterface) {
            $countPromise = $countPromise
                ->then(function ($count) use ($output, $queryStream, $verifyingStream) {
                    $this->showStats($count, $output, $queryStream, $verifyingStream);
                });
        }
        $this->setExecutePromise(all([
            $countPromise,
            $deferred->promise(),
        ]));

        return 0;
    }

    /**
     * @param int                     $totalCount
     * @param ConsoleOutputInterface  $output
     * @param ReadableStreamInterface $queryStream
     * @param DuplexStreamInterface   $verifyingStream
     */
    private function showStats(
        int $totalCount,
        ConsoleOutputInterface $output,
        ReadableStreamInterface $queryStream,
        DuplexStreamInterface $verifyingStream
    ): void {
        $count = 0;
        $timeStart = microtime(true);
        $timeLast = microtime(true);
        /**
         * @todo Hardcoded windowWidth.
         */
        $movingAvg = new MovingAverage(15);
        $queryStream->once('data', function () use (&$timeStart, &$timeLast) {
            $timeLast = $timeStart = microtime(true);
        });
        $progress = new ProgressBar($output->section(), $totalCount);
        $progress->setBarCharacter('▓');
        $progress->setProgressCharacter('');
        $progress->setEmptyBarCharacter('░');
        $section = $output->section();
        $verifyingStream->on('resolve',
            function () use (&$count, $timeStart, &$timeLast, $movingAvg, $section, $progress) {
                ++$count;
                if ($count === $progress->getMaxSteps()) {
                    $progress->finish();
                } else {
                    $progress->advance();
                }
                if (!$section->isVerbose()) {
                    return;
                }
                $time = microtime(true);
                $avgSpeed = $count / ($time - $timeStart);
                $section->clear();
                $avgSpeed = number_format($avgSpeed, 2);
                $section->writeln("Average speed: $avgSpeed emails per second.");
                if (!$section->isVeryVerbose()) {
                    return;
                }
                $movingAvg->insertValue($time, $time - $timeLast);
                $timeLast = $time;
                if (0 !== $movingAvg->get()) {
                    $movingAvgSpeed = 1 / $movingAvg->get();
                    $movingAvgSpeed = number_format($movingAvgSpeed, 2);
                    $section->writeln("Current speed: $movingAvgSpeed emails per second.",
                        OutputInterface::VERBOSITY_VERY_VERBOSE);
                }
            });
    }
}
