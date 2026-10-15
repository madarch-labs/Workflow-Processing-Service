<?php

declare(strict_types=1);

namespace Wps\Controllers\Auth;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Auth\JwtService;
use Wps\Http\ApiException;

#[Route(uri: '/api/auth/refresh', method: 'POST')]
final class RefreshController extends BaseController
{
    protected function handle(): Response
    {
        $token = $this->body()['refresh_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw ApiException::unauthorized('Refresh token is required', 'INVALID_TOKEN');
        }
        try {
            $claims = $this->container()->jwt()->verify($token, JwtService::REFRESH);
        } catch (ApiException) {
            throw ApiException::unauthorized('Invalid or expired refresh token', 'INVALID_TOKEN');
        }
        // the user may have been deleted or their role changed since the token was issued
        $user = $this->container()->users()->find((int)$claims['sub']);
        if ($user === null) {
            throw ApiException::unauthorized('User no longer exists', 'INVALID_TOKEN');
        }
        return $this->ok($this->container()->jwt()->issuePair($user) + ['user' => $user]);
    }
}
