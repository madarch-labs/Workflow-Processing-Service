<?php

declare(strict_types=1);

namespace Wps\SideEffects;

use Psr\Log\LoggerInterface;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ExecutionException;
use Wps\Services\MetricsService;

/**
 * Wrapper of an external call (e-mail, webhook, notification) that makes a step safe to repeat
 * (md/tasks_list/done/0.4-side-effect-ledger.md):
 *
 *   effect key   "{pc}[@{iteration}][#{sub}]" — the position of the call in the program, stable across a crash;
 *   external key "{execution_id}:{effect key}" — passed to the external system (Idempotency-Key, Message-ID);
 *   journal      "started" before the call, "done" + response after it, "failed" after a final error;
 *                a recoverable error keeps "started" (the external system may have executed the request).
 *
 * A step repeated after a worker crash finds "done" → the stored response is returned without calling again;
 * a foreign "started" → at_most_once: UnknownOutcomeException, at_least_once: the call is repeated.
 */
final class SideEffectLedger
{
    public function __construct(
        private readonly SideEffectStore $store,
        private readonly ?MetricsService $metrics = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function effectKey(ExecutionContext $context, string $sub = ''): string
    {
        return $context->pc . ($context->iteration !== '' ? '@' . $context->iteration : '') . ($sub !== '' ? '#' . $sub : '');
    }

    public function externalKey(ExecutionContext $context, string $sub = ''): string
    {
        return ($context->executionId ?? $context->eventId) . ':' . $this->effectKey($context, $sub);
    }

    /**
     * @param array<mixed> $request what is sent (hashed to notice changes between attempts; never stored)
     * @param callable(string): array<mixed> $call gets the external idempotency key, returns a serializable result
     * @return array{response: array<mixed>, replayed: bool, idempotency_key: string}
     */
    public function run(ExecutionContext $context, string $actionType, string $sub, string $delivery, array $request, callable $call): array
    {
        $externalKey = $this->externalKey($context, $sub);
        if ($context->dryRun) {
            return ['response' => ['dry_run' => true], 'replayed' => false, 'idempotency_key' => $externalKey];
        }
        if ($context->executionId === null) {
            return ['response' => $call($externalKey), 'replayed' => false, 'idempotency_key' => $externalKey];
        }

        $executionId = $context->executionId;
        $effectKey = $this->effectKey($context, $sub);
        $token = $context->attemptToken ?? throw new \LogicException('attemptToken is not set: external calls run only inside a step');
        $decision = $this->store->begin($executionId, $effectKey, $actionType, $delivery, $token, hash('sha256', (string)json_encode($request)));

        if ($decision['action'] === SideEffectStore::REPLAY) {
            $this->metrics?->inc(MetricsService::SIDE_EFFECTS_REPLAYED);
            $this->logger?->info('Side effect replayed', ['execution_id' => $executionId, 'effect_key' => $effectKey, 'action' => $actionType]);
            return ['response' => (array)($decision['response'] ?? []), 'replayed' => true, 'idempotency_key' => $externalKey];
        }
        if ($decision['action'] === SideEffectStore::UNKNOWN) {
            $this->metrics?->inc(MetricsService::SIDE_EFFECTS_UNKNOWN);
            $this->logger?->warning('Side effect outcome unknown', ['execution_id' => $executionId, 'effect_key' => $effectKey, 'action' => $actionType]);
            throw UnknownOutcomeException::forEffect($effectKey, $decision['started_at'] ?? null,
                'Outcome of the previous attempt is unknown (the worker stopped after the request was sent); the step was not repeated (delivery: at_most_once)');
        }

        try {
            $response = $call($externalKey);
        } catch (UnknownOutcomeException $e) {
            // the request was sent but no answer came: the journal keeps "started"
            $this->metrics?->inc(MetricsService::SIDE_EFFECTS_UNKNOWN);
            throw $e;
        } catch (\Throwable $e) {
            if (!($e instanceof ExecutionException) || !$e->isRecoverable()) {
                $this->store->failed($executionId, $effectKey, $e->getMessage());
                $this->metrics?->inc(MetricsService::SIDE_EFFECTS_FAILED);
            }
            throw $e;
        }
        $masked = $context->secretMasker?->mask($response) ?? $response;
        $this->store->done($executionId, $effectKey, (array)$masked);
        $this->metrics?->inc(MetricsService::SIDE_EFFECTS_DONE);
        return ['response' => $response, 'replayed' => false, 'idempotency_key' => $externalKey];
    }

    /**
     * Forget an unknown outcome so that an operator can repeat the step (md/tasks_list/7.3-cancel-resume.md).
     */
    public function forget(string $executionId, string $effectKey): void
    {
        $this->store->forget($executionId, $effectKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $executionId): array
    {
        return $this->store->list($executionId);
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->store->purgeOlderThan($days);
    }
}
