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
use App\Command\VerifyCommand;
use Dotenv\Dotenv;

class Application extends \Symfony\Component\Console\Application
{
    private ServiceContainer $container;

    public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);
        Dotenv::createImmutable(dirname(__DIR__))->load();
        $this->container = new ServiceContainer();

        /*
         * @todo Application constructor is wrong place for this. Move it elsewhere.
         *       Maybe we need some dedicated place for bootstrap code.
         */
        if (extension_loaded('xdebug')) {
            ini_set('xdebug.max_nesting_level', '100000');
        }
    }

    /**
     * @param ServiceContainer $container
     */
    public function setContainer(ServiceContainer $container): void
    {
        $this->container = $container;
    }

    protected function getDefaultCommands()
    {
        return array_merge(parent::getDefaultCommands(), [
            new InstallCommand($this->container),
            new VerifyCommand($this->container),
            new ShowCommand($this->container),
            new ImportCommand($this->container),
            new ExportCommand($this->container),
            new GenerateFixturesCommand($this->container),
        ]);
    }
}
