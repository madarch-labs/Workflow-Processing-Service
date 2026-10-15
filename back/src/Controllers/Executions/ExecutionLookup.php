<?php

declare(strict_types=1);

namespace Wps\Controllers\Executions;

use Wps\Http\ApiException;
use Wps\Support\Uuid;

final class ExecutionLookup
{
    public static function assertId(string $id): string
    {
        if (!Uuid::isValid($id)) {
            throw ApiException::notFound('EXECUTION_NOT_FOUND', "Execution {$id} not found");
        }
        return strtolower($id);
    }
}
