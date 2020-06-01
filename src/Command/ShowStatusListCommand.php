<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\Verifier\VerifyStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowStatusListCommand extends Command
{
    protected static $defaultName = 'status-list';

    protected function configure(): void
    {
        $this->setDescription('Shows list of verify statuses');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('<comment>Verification statuses:</comment>');
        foreach (VerifyStatus::all() as $status) {
            $output->writeln("  <info>$status</info> {$status->getDescription()}");
        }

        return ExitCode::SUCCESS;
    }
}
