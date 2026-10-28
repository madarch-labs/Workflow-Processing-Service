<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

/**
 * Contract version and features of the CRM plugin (md/tasks_list/done/0.2-crm-contract-v9.md §7).
 */
#[Route(uri: '/api/dictionaries/crm-capabilities', method: 'GET')]
final class CrmCapabilitiesController extends BaseController
{
    protected function handle(): Response
    {
        try {
            $entry = $this->container()->crmCapabilities()->describe();
        } catch (\Throwable $e) {
            throw new ApiException(503, 'CRM_UNAVAILABLE', 'The CRM is not reachable: ' . $e->getMessage());
        }
        return $this->ok((array)$entry['capabilities'] + ['source' => $entry['source'], 'cached_at' => $entry['cached_at']]);
    }
}
