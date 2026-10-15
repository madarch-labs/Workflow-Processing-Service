<?php

declare(strict_types=1);

namespace Wps\Controllers\Users;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/users', method: 'GET')]
final class ListUsersController extends BaseController
{
    protected function handle(): Response
    {
        [$page, $perPage] = $this->pagination(50);
        [$items, $total] = $this->container()->users()->paginate($page, $perPage);
        return $this->ok($items, 200, $this->meta($page, $perPage, $total));
    }
}
