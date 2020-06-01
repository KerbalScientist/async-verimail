<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\Command\BaseCommand;
use App\Command\ExportCommand;
use App\Command\GenerateFixturesCommand;
use App\Command\ImportCommand;
use App\Command\InstallCommand;
use App\Command\ShowCommand;
use App\Command\ShowStatusListCommand;
use App\Command\UninstallCommand;
use App\Command\VerifyCommand;
use Dotenv\Dotenv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends \Symfony\Component\Console\Application
{
    private ServiceContainer $container;

    public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);
        Dotenv::createImmutable(dirname(__DIR__))->load();
        $this->container = new ServiceContainer();

        if (extension_loaded('xdebug')) {
            ini_set('xdebug.max_nesting_level', '100000');
        }
    }

    public function setContainer(ServiceContainer $container): void
    {
        $this->container = $container;
    }

    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $input) {
            $input = $this->container->getInput();
        } else {
            $this->container->setInput($input);
        }
        if (null === $output) {
            $output = $this->container->getOutput();
        } else {
            $this->container->setOutput($output);
        }

        return parent::run($input, $output);
    }

    protected function getDefaultCommands()
    {
        $loop = $this->container->getEventLoop();
        $entityManager = $this->container->getEntityManager();

        return array_merge(parent::getDefaultCommands(), [
            new InstallCommand($loop, $entityManager),
            new UninstallCommand($loop, $entityManager),
            new VerifyCommand($loop, $entityManager, $this->container->getVerifierFactory()),
            new ShowCommand($loop, $entityManager, $this->container->getThrottlingFactory()),
            new ShowStatusListCommand(),
            new ImportCommand($loop, $entityManager),
            new ExportCommand($loop, $entityManager),
            new GenerateFixturesCommand($loop, $entityManager, $this->container->getEmailFixtures()),
        ]);
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        if ($command instanceof BaseCommand) {
            $this->container->setCommand($command);
        }

        return parent::doRunCommand($command, $input, $output);
    }
}
