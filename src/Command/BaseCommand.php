<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\ServiceContainer;
use Closure;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for all application commands.
 */
abstract class BaseCommand extends Command
{
    use ReactCommandTrait;

    protected ServiceContainer $container;

    /**
     * BaseCommand constructor.
     *
     * @param ServiceContainer $container
     */
    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
        $container->setCommand($this);
        $this->initReactCommand($container->getEventLoop());
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            /*
             * @todo Filter option is needed not for all commands.
             */
            ->addOption('filter', 'f', InputArgument::OPTIONAL,
                'JSON email filter')
            ->addOption('hosts-config', 'hc', InputOption::VALUE_OPTIONAL,
                'Path to hosts.yaml');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $logger = new ConsoleLogger($output->section());
        } else {
            $logger = new ConsoleLogger($output);
        }
        $this->container->setLogger($logger);
        $this->setContainerOptions($input);
        $verbosity = $output->getVerbosity();
        if (OutputInterface::VERBOSITY_DEBUG === $verbosity) {
            $this->container->setDebug(true);
        } elseif ($verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->container->setVerbose(true);
        } elseif (OutputInterface::VERBOSITY_QUIET === $verbosity) {
            $this->container->setQuiet(true);
        }
    }

    protected function getContainerOptionsMap(): array
    {
        return [
            'hosts-config' => 'setHostsConfigFile',
            'verbose' => null,
            'quiet' => null,
            'help' => null,
            'version' => null,
            'ansi' => null,
            'no-ansi' => null,
            'no-interaction' => null,
            'filter' => function ($value) {
                $this->container->setFilter(json_decode($value, true) ?? []);
            },
        ];
    }

    private function setContainerOptions(InputInterface $input): void
    {
        $map = $this->getContainerOptionsMap();
        foreach ($this->getDefinition()->getOptions() as $option) {
            $value = $input->getOption($option->getName());
            if (null === $value) {
                continue;
            }
            if (array_key_exists($option->getName(), $map)) {
                $methodName = $map[$option->getName()];
            } else {
                $methodName = 'set'.ucfirst($this->kebabToCamelCase($option->getName()));
            }
            if (is_null($methodName)) {
                continue;
            }
            if ($methodName instanceof Closure) {
                $methodName($value);

                continue;
            }
            if (!is_callable([$this->container, $methodName])) {
                throw new LogicException("Cannot set option '{$option->getName()}'.");
            }
            $this->container->$methodName($value);
        }
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
}
