<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\DB\EntityManagerInterface;
use App\ServiceContainer;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Base class for all application commands.
 */
abstract class BaseCommand extends Command
{
    use ReactCommandTrait;

    private ServiceContainer $container;
    protected EntityManagerInterface $entityManager;

    /**
     * BaseCommand constructor.
     *
     * @param LoopInterface          $eventLoop
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(LoopInterface $eventLoop, EntityManagerInterface $entityManager)
    {
        $this->initReactCommand($eventLoop);
        $this->entityManager = $entityManager;
        parent::__construct();
    }
}
