<?php

declare(strict_types=1);

namespace Wps\SideEffects;

/**
 * Storage of the side-effect journal (PostgreSQL in production, in memory in unit tests).
 */
interface SideEffectStore
{
    public const CALL = 'call';
    public const REPLAY = 'replay';
    public const UNKNOWN = 'unknown';

    /**
     * Atomically decides what to do with an external call and records "started" when it must run:
     *   no row / "failed" / "started" by the same attempt / foreign "started" + at_least_once → call;
     *   "done" → replay (the stored response); foreign "started" + at_most_once → unknown.
     *
     * @return array{action: string, response?: array<mixed>|null, started_at?: string|null}
     */
    public function begin(string $executionId, string $effectKey, string $actionType, string $delivery, string $attemptToken, string $requestHash): array;

    /**
     * @param array<mixed> $response
     */
    public function done(string $executionId, string $effectKey, array $response): void;

    public function failed(string $executionId, string $effectKey, string $error): void;

    public function forget(string $executionId, string $effectKey): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $executionId): array;

    public function purgeOlderThan(int $days): int;
}
