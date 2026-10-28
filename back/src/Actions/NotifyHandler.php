<?php

declare(strict_types=1);

namespace Wps\Actions;

use Psr\Log\LoggerInterface;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ExecutionException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Notify\ChannelRegistry;
use Wps\Notify\NotifyMessage;
use Wps\Notify\RecordLinks;
use Wps\Services\Errors\ErrorClassifier;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\HasDeliveryPolicy;
use Wps\SideEffects\SideEffectLedger;
use Wps\Template\EscapeMode;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * "Уведомление" (md/tasks_list/4.8-notify.md): a short message to the CRM bell and to messengers in one step.
 * The title, message and link are rendered once; every delivery of every channel is a separate side effect in the
 * journal, so a repeated step does not send again what was delivered. A failed channel does not stop the others:
 * fail_on = any — the step fails when a channel failed, all — only when nothing was delivered. A recoverable error
 * (429, 503, network) is rethrown after all channels, and the engine repeats the step.
 */
final class NotifyHandler implements ActionHandlerInterface, HasDeliveryPolicy
{
    public const MAX_CHANNELS = 10;

    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly ChannelRegistry $channels,
        private readonly SideEffectLedger $ledger,
        private readonly RecordLinks $links,
        private readonly ?MetricsService $metrics = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->validator = new TemplateValidator($templates);
    }

    public function type(): string
    {
        return 'notify';
    }

    public function label(): string
    {
        return 'Уведомление';
    }

    public function description(): string
    {
        return 'Отправляет короткое уведомление в колокольчик CRM, Telegram, Slack или Microsoft Teams';
    }

    public function defaultDelivery(array $config): string
    {
        return DeliveryPolicy::AT_MOST_ONCE;
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $message = $this->message($context, $actionConfig);
        if ($message->text()['message'] === '') {
            throw new NonRecoverableException('Notification message is empty');
        }
        $delivery = DeliveryPolicy::resolve($actionConfig['delivery'] ?? null, $this->defaultDelivery($actionConfig));
        $failOn = ($actionConfig['fail_on'] ?? 'any') === 'all' ? 'all' : 'any';
        $results = [];
        $recoverable = null;
        $delivered = 0;
        $failed = [];
        foreach (array_values((array)$actionConfig['channels']) as $i => $config) {
            $config = (array)$config;
            $name = (string)($config['channel'] ?? '');
            $result = ['channel' => $name];
            try {
                $deliveries = $this->channels->get($name)->deliveries($config, $message, $context, $delivery);
                if ($deliveries === []) {
                    $results[] = $result + ['status' => 'skipped', 'reason' => 'No recipients'];
                    continue;
                }
                $items = [];
                foreach ($deliveries as $d) {
                    $outcome = $this->ledger->run($context, $this->type(), "{$i}:{$d->sub}", $delivery, $d->request + ['text' => $message->plain()], $d->send);
                    $items[] = $d->request + (array)$outcome['response'] + ['replayed' => $outcome['replayed']];
                }
                $results[] = $result + ['status' => 'success', 'deliveries' => $items];
                $delivered++;
                $this->metrics?->inc(MetricsService::NOTIFICATIONS_SENT, count($items));
            } catch (\Throwable $e) {
                $error = (string)($context->secretMasker?->mask($e->getMessage()) ?? $e->getMessage());
                $info = ErrorClassifier::classify($e);
                $results[] = $result + ['status' => 'failed', 'error' => $error, 'error_code' => $info->code];
                $failed[] = "{$name}: {$error}";
                $this->metrics?->inc(MetricsService::NOTIFICATIONS_FAILED);
                if ($info->transient && $recoverable === null) {
                    $recoverable = $e;
                }
            }
        }
        $request = [
            'title' => $message->text()['title'],
            'message_preview' => mb_substr($message->text()['message'], 0, 500),
            'link' => $message->link,
            'channels' => array_map(static fn ($c) => array_intersect_key((array)$c, array_flip(['channel', 'connection', 'chat_id', 'users', 'format'])), array_values((array)$actionConfig['channels'])),
            'delivery' => $delivery,
        ];
        $result = ['channels' => $results] + ($message->warnings !== [] ? ['warnings' => $message->warnings] : []);
        $stepFails = $failed !== [] && ($failOn === 'any' || $delivered === 0);
        $this->logger?->info('Notification sent', ['execution_id' => $context->executionId, 'delivered' => $delivered, 'failed' => count($failed)]);
        if (!$stepFails) {
            return ActionResult::success($request, $result);
        }
        if ($recoverable instanceof ExecutionException) {
            throw $recoverable; // the step is repeated; delivered channels come back from the journal
        }
        throw new NonRecoverableException(sprintf('notify: %d of %d channels failed: %s', count($failed), count($results), implode('; ', $failed)),
            $result + ['request' => $request]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function message(ExecutionContext $context, array $config): NotifyMessage
    {
        $resolver = static fn (string $path) => $context->resolve($path);
        $link = null;
        $module = null;
        $id = null;
        if (isset($config['link']['record'])) {
            $var = (string)$config['link']['record'];
            $id = $context->get("{$var}.id");
            $module = $context->getModule($var);
            $link = $id !== null && $id !== '' && $module !== null ? $this->links->relative($module, (string)$id) : null;
            $id = $id !== null ? (string)$id : null;
        } elseif (isset($config['link']['url'])) {
            $link = trim((string)$this->templates->render((string)$config['link']['url'], $resolver, EscapeMode::Url)->value);
            $link = $link !== '' ? $link : null;
        }
        return new NotifyMessage($this->templates, (string)($config['title'] ?? ''), (string)($config['message'] ?? ''), $resolver, $link, $module, $id);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $message = $actionConfig['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            $scope->error("{$path}.message", 'Message is required');
        } else {
            $this->validator->validate($message, $scope, "{$path}.message");
        }
        if (isset($actionConfig['title'])) {
            is_string($actionConfig['title'])
                ? $this->validator->validate($actionConfig['title'], $scope, "{$path}.title")
                : $scope->error("{$path}.title", 'Must be a template string');
        }
        $link = $actionConfig['link'] ?? null;
        if ($link !== null) {
            if (is_array($link) && isset($link['record'])) {
                $var = $link['record'];
                if (!is_string($var) || !ExecutionContext::isValidVariableName($var)) {
                    $scope->error("{$path}.link.record", 'Variable name is required (e.g. object)');
                } elseif (!$scope->isDeclared($var) && !$scope->inheritsVariables) {
                    $scope->error("{$path}.link.record", "Variable \"{$var}\" is not defined at this point");
                }
            } elseif (is_array($link) && is_string($link['url'] ?? null)) {
                $this->validator->validate($link['url'], $scope, "{$path}.link.url", EscapeMode::Url);
            } else {
                $scope->error("{$path}.link", 'Use {"record": "<variable>"} or {"url": "<template>"}');
            }
        }
        $channels = $actionConfig['channels'] ?? null;
        if (!is_array($channels) || !array_is_list($channels) || $channels === [] || count($channels) > self::MAX_CHANNELS) {
            $scope->error("{$path}.channels", 'From 1 to ' . self::MAX_CHANNELS . ' channels are required');
            $channels = [];
        }
        foreach ($channels as $i => $channel) {
            $name = is_array($channel) ? ($channel['channel'] ?? null) : null;
            if (!$this->channels->has($name)) {
                $scope->error("{$path}.channels[{$i}].channel", is_string($name) ? "Unknown channel \"{$name}\"" : 'Channel is required');
                continue;
            }
            $this->channels->get($name)->validate($channel, $scope, "{$path}.channels[{$i}]");
        }
        if (isset($actionConfig['fail_on']) && !in_array($actionConfig['fail_on'], ['any', 'all'], true)) {
            $scope->error("{$path}.fail_on", 'Must be "any" or "all"');
        }
    }
}
