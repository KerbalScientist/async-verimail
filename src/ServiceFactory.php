<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\DB\CsvBlockingExporter;
use App\DB\CsvBlockingImporter;
use App\DB\EmailEntityManager;
use App\DB\EmailHydrationStrategy;
use App\DB\EntityManagerInterface;
use App\DB\MysqlQueryFactory;
use App\Entity\Email;
use App\Throttling\Factory as ThrottlingFactory;
use App\Verifier\Factory as VerifierFactory;
use Psr\Log\LoggerInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory as MysqlFactory;
use ReactPHP\MySQL\Decorator\BindAssocParamsConnectionDecorator;
use ReflectionClass;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceFactory
{
    private const DEFAULT_HOSTS_CONFIG_PATH = '/config/hosts.yaml';

    private string $hostsConfigFile;
    private int $maxConcurrentVerifications = 2000;
    private float $mxConnectTimeout = 30;
    private string $dbEmailTableName = 'email';
    private string $dbUser = 'root';
    private string $dbPassword = '';
    private string $dbHost = 'localhost';
    private string $dbPort = '3306';
    private string $dbSchema = 'email';

    public function __construct()
    {
        $this->hostsConfigFile = \dirname(__DIR__).self::DEFAULT_HOSTS_CONFIG_PATH;
    }

    public function setHostsConfigFile(string $hostsConfigFile): void
    {
        $this->hostsConfigFile = $hostsConfigFile;
    }

    public function setMaxConcurrentVerifications(int $maxConcurrentVerifications): void
    {
        $this->maxConcurrentVerifications = $maxConcurrentVerifications;
    }

    public function setMxConnectTimeout(float $mxConnectTimeout): void
    {
        $this->mxConnectTimeout = $mxConnectTimeout;
    }

    public function setDbUser(string $dbUser): void
    {
        $this->dbUser = $dbUser;
    }

    public function setDbPassword(string $dbPassword): void
    {
        $this->dbPassword = $dbPassword;
    }

    public function setDbHost(string $dbHost): void
    {
        $this->dbHost = $dbHost;
    }

    public function setDbPort(string $dbPort): void
    {
        $this->dbPort = $dbPort;
    }

    public function setDbSchema(string $dbSchema): void
    {
        $this->dbSchema = $dbSchema;
    }

    public function setDbEmailTableName(string $dbEmailTableName): void
    {
        $this->dbEmailTableName = $dbEmailTableName;
    }

    public function createOutput(ServiceContainer $container): OutputInterface
    {
        return new ConsoleOutput();
    }

    public function createInput(ServiceContainer $container): InputInterface
    {
        return new ArgvInput();
    }

    public function createLogger(ServiceContainer $container): LoggerInterface
    {
        $output = $container->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            return new ConsoleLogger($output->section());
        } else {
            return new ConsoleLogger($output);
        }
    }

    public function createDbConnection(ServiceContainer $container): ConnectionInterface
    {
        $url = rawurlencode($this->dbUser);
        $url .= ':'.rawurlencode($this->dbPassword);
        $url .= '@'.$this->dbHost;
        $url .= ':'.$this->dbPort;
        $url .= "/$this->dbSchema";
        $url .= '?idle=-1&timeout=-1';

        return new BindAssocParamsConnectionDecorator(
            (new MysqlFactory($container->getEventLoop()))->createLazyConnection($url)
        );
    }

    public function createEntityManager(ServiceContainer $container): EntityManagerInterface
    {
        $entityManager = new EmailEntityManager(
            $this->dbEmailTableName,
            $container->getReadDbConnection(),
            $container->getWriteDbConnection(),
            new MysqlQueryFactory()
        );
        $entityManager->setLogger($container->getLogger());

        return $entityManager;
    }

    public function createVerifierFactory(ServiceContainer $container): VerifierFactory
    {
        $factory = new VerifierFactory();
        $factory->setMaxConcurrent($this->maxConcurrentVerifications);
        $factory->setConnectTimeout($this->mxConnectTimeout);
        $factory->setLogger($container->getLogger());
        $factory->setHostsConfigFile($this->hostsConfigFile);
        $factory->setEventLoop($container->getEventLoop());

        return $factory;
    }

    public function createThrottlingFactory(ServiceContainer $container): ThrottlingFactory
    {
        return new ThrottlingFactory($container->getEventLoop());
    }

    public function createImporter(ServiceContainer $container): CsvBlockingImporter
    {
        $importer = new CsvBlockingImporter($this->createEmailHydrationStrategy($container), $container->getEntityManager());
        $importer->setLogger($container->getLogger());

        return $importer;
    }

    public function createExporter(ServiceContainer $container): CsvBlockingExporter
    {
        $hydrationStrategy = $this->createEmailHydrationStrategy($container);
        $exporter = new CsvBlockingExporter($hydrationStrategy);
        $exporter->setLogger($container->getLogger());
        $exporter->setHeaderRow($hydrationStrategy->getRowFields());

        return $exporter;
    }

    public function createEmailHydrationStrategy(ServiceContainer $container): EmailHydrationStrategy
    {
        return new EmailHydrationStrategy(new ReflectionClass(Email::class));
    }
}
