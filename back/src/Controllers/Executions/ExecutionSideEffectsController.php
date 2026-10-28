<?php

declare(strict_types=1);

namespace Wps\Controllers\Executions;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

/**
 * Side-effect journal of an execution, without responses (md/tasks_list/done/0.4-side-effect-ledger.md §7).
 */
#[Route(uri: '/api/executions/{id}/side-effects', method: 'GET')]
final class ExecutionSideEffectsController extends BaseController
{
    protected function handle(): Response
    {
        $id = ExecutionLookup::assertId($this->param('id'));
        $c = $this->container();
        $c->executions()->find($id) ?? throw ApiException::notFound('EXECUTION_NOT_FOUND', "Execution {$id} not found");
        return $this->ok($c->sideEffects()->list($id));
    }
}
