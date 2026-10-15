<?php

declare(strict_types=1);

namespace Wps\Services;

final class ConditionResult
{
    /**
     * @param bool|null $result true/false, or null when some rows depend on variables not loaded yet
     * @param list<array<string, mixed>> $trace one entry per evaluated row (for audit and the manual run UI)
     */
    public function __construct(
        public readonly ?bool $result,
        public readonly array $trace,
    ) {
    }

    public function isMet(): bool
    {
        return $this->result === true;
    }

    public function isUnknown(): bool
    {
        return $this->result === null;
    }
}
