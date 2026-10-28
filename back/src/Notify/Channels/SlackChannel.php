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

/**
 * Slack incoming webhook (secret of kind webhook_url — whoever knows the URL can post to the channel):
 * {"text": fallback, "blocks": [section mrkdwn]}. Slack mrkdwn escapes &, < and >.
 */
final class SlackChannel implements ChannelInterface
{
    public function __construct(
        private readonly HttpChannelSupport $http,
        private readonly RecordLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return 'Slack';
    }

    public function secretKinds(): array
    {
        return ['webhook_url', 'generic'];
    }

    public static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    public function deliveries(array $config, NotifyMessage $message, ExecutionContext $context, string $delivery): array
    {
        $text = $message->text();
        $mrkdwn = ($text['title'] !== '' ? '*' . self::escape($text['title']) . "*\n" : '') . self::escape($text['message']);
        if ($message->link !== null && $this->links->isAbsolute($link = $this->links->absolute($message->link))) {
            $mrkdwn .= "\n<{$link}|Открыть>";
        }
        $body = ['text' => $message->plain(), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $mrkdwn]]]];
        $connection = (string)$config['connection'];
        return [new Delivery('slack:' . sha1($connection), ['connection' => $connection],
            function (string $key) use ($connection, $body, $context, $delivery): array {
                $url = $this->http->secret($connection, $context->secretMasker);
                $response = $this->http->post($url, $body, 'slack', $delivery, $key, $context->secretMasker);
                $this->http->check($response, 'slack', $delivery, $key);
                return ['status' => $response->status];
            })];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $this->http->validateConnection($config['connection'] ?? null, $this->secretKinds(), $scope, "{$path}.connection");
    }
}
