<?php

declare(strict_types=1);

namespace Wps\Notify;

/**
 * Channels of the notify action by code.
 */
final class ChannelRegistry
{
    /** @var array<string, ChannelInterface> */
    private array $channels = [];

    public function register(ChannelInterface $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function has(mixed $name): bool
    {
        return is_string($name) && isset($this->channels[$name]);
    }

    public function get(string $name): ChannelInterface
    {
        return $this->channels[$name] ?? throw new \InvalidArgumentException("Unknown channel \"{$name}\"");
    }

    /**
     * @return list<array{value: string, label: string, secret_kinds: list<string>}>
     */
    public function dictionary(): array
    {
        return array_values(array_map(static fn (ChannelInterface $c) => ['value' => $c->name(), 'label' => $c->label(), 'secret_kinds' => $c->secretKinds()], $this->channels));
    }
}
