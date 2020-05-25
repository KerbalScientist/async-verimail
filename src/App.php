<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\DB\EmailEntityManager;
use App\DB\MysqlQueryFactory;
use App\Entity\Email;
use App\Entity\VerifyStatus;
use App\SmtpVerifier\ConnectionPool;
use App\SmtpVerifier\Connector;
use App\Stream\ReadableStreamWrapperTrait;
use App\Stream\ThroughStream;
use Aura\SqlQuery\Common\SelectInterface;
use Clue\React\Socks\Client as SocksClient;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface as SocketConnectorInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableResourceStream;
use ReactPHP\MySQL\Decorator\BindAssocParamsConnectionDecorator;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;
use WyriHaximus\React\PSR3\Stdio\StdioLogger;
use function React\Promise\all;
use function React\Promise\resolve;
use const STDERR;

/**
 * Class App.
 *
 * @todo God object. Commands container + service container + arguments parser.
 */
class App implements EventEmitterInterface
{
    use EventEmitterTrait;

    const EXIT_CODE_OK = 0;
    const EXIT_CODE_ERROR = 1;

    const DEFAULT_NAMESERVER = '8.8.8.8';

    private const OPTION_PREFIX = '--';
    private const OPTION_VALUE_DELIMITER = '=';
    private const OPTION_SETTER_PREFIX = 'setOption';
    private const COMMAND_METHOD_SUFFIX = 'Command';

    private bool $verbose = false;
    private bool $quiet = false;
    private LoggerInterface $logger;
    /**
     * @var mixed[]
     */
    private array $filter = [
        's_status' => VerifyStatus::UNKNOWN,
    ];
    private LoopInterface $eventLoop;
    /**
     * @var PromiseInterface[]
     */
    private array $resolveBeforeStop = [];
    /**
     * @var int[]
     */
    private array $maxConnectionsPerHost = [
        /*
         * @todo Hardcoded. Move to config.
         */
        'default' => 1,
        'yandex.ru' => 15,
        'narod.ru' => 10,
        'ya.ru' => 10,
        'gmail.com' => 3,
        'mail.ru' => 1,
        'list.ru' => 1,
        'bk.ru' => 1,
        'rambler.ru' => 1,
        'outlook.com' => 2,
    ];
    private int $maxConcurrent = 2000;
    /**
     * @todo Hardcoded.
     */
    private float $connectTimeout = 30;
    private ?string $proxy = null;
    private string $fromEmail = 'info@clockshop.ru';

    /**
     * @param string[]|null $args
     *
     * @return int exit code
     */
    public function run(?array $args = null): int
    {
        $this->emit('start');
        if (is_null($args)) {
            $args = $GLOBALS['argv'];
        }
        /*
         * @todo Move to config.
         */
        if (extension_loaded('xdebug')) {
            ini_set('xdebug.max_nesting_level', '100000');
        }

        try {
            $args = $this->processArgs($args);
            array_shift($args);
            $command = array_shift($args) ?? '';
            $loop = $this->getEventLoop();
            $this->getEventLoop()
                ->addSignal(SIGINT, function () use ($loop) {
                    echo 'Stopped by user.'.PHP_EOL;
                    $this->stop($loop);
                });

            return $this->runCommand($command, $args);
        } catch (Throwable $e) {
            if ($this->verbose) {
                echo "$e".PHP_EOL;
            } else {
                echo "Error: {$e->getMessage()}".PHP_EOL;
            }
        } finally {
            $this->emit('afterStop');
        }

        return self::EXIT_CODE_ERROR;
    }

    private function processArgs(array $args): array
    {
        $result = [];
        foreach ($args as $arg) {
            if (self::OPTION_PREFIX !== substr($arg, 0, strlen(self::OPTION_PREFIX))) {
                $result[] = $arg;

                continue;
            }
            $opt = substr($arg, strlen(self::OPTION_PREFIX));
            $valuePos = strpos($opt, self::OPTION_VALUE_DELIMITER);
            if (false === $valuePos) {
                $optName = $opt;
                $value = true;
            } else {
                $optName = substr($opt, 0, $valuePos);
                $value = substr($opt, $valuePos + 1);
            }
            $methodName = self::OPTION_SETTER_PREFIX.ucfirst($this->kebabToCamelCase($optName));
            if (!method_exists($this, $methodName)) {
                throw new InvalidArgumentException("Unknown option '$optName'");
            }
            $this->$methodName($value);
        }

        return $result;
    }

    private function kebabToCamelCase(string $kebab): string
    {
        $words = explode('-', $kebab);
        $result = array_shift($words);
        foreach ($words as $word) {
            $result .= ucfirst($word);
        }

        return $result;
    }

