<?php

declare(strict_types=1);

namespace Wps\Services\Errors;

/**
 * Classified error of a step (md/tasks_list/done/7.1-on-error.md §4.2).
 */
final class ErrorInfo
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        /** TRANSPORT, TIMEOUT, HTTP_503, HTTP_422, CONFIG, UNKNOWN_OUTCOME, REDIS, DATABASE, ERROR … */
        public readonly string $code,
        public readonly string $message,
        /** repeatable by default (class "transient") */
        public readonly bool $transient,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        /** never repeated automatically, whatever retry_on says: configuration errors, unknown outcome of an effect */
        public readonly bool $permanent = false,
        public readonly array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'transient' => $this->transient,
            'http_status' => $this->httpStatus,
            'retry_after' => $this->retryAfterSeconds,
        ], static fn ($v) => $v !== null);
    }
}
