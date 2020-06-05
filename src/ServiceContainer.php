<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use App\DB\EmailEntityManager;
use App\Throttling\Factory as ThrottlingFactory;
use App\Verifier\Factory as VerifierFactory;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceContainer
{
    private LoggerInterface $logger;
    private LoopInterface $eventLoop;
    private EmailEntityManager $entityManager;
    private string $hostsConfigFile;
    private ConnectionInterface $readDbConnection;
    private ConnectionInterface $writeConnection;
    private VerifierFactory $verifierFactory;
    private ThrottlingFactory $throttlingFactory;
    private InputInterface $input;
    private OutputInterface $output;
    private ServiceFactory $factory;

    public function __construct(ServiceFactory $factory)
    {
        $this->factory = $factory;
        $this->hostsConfigFile = \dirname(__DIR__).'/config/hosts.yaml';
    }

    public function getReadDbConnection(): ConnectionInterface
    {
        if (!isset($this->readDbConnection)) {
            $this->readDbConnection = $this->factory->createDbConnection($this);
        }

        return $this->readDbConnection;
    }

    public function getWriteDbConnection(): ConnectionInterface
    {
        if (!isset($this->writeConnection)) {
            $this->writeConnection = $this->factory->createDbConnection($this);
        }

        return $this->writeConnection;
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

    public function getOutput(): OutputInterface
    {
        if (!isset($this->output)) {
            $this->output = $this->factory->createOutput($this);
        }

        return $this->output;
    }

    public function getInput(): InputInterface
    {
        if (!isset($this->input)) {
            $this->input = $this->factory->createInput($this);
        }

        return $this->input;
    }

    public function getLogger(): LoggerInterface
    {
        if (!isset($this->logger)) {
            $this->logger = $this->factory->createLogger($this);
        }

        return $this->logger;
    }

    public function getEntityManager(): EmailEntityManager
    {
        if (!isset($this->entityManager)) {
            $this->entityManager = $this->factory->createEntityManager($this);
        }

        return $this->entityManager;
    }

    public function getVerifierFactory(): VerifierFactory
    {
        if (!isset($this->verifierFactory)) {
            $this->verifierFactory = $this->factory->createVerifierFactory($this);
        }

        return $this->verifierFactory;
    }

    public function getThrottlingFactory(): ThrottlingFactory
    {
        if (!isset($this->throttlingFactory)) {
            $this->throttlingFactory = $this->factory->createThrottlingFactory($this);
        }

        return $this->throttlingFactory;
    }

    public function setInput(InputInterface $input): void
    {
        $this->input = $input;
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }
}
