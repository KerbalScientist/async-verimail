<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier;

use App\MutexRun\Factory as MutexFactory;
use App\Smtp\Connector as SmtpConnector;
use App\Stream\CollectingThroughStream;
use App\Stream\ResolvingThroughStream;
use App\Stream\ThroughStream;
use App\Verifier\Config\HostsConfig;
use App\Verifier\Connector as VerifierConnector;
use Clue\React\Socks\Client as SocksClient;
use Exception;
use LogicException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\Factory as ResolverFactory;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector;
use React\Socket\ConnectorInterface;
use React\Stream\CompositeStream;
use React\Stream\DuplexStreamInterface;
use React\Stream\Util;
use Symfony\Component\Yaml\Yaml;
use function App\pipeThrough;

class Factory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_NAMESERVER = '8.8.8.8';

    private ResolverInterface $dnsResolver;
    private HostsConfig $hostsConfig;
    private ?string $hostsConfigFile = null;
    private int $maxConcurrent = 1000;
    private float $connectTimeout = 30;
    private ConnectorInterface $socketConnector;
    private LoopInterface $eventLoop;
    /**
     * @var callable
     */
    private $verifyingCallback;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @return Verifier
     *
     * @throws Exception
     */
    public function createVerifier(): Verifier
    {
        $resolver = $this->getDnsResolver();
        $config = $this->getHostsConfig();
        $connector = $this->getSocketConnector();
        $loop = $this->getEventLoop();
        $logger = $this->logger;
        $mutex = new MutexFactory($loop);
        $settings = $config->getSettings();
        $connector = new SmtpConnector(
            $resolver,
            $connector,
            $mutex
        );
        $connector->setLogger($logger);
        $connector = new VerifierConnector($connector, $mutex, $settings);
        $connector->setLogger($logger);
        $connector = new ConnectionPool($connector, $loop, $config->getSettings());
        $connector->setLogger($logger);

        $verifier = new Verifier($connector);
        $verifier->setLogger($logger);

        return $verifier;
    }

    /**
     * Creates stream that verifies emails which is passed to writable end,
     *  emits `[string $email, VerifyStatus $status]` arrays from readable end.
     * You can adjust what stream accepts and what it emits by calling
     *  `Factory::setVerifyingCallback()`.
     *
     * @param mixed[] $pipeOptions Options, passed to `App::pipeThrough()`
     *
     * @return DuplexStreamInterface duplex stream of Email entities at both readable and writable sides
     *
     * @throws Exception
     *
     * @see Factory::setVerifyingCallback().
     */
    public function createVerifyingStream(array $pipeOptions = []): DuplexStreamInterface
    {
        $verifier = $this->createVerifier();
        $through = function ($data) use ($verifier) {
            return ($this->getVerifyingCallback())($verifier, $data);
        };
        $loop = $this->getEventLoop();
        pipeThrough(
            $collectingStream = new CollectingThroughStream($loop),
            [
                $verifyingStream = new ThroughStream($through),
            ],
            $resolvingStream = new ResolvingThroughStream($loop, $this->maxConcurrent),
            $pipeOptions
        );
        $result = new CompositeStream($resolvingStream, $collectingStream);
        $collectingStream->removeListener('close', [$result, 'close']);
        Util::forwardEvents($resolvingStream, $result, ['resolve']);

        return $result;
    }

    public function addSocksProxy(string $socksUri): void
    {
        $this->socketConnector = new SocksClient($socksUri, $this->getSocketConnector());
    }

    public function setDnsResolver(ResolverInterface $dnsResolver): void
    {
        $this->dnsResolver = $dnsResolver;
    }

    public function setHostsConfig(HostsConfig $hostsConfig): void
    {
        $this->hostsConfig = $hostsConfig;
    }

    public function setHostsConfigFile(?string $hostsConfigFile): void
    {
        if (isset($this->hostsConfig)) {
            throw new LogicException('Config file is already loaded.');
        }
        $this->hostsConfigFile = $hostsConfigFile;
    }

    public function setMaxConcurrent(int $maxConcurrent): void
    {
        $this->maxConcurrent = $maxConcurrent;
    }

    public function setConnectTimeout(float $connectTimeout): void
    {
        $this->connectTimeout = $connectTimeout;
    }

    public function setSocketConnector(ConnectorInterface $socketConnector): void
    {
        $this->socketConnector = $socketConnector;
    }

    public function setEventLoop(LoopInterface $eventLoop): void
    {
        $this->eventLoop = $eventLoop;
    }

    /**
     * Sets callback for verifying stream.
     *
     * Callback accepts 2 parameters: one for `Verifier` instance and one for data,
     * passed to writable end of verifying stream.
     *
     * Callback must return an instance of `PromiseInterface`. Resolved value will be passed
     * to the readable side of verifying stream.
     *
     * Default value is:
     * ```php
     * $factory->setVerifyingCallback(function (Verifier $verifier, $data) {
     *      return $verifier->verify($data)
     *          ->then(function (VerifyStatus $status) use ($data) {
     *             return [$data, $status];
     *          });
     * });
     * ```
     *
     * @param callable $verifyingCallback
     *
     * @see Factory::createVerifyingStream()
     */
    public function setVerifyingCallback(callable $verifyingCallback): void
    {
        $this->verifyingCallback = $verifyingCallback;
    }

    private function getDnsResolver(): ResolverInterface
    {
        if (!isset($this->dnsResolver)) {
            $config = DnsConfig::loadSystemConfigBlocking();
            $this->dnsResolver = (new ResolverFactory())->createCached(
                $config->nameservers ? reset($config->nameservers) : self::DEFAULT_NAMESERVER,
                $this->getEventLoop()
            );
        }

        return $this->dnsResolver;
    }

    /**
     * @return HostsConfig
     *
     * @throws Exception
     */
    private function getHostsConfig(): HostsConfig
    {
        if (isset($this->hostsConfig)) {
            return $this->hostsConfig;
        }
        $config = new HostsConfig();
        if (null === $this->hostsConfigFile) {
            return $this->hostsConfig = $config;
        }
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

    private function getSocketConnector(): ConnectorInterface
    {
        if (!isset($this->socketConnector)) {
            $this->socketConnector = new SocketConnector($this->getEventLoop(), [
                'timeout' => $this->connectTimeout,
            ]);
        }

        return $this->socketConnector;
    }

    private function getEventLoop(): LoopInterface
    {
        if (!isset($this->eventLoop)) {
            $this->eventLoop = LoopFactory::create();
        }

        return $this->eventLoop;
    }

    private function getVerifyingCallback(): callable
    {
        if (!isset($this->verifyingCallback)) {
            $this->verifyingCallback = function (Verifier $verifier, $data) {
                return $verifier->verify($data)
                    ->then(function (VerifyStatus $status) use ($data) {
                        return [$data, $status];
                    });
            };
        }

        return $this->verifyingCallback;
    }
}
