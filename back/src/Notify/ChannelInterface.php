<?php

declare(strict_types=1);

namespace Wps\Notify;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * Extension point: a channel of the notify action (CRM alert, Telegram, Slack, Teams). A channel turns the common
 * message into deliveries; NotifyHandler runs them through the side effect journal and builds the step outcome.
 */
interface ChannelInterface
{
    /** Code in the definition: "channel": "telegram". */
    public function name(): string;

    public function label(): string;

    /**
     * Secret kinds accepted in "connection" (empty — the channel has no connection).
     *
     * @return list<string>
     */
    public function secretKinds(): array;

    /**
     * @param array<string, mixed> $config the channel element of "channels"
     */
    public function validate(array $config, DefinitionScope $scope, string $path): void;

    /**
     * @param array<string, mixed> $config
     * @return list<Delivery> empty — nothing to send (the channel is "skipped")
     */
    public function deliveries(array $config, NotifyMessage $message, ExecutionContext $context, string $delivery): array;
}
