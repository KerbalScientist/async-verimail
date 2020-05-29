<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\Command\BaseCommand;
use App\Config\HostsConfig;
use App\DB\EmailEntityManager;
use App\DB\MysqlQueryFactory;
use App\Entity\VerifyStatus;
use App\MutexRun\Factory as MutexFactory;
use App\Smtp\Connector as SmtpConnector;
use App\Stream\ThroughStream;
use App\Verifier\ConnectionPool;
use App\Verifier\Connector as VerifierConnector;
use App\Verifier\Verifier;
use Aura\SqlQuery\Common\SelectInterface;
use Clue\React\Socks\Client as SocksClient;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory as MysqlFactory;
use React\Socket\Connector as SocketConnector;
use React\Socket\ConnectorInterface;
use React\Stream\WritableResourceStream;
use ReactPHP\MySQL\Decorator\BindAssocParamsConnectionDecorator;
use Symfony\Component\Yaml\Yaml;
use WyriHaximus\React\PSR3\Stdio\StdioLogger;

class ServiceContainer
{
    const DEFAULT_NAMESERVER = '8.8.8.8';

    private bool $verbose = false;
    private bool $quiet = false;
    /**
     * @var bool
     */
    private bool $debug = false;
    private LoggerInterface $logger;
    private LoopInterface $eventLoop;
    private EmailEntityManager $entityManager;
    private ?string $proxy = null;
    private string $hostsConfigFile;
    private HostsConfig $hostsConfig;
    private ConnectionInterface $readDbConnection;
    private ConnectionInterface $writeConnection;
    private ResolverInterface $dnsResolver;
    private Verifier $verifier;
    private ConnectorInterface $socketConnector;
    private BaseCommand $command;
    /**
     * @var mixed[]
     */
    private array $filter = [
        's_status' => VerifyStatus::UNKNOWN,
    ];

    public function __construct()
    {
        $this->hostsConfigFile = dirname(__DIR__).'/config/hosts.yaml';
    }

    public function getHostsConfig(): HostsConfig
    {
        if (isset($this->hostsConfig)) {
            return $this->hostsConfig;
        }
        $config = new HostsConfig();
        if (!is_file($this->hostsConfigFile)) {
            throw new Exception("Config file '$this->hostsConfigFile' not found.");
        }
        $contents = file_get_contents($this->hostsConfigFile);
        if (false === $contents) {
            throw new Exception("Cannot read config from '$this->hostsConfigFile'.");
        }
        $config->loadArray(Yaml::parse($contents)['hosts']);

        return $this->hostsConfig = $config;
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
        $url .= rawurlencode(self::getEnvConfigValue('DB_USER', 'root'));
        $url .= ':'.rawurlencode(self::getEnvConfigValue('DB_PASSWORD', ''));
        $url .= '@'.self::getEnvConfigValue('DB_HOST', 'localhost');
        $url .= ':'.self::getEnvConfigValue('DB_PORT', '3306');
        $schemaName = self::getEnvConfigValue('DB_SCHEMA');
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
            $this->eventLoop = \React\EventLoop\Factory::create();
        }

