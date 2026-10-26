<?php

declare(strict_types=1);

namespace Wps\Crm;

use Wps\Comparators\ComparatorRegistry;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\CastOptions;
use Wps\Types\RelativeDate;
use Wps\Types\TypeCaster;

/**
 * Validation of filter rows in the format of process conditions that select CRM records (find_records 4.10,
 * aggregate 5.1.7): fields, operators the CRM contract can express, values and their "cast".
 */
final class FilterRulesValidator
{
    public const LIST_MAX = 100;
    private const FIELD = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private readonly ComparatorRegistry $comparators,
        private readonly TypeCaster $caster,
    ) {
    }

    /**
     * @param bool   $serverSide the filter goes to the CRM (only operators of the contract)
     * @param string $owner      name in the messages: find_records, aggregate
     */
    public function validate(mixed $filter, bool $serverSide, DefinitionScope $scope, string $path, string $owner = 'find_records'): void
    {
        if (!is_array($filter) || !array_is_list($filter)) {
            $scope->error($path, 'Must be a list of conditions');
            return;
        }
        foreach ($filter as $i => $row) {
            $rowPath = "{$path}[{$i}]";
            if (!is_array($row)) {
                $scope->error($rowPath, 'Condition must be an object');
                continue;
            }
            if (!is_string($row['field'] ?? null) || !preg_match(self::FIELD, $row['field'])) {
                $scope->error("{$rowPath}.field", 'Field name of the found records is required (e.g. sales_stage)');
            }
            $operator = $row['operator'] ?? null;
            if (!is_string($operator) || !$this->comparators->has($operator)) {
                $scope->error("{$rowPath}.operator", 'Unknown comparison operator');
                continue;
            }
            if ($serverSide && !FilterTranslator::supportsServerSide($operator)) {
                $scope->error("{$rowPath}.operator", in_array($operator, ['is_null', 'is_not_null'], true)
                    ? "Operator \"{$operator}\" is not supported in {$owner} filter: use is_empty"
                    : "Operator \"{$operator}\" is not supported in {$owner} filter");
                continue;
            }
            if (in_array($operator, ['is_empty', 'is_not_empty', 'is_null', 'is_not_null'], true)) {
                continue;
            }
            $valueType = $row['value_type'] ?? 'static';
            if ($operator === 'between' && $valueType !== 'static') {
                $scope->error("{$rowPath}.value_type", "Operator \"between\" takes static bounds in a {$owner} filter");
                continue;
            }
            if ($valueType === 'from_object_field') {
                $scope->checkReadPath($row['value'] ?? null, "{$rowPath}.value");
                continue;
            }
            if ($valueType !== 'static') {
                $scope->error("{$rowPath}.value_type", 'Must be "static" or "from_object_field"');
                continue;
            }
            $value = $row['value'] ?? null;
            if ($operator === 'between') {
                if (!is_array($value) || count($value) !== 2) {
                    $scope->error("{$rowPath}.value", 'Operator "between" expects a pair [from, to]');
                } elseif (!in_array($row['cast'] ?? null, ['int', 'decimal', 'date', 'datetime'], true)) {
                    $scope->error("{$rowPath}.cast", 'Operator "between" requires cast: int, decimal, date or datetime');
                } else {
                    $options = CastOptions::fromDefinition($row['cast'], $row['cast_options'] ?? null);
                    foreach (array_values($value) as $k => $bound) {
                        if (RelativeDate::looksRelative($bound) && in_array($row['cast'], ['date', 'datetime'], true)) {
                            if (($error = RelativeDate::error((string)$bound)) !== null) {
                                $scope->error("{$rowPath}.value[{$k}]", $error);
                            }
                            continue;
                        }
                        $cast = $this->caster->cast($bound, $options);
                        if (!$cast->isOk()) {
                            $scope->error("{$rowPath}.value[{$k}]", $cast->isError() ? (string)$cast->error : 'Range bound is required');
                        }
                    }
                }
                continue;
            }
            if (in_array($operator, ['in', 'not_in'], true)) {
                if (!is_array($value) || $value === [] || count($value) > self::LIST_MAX) {
                    $scope->error("{$rowPath}.value", sprintf('List of 1..%d values is required', self::LIST_MAX));
                }
                continue;
            }
            if (isset($row['cast']) && in_array($row['cast'], ['date', 'datetime'], true) && RelativeDate::looksRelative($value)) {
                if (($error = RelativeDate::error((string)$value)) !== null) {
                    $scope->error("{$rowPath}.value", $error);
                }
            } elseif (isset($row['cast'])) {
                $options = CastOptions::fromDefinition($row['cast'], $row['cast_options'] ?? null);
                $cast = $this->caster->cast($value, $options);
                if ($cast->isError()) {
                    $scope->error("{$rowPath}.value", (string)$cast->error);
                }
            } elseif (in_array($operator, ['greater_than', 'greater_or_equal', 'less_than', 'less_or_equal'], true)) {
                $scope->error("{$rowPath}.cast", "Operator \"{$operator}\" requires cast: int, decimal, date or datetime");
            }
        }
    }
}
