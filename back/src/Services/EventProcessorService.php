<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Wps\Context\ExecutionContext;
use Wps\Support\Json;

/**
 * Handles one Redis Stream message: parse → resolve processes → run each (in priority order).
 * Every process gets its own fresh context built from the event.
 */
final class EventProcessorService
{
    public function __construct(
        private readonly ProcessResolverService $resolver,
        private readonly ExecutionEngineService $engine,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Throws on infrastructure errors (the message must then stay pending and be re-delivered).
     *
     * @param array<string, string> $fields stream entry fields
     * @return array{status: string, error?: string, processes?: int, started?: int}
     */
    public function process(string $messageId, array $fields): array
    {
        $this->metrics->inc(MetricsService::EVENTS_RECEIVED);

        $parsed = self::parse($fields);
        if (isset($parsed['error'])) {
            $this->metrics->inc(MetricsService::EVENTS_INVALID);
            $this->logger->warning('Invalid stream event', ['message_id' => $messageId, 'error' => $parsed['error']]);
            return ['status' => 'invalid', 'error' => $parsed['error']];
        }
        /** @var array<string, mixed> $object */
        $object = $parsed['object'];
        $module = (string)$parsed['module'];
        $eventId = isset($object['event_id']) && is_scalar($object['event_id']) && $object['event_id'] !== ''
            ? (string)$object['event_id']
            : $messageId;

        $processes = $this->resolver->resolve($module);
        if ($processes === []) {
            $this->metrics->inc(MetricsService::EVENTS_SKIPPED);
            return ['status' => 'no_processes', 'processes' => 0, 'started' => 0];
        }

        $started = 0;
        foreach ($processes as $process) {
            $context = ExecutionContext::fromEvent($eventId, $object, $module);
            $outcome = $this->engine->handle(
                $process,
                $context,
                'stream',
                null,
                ExecutionStateStore::idempotencyKey($eventId, $process->id),
            );
            if ($outcome === 'started') {
                $started++;
            }
        }
        $this->metrics->inc($started > 0 ? MetricsService::EVENTS_PROCESSED : MetricsService::EVENTS_SKIPPED);
        $this->logger->debug('Event handled', [
            'event_id' => $eventId, 'object_type' => $module, 'processes' => count($processes), 'started' => $started,
        ]);
        return ['status' => 'ok', 'processes' => count($processes), 'started' => $started];
    }

    /**
     * Accepted formats: XADD stream * data '{json}' (mock service), or the object fields directly.
     * The object type is taken from "module" or "object_type" (both are sent by the mock).
     *
     * @param array<string, string> $fields
     * @return array{object?: array<string, mixed>, module?: string, error?: string}
     */
    public static function parse(array $fields): array
    {
        if (isset($fields['data'])) {
            $object = Json::decode($fields['data']);
            if (!is_array($object) || array_is_list($object)) {
                return ['error' => 'Field "data" is not a JSON object'];
            }
        } else {
            $object = $fields;
        }
        $module = $object['module'] ?? $object['object_type'] ?? null;
        if (!is_string($module) || $module === '') {
            return ['error' => 'Event has no "module"/"object_type"'];
        }
        if (!isset($object['id']) || !is_scalar($object['id']) || (string)$object['id'] === '') {
            return ['error' => 'Event object has no "id"'];
        }
        return ['object' => $object, 'module' => $module];
    }
}
