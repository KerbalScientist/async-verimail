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

class ImportCommand extends BaseCommand
{
    protected static $defaultName = 'import';

    protected function configure(): void
    {
        $this
            ->setDescription('Import emails from CSV file')
            ->addArgument('filename', InputArgument::REQUIRED,
            "CSV file path to import emails from. First row contains header.\n".
            ' Field names are same as DB column names. Field email is required, others are optional.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filename = $input->getArgument('filename');
        $this->setExecutePromise(
            $this->container
                ->getEntityManager()
                ->importFromCsvBlocking($filename)
                ->then(function () use ($output) {
                    $output->writeln('Import complete.');
                })
        );

        return ExitCode::SUCCESS;
    }
}
