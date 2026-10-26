<?php

declare(strict_types=1);

namespace Wps\Crm;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\RelativeDate;
use Wps\Types\TypeCaster;
use Wps\Time\Clock;
use Wps\Time\DateMath;
use Wps\Time\SystemClock;

/**
 * find_records filter rows (the format of process conditions) → CrmFilter of the WPS contract 2.0
 * (md/tasks_list/done/4.10-find-records.md §4.3–4.4). Values are cast with "cast" and written in the storage format of
 * the CRM: date "Y-m-d", datetime "Y-m-d H:i:s" UTC, decimal with a dot, bool 1/0.
 */
final class FilterTranslator
{
    /** operator → contract operator */
    public const SERVER_SIDE = [
        'equals' => 'eq', 'not_equals' => 'neq', 'greater_than' => 'gt', 'greater_or_equal' => 'gte', 'less_than' => 'lt',
        'less_or_equal' => 'lte', 'contains' => 'like', 'starts_with' => 'like', 'ends_with' => 'like', 'in' => 'in', 'not_in' => 'nin', 'between' => 'between', 'is_empty' => 'null', 'is_not_empty' => 'notnull',
    ];

    private readonly Clock $clock;
    private readonly DateMath $dates;

    public function __construct(private readonly TypeCaster $caster, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->dates = new DateMath($caster->businessTimezone());
    }

    public static function supportsServerSide(string $operator): bool
    {
        return isset(self::SERVER_SIDE[$operator]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param string $onEmpty empty_result | ignore_condition | error
     * @throws EmptyFilterValue for "empty_result"
     */
    public function translate(array $rows, ExecutionContext $context, string $onEmpty = 'empty_result'): CrmFilter
    {
        $filter = new CrmFilter();
        foreach ($rows as $i => $row) {
            $field = (string)($row['field'] ?? '');
            $operator = (string)($row['operator'] ?? '');
            $op = self::SERVER_SIDE[$operator] ?? throw new NonRecoverableException("Operator \"{$operator}\" is not supported in find_records filter");
            if ($op === 'null' || $op === 'notnull') {
                $filter = $filter->add($field, $op);
                continue;
            }
            $value = $this->value($row, $context, $i, $onEmpty);
            if ($value === null) {
                continue; // ignore_condition
            }
            $options = CastOptions::fromDefinition($row['cast'] ?? null, $row['cast_options'] ?? null);
            if ($op === 'between') {
                // [from, to] → gte + lte: the bounds are included
                [$from, $to] = array_values(array_pad((array)$value, 2, null));
                $filter = $filter->add($field, 'gte', $this->storage($from, $options, $i))->add($field, 'lte', $this->storage($to, $options, $i));
                continue;
            }
            if ($operator === 'in' || $operator === 'not_in') {
                $items = array_map(fn ($v) => $this->storage($v, $options, $i), array_values(is_array($value) ? $value : self::split($value)));
                $filter = $filter->add($field, $op, $items);
                continue;
            }
            $stored = $this->storage($value, $options, $i);
            if ($op === 'like') {
                $escaped = addcslashes($stored, '%_\\');
                $stored = match ($operator) {
                    'starts_with' => $escaped . '%',
                    'ends_with' => '%' . $escaped,
                    default => '%' . $escaped . '%',
                };
            }
            $filter = $filter->add($field, $op, $stored);
        }
        return $filter;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function value(array $row, ExecutionContext $context, int $index, string $onEmpty): mixed
    {
        if (($row['value_type'] ?? 'static') !== 'from_object_field') {
            return $row['value'] ?? '';
        }
        $path = (string)($row['value'] ?? '');
        [$found, $value] = $context->resolve($path);
        if ($found && $value !== null && $value !== '' && $value !== []) {
            return $value;
        }
        return match ($onEmpty) {
            'ignore_condition' => null,
            'error' => throw new NonRecoverableException("find_records: the value of filter[{$index}] ({$path}) is empty"),
            default => throw new EmptyFilterValue("The value of filter[{$index}] ({$path}) is empty"),
        };
    }

    private function storage(mixed $value, CastOptions $options, int $index): string
    {
        if (in_array($options->type, [CastType::Date, CastType::DateTime], true) && RelativeDate::looksRelative($value)) {
            $value = RelativeDate::resolve((string)$value, $this->clock->now(), $this->dates); // today-7d (3.7)
        }
        $cast = $this->caster->cast($value, $options);
        if ($cast->isError()) {
            throw new NonRecoverableException(sprintf('find_records: value "%s" of filter[%d] is not a %s', TypeCaster::stringify($value), $index, $options->type->value));
        }
        $v = $cast->value;
        return match ($options->type) {
            CastType::Date => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string)$v,
            CastType::DateTime => $v instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($v)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : (string)$v,
            CastType::Bool => $v ? '1' : '0',
            default => TypeCaster::stringify($v),
        };
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', TypeCaster::stringify($value))), static fn ($v) => $v !== ''));
    }
}
