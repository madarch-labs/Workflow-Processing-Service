<?php

declare(strict_types=1);

namespace Wps\Controllers\Users;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/users/{id}', method: 'DELETE')]
final class DeleteUserController extends BaseController
{
    protected function handle(): Response
    {
        $id = $this->intParam('id');
        $users = $this->container()->users();
        $user = $users->find($id) ?? throw ApiException::notFound('USER_NOT_FOUND', "User with id {$id} not found");
        if ($id === $this->user()['id']) {
            throw ApiException::conflict('CANNOT_DELETE_SELF', 'You cannot delete your own account');
        }
        if ($user['role'] === 'admin' && $users->countAdmins() <= 1) {
            throw ApiException::conflict('LAST_ADMIN', 'The last administrator cannot be deleted');
        }
        $users->delete($id);
        return $this->ok(['deleted' => true]);
    }
}
