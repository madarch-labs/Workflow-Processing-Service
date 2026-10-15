<?php

declare(strict_types=1);

namespace Wps\Kernel;

use Sidalex\SwooleApp\Application;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Wps\Http\JsonResponder;

/**
 * SwooleApp Application with three additions, made by extension instead of patching the framework:
 *  - exact route matching (WpsRoutesCollectionBuilder);
 *  - CORS preflight (OPTIONS) handling and JSON 404 in the API envelope;
 *  - a last-resort 500 response if a controller throws.
 * Global middlewares from config.json are applied by Wps\Controllers\BaseController.
 */
final class WpsApplication extends Application
{
    public function execute(Request $request, Response $response, Server $server): void
    {
        try {
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                JsonResponder::preflight($response);
                return;
            }
            $builder = new WpsRoutesCollectionBuilder($this->config);
            $route = $builder->searchInRoute($request, $this->routesCollection);
            if (empty($route)) {
                JsonResponder::error($response, 404, 'NOT_FOUND', 'Route not found');
                return;
            }
            $controller = $builder->getController($route, $request, $response);
            $controller->setApplication($this, $server);
            $controller->executeWithMiddlewares();
        } catch (\Throwable $e) {
            Container::get()->logger()->error('Unhandled request error', [
                'uri' => $request->server['request_uri'] ?? '', 'error' => $e,
            ]);
            if ($response->isWritable()) {
                JsonResponder::error($response, 500, 'INTERNAL_ERROR', 'Internal server error');
            }
        }
    }
}
