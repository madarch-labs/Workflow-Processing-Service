<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/dictionaries/change-types', method: 'GET')]
final class ChangeTypesController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok($this->container()->changeTypes()->dictionary());
    }
}
