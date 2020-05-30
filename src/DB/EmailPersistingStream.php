<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use App\Entity\Email;
use Aura\SqlQuery\Mysql\Insert as MysqlInsert;
use Aura\SqlQuery\QueryInterface;
use DateTimeImmutable;
use Evenement\EventEmitterTrait;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use React\MySQL\ConnectionInterface;
use React\Promise\PromiseInterface;
use React\Stream\WritableStreamInterface;
use ReflectionClass;
use function React\Promise\all;
use function React\Promise\resolve;

class EmailPersistingStream implements WritableStreamInterface, LoggerAwareInterface
{
    use EventEmitterTrait;
    use LoggerAwareTrait;

    private int $insertBufferSize;
    private int $updateBufferSize;
    /**
     * @var Email[]
     */
    private array $insertBuffer = [];
    /**
     * @var Email[]
     */
    private array $updateBuffer = [];
    private ConnectionInterface $connection;
    private HydrationStrategyInterface $hydrationStrategy;
    private string $tableName;
    private bool $closed = false;
    private MysqlQueryFactory $queryFactory;
    private bool $bufferIsFull = false;
    private bool $insertIgnore;
    private bool $drain = false;

    /**
     * EmailPersistingStream constructor.
     *
     * @param ConnectionInterface $connection
     * @param MysqlQueryFactory   $queryFactory
     * @param mixed[]             $settings
     */
    public function __construct(
        ConnectionInterface $connection,
        MysqlQueryFactory $queryFactory,
        array $settings = []
    ) {
        $this->connection = $connection;
        $this->queryFactory = $queryFactory;
        $this->setLogger(new NullLogger());
        $connection->on('close', function () {
            $this->closed = true;
            $this->emit('close');
        });
        $connection->on('error', function ($error) {
            $this->closed = true;
            $this->emit('error', [$error]);
        });
        $this->insertBufferSize = $settings['insertBufferSize'] ?? 500;
        $this->updateBufferSize = $settings['updateBufferSize'] ?? 500;
        $this->insertIgnore = $settings['insertIgnore'] ?? true;
        $this->tableName = $settings['tableName'] ?? 'emails';
        $this->hydrationStrategy = $settings['hydrationStrategy']
            ?? new EmailHydrationStrategy(new ReflectionClass(Email::class));
    }

    /**
     * @param int $insertBufferSize
     */
    public function setInsertBufferSize(int $insertBufferSize): void
    {
        $this->flush();
        $this->insertBufferSize = $insertBufferSize;
    }

    public function flush(): PromiseInterface
    {
        return all([
            $this->flushUpdateBuffer(),
            $this->flushInsertBuffer(),
        ]);
    }

    private function flushUpdateBuffer(): PromiseInterface
    {
        if (!$this->updateBuffer) {
            $this->emit('drain');

            return resolve();
        }
        $query = $this->createInsertQuery($this->updateBuffer);
        $query->onDuplicateKeyUpdate('s_status', 'VALUES(s_status)');
        $query->onDuplicateKeyUpdate('dt_updated', 'VALUES(dt_updated)');
        $this->updateBuffer = [];
        $params = $this->getBindValues($query);
        $sql = $query->getStatement();
        $this->logger->debugQuery($sql, $params);

        return $this->connection->query($sql, $params)->then(function () {
            $this->bufferIsFull = false;
            if ($this->drain) {
                $this->drain = false;
                $this->emit('drain');
            }
        }, function ($e) {
            $this->logger->error('Error while flushing update buffer.');
            $this->logger->debug("$e");
            $this->emit('error', [$e]);
        });
    }

    private function createInsertQuery(array $emails): MysqlInsert
    {
        $query = $this->queryFactory->newInsert();
        $query->into($this->tableName);
        foreach ($emails as $email) {
            $email->dt_updated = new DateTimeImmutable();
            $row = $this->hydrationStrategy->dehydrate($email);
            $query->addRow($row);
        }
        $query->addRow();

        return $query;
    }

//    public function

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

    private function flushInsertBuffer(): PromiseInterface
    {
        if (!$this->insertBuffer) {
            if ($this->drain) {
                $this->drain = false;
                $this->emit('drain');
            }

            return resolve();
        }
        $query = $this->createInsertQuery($this->insertBuffer);
        if ($this->insertIgnore) {
            $query->ignore(true);
        }
        /**
         * Cannot get insert ID's when INSERT IGNORE is used.
         */
        $buffer = $this->insertIgnore ? [] : $this->insertBuffer;
        $this->insertBuffer = [];
        $params = $this->getBindValues($query);
        $sql = $query->getStatement();
        $this->logger->debugQuery($sql, $params);
        /*
         * @var $result QueryResult
         */
        return $this->connection->query($sql, $params)
            ->then(function ($result) use ($buffer) {
                $id = $result->insertId;
                foreach (array_reverse($buffer) as $email) {
                    /*
                     * @var $email Email
                     */
                    $email->i_id = $id--;
                }
                $this->bufferIsFull = false;
                if ($this->drain) {
                    $this->drain = false;
                    $this->emit('drain');
                }

                return $result;
            }, function ($e) {
                $this->logger->error('Error while flushing insert buffer.');
                $this->logger->debug("$e");
                $this->emit('error', [$e]);
            });
    }

    /**
     * @param int $updateBufferSize
     */
    public function setUpdateBufferSize(int $updateBufferSize): void
    {
        $this->flush();
        $this->updateBufferSize = $updateBufferSize;
    }

    /**
     * {@inheritdoc}
     */
    public function end($data = null)
    {
        if (!is_null($data)) {
            $this->write($data);
        }
        $this->close();
    }

    /**
     * {@inheritdoc}
     */
    public function write($data)
    {
        if (!$this->isWritable()) {
            return false;
        }
        if (!$data instanceof Email) {
            $this->emit('error', [
                new InvalidArgumentException('Invalid Email entity given.'),
            ]);
        }
        if (is_null($data->i_id)) {
            $this->insertBuffer[] = $data;
        } else {
            $this->updateBuffer[] = $data;
        }
        if (count($this->insertBuffer) >= $this->insertBufferSize) {
            $this->bufferIsFull = true;
            $this->flushInsertBuffer();
        }
        if (count($this->updateBuffer) >= $this->updateBufferSize) {
            $this->bufferIsFull = true;
            $this->flushUpdateBuffer();
        }
        if ($this->bufferIsFull) {
            $this->drain = true;

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable()
    {
        return !$this->closed;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $emit = function () {
            $this->emit('close');
        };
        $this->flush()->then($emit, $emit);
    }

    public function __destruct()
    {
        $this->close();
    }
}
