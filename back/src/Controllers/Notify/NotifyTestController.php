<?php

declare(strict_types=1);

namespace Wps\Controllers\Notify;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Context\ExecutionContext;
use Wps\Controllers\BaseController;
use Wps\Http\ApiException;
use Wps\Secrets\SecretMasker;
use Wps\Services\Validation\DefinitionScope;
use Wps\Support\Uuid;

/**
 * POST /api/notify/test (editors): {"channel": {...one channel of notify...}, "message"?: "text"} — sends a test
 * notification to check a channel without a process (md/tasks_list/4.8-notify.md §7). Errors are returned as text.
 */
#[Route(uri: '/api/notify/test', method: 'POST')]
final class NotifyTestController extends BaseController
{
    protected function handle(): Response
    {
        $body = $this->body();
        $channel = is_array($body['channel'] ?? null) ? $body['channel'] : [];
        $step = ['type' => 'notify', 'title' => 'WPS', 'message' => (string)($body['message'] ?? 'Это тестовое уведомление WPS. Канал работает.'),
            'channels' => [$channel], 'delivery' => 'at_least_once'];
        $handler = $this->container()->actions()->get('notify');
        $scope = new DefinitionScope(inheritsVariables: true);
        $handler->validate($step, $scope, 'notify');
        if ($scope->hasErrors()) {
            throw ApiException::validation(array_map(static fn (array $e) => ['field' => preg_replace('/^notify\.channels\[0\]\.?/', 'channel.', $e['field']), 'message' => $e['message']], $scope->errors()));
        }
        $context = ExecutionContext::fromEvent('notify-test-' . Uuid::v4(), ['id' => ''], 'Test');
        $context->secretMasker = $masker = new SecretMasker();
        try {
            $result = $handler->execute($context, $step);
        } catch (\Throwable $e) {
            return $this->ok(['sent' => false, 'error' => $masker->mask($e->getMessage())]);
        }
        return $this->ok(['sent' => true, 'result' => $masker->mask($result->result)]);
    }
}
