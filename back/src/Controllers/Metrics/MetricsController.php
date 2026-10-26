<?php

declare(strict_types=1);

namespace Wps\Controllers\Metrics;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\JsonResponder;

/**
 * Prometheus metrics (TS §12.2), text exposition format.
 */
#[Route(uri: '/metrics', method: 'GET')]
final class MetricsController extends BaseController
{
    protected function handle(): Response
    {
        $c = $this->container();
        $gauges = [];
        try {
            $info = $c->streamInfo()->info();
            $gauges['wps_redis_stream_lag'] = $info['lag'] ?? 0;
            $gauges['wps_redis_stream_pending'] = $info['pending'];
        } catch (\Throwable) {
        }
        try {
            $gauges['wps_executions_waiting'] = $c->executionWaits()->count();
        } catch (\Throwable) {
        }
        return JsonResponder::text($this->response, $c->metrics()->render($gauges), 'text/plain; version=0.0.4; charset=utf-8');
    }
}
