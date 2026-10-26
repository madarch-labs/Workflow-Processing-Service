<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Template filters: {{ x | f:a:b }} calls the expression function f(x, a, b), plus "raw" (md/tasks_list/done/5.1.1-template.md §7).
 */
#[Route(uri: '/api/dictionaries/template-filters', method: 'GET')]
final class TemplateFiltersController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok($this->container()->templates()->filterDictionary());
    }
}
