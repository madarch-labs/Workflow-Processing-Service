<?php

declare(strict_types=1);

namespace Wps\Actions;

final class ActionResult
{
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /**
     * @param array<string, mixed> $request what the step did / sent (audit request_data)
     * @param array<string, mixed> $result what it got / changed (audit result_data)
     */
    public function __construct(
        public readonly string $status,
        public readonly array $request = [],
        public readonly array $result = [],
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     */
    public static function success(array $request = [], array $result = []): self
    {
        return new self(self::SUCCESS, $request, $result);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function skipped(string $reason, array $request = []): self
    {
        return new self(self::SKIPPED, $request, ['reason' => $reason]);
    }
}
