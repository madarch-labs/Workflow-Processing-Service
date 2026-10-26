<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Support;

use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\SideEffectStore;

/**
 * Side-effect journal in memory, same decisions as SideEffectRepository.
 */
final class InMemorySideEffectStore implements SideEffectStore
{
    /** @var array<string, array{status: string, attempt_token: string, delivery: string, response: ?array<mixed>, error: ?string, attempts: int, started_at: string}> */
    public array $rows = [];

    public function begin(string $executionId, string $effectKey, string $actionType, string $delivery, string $attemptToken, string $requestHash): array
    {
        $key = "{$executionId}|{$effectKey}";
        $row = $this->rows[$key] ?? null;
        if ($row === null) {
            $this->rows[$key] = ['status' => 'started', 'attempt_token' => $attemptToken, 'delivery' => $delivery, 'response' => null, 'error' => null, 'attempts' => 1, 'started_at' => '2026-10-19T10:00:00Z'];
            return ['action' => self::CALL];
        }
        if ($row['status'] === 'done') {
            return ['action' => self::REPLAY, 'response' => $row['response']];
        }
        $foreign = $row['status'] === 'started' && $row['attempt_token'] !== $attemptToken;
        if ($foreign && $delivery === DeliveryPolicy::AT_MOST_ONCE) {
            return ['action' => self::UNKNOWN, 'started_at' => $row['started_at']];
        }
        if ($row['status'] === 'failed' || $foreign) {
            $this->rows[$key] = ['status' => 'started', 'attempt_token' => $attemptToken, 'attempts' => $row['attempts'] + 1] + $row;
        }
        return ['action' => self::CALL];
    }

    public function done(string $executionId, string $effectKey, array $response): void
    {
        $this->rows["{$executionId}|{$effectKey}"]['status'] = 'done';
        $this->rows["{$executionId}|{$effectKey}"]['response'] = $response;
    }

    public function failed(string $executionId, string $effectKey, string $error): void
    {
        $this->rows["{$executionId}|{$effectKey}"]['status'] = 'failed';
        $this->rows["{$executionId}|{$effectKey}"]['error'] = $error;
    }

    public function forget(string $executionId, string $effectKey): void
    {
        unset($this->rows["{$executionId}|{$effectKey}"]);
    }

    public function list(string $executionId): array
    {
        return [];
    }

    public function purgeOlderThan(int $days): int
    {
        return 0;
    }
}
