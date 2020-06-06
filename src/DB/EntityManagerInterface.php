<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use App\Entity\Email;
use Aura\SqlQuery\Common\SelectInterface;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

interface EntityManagerInterface
{
    public function createSelectQuery(array $filter = []): SelectInterface;

    public function installSchema(): PromiseInterface;

    public function uninstallSchema(): PromiseInterface;

    /**
     * Returns promise, which will be fulfilled by query rows count.
     *
     * Rows will be counted with respect to LIMIT and OFFSET, so for query
     *  with LIMIT 100 it will always return 100 if total count of matching rows is more than 100.
     *
     * @param SelectInterface $query
     *
     * @return PromiseInterface PromiseInterface<int, Exception>
     */
    public function countByQuery(SelectInterface $query): PromiseInterface;

    /**
     * Creates stream of Email entities, selected by $query.
     *
     * @param SelectInterface $query
     *
     * @return ReadableStreamInterface readable stream of Email entities
     */
    public function streamByQuery(SelectInterface $query): ReadableStreamInterface;

    /**
     * Persists entity.
     *
     * @param object $entity
     *
     * @return PromiseInterface PromiseInterface<null,Throwable>
     */
    public function persist(object $entity): PromiseInterface;

    /**
     * Checks if instances of given class can be persisted by this entity manager.
     *
     * @param string $className
     *
     * @return bool
     */
    public function canPersistType(string $className): bool;

    public function createPersistingStream(): PersistingStreamInterface;

    public function flush(): PromiseInterface;
}
