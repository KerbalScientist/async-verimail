<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;
use SplObjectStorage;
use Throwable;

class CsvBlockingImporter implements LoggerAwareInterface
{
    use \Psr\Log\LoggerAwareTrait;

    private HydrationStrategyInterface $hydrationStrategy;
    private EntityManagerInterface $entityManager;

    public function __construct(HydrationStrategyInterface $hydrationStrategy, EntityManagerInterface $entityManager)
    {
        $this->hydrationStrategy = $hydrationStrategy;
        $this->entityManager = $entityManager;
        $this->logger = new NullLogger();
    }

    /**
     * @param string $filename
     *
     * @return PromiseInterface PromiseInterface<null, Throwable>
     */
    public function import(string $filename): PromiseInterface
    {
        $this->logger->info("Importing emails from CSV file $filename.");
        $f = fopen($filename, 'r');
        $propertyNames = fgetcsv($f);

        $bufferSize = 500;
        $buffer = new SplObjectStorage();
        while ($row = fgetcsv($f)) {
            try {
                /** @noinspection PhpParamsInspection */
                $promise = $this->entityManager->persist(
                    $this->hydrationStrategy->hydrate(
                        array_combine($propertyNames, $row)));
                $buffer->attach($promise);
                if ($buffer->count() >= $bufferSize) {
                    usleep(10);

                    continue;
                }
                $promise->then(function () use ($promise, $buffer) {
                    $buffer->detach($promise);
                }, function ($error) use ($promise, $buffer) {
                    $buffer->detach($promise);

                    throw $error;
                });
            } catch (Throwable $e) {
                $this->logger->error('Failed importing row '.json_encode($row, JSON_UNESCAPED_UNICODE));
                $this->logger->debug("$e");
            }
        }
        fclose($f);

        return $this->entityManager->flush()
            ->then(function () {
                $this->logger->info('Import complete.');
            });
    }
}
