<?php

declare(strict_types=1);

namespace Wps\Controllers\Secrets;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\AuthContext;

/**
 * DELETE /api/secrets/{name} (md/tasks_list/done/7.4-secrets.md §7). The value of a secret is never returned.
 */
#[Route(uri: '/api/secrets/{name}', method: 'DELETE')]
final class DeleteSecretController extends BaseController
{
    protected function handle(): Response
    {
        $this->container()->secrets()->delete((string)$this->param('name'), AuthContext::user()['id'] ?? null);
        return $this->ok(['deleted' => true]);
    }
}
