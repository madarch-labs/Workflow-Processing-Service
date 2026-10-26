<?php

declare(strict_types=1);

namespace Wps\Types;

/**
 * Type a value is cast to before it is compared (md/tasks_list/done/3.0-type-cast.md).
 * The type is set explicitly in the process definition: the engine never reads CRM metadata.
 */
enum CastType: string
{
    case String = 'string';
    case Int = 'int';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Bool = 'bool';
    case List = 'list';

    public function label(): string
    {
        return match ($this) {
            self::String => 'Строка',
            self::Int => 'Целое число',
            self::Decimal => 'Десятичное число',
            self::Date => 'Дата',
            self::DateTime => 'Дата и время',
            self::Bool => 'Логическое',
            self::List => 'Список',
        };
    }

    /** Types that support "greater / less than". */
    public function isOrderable(): bool
    {
        return match ($this) {
            self::Int, self::Decimal, self::Date, self::DateTime => true,
            default => false,
        };
    }

    /**
     * Options accepted in "cast_options" for this type (dictionary GET /api/dictionaries/casts).
     *
     * @return list<array{name: string, type: string, default: mixed, min?: int, max?: int}>
     */
    public function options(): array
    {
        $caseSensitive = ['name' => 'case_sensitive', 'type' => 'bool', 'default' => true];
        $dates = [
            ['name' => 'format', 'type' => 'string|list', 'default' => null],
            ['name' => 'timezone', 'type' => 'string', 'default' => 'UTC'],
        ];
        return match ($this) {
            self::String => [$caseSensitive, ['name' => 'trim', 'type' => 'bool', 'default' => false]],
            self::Decimal => [['name' => 'scale', 'type' => 'int', 'default' => CastOptions::DEFAULT_SCALE, 'min' => 0, 'max' => CastOptions::MAX_SCALE]],
            self::Date, self::DateTime => $dates,
            self::List => [
                ['name' => 'separator', 'type' => 'string', 'default' => ','],
                ['name' => 'wrapper', 'type' => 'string', 'default' => null],
                $caseSensitive,
            ],
            self::Int, self::Bool => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }

    /**
     * @return list<self>
     */
    public static function orderable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t) => $t->isOrderable()));
    }
}
