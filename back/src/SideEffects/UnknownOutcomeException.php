<?php

declare(strict_types=1);

namespace Wps\SideEffects;

use Wps\Exceptions\NonRecoverableException;

/**
 * The previous attempt of an at_most_once external call may have been executed (or the request was sent and the
 * connection broke): the step is not repeated. details: error_code UNKNOWN_OUTCOME, effect_key, started_at.
 */
final class UnknownOutcomeException extends NonRecoverableException
{
    public const CODE = 'UNKNOWN_OUTCOME';

    public static function forEffect(string $effectKey, ?string $startedAt, string $reason): self
    {
        return new self($reason, ['error_code' => self::CODE, 'effect_key' => $effectKey, 'started_at' => $startedAt]);
    }
}
