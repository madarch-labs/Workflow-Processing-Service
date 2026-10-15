<?php

declare(strict_types=1);

namespace Wps\Http;

/**
 * HTTP-level error rendered by BaseController into the standard error envelope.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): mixed
    {
        return $this->details;
    }

    public static function badRequest(string $message, mixed $details = null): self
    {
        return new self(400, 'BAD_REQUEST', $message, $details);
    }

    public static function unauthorized(string $message = 'Authentication required', string $code = 'UNAUTHORIZED'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'Insufficient permissions'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message, mixed $details = null): self
    {
        return new self(409, $code, $message, $details);
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public static function validation(array $errors, string $message = 'Validation failed'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $errors);
    }
}
