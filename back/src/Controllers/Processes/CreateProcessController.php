<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/processes', method: 'POST')]
final class CreateProcessController extends BaseController
{
    protected function handle(): Response
    {
        $process = $this->container()->processService()->create($this->body(), $warnings);
        // non-blocking warnings (e.g. a variable set only in some branches) are returned next to the process
        return $this->ok($process->toArray() + ($warnings !== [] ? ['warnings' => $warnings] : []), 201);
    }
}
