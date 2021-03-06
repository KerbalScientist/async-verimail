<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\DB\EntityManagerInterface;
use App\EmailFixtures;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateFixturesCommand extends BaseCommand
{
    protected static $defaultName = 'generate-fixtures';

    private EmailFixtures $emailFixtures;

    public function __construct(LoopInterface $eventLoop, EntityManagerInterface $entityManager, EmailFixtures $emailFixtures)
    {
        parent::__construct($eventLoop, $entityManager);
        $this->emailFixtures = $emailFixtures;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate random emails for testing')
            ->addArgument('count', InputArgument::REQUIRED,
            'Emails count to generate');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $count = (int) $input->getArgument('count');
        $this->setExecutePromise(
            $this->emailFixtures
                ->generate($count)
                ->then(function () use ($output, $count) {
                    $output->writeln("Generated $count fixtures.");
                })
        );

        return ExitCode::SUCCESS;
    }
}
