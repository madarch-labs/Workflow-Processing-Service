<?php

declare(strict_types=1);

namespace Wps\LoadStrategies;

use Wps\Exceptions\ConfigurationException;

final class LoadStrategyRegistry
{
    /** @var array<string, LoadStrategyInterface> */
    private array $strategies = [];

    public static function default(): self
    {
        $registry = new self();
        $registry->register(new ByIdStrategy());
        $registry->register(new ByFieldStrategy());
        return $registry;
    }

    public function register(LoadStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->name()] = $strategy;
    }

    public function has(string $name): bool
    {
        return isset($this->strategies[$name]);
    }

    /**
     * Explicit "strategy" in the preload row wins; otherwise by_field "id" → by_id, anything else → by_field.
     *
     * @param array<string, mixed> $config
     */
    public function resolve(array $config): LoadStrategyInterface
    {
        $name = $config['strategy'] ?? null;
        if (!is_string($name) || $name === '') {
            $byField = trim((string)($config['by_field'] ?? 'id'));
            $name = ($byField === '' || $byField === 'id') ? 'by_id' : 'by_field';
        }
        return $this->strategies[$name] ?? throw new ConfigurationException("Unknown load strategy \"{$name}\"");
    }
}
