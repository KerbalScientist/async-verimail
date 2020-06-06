<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\DB\CsvBlockingExporter;
use App\DB\EmailEntityManager;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends BaseCommand
{
    use EmailQueryCommandTrait {
        configure as configureQuery;
    }

    private CsvBlockingExporter $exporter;

    protected static $defaultName = 'export';

    public function __construct(LoopInterface $eventLoop, EmailEntityManager $entityManager, CsvBlockingExporter $exporter)
    {
        parent::__construct($eventLoop, $entityManager);
        $this->exporter = $exporter;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->configureQuery();
        $this
            ->setDescription('Export emails to CSV file')
            ->addArgument('filename', InputArgument::REQUIRED,
            "CSV file path to export emails to. First row contains header.\n".
            ' Field names are same as DB column names.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filename = $input->getArgument('filename');
        $this->setExecutePromise(
            $this->exporter
                ->export($filename, $this->entityManager->streamByQuery($this->emailSelectQuery))
                ->then(function () use ($output) {
                    $output->writeln('Export complete.');
                })
        );

        return ExitCode::SUCCESS;
    }
}
