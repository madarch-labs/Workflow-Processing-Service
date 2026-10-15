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
        return $this->ok($this->container()->dictionaries()->modules(refresh: true));
    }
}
