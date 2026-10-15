<?php

declare(strict_types=1);

namespace Wps\LoadStrategies;

use Wps\Exceptions\ConfigurationException;
use Wps\Services\ExternalApiAdapterService;

/**
 * Search by an arbitrary field (e.g. inn). When several records match, the first one is used.
 */
final class ByFieldStrategy implements LoadStrategyInterface
{
    public function name(): string
    {
        return 'by_field';
    }

    public function label(): string
    {
        return 'По полю';
    }

    public function load(ExternalApiAdapterService $api, string $module, array $config, string $lookupValue): ?array
    {
        $field = trim((string)($config['by_field'] ?? ''));
        if ($field === '') {
            throw new ConfigurationException('Preload: by_field is required for search by field');
        }
        return $api->findOneByField($module, $field, $lookupValue);
    }
}
