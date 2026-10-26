<?php

declare(strict_types=1);

namespace Wps\Services\Errors;

/**
 * What happens after a step failed for good: "stop" (default), "continue" or {"goto": "<step id>"}.
 */
final class ErrorPolicy
{
    public const STOP = 'stop';
    public const CONTINUE = 'continue';
    public const GOTO = 'goto';

    private function __construct(private readonly string $mode, private readonly ?string $target = null)
    {
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function fromStep(array $step): self
    {
        $value = $step['on_error'] ?? self::STOP;
        if ($value === self::CONTINUE) {
            return new self(self::CONTINUE);
        }
        if (is_array($value) && is_string($value['goto'] ?? null) && $value['goto'] !== '') {
            return new self(self::GOTO, $value['goto']);
        }
        return new self(self::STOP);
    }

    /**
     * Save-time check of the "on_error" value; null when valid.
     */
    public static function error(mixed $value): ?string
    {
        if ($value === self::STOP || $value === self::CONTINUE) {
            return null;
        }
        if (is_array($value) && array_keys($value) === ['goto'] && is_string($value['goto']) && $value['goto'] !== '') {
            return null;
        }
        return 'Must be "stop", "continue" or {"goto": "<step id>"}';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function target(): ?string
    {
        return $this->target;
    }
}
