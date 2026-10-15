<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * run_once marks of a process: records the process has already been started for (md/idempotentie_plan.md).
 */
#[Route(uri: '/api/processes/{id}/runs', method: 'GET')]
final class ProcessRunsController extends BaseController
{
    protected function handle(): Response
    {
        $process = $this->container()->processService()->get($this->intParam('id'));
        [$page, $perPage] = $this->pagination();
        [$items, $total] = $this->container()->processRuns()->paginate($process->id, $this->query('object_id'), $page, $perPage);
        return $this->ok($items, 200, $this->meta($page, $perPage, $total));
    }
}
