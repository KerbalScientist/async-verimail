<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\Command\BaseCommand;
use App\DB\EmailEntityManager;
use App\DB\MysqlQueryFactory;
use App\Verifier\Factory as VerifierFactory;
use App\Verifier\Verifier;
use App\Verifier\VerifyStatus;
use Aura\SqlQuery\Common\SelectInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory as MysqlFactory;
use ReactPHP\MySQL\Decorator\BindAssocParamsConnectionDecorator;

class ServiceContainer
{
    private LoggerInterface $logger;
    private LoopInterface $eventLoop;
    private EmailEntityManager $entityManager;
    private string $hostsConfigFile;
    private ConnectionInterface $readDbConnection;
    private ConnectionInterface $writeConnection;
    private VerifierFactory $verifierFactory;
    private BaseCommand $command;
    /**
     * @var mixed[]
     */
    private array $filter = [
        'status' => VerifyStatus::UNKNOWN,
    ];

    public function __construct()
    {
        $this->hostsConfigFile = dirname(__DIR__).'/config/hosts.yaml';
    }

    public function getReadDbConnection(): ConnectionInterface
    {
        if (!isset($this->readDbConnection)) {
            $this->readDbConnection = $this->createDbConnection();
        }

        return $this->readDbConnection;
    }

    public function getWriteDbConnection(): ConnectionInterface
    {
        if (!isset($this->writeConnection)) {
            $this->writeConnection = $this->createDbConnection();
        }

        return $this->writeConnection;
    }

    public function createDbConnection(): ConnectionInterface
    {
        $url = '';
        $url .= rawurlencode($this->getEnvConfigValue('DB_USER', 'root'));
        $url .= ':'.rawurlencode($this->getEnvConfigValue('DB_PASSWORD', ''));
        $url .= '@'.$this->getEnvConfigValue('DB_HOST', 'localhost');
        $url .= ':'.$this->getEnvConfigValue('DB_PORT', '3306');
        $schemaName = $this->getEnvConfigValue('DB_SCHEMA');
        if (!is_null($schemaName)) {
            $url .= "/$schemaName";
        }
        $url .= '?idle=-1&timeout=-1';

        return new BindAssocParamsConnectionDecorator(
            (new MysqlFactory($this->getEventLoop()))->createLazyConnection($url)
        );
    }

    public function getEventLoop(): LoopInterface
    {
        if (!isset($this->eventLoop)) {
            $this->eventLoop = LoopFactory::create();
        }

        return $this->eventLoop;
    }

    public function getEmailFixtures(): EmailFixtures
    {
        return new EmailFixtures($this->getEntityManager(), $this->getEventLoop());
    }

    public function getLogger(): LoggerInterface
    {
        if (!isset($this->logger)) {
            $this->logger = new NullLogger();
        }

        return $this->logger;
    }

    public function getEntityManager(): EmailEntityManager
    {
        if (!isset($this->entityManager)) {
            $this->entityManager = new EmailEntityManager(
                $this->getEnvConfigValue('DB_EMAIL_TABLE_NAME', 'email'),
                $this->getReadDbConnection(),
                $this->getWriteDbConnection(),
                new MysqlQueryFactory()
            );
            $this->entityManager->setLogger($this->getLogger());
        }

        return $this->entityManager;
    }

    /**
     * @return Verifier
     *
     * @throws Exception
     */
    public function getVerifier(): Verifier
    {
        return $this->getVerifierFactory()->createVerifier();
    }

    public function getVerifierFactory(): VerifierFactory
    {
        if (!isset($this->verifierFactory)) {
            $factory = new VerifierFactory();
            $factory->setMaxConcurrent((int) $this->getEnvConfigValue('MAX_CONCURRENT', 1000));
            $factory->setConnectTimeout((int) $this->getEnvConfigValue('CONNECT_TIMEOUT', 30));
            $factory->setLogger($this->getLogger());
            $factory->setHostsConfigFile($this->hostsConfigFile);
            $factory->setEventLoop($this->getEventLoop());
            $this->verifierFactory = $factory;
        }

        return $this->verifierFactory;
    }

    public function getSelectQuery(): SelectInterface
    {
        return $this->getEntityManager()->createSelectQuery($this->filter);
    }

    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getEnvConfigValue(string $name, $default = null)
    {
        return $_SERVER[$name] ?? $default;
    }

    public function setCommand(BaseCommand $command): void
    {
        $this->command = $command;
    }

    public function setHostsConfigFile(string $hostsConfigFile): void
    {
        $this->getVerifierFactory()->setHostsConfigFile($hostsConfigFile);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setEventLoop(LoopInterface $eventLoop): void
    {
        $this->eventLoop = $eventLoop;
    }

    public function setProxy(?string $proxy): void
    {
        $this->getVerifierFactory()->addSocksProxy($proxy);
    }

    public function setReadDbConnection(ConnectionInterface $readDbConnection): void
    {
        $this->readDbConnection = $readDbConnection;
    }

    public function setWriteConnection(ConnectionInterface $writeConnection): void
    {
        $this->writeConnection = $writeConnection;
    }

    /**
     * @param mixed[] $filter
     */
    public function setFilter(array $filter): void
    {
        $this->filter = $filter;
    }
}