    public function getEventLoop(): LoopInterface
    {
        if (empty($this->eventLoop)) {
            $this->eventLoop = \React\EventLoop\Factory::create();
        }

        return $this->eventLoop;
    }

    private function resolveBeforeStop(PromiseInterface $promise): void
    {
        $this->resolveBeforeStop[] = $promise;
    }

    private function stop(LoopInterface $loop, bool $force = false): void
    {
        $this->emit('beforeStop');
        if ($force || !$this->resolveBeforeStop) {
            $promise = resolve();
        } else {
            $promise = all($this->resolveBeforeStop);
        }
        $stop = function () use ($loop) {
            $loop->addTimer(3, function () use ($loop) {
                $loop->stop();
            });
        };
        $promise->then($stop, $stop);
    }

    /**
     * @param string   $name
     * @param string[] $args
     *
     * @return int exit code
     *
     * @throws ReflectionException
     */
    private function runCommand(string $name, array $args): int
    {
        $startTime = microtime(true);
        $method = $this->kebabToCamelCase($name).self::COMMAND_METHOD_SUFFIX;
        if (!is_callable([$this, $method])) {
            echo "Unknown command '$name'.".PHP_EOL;
            echo 'Commands: '.implode(', ', $this->getCommands()).'.'.PHP_EOL;

            return self::EXIT_CODE_ERROR;
        }
        $promise = $this->$method(...$args);
        $writeInfo = function () use ($startTime) {
            $this->logger->info('Time: '.(microtime(true) - $startTime));
            $this->logger->debug('Memory peak: '.memory_get_peak_usage(true));
        };
        $exitCode = self::EXIT_CODE_OK;
        $loop = $this->getEventLoop();
        $promise->then(function ($result) use ($loop, $writeInfo, &$exitCode) {
            $writeInfo();
            $exitCode = (int) $result;
            $this->stop($loop);
        }, function ($error) use ($loop, $writeInfo, &$exitCode) {
            $this->logger->error("$error");
            $writeInfo();
            $exitCode = self::EXIT_CODE_ERROR;
            $this->stop($loop);
            echo "{$error->getMessage()}".PHP_EOL;

            throw $error;
        });
        $loop->run();

        return $exitCode;
    }

    /**
     * @return string[]
     *
     * @throws ReflectionException
     */
    private function getCommands(): array
    {
        $result = [];
        $class = new ReflectionClass($this);
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $suffixLength = strlen(self::COMMAND_METHOD_SUFFIX);
            if (self::COMMAND_METHOD_SUFFIX === substr(
                    $method->getName(),
                    -$suffixLength)
            ) {
                $result[] = $this->camelToKebabCase(substr($method->getName(), 0, -$suffixLength));
            }
        }

