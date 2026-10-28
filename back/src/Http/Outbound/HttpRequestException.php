<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

use Wps\Exceptions\ExecutionException;

/**
 * Error of an outgoing HTTP request. $sent: the request reached the server (a timeout or a broken connection after
 * sending — the outcome is unknown). Retry-After is passed in details.retry_after_seconds (read by ErrorClassifier, 7.1).
 */
final class HttpRequestException extends ExecutionException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly bool $recoverable,
        private readonly bool $sent,
        array $details = [],
    ) {
        parent::__construct($message, $details + ['http_status' => $status > 0 ? $status : null, 'error_code' => $status > 0 ? "HTTP_{$status}" : ($sent ? 'TIMEOUT' : 'TRANSPORT')]);
    }

    public function isRecoverable(): bool
    {
        return $this->recoverable;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function sent(): bool
    {
        return $this->sent;
    }
}
