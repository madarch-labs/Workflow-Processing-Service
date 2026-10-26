<?php

declare(strict_types=1);

namespace Wps\SideEffects;

use PDO;
use Wps\Infrastructure\Database;
use Wps\Support\Json;

/**
 * step_side_effects in PostgreSQL. begin() runs in a short transaction with SELECT … FOR UPDATE;
 * the external call itself is made outside of any transaction.
 */
final class SideEffectRepository implements SideEffectStore
{
    public function __construct(private readonly Database $db)
    {
    }

    public function begin(string $executionId, string $effectKey, string $actionType, string $delivery, string $attemptToken, string $requestHash): array
    {
        return $this->db->transaction(function (PDO $pdo) use ($executionId, $effectKey, $actionType, $delivery, $attemptToken, $requestHash): array {
            $select = $pdo->prepare('SELECT status, CAST(attempt_token AS TEXT) AS attempt_token, response, started_at
                FROM step_side_effects WHERE execution_id = CAST(:e AS UUID) AND effect_key = :k FOR UPDATE');
            $select->execute(['e' => $executionId, 'k' => $effectKey]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->prepare('INSERT INTO step_side_effects (execution_id, effect_key, action_type, delivery, status, attempt_token, request_hash)
                    VALUES (CAST(:e AS UUID), :k, :t, :d, \'started\', CAST(:a AS UUID), :h)')
                    ->execute(['e' => $executionId, 'k' => $effectKey, 't' => $actionType, 'd' => $delivery, 'a' => $attemptToken, 'h' => $requestHash]);
                return ['action' => self::CALL];
            }
            if ($row['status'] === 'done') {
                return ['action' => self::REPLAY, 'response' => (array)Json::decode((string)$row['response'], [])];
            }
            $foreign = $row['status'] === 'started' && $row['attempt_token'] !== $attemptToken;
            if ($foreign && $delivery === DeliveryPolicy::AT_MOST_ONCE) {
                return ['action' => self::UNKNOWN, 'started_at' => Json::time((string)$row['started_at'])];
            }
            if ($row['status'] === 'failed' || $foreign) {
                $pdo->prepare('UPDATE step_side_effects SET status = \'started\', attempt_token = CAST(:a AS UUID), request_hash = :h,
                        delivery = :d, attempts = attempts + 1, error_message = NULL, started_at = NOW(), finished_at = NULL
                    WHERE execution_id = CAST(:e AS UUID) AND effect_key = :k')
                    ->execute(['e' => $executionId, 'k' => $effectKey, 'a' => $attemptToken, 'h' => $requestHash, 'd' => $delivery]);
            }
            return ['action' => self::CALL];
        });
    }

    public function done(string $executionId, string $effectKey, array $response): void
    {
        $this->db->execute(
            "UPDATE step_side_effects SET status = 'done', response = CAST(:r AS JSONB), finished_at = NOW()
             WHERE execution_id = CAST(:e AS UUID) AND effect_key = :k",
            ['e' => $executionId, 'k' => $effectKey, 'r' => Json::encode((object)$response)],
        );
    }

    public function failed(string $executionId, string $effectKey, string $error): void
    {
        $this->db->execute(
            "UPDATE step_side_effects SET status = 'failed', error_message = :m, finished_at = NOW()
             WHERE execution_id = CAST(:e AS UUID) AND effect_key = :k",
            ['e' => $executionId, 'k' => $effectKey, 'm' => mb_substr($error, 0, 2000)],
        );
    }

    public function forget(string $executionId, string $effectKey): void
    {
        $this->db->execute('DELETE FROM step_side_effects WHERE execution_id = CAST(:e AS UUID) AND effect_key = :k', ['e' => $executionId, 'k' => $effectKey]);
    }

    public function list(string $executionId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT effect_key, action_type, delivery, status, attempts, started_at, finished_at, error_message
             FROM step_side_effects WHERE execution_id = CAST(:e AS UUID) ORDER BY started_at, effect_key',
            ['e' => $executionId],
        );
        return array_map(static fn (array $r) => [
            'effect_key' => (string)$r['effect_key'],
            'action_type' => (string)$r['action_type'],
            'delivery' => (string)$r['delivery'],
            'status' => (string)$r['status'],
            'attempts' => (int)$r['attempts'],
            'started_at' => Json::time($r['started_at'] ?? null),
            'finished_at' => Json::time($r['finished_at'] ?? null),
            'error_message' => $r['error_message'] !== null ? (string)$r['error_message'] : null,
        ], $rows);
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->db->execute('DELETE FROM step_side_effects WHERE started_at < NOW() - make_interval(days => :d)', ['d' => $days]);
    }
}
