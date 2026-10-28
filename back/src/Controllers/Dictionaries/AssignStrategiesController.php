<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Actions\AssignHandler;
use Wps\Controllers\BaseController;

/**
 * Strategies of the assign action (md/tasks_list/4.9-assign.md §7).
 */
#[Route(uri: '/api/dictionaries/assign-strategies', method: 'GET')]
final class AssignStrategiesController extends BaseController
{
    protected function handle(): Response
    {
        $result = [];
        foreach (AssignHandler::STRATEGIES as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }
        return $this->ok($result);
    }
}
