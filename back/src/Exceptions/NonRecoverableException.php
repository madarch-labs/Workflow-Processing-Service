<?php

declare(strict_types=1);

namespace Wps\Exceptions;

/**
 * Permanent failure: invalid module, unknown action type, bad configuration, CRM 4xx. Fails immediately.
 */
class NonRecoverableException extends ExecutionException
{
    public function isRecoverable(): bool
    {
        return false;
    }
}
