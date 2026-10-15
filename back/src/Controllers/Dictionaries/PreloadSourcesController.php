<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Actions\PreloadHandler;

#[Route(uri: '/api/dictionaries/preload-sources', method: 'GET')]
final class PreloadSourcesController extends BaseController
{
    protected function handle(): Response
    {
        $items = [];
        foreach (PreloadHandler::SOURCES as $value => $label) {
            $items[] = ['value' => $value, 'label' => $label];
        }
        return $this->ok($items);
    }
}