        return $this->eventLoop;
    }

    public function getEmailFixtures(): EmailFixtures
    {
        return new EmailFixtures($this->getEntityManager(), $this->getEventLoop());
    }

    public function getLogger(): LoggerInterface
    {
        if ($this->quiet) {
            return new NullLogger();
        }
        if (!isset($this->logger)) {
            $dumping = new ThroughStream(function ($data) {
                return $data;
            });
            $loggerStream = new WritableResourceStream(STDERR, $this->getEventLoop());
            $dumping->pipe($loggerStream);
            $loggerStream = $dumping;
            $this->command->on('afterStop', function () use ($loggerStream) {
                $loggerStream->end();
            });
            /*
             * @todo Using internal StdioLogger constructor to write to STDERR. Replace by own logger.
             */
            $this->logger = (new LevelFilteringLogger(
                (new StdioLogger($loggerStream))
                    ->withNewLine(true)
            ));
            if (!$this->debug) {
                $this->logger = $this->logger->withHideLevels([
                    LogLevel::DEBUG,
                ]);
            }
            if (!$this->verbose) {
                $this->logger = $this->logger->withHideLevels([
                    LogLevel::INFO,
                ]);
            }
        }

        return $this->logger;
    }

    /**
     * @return EmailEntityManager
     */
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
        if (isset($this->verifier)) {
            return $this->verifier;
        }
        $resolver = $this->getDnsResolver();
        $config = $this->getHostsConfig();
        $connector = $this->getSocketConnector();
        $loop = $this->getEventLoop();
        $logger = $this->getLogger();
        $mutex = new MutexFactory($loop);
        $settings = $config->getSettings();
        $verifierConnector = new SmtpConnector(
            $resolver,
            $connector,
            $mutex
        );
        $verifierConnector->setLogger($logger);
        $verifierConnector = new VerifierConnector($verifierConnector, $mutex, $settings);
        $verifierConnector->setLogger($logger);
        $verifierConnector = new ConnectionPool($verifierConnector, $loop, $config->getSettings());
        $verifierConnector->setLogger($logger);

        $this->verifier = new Verifier($verifierConnector);
        $this->verifier->setLogger($this->getLogger());
        $this->verifier->setMaxConcurrent((int) $this->getEnvConfigValue('MAX_CONCURRENT', 1000));

        return $this->verifier;
    }

    public function getSocketConnector(): ConnectorInterface
    {
        if (!isset($this->socketConnector)) {
            $this->socketConnector = new SocketConnector($this->getEventLoop(), [
                'timeout' => (int) $this->getEnvConfigValue('CONNECT_TIMEOUT', 30),
            ]);
            if (isset($this->proxy)) {
                $this->socketConnector = new SocksClient($this->proxy, $this->socketConnector);
            }
        }

        return $this->socketConnector;
    }

    public function getDnsResolver(): ResolverInterface
    {
        if (!isset($this->dnsResolver)) {
            $config = DnsConfig::loadSystemConfigBlocking();
            $this->dnsResolver = (new \React\Dns\Resolver\Factory())->createCached(
                $config->nameservers ? reset($config->nameservers) : self::DEFAULT_NAMESERVER,
                $this->getEventLoop()
            );
        }

        return $this->dnsResolver;
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

    /**
     * @param BaseCommand $command
     */
    public function setCommand(BaseCommand $command): void
    {
        $this->command = $command;
    }

    /**
     * @param string $hostsConfigFile
     */
    public function setHostsConfigFile(string $hostsConfigFile): void
    {
        if (isset($this->hostsConfig)) {
            throw new LogicException('Config file is already loaded.');
        }
        $this->hostsConfigFile = $hostsConfigFile;
    }

    /**
     * @param bool $verbose
     */
    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * @param bool $quiet
     */
    public function setQuiet(bool $quiet): void
    {
        $this->quiet = $quiet;
    }

    /**
     * @param bool $debug
     *
     * @todo Implement.
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
        $this->verbose = $debug;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param LoopInterface $eventLoop
     */
    public function setEventLoop(LoopInterface $eventLoop): void
    {
        $this->eventLoop = $eventLoop;
    }

    /**
     * @param string|null $proxy
     */
    public function setProxy(?string $proxy): void
    {
        $this->proxy = $proxy;
    }

    /**
     * @param HostsConfig $hostsConfig
     */
    public function setHostsConfig(HostsConfig $hostsConfig): void
    {
        $this->hostsConfig = $hostsConfig;
    }

    /**
     * @param ConnectionInterface $readDbConnection
     */
    public function setReadDbConnection(ConnectionInterface $readDbConnection): void
    {
        $this->readDbConnection = $readDbConnection;
    }

    /**
     * @param ConnectionInterface $writeConnection
     */
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
