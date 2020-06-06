<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use App\Entity\Email;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

class CsvBlockingExporter implements LoggerAwareInterface
{
    use \Psr\Log\LoggerAwareTrait;

    private ?array $headerRow = null;
    private HydrationStrategyInterface $hydrationStrategy;

    public function __construct(HydrationStrategyInterface $hydrationStrategy)
    {
        $this->hydrationStrategy = $hydrationStrategy;
        $this->logger = new NullLogger();
    }

    /**
     * @param array|null $headerRow
     */
    public function setHeaderRow(?array $headerRow): void
    {
        $this->headerRow = $headerRow;
    }

    /**
     * @param string                  $filename
     * @param ReadableStreamInterface $entityStream
     *
     * @return PromiseInterface PromiseInterface<null,Throwable>
     */
    public function export(string $filename, ReadableStreamInterface $entityStream): PromiseInterface
    {
        $deferred = new Deferred();
        $this->logger->info("Exporting emails to CSV file $filename.");
        $f = fopen($filename, 'w');
        if (null !== $this->headerRow) {
            fputcsv($f, $this->headerRow);
        }
        $entityStream->on('data', function (Email $email) use ($f) {
            fputcsv($f, $this->hydrationStrategy->dehydrate($email));
        });
        $entityStream->on('close', function () use (&$f, $deferred) {
            if ($f) {
                fclose($f);
                $f = null;
            }
            $this->logger->info('Export complete.');
            $deferred->resolve();
        });
        $entityStream->on('error', function (Exception $e) use (&$f, $deferred) {
            if ($f) {
                fclose($f);
                $f = null;
            }
            $this->logger->info('Export error.');
            $this->logger->debug("$e");
            $deferred->reject($e);
        });

        return $deferred->promise();
    }
}
