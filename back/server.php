<?php

declare(strict_types=1);

/**
 * WPS entry point: loads .env (real environment variables take precedence), maps the
 * TS §11.1 variables into the SwooleApp config and starts the HTTP server with CyclicJobs.
 */

use Wps\Kernel\Env;
use Wps\Kernel\WpsApplication;

require __DIR__ . '/vendor/autoload.php';

chdir(__DIR__);
Env::load(__DIR__ . '/.env');

$config = json_decode((string)file_get_contents(__DIR__ . '/config/config.json'));
if (!$config instanceof stdClass) {
    fwrite(STDERR, "config/config.json is not valid JSON\n");
    exit(1);
}

$config->APP_DEBUG = Env::bool('APP_DEBUG', (bool)($config->APP_DEBUG ?? false));
$config->server->host = Env::get('SERVER_HOST', $config->server->host);
$config->server->port = Env::int('SERVER_PORT', (int)$config->server->port);
$config->server->mode = Env::get('SERVER_MODE', $config->server->mode);
$config->SWOOLE->worker_num = Env::int('SWOOLE_WORKER_NUM', (int)$config->SWOOLE->worker_num);
$config->SWOOLE->task_worker_num = Env::int('SWOOLE_TASK_WORKER_NUM', (int)$config->SWOOLE->task_worker_num);
$config->SWOOLE->max_coroutine = Env::int('SWOOLE_MAX_COROUTINE', (int)$config->SWOOLE->max_coroutine);
// make PDO (pgsql), phpredis, sockets and sleep non-blocking inside coroutines
$config->SWOOLE->hook_flags = SWOOLE_HOOK_ALL;

$app = new WpsApplication($config);
$server = $app->createServer();
echo sprintf("[WPS] listening on %s:%d\n", $config->server->host, $config->server->port);
$server->start();
