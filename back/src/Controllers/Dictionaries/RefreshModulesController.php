<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/dictionaries/modules/refresh', method: 'POST')]
final class RefreshModulesController extends BaseController
{
    protected function handle(): Response
    {
        $c = $this->container();
        try {
            $c->crmCapabilities()->describe(refresh: true); // the plugin may have been updated together with the modules
        } catch (\Throwable $e) {
            $c->logger()->warning('CRM capabilities refresh failed', ['error' => $e->getMessage()]);
        }
        return $this->ok($c->dictionaries()->modules(refresh: true));
    }
}
