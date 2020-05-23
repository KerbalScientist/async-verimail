<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

require dirname(__DIR__).'/vendor/autoload.php';
$app = new App();

return $app->run();
