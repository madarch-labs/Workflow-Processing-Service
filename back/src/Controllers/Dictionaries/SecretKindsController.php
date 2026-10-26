<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Secrets\SecretService;

/**
 * Kinds of secrets and their fields (md/tasks_list/done/7.4-secrets.md §7).
 */
#[Route(uri: '/api/dictionaries/secret-kinds', method: 'GET')]
final class SecretKindsController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok(SecretService::kinds());
    }
}
