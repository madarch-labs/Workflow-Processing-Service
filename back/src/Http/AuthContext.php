<?php

declare(strict_types=1);

namespace Wps\Http;

use Swoole\Coroutine;

/**
 * Authenticated user of the current request, kept in the request coroutine's context.
 */
final class AuthContext
{
    private const KEY = 'wps.auth';

    /**
     * @param array{id: int, email: string, role: string} $user
     */
    public static function set(array $user): void
    {
        $context = Coroutine::getContext();
        if ($context !== null) {
            $context[self::KEY] = $user;
        }
    }

    /**
     * @return array{id: int, email: string, role: string}|null
     */
    public static function user(): ?array
    {
        $context = Coroutine::getContext();
        return $context[self::KEY] ?? null;
    }
}
