<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/processes', method: 'POST')]
final class CreateProcessController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok($this->container()->processService()->create($this->body())->toArray(), 201);
    }
}
