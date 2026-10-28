<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Types\RelativeDate;

/**
 * Anchors and units of relative dates in conditions and filters (md/tasks_list/done/3.7-relative-dates.md §7).
 */
#[Route(uri: '/api/dictionaries/relative-dates', method: 'GET')]
final class RelativeDatesController extends BaseController
{
    protected function handle(): Response
    {
        $list = static fn (array $map) => array_map(static fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys($map), array_values($map));
        return $this->ok([
            'anchors' => $list(RelativeDate::ANCHORS),
            'units' => $list(RelativeDate::UNITS),
            'timezone' => $this->container()->typeCaster()->businessTimezone()->getName(),
        ]);
    }
}
