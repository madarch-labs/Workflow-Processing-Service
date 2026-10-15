<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/dictionaries/operators', method: 'GET')]
final class OperatorsController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok($this->container()->comparators()->dictionary());
    }
}
