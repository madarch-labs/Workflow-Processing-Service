<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Exceptions\ConfigurationException;

final class BlockTypeRegistry
{
    /** @var array<string, BlockTypeInterface> */
    private array $blocks = [];

    public function register(BlockTypeInterface $block): void
    {
        $this->blocks[$block->type()] = $block;
    }

    public function has(mixed $type): bool
    {
        return is_string($type) && isset($this->blocks[$type]);
    }

    public function get(string $type): BlockTypeInterface
    {
        return $this->blocks[$type] ?? throw new ConfigurationException("Unknown block type \"{$type}\"");
    }

    /**
     * @return list<array{value: string, label: string, description: string, kind: string}>
     */
    public function dictionary(): array
    {
        return array_values(array_map(
            static fn (BlockTypeInterface $b) => ['value' => $b->type(), 'label' => $b->label(), 'description' => $b->description(), 'kind' => 'block'],
            $this->blocks,
        ));
    }
}
