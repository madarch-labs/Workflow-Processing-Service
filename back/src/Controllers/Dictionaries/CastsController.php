<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Types\CastType;

/**
 * Types for "cast" in conditions (md/tasks_list/done/3.0-type-cast.md §7).
 */
#[Route(uri: '/api/dictionaries/casts', method: 'GET')]
final class CastsController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok(array_map(static fn (CastType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'orderable' => $t->isOrderable(),
            'options' => $t->options(),
        ], CastType::cases()));
    }
}
