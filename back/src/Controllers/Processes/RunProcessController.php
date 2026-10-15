<?php

declare(strict_types=1);

namespace Wps\Controllers\Processes;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;

/**
 * Manual run with a test object (TS §7.2.6). Conditions are evaluated synchronously; when they pass,
 * the execution is created and its steps run in the background — the UI follows it live.
 * run_once: a record that already has a mark is not run again ("run_once_blocked": true), even with force.
 */
#[Route(uri: '/api/processes/{id}/run', method: 'POST')]
final class RunProcessController extends BaseController
{
    protected function handle(): Response
    {
        $process = $this->container()->processService()->get($this->intParam('id'));
        $body = $this->body();
        $object = array_key_exists('object', $body) ? $body['object'] : $body;
        $force = (bool)($body['force'] ?? false);
        if (!array_key_exists('object', $body)) {
            unset($object['force']);
        }
        if (!is_array($object) || $object === [] || array_is_list($object)) {
            throw ApiException::validation([['field' => 'object', 'message' => 'A JSON object is required']]);
        }
        if (!isset($object['id']) || !is_scalar($object['id']) || (string)$object['id'] === '') {
            throw ApiException::validation([['field' => 'object.id', 'message' => 'The object must have an "id"']]);
        }

        $result = $this->container()->engine()->runManual($process, $object, $force);
        // 201 only when an execution was created; conditions not met / blocked by run_once → 200
        return $this->ok($result, $result['execution_id'] !== null ? 201 : 200);
    }
}
