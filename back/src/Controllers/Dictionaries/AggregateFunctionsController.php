<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Actions\ChangeTypes\AggregateChangeType;
use Wps\Controllers\BaseController;

/**
 * Functions of the aggregate change type (md/tasks_list/5.1.7-aggregate.md §7).
 */
#[Route(uri: '/api/dictionaries/aggregate-functions', method: 'GET')]
final class AggregateFunctionsController extends BaseController
{
    protected function handle(): Response
    {
        $result = [];
        foreach (AggregateChangeType::FUNCTIONS as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }
        return $this->ok($result);
    }
}
