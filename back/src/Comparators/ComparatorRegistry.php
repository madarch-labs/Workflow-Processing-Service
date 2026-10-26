<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Exceptions\ConfigurationException;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

final class ComparatorRegistry
{
    /** @var array<string, ComparatorInterface> */
    private array $comparators = [];

    public static function default(?TypeCaster $caster = null): self
    {
        $caster ??= new TypeCaster();
        $registry = new self();
        $registry->register(new EqualsComparator($caster));
        $registry->register(new NotEqualsComparator($caster));
        $registry->register(new GreaterThanComparator($caster));
        $registry->register(new GreaterOrEqualComparator($caster));
        $registry->register(new LessThanComparator($caster));
        $registry->register(new LessOrEqualComparator($caster));
        $registry->register(new BetweenComparator($caster));
        $registry->register(new NotBetweenComparator($caster));
        $registry->register(new InComparator($caster));
        $registry->register(new NotInComparator($caster));
        $registry->register(new ContainsComparator($caster));
        $registry->register(new NotContainsComparator($caster));
        $registry->register(new StartsWithComparator($caster));
        $registry->register(new EndsWithComparator($caster));
        $registry->register(new ContainsAnyComparator($caster));
        $registry->register(new ContainsAllComparator($caster));
        $registry->register(new ContainsNoneComparator($caster));
        $registry->register(new IsEmptyComparator($caster));
        $registry->register(new IsNotEmptyComparator($caster));
        $registry->register(new IsNullComparator($caster));
        $registry->register(new IsNotNullComparator($caster));
        $registry->register(new ChangedComparator($caster));
        $registry->register(new NotChangedComparator($caster));
        $registry->register(new ChangedToComparator($caster));
        $registry->register(new ChangedFromComparator($caster));
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
     * @return list<array{value: string, label: string, casts: list<string>, operands: string}>
     */
    public function dictionary(): array
    {
        return array_values(array_map(
            static fn (ComparatorInterface $c) => [
                'value' => $c->name(),
                'label' => $c->label(),
                'casts' => array_map(static fn (CastType $t) => $t->value, $c->supportedCasts()),
                'operands' => $c->operandKind()->value,
            ],
            $this->comparators,
        ));
    }

    /**
     * Normalizes any scalar/array value to the string form used by comparators.
     *
     * @deprecated use TypeCaster::stringify()
     */
    public static function stringify(mixed $value): string
    {
        return TypeCaster::stringify($value);
    }
}
