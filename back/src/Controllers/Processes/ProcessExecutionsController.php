<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/processes/{id}/executions', method: 'GET')]
final class ProcessExecutionsController extends BaseController
{
    public const STATUSES = ['running', 'retrying', 'waiting', 'completed', 'failed', 'cancelled'];

    protected function handle(): Response
    {
        $process = $this->container()->processService()->get($this->intParam('id'));
        [$page, $perPage] = $this->pagination();

        $status = $this->query('status');
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw ApiException::validation([['field' => 'status', 'message' => 'Status must be one of: ' . implode(', ', self::STATUSES)]]);
        }
        $filters = ['status' => $status];
        foreach (['date_from', 'date_to'] as $key) {
            $value = $this->query($key);
            if ($value !== null && strtotime($value) === false) {
                throw ApiException::validation([['field' => $key, 'message' => 'Invalid date']]);
            }
            $filters[$key] = $value;
        }

        [$items, $total] = $this->container()->executions()->paginateByProcess($process->id, $filters, $page, $perPage);
        return $this->ok($items, 200, $this->meta($page, $perPage, $total));
    }
}
