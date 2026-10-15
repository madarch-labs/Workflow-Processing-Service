<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/processes/{id}', method: 'DELETE')]
final class DeleteProcessController extends BaseController
{
    protected function handle(): Response
    {
        $this->container()->processService()->delete($this->intParam('id'));
        return $this->ok(['deleted' => true]);
    }
}
