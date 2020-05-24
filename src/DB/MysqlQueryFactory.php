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

    public function newDelete(): Delete
    {
        return $this->newInstance('Delete');
    }

    public function newInsert(): Insert
    {
        return $this->newInstance('Insert');
    }

    public function newSelect(): Select
    {
        return $this->newInstance('Select');
    }

    public function newUpdate(): Update
    {
        return $this->newInstance('Update');
    }

    /**
     * @param string $query
     *
     * @return Delete|Insert|Select|Update
     */
    protected function newInstance($query)
    {
        $class = "Aura\SqlQuery\\{$this->db}\\{$query}";

        return new $class(
            $this->getQuoter(),
            $this->newSeqBindPrefix()
        );
    }
}
