<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use Aura\SqlQuery\Mysql\Delete;
use Aura\SqlQuery\Mysql\Insert;
use Aura\SqlQuery\Mysql\Select;
use Aura\SqlQuery\Mysql\Update;

class MysqlQueryFactory extends \Aura\SqlQuery\QueryFactory
{
    public function __construct()
    {
        parent::__construct('mysql', null);
    }

    /**
     * @return Delete
     */
    public function newDelete()
    {
        return parent::newDelete();
    }

    /**
     * @return Insert
     */
    public function newInsert()
    {
        return parent::newInsert();
    }

    /**
     * @return Select
     */
    public function newSelect()
    {
        return parent::newSelect();
    }

    /**
     * @return Update
     */
    public function newUpdate()
    {
        return parent::newUpdate();
    }
}
