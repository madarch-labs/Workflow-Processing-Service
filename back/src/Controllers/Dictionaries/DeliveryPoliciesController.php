<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\SideEffects\DeliveryPolicy;

/**
 * "delivery" of actions with external side effects (md/tasks_list/done/0.4-side-effect-ledger.md §7).
 */
#[Route(uri: '/api/dictionaries/delivery-policies', method: 'GET')]
final class DeliveryPoliciesController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok(DeliveryPolicy::dictionary());
    }
}
