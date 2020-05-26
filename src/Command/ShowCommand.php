<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Command;

use App\Entity\Email;
use App\Stream\ReadableStreamWrapperTrait;
use App\Throttling\Factory;
use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowCommand extends BaseCommand
{
    protected static $defaultName = 'show';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addUsage('--filter=\'{"m_mail":["NOT LIKE","%@mail.ru"],'.
                '"s_status":  "unknown","dt_updated":["<", "2020-05-20 00:00"], "#limit": 100}\' 1')
            ->addArgument('min-interval', InputArgument::OPTIONAL,
            'Minimum interval between rows output in seconds.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $minInterval = (float) $input->getArgument('min-interval');
        $loop = $this->container->getEventLoop();
        $entityManager = $this->container->getEntityManager();
        $throttling = new Factory($loop);
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
                    " {$email->dt_updated->format(DATE_ATOM)}",
                ];
            }
        };
        $deferred = new Deferred();
        $total = 0;
        $stream->on('data', function ($data) use (&$total, $output) {
            ++$total;
            $output->writeln($data);
        });
        $stream->on('error', function ($e) use ($deferred) {
            $deferred->reject($e);
        });
        $stream->on('end', function () use ($deferred, &$total, $output) {
            $output->writeln('');
            $output->writeln("Total: $total");
            $deferred->resolve();
        });

        $this->setExecutePromise($deferred->promise());

        return 0;
    }
}
