<?php

declare(strict_types=1);

namespace Wps\Controllers\Executions;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/executions/{id}/actions', method: 'GET')]
final class ExecutionActionsController extends BaseController
{
    protected function handle(): Response
    {
        $id = ExecutionLookup::assertId($this->param('id'));
        $c = $this->container();
        if ($c->executions()->status($id) === null) {
            throw ApiException::notFound('EXECUTION_NOT_FOUND', "Execution {$id} not found");
        }
        return $this->ok($c->audit()->steps($id));
    }
}
