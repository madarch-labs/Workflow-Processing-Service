<?php

declare(strict_types=1);

namespace Wps\Controllers\Users;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/users/{id}', method: 'GET')]
final class GetUserController extends BaseController
{
    protected function handle(): Response
    {
        $id = $this->intParam('id');
        $user = $this->container()->users()->find($id) ?? throw ApiException::notFound('USER_NOT_FOUND', "User with id {$id} not found");
        return $this->ok($user);
    }
}
