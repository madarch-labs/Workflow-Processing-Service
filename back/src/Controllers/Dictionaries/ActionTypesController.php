<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/dictionaries/action-types', method: 'GET')]
final class ActionTypesController extends BaseController
{
    protected function handle(): Response
    {
        // actions and control-flow blocks share the "type" field of a definition row; "kind" tells them apart
        $c = $this->container();
        $actions = array_map(static fn (array $a) => $a + ['kind' => 'action'], $c->actions()->dictionary());
        return $this->ok(array_merge($actions, $c->blocks()->dictionary()));
    }
}
