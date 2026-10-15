<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Exceptions\ConfigurationException;

final class ComparatorRegistry
{
    /** @var array<string, ComparatorInterface> */
    private array $comparators = [];

    public static function default(): self
    {
        $registry = new self();
        $registry->register(new EqualsComparator());
        $registry->register(new NotEqualsComparator());
        $registry->register(new ContainsComparator());
        $registry->register(new NotContainsComparator());
        return $registry;
    }

    public function register(ComparatorInterface $comparator): void
    {
        $this->comparators[$comparator->name()] = $comparator;
    }

    public function has(string $name): bool
    {
        return isset($this->comparators[$name]);
    }

    public function get(string $name): ComparatorInterface
    {
        return $this->comparators[$name] ?? throw new ConfigurationException("Unknown comparison operator \"{$name}\"");
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function dictionary(): array
    {
        return array_values(array_map(
            static fn (ComparatorInterface $c) => ['value' => $c->name(), 'label' => $c->label()],
            $this->comparators,
        ));
    }

    /**
     * Normalizes any scalar/array value to the string form used by comparators.
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
