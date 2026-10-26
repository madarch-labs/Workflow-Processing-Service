<?php

declare(strict_types=1);

namespace Wps\Types;

/**
 * Outcome of TypeCaster::cast(): a value, "empty" (null / blank / empty list) or an error message.
 * Casting never throws — a failed cast is an ordinary result the caller decides about.
 */
final class CastResult
{
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const ERROR = 'error';

    private function __construct(
        public readonly string $status,
        public readonly mixed $value,
        public readonly ?string $error,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(self::OK, $value, null);
    }

    public static function empty(): self
    {
        return new self(self::EMPTY, null, null);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, null, $message);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isEmpty(): bool
    {
        return $this->status === self::EMPTY;
    }

    public function isError(): bool
    {
        return $this->status === self::ERROR;
    }
}
