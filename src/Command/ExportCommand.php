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

class ExportCommand extends BaseCommand
{
    protected static $defaultName = 'export';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Export emails to CSV file')
            ->addArgument('filename', InputArgument::REQUIRED,
            "CSV file path to export emails to. First row contains header.\n".
            ' Field names are same as DB column names.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filename = $input->getArgument('filename');
        if (null === $input->getOption('filter')) {
            $query = $this->container
                ->getEntityManager()
                ->createSelectQuery();
        } else {
            $query = $this->container->getSelectQuery();
        }

        $this->setExecutePromise(
            $this->container
                ->getEntityManager()
                ->exportToCsvBlocking($filename, $query)
                ->then(function () use ($output) {
                    $output->writeln('Export complete.');
                })
        );

        return 0;
    }
}
