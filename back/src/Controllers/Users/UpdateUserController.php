<?php

declare(strict_types=1);

namespace Wps\Controllers\Users;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/users/{id}', method: 'PUT')]
final class UpdateUserController extends BaseController
{
    protected function handle(): Response
    {
        $id = $this->intParam('id');
        $users = $this->container()->users();
        $current = $users->find($id) ?? throw ApiException::notFound('USER_NOT_FOUND', "User with id {$id} not found");
        $data = UserInput::validate($this->body(), false);

        if (isset($data['email']) && $users->emailTaken($data['email'], $id)) {
            throw ApiException::conflict('EMAIL_EXISTS', 'A user with this email already exists');
        }
        if (($data['role'] ?? 'admin') !== 'admin' && $current['role'] === 'admin' && $users->countAdmins() <= 1) {
            throw ApiException::conflict('LAST_ADMIN', 'The last administrator cannot lose the admin role');
        }
        return $this->ok($users->update($id, $data));
    }
}
