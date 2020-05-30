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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function App\pipeThrough;
use function React\Promise\all;

class VerifyCommand extends BaseCommand
{
    private const MOVING_AVG_SPEED_WINDOW_WIDTH = 15;
    private const SPEED_SHOW_DECIMALS = 2;

    protected static $defaultName = 'verify';

    private MovingAverage $movingAvg;
    private float $timeStart;
    private int $verifiedCount;

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Verify emails from DB')
            ->addUsage('--filter=\'{"m_mail":["NOT LIKE","%@mail.ru"],'.
                '"s_status":  "unknown","dt_updated":["<", "2020-05-20 00:00"], "#limit": 100}\' --proxy="127.0.0.1:10000"')
            ->addOption('proxy', 'x', InputArgument::OPTIONAL,
                'SOCKS5 proxy IP:PORT')
            ->addOption('hosts-config', 'hc', InputOption::VALUE_OPTIONAL,
                'Path to hosts.yaml');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws \Exception
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

    private function showStats(
        int $totalCount,
        ConsoleOutputInterface $output,
        ReadableStreamInterface $queryStream,
        DuplexStreamInterface $verifyingStream
    ): void {
        $this->verifiedCount = 0;
        $this->timeStart = microtime(true);
        $queryStream->once('data', function () use (&$timeStart, &$timeLast) {
            $timeLast = $timeStart = microtime(true);
        });
        $progress = new ProgressBar($output->section(), $totalCount);
        $progress->setBarCharacter('▓');
        $progress->setProgressCharacter('');
        $progress->setEmptyBarCharacter('░');
        $section = $output->section();
        $verifyingStream->on('resolve',
            function () use ($section, $progress) {
                ++$this->verifiedCount;
                if ($this->verifiedCount === $progress->getMaxSteps()) {
                    $progress->finish();
                } else {
                    $progress->advance();
                }
                if ($section->isVerbose()) {
                    $time = microtime(true);
                    $section->clear();
                    $this->printAvgSpeed($section, $time);
                }
                if ($section->isVeryVerbose()) {
                    $this->printCurrentSpeed($section, $time ?? microtime(true));
                }
            });
    }

    private function printAvgSpeed(OutputInterface $output, float $time): void
    {
        $avgSpeed = $this->verifiedCount / ($time - $this->timeStart);
        $avgSpeed = number_format($avgSpeed, self::SPEED_SHOW_DECIMALS);
        $output->writeln("Average speed: $avgSpeed emails per second.",
            OutputInterface::VERBOSITY_VERBOSE);
    }

    private function printCurrentSpeed(OutputInterface $output, float $time): void
    {
        if (!isset($this->movingAvg)) {
            $this->movingAvg = new MovingAverage(self::MOVING_AVG_SPEED_WINDOW_WIDTH);
        }
        $this->movingAvg->insertValue($time, $time - ($this->movingAvg->getWindowEnd() ?? $this->timeStart));
        if (0 == $this->movingAvg->get()) {
            return;
        }
        $movingAvgSpeed = 1 / $this->movingAvg->get();
        $movingAvgSpeed = number_format($movingAvgSpeed, self::SPEED_SHOW_DECIMALS);
        $output->writeln("Current speed: $movingAvgSpeed emails per second.",
            OutputInterface::VERBOSITY_VERY_VERBOSE);
    }
}
