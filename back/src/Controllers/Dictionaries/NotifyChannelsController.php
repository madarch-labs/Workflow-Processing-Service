<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Channels of the notify action with the secret kinds of their connections (md/tasks_list/4.8-notify.md §7).
 */
#[Route(uri: '/api/dictionaries/notify-channels', method: 'GET')]
final class NotifyChannelsController extends BaseController
{
    protected function handle(): Response
    {
        return $this->ok($this->container()->notifyChannels()->dictionary());
    }
}
