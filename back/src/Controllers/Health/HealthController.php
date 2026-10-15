<?php

declare(strict_types=1);

namespace Wps\Controllers\Health;

use Redis;
use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/health', method: 'GET')]
final class HealthController extends BaseController
{
    protected function handle(): Response
    {
        $c = $this->container();
        $checks = [
            'postgres' => $this->check(static fn () => $c->db()->fetchValue('SELECT 1')),
            'redis_data' => $this->check(static fn () => $c->redisData()->run(static fn (Redis $r) => $r->ping())),
            'redis_stream' => $this->check(static fn () => $c->redisStream()->run(static fn (Redis $r) => $r->ping())),
        ];
        $ok = !in_array('error', array_column($checks, 'status'), true);
        $stream = null;
        if ($checks['redis_stream']['status'] === 'ok') {
            try {
                $stream = $c->streamInfo()->info();
            } catch (\Throwable) {
            }
        }
        return $this->ok([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'stream' => $stream,
            'time' => gmdate(\DATE_ATOM),
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{status: string, latency_ms?: int, error?: string}
     */
    private function check(callable $probe): array
    {
        $started = microtime(true);
        try {
            $probe();
            return ['status' => 'ok', 'latency_ms' => (int)round((microtime(true) - $started) * 1000)];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
}
