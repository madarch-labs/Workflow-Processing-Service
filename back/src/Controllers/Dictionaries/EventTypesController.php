<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Event types for "trigger_on" of a process (md/tasks_list/done/6.2-event-type.md §7).
 */
#[Route(uri: '/api/dictionaries/event-types', method: 'GET')]
final class EventTypesController extends BaseController
{
    private const LABELS = ['create' => 'Создание записи', 'update' => 'Изменение записи', 'delete' => 'Удаление записи'];

    protected function handle(): Response
    {
        return $this->ok(array_map(static fn (string $type, string $label) => ['value' => $type, 'label' => $label], array_keys(self::LABELS), self::LABELS));
    }
}
