<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

/**
 * Removes a run_once mark: the next event of the record starts the process again.
 */
#[Route(uri: '/api/processes/{id}/runs/{object_type}/{object_id}', method: 'DELETE')]
final class ResetProcessRunController extends BaseController
{
    protected function handle(): Response
    {
        $process = $this->container()->processService()->get($this->intParam('id'));
        $objectType = rawurldecode($this->param('object_type'));
        $objectId = rawurldecode($this->param('object_id'));
        if (!$this->container()->processRuns()->release($process->id, $objectType, $objectId)) {
            throw ApiException::notFound('NOT_FOUND', "Process {$process->id} has no run mark for {$objectType} {$objectId}");
        }
        $this->container()->logger()->info('run_once mark reset', [
            'process_id' => $process->id, 'object_type' => $objectType, 'object_id' => $objectId,
            'user' => $this->user()['email'],
        ]);
        return $this->ok(['deleted' => true]);
    }
}
