<?php

declare(strict_types=1);

namespace Wps\Notify\Channels;

use Wps\Context\ExecutionContext;
use Wps\Notify\ChannelInterface;
use Wps\Notify\Delivery;
use Wps\Notify\HttpChannelSupport;
use Wps\Notify\NotifyMessage;
use Wps\Notify\RecordLinks;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\EscapeMode;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * Telegram bot: POST {TELEGRAM_API_BASE}/bot{token}/sendMessage with parse_mode HTML. The token (secret of kind
 * telegram_bot) is in the URL path — the whole URL is masked in the audit and the logs.
 */
final class TelegramChannel implements ChannelInterface
{
    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly HttpChannelSupport $http,
        private readonly TemplateEngine $templates,
        private readonly RecordLinks $links,
        private readonly string $apiBase = 'https://api.telegram.org',
    ) {
        $this->validator = new TemplateValidator($templates);
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram';
    }

    public function secretKinds(): array
    {
        return ['telegram_bot', 'generic'];
    }

    public function deliveries(array $config, NotifyMessage $message, ExecutionContext $context, string $delivery): array
    {
        $chat = trim((string)$this->templates->render((string)($config['chat_id'] ?? ''), static fn (string $p) => $context->resolve($p))->value);
        if ($chat === '') {
            return [];
        }
        $text = $message->text(EscapeMode::Html);
        $body = ($text['title'] !== '' ? "<b>{$text['title']}</b>\n" : '') . $text['message'];
        if ($message->link !== null && $this->links->isAbsolute($link = $this->links->absolute($message->link))) {
            $body .= "\n<a href=\"" . htmlspecialchars($link, ENT_QUOTES) . '">Открыть</a>';
        }
        $connection = (string)$config['connection'];
        return [new Delivery("telegram:{$chat}", ['connection' => $connection, 'chat_id' => $chat],
            function (string $key) use ($connection, $chat, $body, $context, $delivery): array {
                $token = $this->http->secret($connection, $context->secretMasker);
                $url = rtrim($this->apiBase, '/') . '/bot' . $token . '/sendMessage';
                $response = $this->http->post($url, ['chat_id' => $chat, 'text' => $body, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true],
                    'telegram', $delivery, $key, $context->secretMasker);
                $data = json_decode($response->body, true);
                $ok = is_array($data) && ($data['ok'] ?? false) === true;
                $this->http->check($response, 'telegram', $delivery, $key, $ok ? null : (string)(is_array($data) ? ($data['description'] ?? 'not ok') : 'invalid answer'),
                    is_array($data) && isset($data['parameters']['retry_after']) ? (int)$data['parameters']['retry_after'] : null);
                return ['message_id' => $data['result']['message_id'] ?? null];
            })];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $this->http->validateConnection($config['connection'] ?? null, $this->secretKinds(), $scope, "{$path}.connection");
        $chat = $config['chat_id'] ?? null;
        if (!is_string($chat) && !is_int($chat) || trim((string)$chat) === '') {
            $scope->error("{$path}.chat_id", 'Chat id is required');
        } elseif (is_string($chat)) {
            $this->validator->validate($chat, $scope, "{$path}.chat_id");
        }
    }
}
