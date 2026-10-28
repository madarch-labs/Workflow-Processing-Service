<?php

declare(strict_types=1);

namespace Wps\Crm;

use Swoole\Coroutine;

/**
 * Origin of the CRM calls made by the current step (headers X-WPS-Execution-Id / X-WPS-Process-Id / X-WPS-Depth):
 * the plugin copies it into "wps_origin" of the events caused by these saves (protection against cascades).
 * Kept in the context of the current coroutine, so parallel executions do not see each other's values.
 */
final class CrmCallOrigin
{
    private const KEY = 'wps.crm_origin';

    /** @var array{execution_id: ?string, process_id: ?int, depth: int}|null outside of coroutines (CLI, tests) */
    private static ?array $fallback = null;

    public static function set(?string $executionId, ?int $processId, int $depth): void
    {
        $origin = ['execution_id' => $executionId, 'process_id' => $processId, 'depth' => $depth];
        $context = Coroutine::getCid() > 0 ? Coroutine::getContext() : null;
        if ($context !== null) {
            $context[self::KEY] = $origin;
        } else {
            self::$fallback = $origin;
        }
    }

    public static function clear(): void
    {
        $context = Coroutine::getCid() > 0 ? Coroutine::getContext() : null;
        if ($context !== null) {
            unset($context[self::KEY]);
        } else {
            self::$fallback = null;
        }
    }

    /**
     * @return array<string, string> HTTP headers (empty outside of an execution)
     */
    public static function headers(): array
    {
        $context = Coroutine::getCid() > 0 ? Coroutine::getContext() : null;
        $origin = $context !== null ? ($context[self::KEY] ?? null) : self::$fallback;
        if (!is_array($origin) || ($origin['execution_id'] ?? null) === null) {
            return [];
        }
        return array_filter([
            'X-WPS-Execution-Id' => (string)$origin['execution_id'],
            'X-WPS-Process-Id' => $origin['process_id'] !== null ? (string)$origin['process_id'] : null,
            'X-WPS-Depth' => (string)$origin['depth'],
        ], static fn (?string $v) => $v !== null);
    }
}
