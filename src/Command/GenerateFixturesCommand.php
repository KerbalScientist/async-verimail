<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateFixturesCommand extends BaseCommand
{
    protected static $defaultName = 'generate-fixtures';

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('count', InputArgument::REQUIRED,
            'Emails count to generate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setExecutePromise(
            $this->container
                ->getEmailFixtures()
                ->generate((int) $input->getArgument('count'))
        );

        return 0;
    }
}
