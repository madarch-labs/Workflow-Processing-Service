<?php

declare(strict_types=1);

// Unit tests: pure logic only, no network, no Swoole server (doc/{ru,en}/07-developer-guide/testing.md).
require __DIR__ . '/../../vendor/autoload.php';

date_default_timezone_set('UTC');
