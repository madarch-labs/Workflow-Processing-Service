<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Exceptions\ConfigurationException;

final class ChangeTypeRegistry
{
    /** @var array<string, ChangeTypeInterface> */
    private array $types = [];

    public function register(ChangeTypeInterface $type): void
    {
        $this->types[$type->name()] = $type;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    public function get(string $name): ChangeTypeInterface
    {
        return $this->types[$name] ?? throw new ConfigurationException("Unknown change type \"{$name}\"");
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function dictionary(): array
    {
        return array_values(array_map(
            static fn (ChangeTypeInterface $t) => ['value' => $t->name(), 'label' => $t->label()],
            $this->types,
        ));
    }
}
