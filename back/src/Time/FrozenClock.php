<?php

declare(strict_types=1);

namespace Wps\Time;

/**
 * A clock that always returns the same moment (tests, dry runs).
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now)
    {
        $this->set($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $now): void
    {
        $moment = is_string($now) ? new \DateTimeImmutable($now, new \DateTimeZone('UTC')) : $now;
        $this->now = $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
