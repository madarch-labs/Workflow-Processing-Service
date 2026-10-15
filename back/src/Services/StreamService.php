<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Redis;
use Wps\Config\AppConfig;
use Wps\Infrastructure\RedisConnection;
use Wps\Support\Json;

/**
 * Redis Stream consumer group operations (TS §5.1, §10).
 * At-least-once: a message is acknowledged only after it has been fully processed; on an
 * infrastructure error it stays pending and is re-claimed later (XAUTOCLAIM) by RecoveryJob.
 * Invalid and poison messages go to the dead-letter stream "{stream}:dlq".
 */
final class StreamService
{
    private const MAX_DELIVERIES = 5;

    private bool $groupReady = false;
    private string $consumer;

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly EventProcessorService $processor,
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        $this->consumer = (gethostname() ?: 'wps') . '-' . getmypid();
    }

    public function consumerName(): string
    {
        return $this->consumer;
    }

    public function ensureGroup(): void
    {
        if ($this->groupReady) {
            return;
        }
        $stream = $this->config->streamName;
        $group = $this->config->consumerGroup;
        try {
            // "0": a freshly created group also processes events published before the first start
            $created = $this->redis->run(static fn (Redis $r) => $r->xGroup('CREATE', $stream, $group, '0', true));
            if ($created === true) {
                $this->logger->info('Consumer group created', ['stream' => $stream, 'group' => $group]);
            }
        } catch (\RedisException $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        // phpredis may report BUSYGROUP via getLastError() instead of an exception
        $this->redis->run(static fn (Redis $r) => $r->clearLastError());
        $this->groupReady = true;
    }

    /**
     * Reads one batch of new messages and processes them. Returns the number of messages read.
     */
    public function consumeBatch(): int
    {
        $this->ensureGroup();
        $stream = $this->config->streamName;
        $messages = $this->redis->run(fn (Redis $r) => $r->xReadGroup(
            $this->config->consumerGroup,
            $this->consumer,
            [$stream => '>'],
            $this->config->streamBatch,
            $this->config->streamBlockMs,
        ));
        if (!is_array($messages) || !isset($messages[$stream]) || !is_array($messages[$stream])) {
            if (is_array($messages) && $messages === [] && $this->groupMissing()) {
                $this->groupReady = false;
            }
            return 0;
        }
        foreach ($messages[$stream] as $id => $fields) {
            $this->handle((string)$id, is_array($fields) ? $fields : []);
        }
        return count($messages[$stream]);
    }

    /**
     * Takes over messages that another (crashed) consumer did not acknowledge in time.
     */
    public function reclaimPending(): int
    {
        $this->ensureGroup();
        $stream = $this->config->streamName;
        $group = $this->config->consumerGroup;
        $claimed = $this->redis->run(fn (Redis $r) => $r->xAutoClaim($stream, $group, $this->consumer, $this->config->streamClaimIdleMs, '0-0', 20));
        $messages = is_array($claimed) ? ($claimed[1] ?? []) : [];
        if (!is_array($messages) || $messages === []) {
            return 0;
        }
        foreach ($messages as $id => $fields) {
            $id = (string)$id;
            $pending = $this->redis->run(static fn (Redis $r) => $r->xPending($stream, $group, $id, $id, 1));
            $deliveries = (int)($pending[0][3] ?? 1);
            if ($deliveries > self::MAX_DELIVERIES) {
                $this->deadLetter($id, is_array($fields) ? $fields : [], "Delivered {$deliveries} times without success");
                $this->ack($id);
                continue;
            }
            $this->logger->info('Re-processing pending stream message', ['message_id' => $id, 'deliveries' => $deliveries]);
            $this->handle($id, is_array($fields) ? $fields : []);
        }
        return count($messages);
    }

    /**
     * @param array<string, string> $fields
     */
    public function handle(string $id, array $fields): void
    {
        try {
            $result = $this->processor->process($id, $fields);
        } catch (\Throwable $e) {
            // not acknowledged: stays pending and will be re-claimed after REDIS_STREAM_CLAIM_IDLE_MS
            $this->logger->error('Stream message processing failed', ['message_id' => $id, 'error' => $e]);
            return;
        }
        if ($result['status'] === 'invalid') {
            $this->deadLetter($id, $fields, $result['error'] ?? 'invalid');
        }
        // processed (even when no process matched — customer answer #23)
        $this->ack($id);
    }

    private function ack(string $id): void
    {
        $this->redis->run(fn (Redis $r) => $r->xAck($this->config->streamName, $this->config->consumerGroup, [$id]));
    }

    /**
     * @param array<string, string> $fields
     */
    private function deadLetter(string $id, array $fields, string $reason): void
    {
        $dlq = $this->config->streamName . ':dlq';
        $entry = ['original_id' => $id, 'reason' => $reason, 'fields' => Json::encode($fields), 'at' => gmdate(\DATE_ATOM)];
        try {
            $this->redis->run(static fn (Redis $r) => $r->xAdd($dlq, '*', $entry, 10000, true));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write to the dead-letter stream', ['message_id' => $id, 'error' => $e]);
        }
        $this->logger->warning('Message moved to the dead-letter stream', ['message_id' => $id, 'reason' => $reason, 'dlq' => $dlq]);
    }

    private function groupMissing(): bool
    {
        $error = $this->redis->run(static fn (Redis $r) => $r->getLastError());
        return is_string($error) && str_contains($error, 'NOGROUP');
    }
}
