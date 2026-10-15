<?php

declare(strict_types=1);

namespace Wps\LoadStrategies;

use Wps\Services\ExternalApiAdapterService;

/**
 * Extension point: how an object is loaded from the CRM into a variable (TS §13.3).
 * Examples of future strategies: "newest record created after a date", "by several fields".
 */
interface LoadStrategyInterface
{
    public function name(): string;

    public function label(): string;

    /**
     * @param array<string, mixed> $config the preload row (module, by_field, ...)
     * @return array<string, mixed>|null null when nothing was found
     */
    public function load(ExternalApiAdapterService $api, string $module, array $config, string $lookupValue): ?array;
}
