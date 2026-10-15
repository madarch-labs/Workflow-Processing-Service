<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Http\ApiException;
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
    ) {
    }

    public function get(int $id): Process
    {
        return $this->processes->find($id) ?? throw ApiException::notFound('PROCESS_NOT_FOUND', "Process with id {$id} not found");
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): Process
    {
        $data = $this->validator->validate($input);
        $process = $this->processes->create($data);
        $this->resolver->invalidate($process->objectType);
        return $process;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input): Process
    {
        $current = $this->get($id);
        $data = $this->validator->validate($input, $id);
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

    public function delete(int $id): void
    {
        $process = $this->get($id);
        $parents = $this->processes->findParents($id);
        if ($parents !== []) {
            throw ApiException::conflict('PROCESS_IN_USE', 'The process is started by other processes', $parents);
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
