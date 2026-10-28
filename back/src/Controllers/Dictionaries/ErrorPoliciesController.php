<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Values of "on_error" of a step (md/tasks_list/done/7.1-on-error.md §7).
 */
#[Route(uri: '/api/dictionaries/error-policies', method: 'GET')]
final class ErrorPoliciesController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok([
            ['value' => 'stop', 'label' => 'Остановить процесс'],
            ['value' => 'continue', 'label' => 'Продолжить'],
            ['value' => 'goto', 'label' => 'Перейти к шагу'],
        ]);
    }
}
