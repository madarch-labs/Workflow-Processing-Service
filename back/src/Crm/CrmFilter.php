<?php

declare(strict_types=1);

namespace Wps\Crm;

/**
 * Filter of a CRM search in the WPS contract (md/tasks_list/done/0.2-crm-contract-v9.md §4.2): conditions joined by AND,
 * operators eq neq gt gte lt lte like in nin null notnull (nin needs the
 * filter_nin feature). Values are strings in the storage format of the CRM.
 */
final class CrmFilter
{
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'nin', 'null', 'notnull'];

    /** @var list<array{field: string, op: string, value: string|list<string>|null}> */
    private array $conditions = [];

    public static function eq(string $field, string $value): self
    {
        return (new self())->add($field, 'eq', $value);
    }

    /**
     * @param string|list<string>|null $value a list for "in" / "nin", ignored for "null" / "notnull"
     */
    public function add(string $field, string $op, string|array|null $value = null): self
    {
        if (!in_array($op, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unknown CRM filter operator \"{$op}\"");
        }
        $clone = clone $this;
        $clone->conditions[] = ['field' => $field, 'op' => $op, 'value' => in_array($op, ['in', 'nin'], true) ? array_values(array_map('strval', (array)$value)) : ($value === null ? null : (string)$value)];
        return $clone;
    }

    /**
     * @return list<array{field: string, op: string, value: string|list<string>|null}>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /** Only "eq" and every field at most once: expressible in contract 1.0 (filter[f]=v). */
    public function hasOperator(string $op): bool
    {
        foreach ($this->conditions as $c) {
            if ($c['op'] === $op) {
                return true;
            }
        }
        return false;
    }

    public function isEqualityOnly(): bool
    {
        $fields = [];
        foreach ($this->conditions as $c) {
            if ($c['op'] !== 'eq' || isset($fields[$c['field']])) {
                return false;
            }
            $fields[$c['field']] = true;
        }
        return true;
    }

    /**
     * Query parameters of contract 2.0: filter[field][op]=value.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function toQuery(): array
    {
        $filter = [];
        foreach ($this->conditions as $c) {
            $filter[$c['field']][$c['op']] = in_array($c['op'], ['null', 'notnull'], true) ? '1' : $c['value'];
        }
        return $filter === [] ? [] : ['filter' => $filter];
    }

    /**
     * Query parameters of contract 1.0: filter[field]=value (equality only).
     *
     * @return array<string, array<string, string>>
     */
    public function toLegacyQuery(): array
    {
        $filter = [];
        foreach ($this->conditions as $c) {
            $filter[$c['field']] = (string)$c['value'];
        }
        return $filter === [] ? [] : ['filter' => $filter];
    }
}
