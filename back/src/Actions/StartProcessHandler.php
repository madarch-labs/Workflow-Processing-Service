<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Persistence\ProcessRepository;
use Wps\Services\ExecutionEngineService;
use Wps\Services\Validation\DefinitionScope;

/**
 * Starts one or several child processes asynchronously (each in its own coroutine) and does not
 * wait for them (customer answer #13). The child receives the event and all variables (answer #20).
 */
final class StartProcessHandler implements ActionHandlerInterface
{
    /**
     * @param \Closure(): ExecutionEngineService $engine lazy, the engine itself depends on the action registry
     */
    public function __construct(
        private readonly ProcessRepository $processes,
        private readonly \Closure $engine,
        private readonly int $maxDepth,
    ) {
    }

    public function type(): string
    {
        return 'start_process';
    }

    public function label(): string
    {
        return 'Запустить следующий процесс';
    }

    public function description(): string
    {
        return 'Асинхронно запускает ведомый процесс, передавая ему событие и все переменные';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $ids = self::processIds($actionConfig);
        if ($ids === []) {
            throw new ConfigurationException('start_process: process_id is required');
        }
        if ($context->depth + 1 > $this->maxDepth) {
            throw new NonRecoverableException("Maximum child process depth ({$this->maxDepth}) exceeded");
        }

        $children = [];
        foreach ($ids as $id) {
            $process = $this->processes->find($id);
            if ($process === null) {
                throw new NonRecoverableException("Child process {$id} not found");
            }
            if (!$process->isChild) {
                throw new NonRecoverableException("Process {$id} \"{$process->name}\" is not a child process");
            }
            $children[] = $process;
        }

        $started = [];
        foreach ($children as $process) {
            if (!$process->isActive) {
                $started[] = ['process_id' => $process->id, 'name' => $process->name, 'status' => 'skipped', 'reason' => 'Process is inactive'];
                continue;
            }
            ($this->engine)()->spawnChild($process, $context);
            $started[] = ['process_id' => $process->id, 'name' => $process->name, 'status' => 'started'];
        }

        return ActionResult::success(['process_ids' => $ids], ['children' => $started]);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $raw = $actionConfig['process_ids'] ?? (isset($actionConfig['process_id']) ? [$actionConfig['process_id']] : []);
        $field = isset($actionConfig['process_ids']) ? "{$path}.process_ids" : "{$path}.process_id";
        if (!is_array($raw) || $raw === []) {
            $scope->error($field, 'Child process is required');
            return;
        }
        foreach ($raw as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                $scope->error($field, 'Process id must be an integer');
                continue;
            }
            $id = (int)$id;
            if ($scope->processId !== null && $id === $scope->processId) {
                $scope->error($field, 'A process cannot start itself');
                continue;
            }
            $scope->addChildReference($id, $field);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return int[]
     */
    public static function processIds(array $config): array
    {
        $raw = $config['process_ids'] ?? (isset($config['process_id']) ? [$config['process_id']] : []);
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int)$id;
            }
        }
        return array_values(array_unique($ids));
    }
}
