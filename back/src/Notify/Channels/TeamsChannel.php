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
 * Microsoft Teams webhook (secret of kind webhook_url): "adaptive_card" (Workflows / Power Automate, the default) or
 * "message_card" (the retiring Office 365 connectors).
 */
final class TeamsChannel implements ChannelInterface
{
    public const FORMATS = ['adaptive_card', 'message_card'];

    public function __construct(
        private readonly HttpChannelSupport $http,
        private readonly RecordLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return 'Microsoft Teams';
    }

    public function secretKinds(): array
    {
        return ['webhook_url', 'generic'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(string $format, string $title, string $message, ?string $link): array
    {
        if ($format === 'message_card') {
            return ['@type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'summary' => $title !== '' ? $title : mb_substr($message, 0, 80),
                'title' => $title, 'text' => $message]
                + ($link !== null ? ['potentialAction' => [['@type' => 'OpenUri', 'name' => 'Открыть', 'targets' => [['os' => 'default', 'uri' => $link]]]]] : []);
        }
        $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => array_values(array_filter([
            $title !== '' ? ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true] : null,
            ['type' => 'TextBlock', 'text' => $message, 'wrap' => true],
        ]))] + ($link !== null ? ['actions' => [['type' => 'Action.OpenUrl', 'title' => 'Открыть', 'url' => $link]]] : []);
        return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]]];
    }

    public function deliveries(array $config, NotifyMessage $message, ExecutionContext $context, string $delivery): array
    {
        $text = $message->text();
        $link = $message->link !== null && $this->links->isAbsolute($l = $this->links->absolute($message->link)) ? $l : null;
        $body = self::body((string)($config['format'] ?? 'adaptive_card'), $text['title'], $text['message'], $link);
        $connection = (string)$config['connection'];
        return [new Delivery('teams:' . sha1($connection), ['connection' => $connection, 'format' => $config['format'] ?? 'adaptive_card'],
            function (string $key) use ($connection, $body, $context, $delivery): array {
                $url = $this->http->secret($connection, $context->secretMasker);
                $response = $this->http->post($url, $body, 'teams', $delivery, $key, $context->secretMasker);
                $this->http->check($response, 'teams', $delivery, $key);
                return ['status' => $response->status];
            })];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $this->http->validateConnection($config['connection'] ?? null, $this->secretKinds(), $scope, "{$path}.connection");
        if (isset($config['format']) && !in_array($config['format'], self::FORMATS, true)) {
            $scope->error("{$path}.format", 'Must be adaptive_card or message_card');
        }
    }
}
