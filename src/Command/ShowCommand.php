<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\DB\EntityManagerInterface;
use App\Entity\Email;
use App\Stream\ReadableStreamWrapperTrait;
use App\Throttling\Factory as ThrottlingFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowCommand extends BaseCommand
{
    use EmailQueryCommandTrait {
        configure as configureQuery;
    }

    private ThrottlingFactory $throttlingFactory;

    protected static $defaultName = 'show';

    public function __construct(
        LoopInterface $eventLoop,
        EntityManagerInterface $entityManager,
        ThrottlingFactory $throttlingFactory
    ) {
        parent::__construct($eventLoop, $entityManager);
        $this->throttlingFactory = $throttlingFactory;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->configureQuery();
        $this
            ->setDescription('Show emails from DB')
            ->addUsage('--filter=\'{"email":["NOT LIKE","%@mail.ru"],'.
                '"status":  "unknown","updated":["<", "2020-05-20 00:00"], "#limit": 100}\' 1')
            ->addArgument('min-interval', InputArgument::OPTIONAL,
            'Each next row will be printed after min-interval seconds passed', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $minInterval = (float) $input->getArgument('min-interval');
        $entityManager = $this->entityManager;
        $stream = $entityManager->streamByQuery($this->emailSelectQuery);
        $stream = $this->throttlingFactory->readableStream($stream, $minInterval);
        $stream = new class($stream) implements ReadableStreamInterface {
            use ReadableStreamWrapperTrait;

            /**
             * {@inheritdoc}
             */
            protected function filterData(Email $email): ?array
            {
                return [
                    "$email->id $email->email $email->status ({$email->status->getDescription()})".
                    " {$email->updated->format(DATE_ATOM)}",
                ];
            }
        };
        $deferred = new Deferred();
        $total = 0;
        $stream->on('data', function ($data) use (&$total, $output) {
            ++$total;
            $output->writeln($data);
        });
        $stream->on('error', function ($e) use ($deferred) {
            $deferred->reject($e);
        });
        $stream->on('end', function () use ($deferred, &$total, $output) {
            $output->writeln('');
            $output->writeln("Total: $total");
            $deferred->resolve();
        });

        $this->setExecutePromise($deferred->promise());

        return ExitCode::SUCCESS;
    }
}
