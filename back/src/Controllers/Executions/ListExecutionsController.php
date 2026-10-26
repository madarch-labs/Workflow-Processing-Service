<?php

declare(strict_types=1);

namespace Wps\Controllers\Executions;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Controllers\Processes\ProcessExecutionsController;
use Wps\Http\ApiException;

/**
 * GET /api/executions: executions of every process, e.g. ?status=waiting&due_before=… for the "waiting" screen
 * (md/tasks_list/done/7.2-waiting-executions.md §4.6).
 */
#[Route(uri: '/api/executions', method: 'GET')]
final class ListExecutionsController extends BaseController
{
    protected function handle(): Response
    {
        [$page, $perPage] = $this->pagination();
        $status = $this->query('status');
        if ($status !== null && $status !== '' && !in_array($status, ProcessExecutionsController::STATUSES, true)) {
            throw ApiException::validation([['field' => 'status', 'message' => 'Status must be one of: ' . implode(', ', ProcessExecutionsController::STATUSES)]]);
        }
        $dueBefore = $this->query('due_before');
        if ($dueBefore !== null && $dueBefore !== '' && strtotime($dueBefore) === false) {
            throw ApiException::validation([['field' => 'due_before', 'message' => 'Invalid date']]);
        }
        [$items, $total] = $this->container()->executions()->paginate([
            'status' => $status,
            'process_id' => $this->query('process_id'),
            'due_before' => $dueBefore,
            'date_from' => $this->query('date_from'),
            'date_to' => $this->query('date_to'),
        ], $page, $perPage);
        return $this->ok($items, 200, $this->meta($page, $perPage, $total));
    }
}
