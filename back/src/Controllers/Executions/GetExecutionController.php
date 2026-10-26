<?php

declare(strict_types=1);

namespace Wps\Controllers\Executions;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

/**
 * Execution details + steps + child executions. While running, the context comes live from Redis.
 */
#[Route(uri: '/api/executions/{id}', method: 'GET')]
final class GetExecutionController extends BaseController
{
    protected function handle(): Response
    {
        $id = ExecutionLookup::assertId($this->param('id'));
        $c = $this->container();
        $execution = $c->executions()->find($id) ?? throw ApiException::notFound('EXECUTION_NOT_FOUND', "Execution {$id} not found");

        $snapshot = $execution['context_snapshot'];
        unset($execution['context_snapshot']);
        if ($snapshot === null || !$execution['finished_at']) {
            $live = $c->stateStore()->load($id);
            if ($live !== null) {
                $snapshot = [
                    'event' => $live['event'] ?? null,
                    'variables' => $live['variables'] ?? null,
                    'meta' => $live['meta'] ?? null,
                    'conditions' => $live['conditions'] ?? [],
                ];
                $execution['current_step'] = $live['current_step'] ?? null;
                $execution['seq'] = $live['seq'] ?? $execution['current_step'];
                $execution['frames'] = $live['frames'] ?? [];
            }
        }
        if ($execution['status'] === 'waiting' && ($wait = $c->executionWaits()->find($id)) !== null) {
            // a parked execution has no Redis state: its context is in execution_waits (7.2)
            $snapshot = [
                'event' => $wait->state['event'] ?? null,
                'variables' => $wait->state['variables'] ?? null,
                'meta' => $wait->state['meta'] ?? null,
                'conditions' => $wait->state['conditions'] ?? [],
            ];
            $execution['current_step'] = $wait->state['current_step'] ?? null;
            $execution['wait'] = ['due_at' => $wait->dueAt->format(\DATE_ATOM), 'reason' => $wait->reason, 'attempts' => $wait->attempts, 'info' => $wait->info];
        }
        $execution['context'] = $snapshot;
        $execution['actions'] = $c->audit()->steps($id);
        $execution['children'] = $c->executions()->children($id);
        return $this->ok($execution);
    }
}
