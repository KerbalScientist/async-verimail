<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use Evenement\EventEmitterTrait;
use Exception;
use http\Exception\RuntimeException;
use LogicException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Provides functionality for Symfony commands to be run with ReactPHP event loop.
 *
 * To run event loop, command must:
 *  - call ReactCommandTrait::initReactCommand() from constructor,
 *  - call ReactCommandTrait::setExecutePromise() from execute() method.
 *
 * Command may listen to 'beforeRun', 'beforeStop', 'stop' events and at any time before 'stop' event
 *  call addResolveBeforeStop() to ensure that all important promises will be fulfilled or rejected
 *  before event loop stops.
 *
 * Must be part of @see \Symfony\Component\Console\Command\Command child.
 */
trait ReactCommandTrait
{
    use EventEmitterTrait;

    private ?PromiseInterface $executePromise = null;
    /**
     * @var PromiseInterface[]
     */
    private array $resolveBeforeStop = [];
    private bool $stopped = false;
    private LoopInterface $eventLoop;

    public function initReactCommand(LoopInterface $loop): void
    {
        $this->eventLoop = $loop;
    }

    /**
     * Must be called from execute() method to run event loop.
     *
     * Promise must be fulfilled with exit code or rejected with Throwable.
     * After given promise is fulfilled or rejected, 'beforeStop' event will be emitted.
     *
     * Then after all promises set by addResolveBeforeStop() are fulfilled or rejected,
     * LoopInterface::stop() will be called.
     *
     * @param PromiseInterface $executePromise
     */
    public function setExecutePromise(PromiseInterface $executePromise): void
    {
        $this->executePromise = $executePromise;
    }

    /**
     * Command will wait for given promise before LoopInterface::stop() will be called.
     *
     * @param PromiseInterface $resolveBeforeStop
     */
    public function addResolveBeforeStop(PromiseInterface $resolveBeforeStop): void
    {
        $this->resolveBeforeStop[] = $resolveBeforeStop;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws Exception
     */
    public function run(InputInterface $input, OutputInterface $output)
    {
        if (!isset($this->eventLoop)) {
            throw new LogicException('Command constructor'.
                " must call App\Command\ReactCommandTrait::initReactCommand()");
        }
        $this->emit('beforeRun');
        $result = parent::run($input, $output);
        if (null === $this->executePromise) {
            $this->emit('afterRun');

            return $result;
        }
        $error = null;
        $statusCode = 0;
        $this->executePromise
            ->then(function ($code) use (&$statusCode) {
                $statusCode = $code;
                $this->stop();
            }, function ($e) use (&$error) {
                $error = $e;
                $this->stop();
            });
        $this->eventLoop
            ->addSignal(SIGINT, function () use ($output) {
                if ($output instanceof ConsoleOutputInterface) {
                    $output = $output->section();
                }
                $output->writeln('Stopped by user.');
                $this->stop();
            });
        $this->eventLoop->run();
        $this->emit('stop');
        if ($error instanceof Exception) {
            throw $error;
        }
        if ($error instanceof Throwable) {
            throw new RuntimeException('Error while running command.', 0, $error);
        }
        if (null !== $error) {
            throw new LogicException('Execute promise rejected with non-throwable error '.
                var_export($error, true));
        }

        return is_numeric($statusCode) ? (int) $statusCode : 0;
    }

    private function stop(bool $force = false): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->emit('beforeStop');
        if ($force || !$this->resolveBeforeStop) {
            $promise = resolve();
        } else {
            $promise = all($this->resolveBeforeStop);
        }
        $stop = function () {
            $this->eventLoop->addTimer(1, function () {
                $this->eventLoop->stop();
            });
        };
        $promise->then($stop, $stop);
    }
}
