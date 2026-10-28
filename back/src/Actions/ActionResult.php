<?php

declare(strict_types=1);

namespace Wps\Actions;

final class ActionResult
{
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';
    /** the step asks to park the execution until $dueAt (md/tasks_list/done/7.2-waiting-executions.md) */
    public const WAITING = 'waiting';

    /**
     * @param array<string, mixed> $request what the step did / sent (audit request_data)
     * @param array<string, mixed> $result what it got / changed (audit result_data)
     */
    public function __construct(
        public readonly string $status,
        public readonly array $request = [],
        public readonly array $result = [],
        public readonly ?\DateTimeImmutable $dueAt = null,
        public readonly ?string $waitReason = null,
        /** @var array<string, mixed> given back to ResumableActionInterface::resume() */
        public readonly array $waitInfo = [],
    ) {
    }

    /**
     * Park the execution until $dueAt; after waking the handler's resume() finishes the step.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     * @param array<string, mixed> $info
     */
    public static function wait(\DateTimeImmutable $dueAt, string $reason, array $request = [], array $result = [], array $info = []): self
    {
        return new self(self::WAITING, $request, $result, $dueAt, $reason, $info);
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
