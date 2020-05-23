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
        return $this->newInstance('Delete');
    }

    /**
     * @return Insert
     */
    public function newInsert()
    {
        return $this->newInstance('Insert');
    }

    /**
     * @return Select
     */
    public function newSelect()
    {
        return $this->newInstance('Select');
    }

    /**
     * @return Update
     */
    public function newUpdate()
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
