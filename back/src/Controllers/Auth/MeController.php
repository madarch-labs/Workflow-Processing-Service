<?php

declare(strict_types=1);

namespace Wps\Controllers\Auth;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

#[Route(uri: '/api/auth/me', method: 'GET')]
final class MeController extends BaseController
{
    protected function handle(): Response
    {
        $user = $this->container()->users()->find($this->user()['id']);
        if ($user === null) {
            throw ApiException::unauthorized('User no longer exists', 'INVALID_TOKEN');
        }
        return $this->ok($user);
    }
}
