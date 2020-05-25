<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\Entity\Email;
use App\Stream\ReadableStreamWrapperTrait;
use Dotenv\Dotenv;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Exception;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Throwable;
use function React\Promise\all;
use function React\Promise\resolve;

class App implements EventEmitterInterface
{
    use EventEmitterTrait;

    const EXIT_CODE_OK = 0;
    const EXIT_CODE_ERROR = 1;

    private const OPTION_PREFIX = '--';
    private const OPTION_VALUE_DELIMITER = '=';
    private const OPTION_SETTER_PREFIX = 'setOption';
    private const COMMAND_METHOD_SUFFIX = 'Command';

    private bool $verbose = false;
    private bool $quiet = false;
    /**
     * @var PromiseInterface[]
     */
    private array $resolveBeforeStop = [];
    private ServiceContainer $container;

    public function __construct()
    {
        $this->container = new ServiceContainer($this);
    }

    /**
     * @param string[]|null $args
     *
     * @return int exit code
     */
    public function run(?array $args = null): int
    {
        Dotenv::createImmutable(dirname(__DIR__))->load();
        $this->emit('start');
        if (is_null($args)) {
            $args = $GLOBALS['argv'];
        }
        if (extension_loaded('xdebug')) {
            ini_set('xdebug.max_nesting_level', '100000');
        }

        try {
            $args = $this->processArgs($args);
            array_shift($args);
            $command = array_shift($args) ?? '';
            $loop = $this->container->getEventLoop();
            $loop->addSignal(SIGINT, function () use ($loop) {
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
            $loop->addTimer(1, function () use ($loop) {
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
        $this->emit('beforeRunCommand');
        $startTime = microtime(true);
        $method = $this->kebabToCamelCase($name).self::COMMAND_METHOD_SUFFIX;
        if (!is_callable([$this, $method])) {
            echo "Unknown command '$name'.".PHP_EOL;
            echo 'Commands: '.implode(', ', $this->getCommands()).'.'.PHP_EOL;

            return self::EXIT_CODE_ERROR;
        }
        $promise = $this->$method(...$args);
        $writeInfo = function () use ($startTime) {
            $this->container->getLogger()
                ->info('Time: '.(microtime(true) - $startTime));
            $this->container->getLogger()
                ->debug('Memory peak: '.memory_get_peak_usage(true));
        };
        $exitCode = self::EXIT_CODE_OK;
        $loop = $this->container->getEventLoop();
        $promise->then(function ($result) use ($loop, $writeInfo, &$exitCode) {
            $writeInfo();
            $exitCode = (int) $result;
            $this->stop($loop);
        }, function ($error) use ($loop, $writeInfo, &$exitCode) {
            $this->container->getLogger()
                ->error("$error");
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
        return $this->container
            ->getEntityManager()
            ->installSchema();
    }

    public function importCommand(string $filename): PromiseInterface
    {
        return $this->container
            ->getEntityManager()
            ->importFromCsvBlocking($filename);
    }

    public function exportCommand(string $filename): PromiseInterface
    {
        return $this->container
            ->getEntityManager()
            ->exportToCsvBlocking($filename);
    }

    /**
     * @return PromiseInterface
     *
     * @throws Exception
     *
     * @todo Too large. Refactor.
     */
    public function verifyCommand(): PromiseInterface
    {
        $loop = $this->container->getEventLoop();
        $entityManager = $this->container->getEntityManager();
        $verifier = $this->container->getVerifier();

        $pipeOptions = [
            'error' => true,
            'closeToEnd' => true,
            'end' => false,
        ];
        $queryStream = $entityManager->streamByQuery($this->container->getSelectQuery());

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
            function () use (&$count, &$timeStart, &$timeLast, $movingAvg) {
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

                $this->container->getLogger()
                    ->debug("$count emails verified.");
                $this->container->getLogger()
                    ->debug("Average speed: $avgSpeed emails per second.");
                if (0 !== $movingAvg->get()) {
                    $movingAvgSpeed = 1 / $movingAvg->get();
                    $this->container->getLogger()
                        ->debug("Current speed: $movingAvgSpeed emails per second.");
                }
            });

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface
     *
     * @throws Exception
     */
    public function configDumpCommand(): PromiseInterface
    {
        $dumper = new YamlReferenceDumper();
        echo $dumper->dump($this->container->getHostsConfig());

        return resolve();
    }

    public function showCommand(string $minInterval = '0'): PromiseInterface
    {
        $minInterval = (float) $minInterval;
        $loop = $this->container->getEventLoop();
        $entityManager = $this->container->getEntityManager();
        $throttling = new Throttling\Factory($loop);
        $stream = $entityManager->streamByQuery($this->container->getSelectQuery());
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
        $loop = $this->container->getEventLoop();
        $entityManager = $this->container->getEntityManager();
        $fixtures = new EmailFixtures($entityManager, $loop);

        return $fixtures->generate((int) $count);
    }

    public function setOptionVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
        $this->container->setVerbose($verbose);
    }

    public function setOptionQuiet(bool $quiet): void
    {
        $this->quiet = $quiet;
        $this->container->setQuiet($quiet);
    }

    /**
     * @param string $filename
     */
    public function setOptionConfigFile(string $filename): void
    {
        $this->container->setHostsConfigFile($filename);
    }

    /**
     * @param string $filter
     */
    public function setOptionFilter(string $filter): void
    {
        $this->container->setFilter(json_decode($filter, true) ?? []);
    }

    /**
     * @param string $proxy
     */
    public function setOptionProxy(string $proxy): void
    {
        $this->container->setProxy($proxy);
    }
}
