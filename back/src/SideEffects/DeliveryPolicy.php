<?php

declare(strict_types=1);

namespace Wps\SideEffects;

use Wps\Services\Validation\DefinitionScope;

/**
 * What happens when the outcome of a previous attempt of an external call is unknown (the worker stopped after
 * the request was sent): at_most_once — the step fails with UNKNOWN_OUTCOME, at_least_once — the call is repeated
 * with the same idempotency key.
 */
final class DeliveryPolicy
{
    public const AT_MOST_ONCE = 'at_most_once';
    public const AT_LEAST_ONCE = 'at_least_once';

    public const LABELS = [
        self::AT_MOST_ONCE => 'Не более одного раза',
        self::AT_LEAST_ONCE => 'Не менее одного раза',
    ];

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    public static function resolve(mixed $configured, string $default): string
    {
        return is_string($configured) && isset(self::LABELS[$configured]) ? $configured : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function validate(array $config, DefinitionScope $scope, string $path): void
    {
        if (array_key_exists('delivery', $config) && !(is_string($config['delivery']) && isset(self::LABELS[$config['delivery']]))) {
            $scope->error("{$path}.delivery", 'Delivery must be "at_most_once" or "at_least_once"');
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function dictionary(): array
    {
        return array_map(static fn (string $v) => ['value' => $v, 'label' => self::LABELS[$v]], self::values());
    }
}
