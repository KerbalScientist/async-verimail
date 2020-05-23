<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\DB;


use App\Entity\Email;
use App\Entity\VerifyStatus;
use App\Stream\ThroughStream;
use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\QueryFactory;
use Aura\SqlQuery\QueryInterface;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use React\MySQL\ConnectionInterface;
use React\MySQL\QueryResult;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use ReflectionClass;
use ReflectionProperty;
use SplObjectStorage;
use Throwable;
use function App\pipeThrough;
use function React\Promise\resolve;

class EmailEntityManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DATE_FORMAT_DB = 'Y-m-d H:i:s';

    private string $tableName;
    private ConnectionInterface $readConnection;
    private ConnectionInterface $writeConnection;
    private QueryFactory $queryFactory;
    private int $selectChunkSize;
    private HydrationStrategyInterface $hydrationStrategy;
    private ?EmailPersistingStream $persistingStream = null;

    /**
     * EmailEntityManager constructor.
     * @param string $tableName
     * @param ConnectionInterface $readConnection
     * @param ConnectionInterface $writeConnection
     * @param QueryFactory $queryFactory
     * @param array $settings
     */
    public function __construct(
        string $tableName,
        ConnectionInterface $readConnection,
        ConnectionInterface $writeConnection,
        QueryFactory $queryFactory,
        array $settings = [])
    {
        $this->tableName = $tableName;
        $this->readConnection = $readConnection;
        $this->writeConnection = $writeConnection;
        $this->queryFactory = $queryFactory;
        $this->setLogger(new NullLogger());
        $this->selectChunkSize = $settings['selectChunkSize'] ?? 500;
        $this->hydrationStrategy = $settings['hydrationStrategy']
            ?? new EmailHydrationStrategy(new ReflectionClass(Email::class));
    }

    public function createSelectQuery(array $filter = []): SelectInterface
    {
        $colNames = array_column($this->getDbProperties(), 'name');
        $query = $this->queryFactory->newSelect()
            ->from($this->tableName)
            ->cols($colNames);
        if (isset($filter['#limit'])) {
            $query->limit((int)$filter['#limit']);
            unset($filter['#limit']);
        }
        if (isset($filter['#offset'])) {
            $query->offset((int)$filter['#offset']);
            unset($filter['#offset']);
        }
        foreach ($filter as $colName => $value) {
            if (!in_array($colName, $colNames)) {
                continue;
            }
            if (!is_array($value)) {
                /** @noinspection PhpMethodParametersCountMismatchInspection */
                $query->where("$colName = ?", $value);
                continue;
            }
            $operator = array_shift($value);
            if (!$operator || !count($value)) {
                continue;
            }
            $negate = false;
            $value = array_shift($value);
            if ($operator === 'NOT') {
                /** @noinspection PhpMethodParametersCountMismatchInspection */
                $query->where("$colName <> ?", $value);
                continue;
            } else if (substr($operator, 0, strlen('NOT ')) === 'NOT ') {
                $negate = true;
                $operator = trim(
                    substr($operator, strlen('NOT '))
                );
            }
            if ($operator === 'IN') {
                $query->where(
                    $this->sqlNegate("$colName IN (:$colName)", $negate));
                $query->bindValue($colName, (array)$value);
                continue;
            } else if (
                $operator === 'LIKE'
                || $operator === '>'
                || $operator === '<'
                || $operator === '<='
                || $operator === '>='
            ) {
                $query->where(
                    $this->sqlNegate("$colName $operator :$colName", $negate));
                $query->bindValue($colName, (string)$value);
                continue;
            }
            throw new InvalidArgumentException("Unknown SQL operator '$operator'");
        }
        return $query;
    }

    private function getDbProperties()
    {
        static $result = null;
        if (!is_null($result)) {
            return $result;
        }
        $emailReflection = new ReflectionClass(Email::class);
        $result = array_filter(
            $emailReflection->getProperties(ReflectionProperty::IS_PUBLIC),
            function (ReflectionProperty $property) {
                return !$property->isStatic();
            }
        );
        return $result;
    }

    private function sqlNegate(string $condition, bool $negate = true): string
    {
        if ($negate) {
            return "NOT ($condition)";
        }
        return $condition;
    }

    public function installSchema(): PromiseInterface
    {
        $statusEnumValues = "'" . implode("', '", VerifyStatus::all()) . "'";
        $sql = "
            CREATE TABLE IF NOT EXISTS `$this->tableName` (
                i_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                m_mail VARCHAR(255) NOT NULL,
                s_status ENUM($statusEnumValues),
                dt_updated DATETIME,
                UNIQUE KEY m_mail(m_mail),
                KEY s_status(s_status)
            )
        ";
        $this->logger->debugQuery($sql);
        return $this->readConnection->query($sql);
    }

    public function exportToCsvBlocking(string $filename): PromiseInterface
    {
        $deferred = new Deferred();
        $this->logger->info("Exporting emails to CSV file $filename.");
        $f = null;
        $f = fopen($filename, 'w');
        $propertyNames = array_column($this->getDbProperties(), 'name');
        fputcsv($f, $propertyNames);
        $stream = $this->streamByStatus(VerifyStatus::all());
        $stream->on('data', function (Email $email) use ($f) {
            fputcsv($f, $this->hydrationStrategy->dehydrate($email));
        });
        $stream->on('end', function () use (& $f, $deferred) {
            if ($f) {
                fclose($f);
                $f = null;
            }
            $this->logger->info('Export complete.');
            $deferred->resolve();
        });
        $stream->on('close', function () use (& $f, $deferred) {
            if ($f) {
                fclose($f);
                $f = null;
            }
            $this->logger->info('Export complete.');
            $deferred->resolve();
        });
        $stream->on('error', function (Exception $e) use (& $f, $deferred) {
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

    /**
     * @param VerifyStatus[] $statusList
     * @return ReadableStreamInterface<Email>
     */
    public function streamByStatus(array $statusList): ReadableStreamInterface
    {
        $query = $this->queryFactory->newSelect();
        $query->from($this->tableName);
        $query->cols(array_column($this->getDbProperties(), 'name'));
        $query->where('s_status IN (:statusList)');
        $query->bindValue('statusList', array_map('strval', $statusList));
        return $this->streamByQuery($query);
    }

    /**
     * Creates stream of Email entities, selected by $query.
     *
     * @param SelectInterface $query
     * @return ReadableStreamInterface<Email>
     */
    public function streamByQuery(SelectInterface $query): ReadableStreamInterface
    {
        $sql = $query->getStatement();
        $params = $this->getBindValues($query);
        $this->logger->debugQuery($sql, $params);
        $result = new ThroughStream(function ($data) {
            return $this->hydrationStrategy->hydrate($data);
        });
        pipeThrough(
            $this->readConnection->queryStream($sql, $params),
            [],
            $result,
            [
                'error' => true,
                'closeToEnd' => true,
                'end' => false,
            ]
        );
//        $this->readConnection->queryStream($sql, $params)->pipe($result);
        return $result;
    }

    private function getBindValues(QueryInterface $query)
    {
        $result = [];
        foreach ($query->getBindValues() as $key => $value) {
            $result[":$key"] = $value;
        }
        return $result;
    }

    /**
     * @param string $filename
     * @return PromiseInterface
     * @todo Stream
     */
    public function importFromCsvBlocking(string $filename): PromiseInterface
    {
        $this->logger->info("Importing emails from CSV file $filename.");
        $f = fopen($filename, 'r');
        $propertyNames = fgetcsv($f);

        $bufferSize = 500;
        $buffer = new SplObjectStorage();
        while ($row = fgetcsv($f)) {
            try {
                /** @noinspection PhpParamsInspection */
                $promise = $this->persist(
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
                $this->logger->error('Failed importing row ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                $this->logger->debug("$e");
            }
        }
        fclose($f);
        return $this->flushPersist()->then(function () {
            $this->logger->info('Import complete.');
        });
    }

    /**
     *
     * @param Email $email
     * @return PromiseInterface<QueryResult>
     */
    public function persist(Email $email): PromiseInterface
    {
        $deferred = new Deferred();
        if (!$this->persistingStream) {
            $this->persistingStream = $this->createPersistingStream();
        }
        $stream = $this->persistingStream;
        $close = function () use ($deferred) {
            $deferred->resolve();
        };
        $error = function ($error) use ($deferred) {
            $deferred->reject($error);
        };
        $stream->once('close', $close);
        $stream->once('error', $error);
        $stream->once('drain', function () use ($stream, $deferred, $close, $error) {
            $stream->removeListener('close', $close);
            $stream->removeListener('error', $error);
            $deferred->resolve();
        });
        try {
            $stream->write($email);
        } catch (Exception $e) {
            $deferred->reject($e);
        }
        return $deferred->promise();
    }

    public function createPersistingStream(): EmailPersistingStream
    {
        $stream = new EmailPersistingStream(
            $this->writeConnection,
            $this->queryFactory,
            [
                'hydrationStrategy' => $this->hydrationStrategy,
                'tableName' => $this->tableName,
            ]
        );
        $stream->setLogger($this->logger);
        return $stream;
    }

    public function flushPersist(): PromiseInterface
    {
        if ($this->persistingStream) {
            return $this->persistingStream->flush();
        }
        return resolve();
    }

    public function __destruct()
    {
        $this->flushPersist();
    }
}
