<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Context\ExecutionContext;
use Wps\Controllers\BaseController;

/**
 * Read-only system variables sys.* for the "Variables" panel of the UI (md/tasks_list/5.2.9-system-variables.md §7).
 */
#[Route(uri: '/api/dictionaries/system-variables', method: 'GET')]
final class SystemVariablesController extends BaseController
{
    /** @var array<string, array{label: string, type: string, values?: list<string>}> */
    private const FIELDS = [
        'execution_id' => ['label' => 'ID выполнения', 'type' => 'string'],
        'parent_execution_id' => ['label' => 'ID родительского выполнения', 'type' => 'string'],
        'process_id' => ['label' => 'ID процесса', 'type' => 'integer'],
        'process_name' => ['label' => 'Название процесса', 'type' => 'string'],
        'process_version' => ['label' => 'Версия процесса', 'type' => 'integer'],
        'event_id' => ['label' => 'ID события', 'type' => 'string'],
        'trigger' => ['label' => 'Способ запуска', 'type' => 'string', 'values' => ['stream', 'manual', 'child']],
        'depth' => ['label' => 'Глубина вложенности', 'type' => 'integer'],
        'module' => ['label' => 'Модуль CRM', 'type' => 'string'],
        'record_id' => ['label' => 'ID записи', 'type' => 'string'],
        'event_type' => ['label' => 'Тип события', 'type' => 'string', 'values' => ['create', 'update', 'delete']],
        'changed_fields' => ['label' => 'Изменённые поля', 'type' => 'list'],
        'user_id' => ['label' => 'ID пользователя CRM', 'type' => 'string'],
        'now' => ['label' => 'Текущее время (UTC)', 'type' => 'datetime'],
        'started_at' => ['label' => 'Начало выполнения (UTC)', 'type' => 'datetime'],
        'last_error' => ['label' => 'Последняя обработанная ошибка', 'type' => 'object'],
        'errors_count' => ['label' => 'Обработано ошибок', 'type' => 'integer'],
    ];

    protected function handle(): Response
    {
        $result = [];
        foreach (ExecutionContext::SYS_FIELDS as $field) {
            $result[] = ['value' => ExecutionContext::SYS . ".{$field}"] + self::FIELDS[$field];
        }
        return $this->ok($result);
    }
}
