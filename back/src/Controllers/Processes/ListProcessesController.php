<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Persistence\Process;

#[Route(uri: '/api/processes', method: 'GET')]
final class ListProcessesController extends BaseController
{
    protected function handle(): Response
    {
        [$page, $perPage] = $this->pagination();
        [$items, $total] = $this->container()->processes()->paginate([
            'search' => $this->query('search'),
            'object_type' => $this->query('object_type'),
            'is_child' => $this->queryBool('is_child'),
            'is_active' => $this->queryBool('is_active'),
        ], $page, $perPage);
        return $this->ok(array_map(static fn (Process $p) => $p->toArray(), $items), 200, $this->meta($page, $perPage, $total));
    }
}
