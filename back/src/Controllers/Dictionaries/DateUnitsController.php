<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Time\DateUnit;

/**
 * Units of the date_shift change type (md/tasks_list/done/5.1.2-date-shift.md §7).
 */
#[Route(uri: '/api/dictionaries/date-units', method: 'GET')]
final class DateUnitsController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok(array_map(static fn (DateUnit $u) => ['value' => $u->value, 'label' => $u->label()], DateUnit::cases()));
    }
}
