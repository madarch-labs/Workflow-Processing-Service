<?php

declare(strict_types=1);

namespace Wps\Controllers\Secrets;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\AuthContext;

/**
 * PUT /api/secrets/{name} (md/tasks_list/done/7.4-secrets.md §7). The value of a secret is never returned.
 */
#[Route(uri: '/api/secrets/{name}', method: 'PUT')]
final class UpdateSecretController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok($this->container()->secrets()->update((string)$this->param('name'), $this->body(), AuthContext::user()['id'] ?? null));
    }
}
