<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */


namespace App\DB;


interface HydrationStrategyInterface
{
    /**
     * Creates entity object from DB row.
     *
     * @param array $row DB row.
     * @return object Entity object.
     */
    public function hydrate(array $row): object;

    /**
     * Creates DB row from entity object.
     *
     * @param object $entity Entity object.
     * @return array DB row.
     */
    public function dehydrate(object $entity): array;
}
