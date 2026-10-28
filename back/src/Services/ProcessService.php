<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Http\ApiException;
use Wps\Persistence\ExecutionWaitRepository;
use Wps\Persistence\Process;
use Wps\Persistence\ProcessRepository;

/**
 * Process CRUD used by the REST controllers (validation + cache invalidation).
 */
final class ProcessService
{
    public function __construct(
        private readonly ProcessRepository $processes,
        private readonly ProcessValidatorService $validator,
        private readonly ProcessResolverService $resolver,
        private readonly ?ExecutionWaitRepository $waits = null,
    ) {
    }

    public function get(int $id): Process
    {
        return $this->processes->find($id) ?? throw ApiException::notFound('PROCESS_NOT_FOUND', "Process with id {$id} not found");
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array{field: string, message: string}>|null $warnings non-blocking validation warnings
     */
    public function create(array $input, ?array &$warnings = null): Process
    {
        $data = $this->validator->validate($input);
        $warnings = $data['warnings'];
        unset($data['warnings']);
        $process = $this->processes->create($data);
        $this->resolver->invalidate($process->objectType);
        return $process;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array{field: string, message: string}>|null $warnings non-blocking validation warnings
     */
    public function update(int $id, array $input, ?array &$warnings = null): Process
    {
        $current = $this->get($id);
        // a client that does not know the field (old UI) must not reset it
        $input['trigger_on'] ??= $current->triggerOn;
        $data = $this->validator->validate($input, $id);
        $warnings = $data['warnings'];
        unset($data['warnings']);
        if ($current->isChild && !$data['is_child']) {
            $parents = $this->processes->findParents($id);
            if ($parents !== []) {
                throw ApiException::validation([[
                    'field' => 'is_child',
                    'message' => 'The process is started by other processes (' . implode(', ', array_column($parents, 'name')) . ') and must stay a child process',
                ]]);
            }
        }
        $process = $this->processes->update($id, $data) ?? throw ApiException::notFound('PROCESS_NOT_FOUND', "Process with id {$id} not found");
        $this->resolver->invalidate($current->objectType, $process->objectType);
        return $process;
    }

    public function delete(int $id, bool $force = false): void
    {
        $process = $this->get($id);
        $waiting = $this->waits?->countByProcess($id) ?? 0;
        if ($waiting > 0 && !$force) {
            throw ApiException::conflict('PROCESS_HAS_WAITING_EXECUTIONS',
                "The process has {$waiting} waiting executions; delete with force=true to cancel them", ['waiting' => $waiting]);
        }
        $parents = $this->processes->findParents($id);
        if ($parents !== []) {
            throw ApiException::conflict('PROCESS_IN_USE', 'The process is started by other processes', $parents);
        }
        if ($waiting > 0) {
            $this->waits?->cancelByProcess($id, 'cancelled', 'Process deleted');
        }
        $this->processes->softDelete($id);
        $this->resolver->invalidate($process->objectType);
    }

    public function clone(int $id): Process
    {
        $source = $this->get($id);
        $process = $this->processes->create([
            'name' => mb_substr($source->name . ' (копия)', 0, 255),
            'object_type' => $source->objectType,
            'is_child' => $source->isChild,
            'is_active' => false,
            'priority' => $source->priority,
            'run_once' => $source->runOnce, // the flag is copied, the marks of the source process are not
            'trigger_on' => $source->triggerOn,
            'definition' => $source->definition,
        ]);
        $this->resolver->invalidate($process->objectType);
        return $process;
    }

    /**
     * @return array<string, mixed> process + references to children/parents for the UI
     */
    public function withReferences(Process $process): array
    {
        $childIds = [];
        foreach ($process->definition['actions'] as $action) {
            if (is_array($action) && ($action['type'] ?? null) === 'start_process') {
                array_push($childIds, ...\Wps\Actions\StartProcessHandler::processIds($action));
            }
        }
        $children = [];
        foreach ($this->processes->findMany($childIds) as $child) {
            $children[] = ['id' => $child->id, 'name' => $child->name, 'is_active' => $child->isActive, 'is_child' => $child->isChild];
        }
        return $process->toArray() + ['references' => [
            'children' => $children,
            'parents' => $this->processes->findParents($process->id),
        ]];
    }
}
