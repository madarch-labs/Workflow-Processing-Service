<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Exceptions\ConfigurationException;

final class ActionHandlerRegistry
{
    /** @var array<string, ActionHandlerInterface> */
    private array $handlers = [];

    public function register(ActionHandlerInterface $handler): void
    {
        $this->handlers[$handler->type()] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function get(string $type): ActionHandlerInterface
    {
        return $this->handlers[$type] ?? throw new ConfigurationException("Unknown action type \"{$type}\"");
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function dictionary(): array
    {
        return array_values(array_map(
            static fn (ActionHandlerInterface $h) => ['value' => $h->type(), 'label' => $h->label(), 'description' => $h->description()],
            $this->handlers,
        ));
    }
}
