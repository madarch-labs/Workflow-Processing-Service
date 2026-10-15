<?php

declare(strict_types=1);

namespace Wps\Controllers\Auth;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/auth/login', method: 'POST')]
final class LoginController extends BaseController
{
    protected function handle(): Response
    {
        $body = $this->body();
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $errors = [];
        if ($email === '') {
            $errors[] = ['field' => 'email', 'message' => 'Email is required'];
        }
        if ($password === '') {
            $errors[] = ['field' => 'password', 'message' => 'Password is required'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $users = $this->container()->users();
        $row = $users->findByEmail($email);
        if ($row === null || !password_verify($password, (string)$row['password_hash'])) {
            throw ApiException::unauthorized('Invalid email or password', 'INVALID_CREDENTIALS');
        }
        $user = $users::toPublic($row);
        return $this->ok($this->container()->jwt()->issuePair($user) + ['user' => $user]);
    }
}
