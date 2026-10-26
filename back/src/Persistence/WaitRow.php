<?php

declare(strict_types=1);

namespace Wps\Persistence;

/**
 * A row of execution_waits: a parked execution and its state.
 */
final class WaitRow
{
    /**
     * @param array<string, mixed> $state the exec:{uuid} hash, decoded
     * @param array<string, mixed> $info data for the step after waking
     */
    public function __construct(
        public readonly string $executionId,
        public readonly int $processId,
        public readonly \DateTimeImmutable $dueAt,
        public readonly string $reason,
        public readonly array $state,
        public readonly array $info,
        public readonly int $attempts,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $decode = static fn (mixed $v): array => is_array($v) ? $v : (array)json_decode((string)$v, true);
        return new self(
            executionId: (string)$row['execution_id'],
            processId: (int)$row['process_id'],
            dueAt: new \DateTimeImmutable((string)$row['due_at']),
            reason: (string)$row['reason'],
            state: $decode($row['state'] ?? '{}'),
            info: $decode($row['info'] ?? '{}'),
            attempts: (int)($row['attempts'] ?? 0),
        );
    }
}
