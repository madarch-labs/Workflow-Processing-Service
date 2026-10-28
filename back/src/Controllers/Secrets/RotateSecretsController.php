<?php

declare(strict_types=1);

namespace Wps\Controllers\Secrets;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\AuthContext;

/**
 * POST /api/secrets/rotate (md/tasks_list/done/7.4-secrets.md §7). The value of a secret is never returned.
 */
#[Route(uri: '/api/secrets/rotate', method: 'POST')]
final class RotateSecretsController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok(['reencrypted' => $this->container()->secrets()->rotate(AuthContext::user()['id'] ?? null)]);
    }
}
