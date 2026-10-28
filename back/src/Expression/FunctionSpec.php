<?php

declare(strict_types=1);

namespace Wps\Expression;

/**
 * Description of an expression function: arity (checked when a process is saved), category and texts for the
 * dictionary GET /api/dictionaries/functions and the future UI.
 */
final class FunctionSpec
{
    /**
     * Literal string value of an argument node ('str' / 'num'), null for a computed argument.
     *
     * @param array<int, mixed>|null $node
     */
    public static function literal(?array $node): ?string
    {
        return $node !== null && in_array($node[0], ['str', 'num'], true) ? (string)$node[1] : null;
    }

    public function __construct(
        public readonly string $name,
        public readonly int $minArgs,
        public readonly ?int $maxArgs,
        public readonly string $category,
        public readonly string $signature,
        public readonly string $description,
        public readonly string $example = '',
        public readonly string $returns = 'any',
        public readonly bool $pure = true,
        /** @var (\Closure(list<array<int, mixed>>): ?string)|null checks literal arguments when a process is saved */
        public readonly ?\Closure $validator = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'category' => $this->category, 'signature' => $this->signature,
            'min_args' => $this->minArgs, 'max_args' => $this->maxArgs, 'returns' => $this->returns,
            'description' => $this->description, 'example' => $this->example, 'pure' => $this->pure,
        ];
    }
}