        return $result;
    }

    private function camelToKebabCase(string $camel): string
    {
        $words = preg_split(
            '/([A-Z])/',
            $camel,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        $camel = '';
        $camel .= strtolower(array_shift($words));
        foreach ($words as $part) {
            if (strtolower($part) === $part) {
                $camel .= $part;

                continue;
            }
            $camel .= '-'.strtolower($part);
        }

        return $camel;
    }

    public function installCommand(): PromiseInterface
    {
        $loop = $this->getEventLoop();
        $logger = $this->getLogger($loop);
        $entityManager = $this->getEntityManager($loop, $logger);

        return $entityManager->installSchema();
    }

    /**
     * @param LoopInterface $loop
     *
     * @return LoggerInterface
     */
    public function getLogger(LoopInterface $loop): LoggerInterface
    {
        if ($this->quiet) {
            return new NullLogger();
        }
        if (!isset($this->logger)) {
            $dumping = new ThroughStream(function ($data) {
                return $data;
            });
            $loggerStream = new WritableResourceStream(STDERR, $loop);
            $dumping->pipe($loggerStream);
            $loggerStream = $dumping;
            $this->on('afterStop', function () use ($loggerStream) {
                $loggerStream->end();
            });
            /*
             * @todo Using internal StdioLogger constructor to write to STDERR. Replace by own logger.
             */
            $this->logger = (new LevelFilteringLogger(
                (new StdioLogger($loggerStream))
                    ->withNewLine(true)
            ));
            if (!$this->verbose) {
                $this->logger = $this->logger->withHideLevels([
                    LogLevel::DEBUG,
                ]);
            }
        }

        return $this->logger;
    }

    /**
     * @param LoopInterface   $loop
     * @param LoggerInterface $logger
     *
     * @return EmailEntityManager
     */
    public function getEntityManager(LoopInterface $loop, LoggerInterface $logger): EmailEntityManager
    {
        $entityManager = new EmailEntityManager(
            $this->getConfig('DB_EMAIL_TABLE_NAME', 'email'),
            $this->getReadDbConnection($loop),
            $this->getWriteDbConnection($loop),
            new MysqlQueryFactory()
        );
        $entityManager->setLogger($logger);

        return $entityManager;
    }

    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getConfig(string $name, $default = null)
    {
        $value = getenv($name);
        if (false === $value) {
            return $default;
        }

        return $value;
    }

    public function getReadDbConnection(LoopInterface $loop): ConnectionInterface
    {
        return $this->createDbConnection($loop);
    }

    public function createDbConnection(LoopInterface $loop): ConnectionInterface
    {
        $url = '';
        $url .= rawurlencode(self::getConfig('DB_USER', 'root'));
        $url .= ':'.rawurlencode(self::getConfig('DB_PASSWORD', ''));
        $url .= '@'.self::getConfig('DB_HOST', 'localhost');
        $url .= ':'.self::getConfig('DB_PORT', '3306');
        $schemaName = self::getConfig('DB_SCHEMA');
        if (!is_null($schemaName)) {
            $url .= "/$schemaName";
        }
        $url .= '?idle=-1&timeout=-1';

        return new BindAssocParamsConnectionDecorator(
            (new Factory($loop))->createLazyConnection($url)
        );
    }

    public function getWriteDbConnection(LoopInterface $loop): ConnectionInterface
    {
        return self::createDbConnection($loop);
    }

    public function importCommand(string $filename): PromiseInterface
    {
        $loop = $this->getEventLoop();
        $logger = $this->getLogger($loop);
        $entityManager = $this->getEntityManager($loop, $logger);

        return $entityManager->importFromCsvBlocking($filename);
    }

    public function exportCommand(string $filename): PromiseInterface
    {
        $loop = $this->getEventLoop();
        $logger = $this->getLogger($loop);
        $entityManager = $this->getEntityManager($loop, $logger);

        return $entityManager->exportToCsvBlocking($filename);
    }

    /**
     * @return PromiseInterface
     *
     * @todo Too large. Refactor.
     */
    public function verifyCommand(): PromiseInterface
    {
        $loop = $this->getEventLoop();
        $logger = $this->getLogger($loop);
        $entityManager = $this->getEntityManager($loop, $logger);
        $config = DnsConfig::loadSystemConfigBlocking();
        $resolver = (new \React\Dns\Resolver\Factory())->createCached(
            $config->nameservers ? reset($config->nameservers) : self::DEFAULT_NAMESERVER,
            $loop
        );
        $connector = new \React\Socket\Connector($loop, [
            'timeout' => $this->connectTimeout,
        ]);

        if (isset($this->proxy)) {
            $connector = new SocksClient($this->proxy, $connector);
        }
        $verifier = $this->getVerifier($resolver, $connector, $logger, $loop);
        $verifier->setLogger($logger);

        $pipeOptions = [
            'error' => true,
            'closeToEnd' => true,
            'end' => false,
        ];
        $queryStream = $entityManager->streamByQuery($this->getSelectQuery($entityManager));

        pipeThrough(
            $queryStream,
            [$verifyingStream = $verifier->createVerifyingStream($loop, $pipeOptions)],
            $persistingStream = $entityManager->createPersistingStream(),
            $pipeOptions
        );

        $deferred = new Deferred();
        $persistingStream->on('error', function ($error) use ($deferred) {
            $deferred->reject($error);
        });
        $persistingStream->on('close', function () use ($deferred) {
            $deferred->resolve();
        });
        $this->on('beforeStop', function () use ($persistingStream, $queryStream, $verifyingStream) {
            $this->resolveBeforeStop($persistingStream->flush());
            $persistingStream->close();
            $verifyingStream->close();
            $queryStream->close();
        });

        $count = 0;
        $timeStart = null;
        $timeLast = null;
        /**
         * @todo Hardcoded windowWidth.
         */
        $movingAvg = new MovingAverage(15);
        $queryStream->once('data', function () use (&$timeStart, &$timeLast) {
            $timeLast = $timeStart = microtime(true);
        });
        $verifyingStream->on('data',
            function () use (&$count, $logger, &$timeStart, &$timeLast, $movingAvg) {
                ++$count;
                $time = microtime(true);
                if (is_null($timeStart)) {
                    /**
                     * @todo Hardcoded  - 0.1.
                     */
                    $timeLast = $timeStart = $time - 0.1;
                }
                $avgSpeed = $count / ($time - $timeStart);
                $movingAvg->insertValue($time, $time - $timeLast);
                $timeLast = $time;

                $logger->debug("$count emails verified.");
                $logger->debug("Average speed: $avgSpeed emails per second.");
                if (0 !== $movingAvg->get()) {
                    $movingAvgSpeed = 1 / $movingAvg->get();
                    $logger->debug("Current speed: $movingAvgSpeed emails per second.");
                }
            });

        return $deferred->promise();
    }

    /**
     * @param ResolverInterface        $resolver
     * @param SocketConnectorInterface $connector
     * @param LoggerInterface          $logger
     * @param LoopInterface            $loop
     *
     * @return Verifier
     */
    public function getVerifier(
        ResolverInterface $resolver,
        SocketConnectorInterface $connector,
        LoggerInterface $logger,
        LoopInterface $loop
    ): Verifier {
        $verifierConnector = new Connector($resolver, $connector, new Mutex($loop), [
            /*
             * @todo Hardcoded. Move to config.
             */
            'default' => [
                'fromEmail' => $this->fromEmail,
                'resetAfterVerifications' => 1,
            ],
            'yandex.ru' => [
                'resetAfterVerifications' => 25,
            ],
            'google.com' => [
                'resetAfterVerifications' => 10,
            ],
            'outlook.com' => [
                'resetAfterVerifications' => 1,
            ],
            'hotmail.com' => [
                'resetAfterVerifications' => 1,
            ],
            'sibmail.com' => [
                'resetAfterVerifications' => 3,
            ],
            'icloud.com' => [
                'closeAfterVerifications' => 10,
            ],
            'mail.com' => [
                'fromHost' => 'kerbal-scientist.host',
                'fromEmail' => 'info@kerbal-scientist.host',
            ],
        ]);
        $verifierConnector->setLogger($logger);
        $verifierConnector = new ConnectionPool($verifierConnector, $loop, [
            'maxConnectionsPerHost' => $this->maxConnectionsPerHost,
        ]);
        $verifierConnector->setLogger($logger);

        return new Verifier($verifierConnector, new Mutex($loop), [
            'maxConcurrent' => $this->maxConcurrent,
        ]);
    }

    private function getSelectQuery(EmailEntityManager $entityManager): SelectInterface
    {
        return $entityManager->createSelectQuery($this->filter);
    }

    public function showCommand(string $minInterval = '0'): PromiseInterface
    {
        $minInterval = (float) $minInterval;
        $loop = $this->getEventLoop();
        $logger = $this->getLogger($loop);
        $entityManager = $this->getEntityManager($loop, $logger);
        $throttling = new Throttling\Factory($loop);
        $stream = $entityManager->streamByQuery($this->getSelectQuery($entityManager));
        $stream = $throttling->readableStream($stream, $minInterval);
        $stream = new class($stream) implements ReadableStreamInterface {
            use ReadableStreamWrapperTrait;

            /**
             * {@inheritdoc}
             */
            protected function filterData(Email $email): ?array
            {
                return [
                    "$email->i_id $email->m_mail $email->s_status ({$email->s_status->getDescription()})".
                    " {$email->dt_updated->format(DATE_ATOM)}".PHP_EOL,
                ];
            }
        };
        $deferred = new Deferred();
        $total = 0;
        $stream->on('data', function ($data) use (&$total) {
            ++$total;
            echo $data;
        });
        $stream->on('error', function ($e) use ($deferred) {
            $deferred->reject($e);
        });
        $stream->on('end', function () use ($deferred, &$total) {
            echo PHP_EOL."Total: $total".PHP_EOL;
            $deferred->resolve();
        });

        return $deferred->promise();
    }

    public function generateFixturesCommand(string $count = '1000'): PromiseInterface
    {
        $loop = $this->getEventLoop();
        $logger = $this->getLogger($loop);
        $entityManager = $this->getEntityManager($loop, $logger);
        $fixtures = new EmailFixtures($entityManager, $loop);

        return $fixtures->generate((int) $count);
    }

    public function setOptionVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function setOptionQuiet(bool $quiet): void
    {
        $this->quiet = $quiet;
    }

    /**
     * @param string $maxConcurrent
     */
    public function setOptionMaxConcurrent(string $maxConcurrent): void
    {
        $this->maxConcurrent = (int) $maxConcurrent;
    }

    /**
     * @param string $emailFrom
     */
    public function setOptionEmailFrom(string $emailFrom): void
    {
        $this->fromEmail = $emailFrom;
    }

    /**
     * @param string $maxConnectionsPerHost
     */
    public function setOptionMaxConnectionsPerHost(string $maxConnectionsPerHost): void
    {
        $this->maxConnectionsPerHost = json_decode($maxConnectionsPerHost, true) ?? ['default' => 1];
    }

    /**
     * @param string $filter
     */
    public function setOptionFilter(string $filter): void
    {
        $this->filter = json_decode($filter, true) ?? [];
    }

    /**
     * @param string $proxy
     */
    public function setOptionProxy(string $proxy): void
    {
        $this->proxy = $proxy;
    }
}
