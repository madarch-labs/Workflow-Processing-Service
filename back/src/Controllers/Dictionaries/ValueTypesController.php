<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Services\ConditionEngineService;

/**
 * Kinds of the right side of a condition: static value, field, expression (md/tasks_list/done/3.10-expression-value.md §7).
 */
#[Route(uri: '/api/dictionaries/value-types', method: 'GET')]
final class ValueTypesController extends BaseController
{
    protected function handle(): Response
    {
        $result = [];
        foreach (ConditionEngineService::VALUE_TYPES as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }
        return $this->ok($result);
    }
}
