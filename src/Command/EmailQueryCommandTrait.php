<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\Verifier\VerifyStatus;
use Aura\SqlQuery\Common\SelectInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 *
 * Must be part of @see BaseCommand instance
 */
trait EmailQueryCommandTrait
{
    private SelectInterface $emailSelectQuery;

    protected function configure(): void
    {
        $this->addOption('filter', 'f', InputArgument::OPTIONAL,
            'JSON email filter');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->emailSelectQuery = $this->entityManager->createSelectQuery(
            json_decode($input->getOption('filter'), true)
            ?? [
                'status' => VerifyStatus::UNKNOWN,
            ]
        );
    }
}
