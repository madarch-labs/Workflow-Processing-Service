<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Classes of errors for "retry.retry_on" (md/tasks_list/done/7.1-on-error.md §4.2); a 3-digit HTTP code is allowed too.
 */
#[Route(uri: '/api/dictionaries/retry-classes', method: 'GET')]
final class RetryClassesController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok([
            ['value' => 'transient', 'label' => 'Временные ошибки: сеть, таймаут, 5xx, 408/425/429 (по умолчанию)'],
            ['value' => '5xx', 'label' => 'Ответы HTTP 5xx'],
            ['value' => '4xx', 'label' => 'Ответы HTTP 4xx'],
            ['value' => 'any', 'label' => 'Любая ошибка, кроме ошибок конфигурации и неизвестного исхода'],
        ]);
    }
}
