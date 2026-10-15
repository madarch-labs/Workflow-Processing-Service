<?php

declare(strict_types=1);

namespace Wps\Controllers\Users;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/users', method: 'POST')]
final class CreateUserController extends BaseController
{
    protected function handle(): Response
    {
        $data = UserInput::validate($this->body(), true);
        $users = $this->container()->users();
        if ($users->emailTaken($data['email'])) {
            throw ApiException::conflict('EMAIL_EXISTS', 'A user with this email already exists');
        }
        return $this->ok($users->create($data['email'], $data['password'], $data['role']), 201);
    }
}
