<?php

declare(strict_types=1);

namespace Wps\Exceptions;

/**
 * Error returned by the external CRM API. Recoverable for transport errors and 5xx, permanent for 4xx.
 */
final class CrmApiException extends ExecutionException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, private readonly int $httpStatus, private readonly bool $recoverable, array $details = [])
    {
        parent::__construct($message, $details + ['http_status' => $httpStatus]);
    }

    public function isRecoverable(): bool
    {
        return $this->recoverable;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
