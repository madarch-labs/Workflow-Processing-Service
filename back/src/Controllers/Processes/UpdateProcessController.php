<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

#[Route(uri: '/api/processes/{id}', method: 'PUT')]
final class UpdateProcessController extends BaseController
{
    protected function handle(): Response
    {
        $process = $this->container()->processService()->update($this->intParam('id'), $this->body(), $warnings);
        return $this->ok($process->toArray() + ($warnings !== [] ? ['warnings' => $warnings] : []));
    }
}
