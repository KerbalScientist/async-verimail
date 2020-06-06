<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use App\Entity\Email;
use App\Stream\ThroughStream;
use App\Verifier\VerifyStatus;
use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\QueryInterface;
use Exception;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use React\MySQL\ConnectionInterface;
use React\MySQL\QueryResult;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use ReflectionClass;
use ReflectionProperty;
use function App\pipeThrough;
use function React\Promise\reject;
use function React\Promise\resolve;

class EmailEntityManager implements LoggerAwareInterface, EntityManagerInterface
{
    use LoggerAwareTrait;

    const DATE_FORMAT_DB = 'Y-m-d H:i:s';

    private string $tableName;
    private ConnectionInterface $readConnection;
    private ConnectionInterface $writeConnection;
    private MysqlQueryFactory $queryFactory;
    private HydrationStrategyInterface $hydrationStrategy;
    private ?PersistingStreamInterface $persistingStream = null;

    /**
     * EmailEntityManager constructor.
     *
     * @param string              $tableName
     * @param ConnectionInterface $readConnection
     * @param ConnectionInterface $writeConnection
     * @param MysqlQueryFactory   $queryFactory
     * @param mixed[]             $settings
     */
    public function __construct(
        string $tableName,
        ConnectionInterface $readConnection,
        ConnectionInterface $writeConnection,
        MysqlQueryFactory $queryFactory,
        array $settings = [])
    {
        $this->tableName = $tableName;
        $this->readConnection = $readConnection;
        $this->writeConnection = $writeConnection;
        $this->queryFactory = $queryFactory;
        $this->setLogger(new NullLogger());
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
            $query->limit((int) $filter['#limit']);
            unset($filter['#limit']);
        }
        if (isset($filter['#offset'])) {
            $query->offset((int) $filter['#offset']);
            unset($filter['#offset']);
        }
        foreach ($filter as $colName => $value) {
            if (!\in_array($colName, $colNames)) {
                continue;
            }
            if (!\is_array($value)) {
                /* @noinspection PhpMethodParametersCountMismatchInspection */
                $query->where("$colName = ?", $value);

                continue;
            }
            $operator = array_shift($value);
            if (!$operator || !\count($value)) {
                continue;
            }
            $negate = false;
            $value = array_shift($value);
            if ('NOT' === $operator) {
                /* @noinspection PhpMethodParametersCountMismatchInspection */
                $query->where("$colName <> ?", $value);

                continue;
            } elseif ('NOT ' === substr($operator, 0, \strlen('NOT '))) {
                $negate = true;
                $operator = trim(
                    substr($operator, \strlen('NOT '))
                );
            }
            if ('IN' === $operator) {
                $query->where(
                    $this->sqlNegate("$colName IN (:$colName)", $negate));
                $query->bindValue($colName, (array) $value);

                continue;
            } elseif (\in_array($operator, ['LIKE', '>', '<', '<=', '>='], true)) {
                $query->where(
                    $this->sqlNegate("$colName $operator :$colName", $negate));
                $query->bindValue($colName, (string) $value);

                continue;
            }

            throw new InvalidArgumentException("Unknown SQL operator '$operator'");
        }

        return $query;
    }

    /**
     * @return ReflectionProperty[]
     */
    private function getDbProperties(): array
    {
        static $result = null;
        if (!\is_null($result)) {
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
        $statusEnumValues = "'".implode("', '", VerifyStatus::all())."'";
        $sql = "
            CREATE TABLE `$this->tableName` (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                status ENUM($statusEnumValues),
                updated DATETIME,
                UNIQUE KEY email(email),
                KEY status(status)
            )
        ";
        $this->logger->debugQuery($sql);

        return $this->readConnection->query($sql);
    }

    public function uninstallSchema(): PromiseInterface
    {
        $sql = "DROP TABLE `$this->tableName`";
        $this->logger->debugQuery($sql);

        return $this->readConnection->query($sql);
    }

    public function countByQuery(SelectInterface $query): PromiseInterface
    {
        $sql = "SELECT count(*) FROM ({$query->getStatement()}) t";
        $bind = $this->getBindValues($query);
        $this->logger->debugQuery($sql, $bind);

        return $this->readConnection
            ->query($sql, $bind)
            ->then(function (QueryResult $result) {
                return (int) reset($result->resultRows[0]);
            });
    }

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

        return $result;
    }

    /**
     * @param QueryInterface $query
     *
     * @return mixed[]
     */
    private function getBindValues(QueryInterface $query): array
    {
        $result = [];
        foreach ($query->getBindValues() as $key => $value) {
            $result[":$key"] = $value;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function canPersistType(string $className): bool
    {
        return is_a($className, Email::class, true);
    }

    /**
     * {@inheritdoc}
     */
    public function persist(object $entity): PromiseInterface
    {
        if (!$entity instanceof Email) {
            return reject(new LogicException('Only Email instances can be persisted.'));
        }
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
            $stream->write($entity);
        } catch (Exception $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    public function createPersistingStream(): PersistingStreamInterface
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

    public function flush(): PromiseInterface
    {
        if ($this->persistingStream) {
            return $this->persistingStream->flush();
        }

        return resolve();
    }

    public function __destruct()
    {
        $this->flush();
    }
}
