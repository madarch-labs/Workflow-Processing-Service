<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/processes/{id}', method: 'GET')]
final class GetProcessController extends BaseController
{
    protected function handle(): Response
    {
        $service = $this->container()->processService();
        return $this->ok($service->withReferences($service->get($this->intParam('id'))));
    }
}
