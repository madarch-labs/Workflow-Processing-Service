<?php

declare(strict_types=1);

namespace Wps\LoadStrategies;

use Wps\Services\ExternalApiAdapterService;

final class ByIdStrategy implements LoadStrategyInterface
{
    public function name(): string
    {
        return 'by_id';
    }

    public function label(): string
    {
        return 'По ID';
    }

    public function load(ExternalApiAdapterService $api, string $module, array $config, string $lookupValue): ?array
    {
        return $api->getById($module, $lookupValue);
    }
}
