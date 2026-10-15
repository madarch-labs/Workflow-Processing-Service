<?php

declare(strict_types=1);

namespace Wps\Exceptions;

/**
 * Temporary failure: CRM timeout, CRM 5xx, network error, lost Redis connection. Retried with backoff.
 */
class RecoverableException extends ExecutionException
{
    public function isRecoverable(): bool
    {
        return true;
    }
}
