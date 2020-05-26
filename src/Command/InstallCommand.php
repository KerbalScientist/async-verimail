<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends BaseCommand
{
    protected static $defaultName = 'install';

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setExecutePromise(
            $this->container
                ->getEntityManager()
                ->installSchema()
                ->then(function () use ($output) {
                    $output->writeln('DB schema installed successfully.');
                })
        );

        return 0;
    }
}
