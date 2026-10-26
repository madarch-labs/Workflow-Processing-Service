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
        // ?force=true (admin): waiting executions of the process are cancelled (7.2)
        $this->container()->processService()->delete($this->intParam('id'), $this->queryBool('force') === true);
        return $this->ok(['deleted' => true]);
    }
}
