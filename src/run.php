<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use Exception;

require \dirname(__DIR__).'/vendor/autoload.php';

try {
    (new Application())->run();
} catch (Exception $e) {
    echo "$e";
}
