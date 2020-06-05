<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\Command\ExportCommand;
use App\Command\GenerateFixturesCommand;
use App\Command\ImportCommand;
use App\Command\InstallCommand;
use App\Command\ShowCommand;
use App\Command\ShowStatusListCommand;
use App\Command\UninstallCommand;
use App\Command\VerifyCommand;
use Dotenv\Dotenv;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends \Symfony\Component\Console\Application
{
    private ServiceContainer $container;

    public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);
        Dotenv::createImmutable(\dirname(__DIR__))->load();

        if (\extension_loaded('xdebug')) {
            ini_set('xdebug.max_nesting_level', '100000');
        }
    }

    public function setContainer(ServiceContainer $container): void
    {
        $this->container = $container;
    }

    private function getContainer(): ServiceContainer
    {
        if (!isset($this->container)) {
            $factory = new ServiceFactory();
            $envConfig = new EnvConfig();
            $envConfig->loadArray($_SERVER);
            $envConfig->configureFactory($factory);
            $this->container = new ServiceContainer($factory);
        }

        return $this->container;
    }

    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        $container = $this->getContainer();
        if (null === $input) {
            $input = $container->getInput();
        } else {
            $container->setInput($input);
        }
        if (null === $output) {
            $output = $container->getOutput();
        } else {
            $container->setOutput($output);
        }

        return parent::run($input, $output);
    }

    protected function getDefaultCommands()
    {
        $container = $this->getContainer();
        $loop = $container->getEventLoop();
        $entityManager = $container->getEntityManager();

        return array_merge(parent::getDefaultCommands(), [
            new InstallCommand($loop, $entityManager),
            new UninstallCommand($loop, $entityManager),
            new VerifyCommand($loop, $entityManager, $container->getVerifierFactory()),
            new ShowCommand($loop, $entityManager, $container->getThrottlingFactory()),
            new ShowStatusListCommand(),
            new ImportCommand($loop, $entityManager),
            new ExportCommand($loop, $entityManager),
            new GenerateFixturesCommand($loop, $entityManager, $container->getEmailFixtures()),
        ]);
    }
}
