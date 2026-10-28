<?php

declare(strict_types=1);

namespace Wps\Crm;

use Wps\Exceptions\NonRecoverableException;

/**
 * The CRM plugin does not implement a feature of the WPS contract (an old plugin): the step fails with
 * error_code CRM_CAPABILITY_MISSING and a hint to update the plugin.
 */
final class CrmCapabilityMissingException extends NonRecoverableException
{
    public const CODE = 'CRM_CAPABILITY_MISSING';

    public static function forFeature(string $feature, string $contractVersion): self
    {
        return new self("CRM does not support \"{$feature}\" (contract {$contractVersion}); update the CRM plugin",
            ['error_code' => self::CODE, 'feature' => $feature, 'contract_version' => $contractVersion]);
    }
}
