<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\DB\EmailEntityManager;
use App\Entity\Email;
use App\MovingAverage;
use App\Verifier\Factory as VerifierFactory;
use App\Verifier\Verifier;
use App\Verifier\VerifyStatus;
use React\EventLoop\LoopInterface;
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
    use EmailQueryCommandTrait {
        configure as configureQuery;
        initialize as initializeQuery;
    }
    private const MOVING_AVG_SPEED_WINDOW_WIDTH = 15;
    private const SPEED_SHOW_DECIMALS = 2;

    protected static $defaultName = 'verify';

    private MovingAverage $movingAvg;
    private float $timeStart;
    private int $verifiedCount;
    private VerifierFactory $verifierFactory;

    public function __construct(LoopInterface $eventLoop, EmailEntityManager $entityManager, VerifierFactory $verifierFactory)
    {
        parent::__construct($eventLoop, $entityManager);
        $this->verifierFactory = $verifierFactory;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->configureQuery();
        $this
            ->setDescription('Verify emails from DB')
            ->addUsage('--filter=\'{"email":["NOT LIKE","%@mail.ru"],'.
                '"status":  "unknown","updated":["<", "2020-05-20 00:00"], "#limit": 100}\' --proxy="127.0.0.1:10000"')
            ->addOption('proxy', 'x', InputArgument::OPTIONAL,
                'SOCKS5 proxy IP:PORT')
            ->addOption('hosts-config', 'hc', InputOption::VALUE_OPTIONAL,
                'Path to hosts.yaml');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->initializeQuery($input, $output);

        if (null !== $input->getOption('hosts-config')) {
            $this->verifierFactory->setHostsConfigFile($input->getOption('hosts-config'));
        }

        if (null !== $input->getOption('proxy')) {
            $this->verifierFactory->addSocksProxy($input->getOption('proxy'));
        }
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
        $entityManager = $this->entityManager;
        $verifierFactory = $this->verifierFactory;
        $verifierFactory->setVerifyingCallback(function (Verifier $verifier, Email $email) {
            return $verifier->verify($email->email)
                ->then(function (VerifyStatus $status) use ($email) {
                    if (!$status->isUnknown()) {
                        $email->status = $status;
                    }

                    return $email;
                });
        });

        $pipeOptions = [
            'error' => true,
            'closeToEnd' => true,
            'end' => false,
        ];
        $query = $this->emailSelectQuery;
        $countPromise = $entityManager->countByQuery($query);
        $queryStream = $entityManager->streamByQuery($query);

        pipeThrough(
            $queryStream,
            [$verifyingStream = $verifierFactory->createVerifyingStream($pipeOptions)],
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

        return ExitCode::SUCCESS;
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
