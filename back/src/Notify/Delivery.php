<?php

declare(strict_types=1);

namespace Wps\Notify;

/**
 * One external call of a channel: a CRM alert for one user, one message to a chat. Each delivery is a separate side
 * effect in the journal ($sub), so a repeated step does not send what was already delivered.
 */
final class Delivery
{
    /**
     * @param array<string, mixed> $request what the audit shows (no secrets)
     * @param \Closure(string): array<string, mixed> $send gets the idempotency key, returns what is journaled
     */
    public function __construct(
        public readonly string $sub,
        public readonly array $request,
        public readonly \Closure $send,
    ) {
    }
}
