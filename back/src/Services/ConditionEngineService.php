<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;

/**
 * Evaluates process conditions (TS §5.3).
 *
 * The definition may be either a flat list of rows (current UI, all joined by AND) or a tree
 * { "logical_operator": "AND"|"OR", "conditions": [...], "groups": [...] } — so adding OR/brackets
 * later only needs UI support, not changes here.
 *
 * Three-valued evaluation is used for lazy preload: a row that references a variable which is not
 * loaded yet evaluates to null ("unknown") when $allowUnknown is true. The caller then runs the
 * preload block and evaluates again.
 */
final class ConditionEngineService
{
    public const VALUE_STATIC = 'static';
    public const VALUE_FROM_FIELD = 'from_object_field';
    public const VALUE_TYPES = [self::VALUE_STATIC => 'Значение', self::VALUE_FROM_FIELD => 'Из поля объекта/переменной'];

    public function __construct(private readonly ComparatorRegistry $comparators)
    {
    }

    /**
     * @param array<mixed>|null $conditions
     */
    public function evaluate(?array $conditions, ExecutionContext $context, bool $allowUnknown = false): ConditionResult
    {
        $trace = [];
        $result = $this->evaluateNode(self::normalize($conditions), $context, $allowUnknown, $trace);
        return new ConditionResult($result, $trace);
    }

    /**
     * Names of variables (other than "object") referenced by any condition row.
     *
     * @param array<mixed>|null $conditions
     * @return string[]
     */
    public function referencedVariables(?array $conditions): array
    {
        $vars = [];
        foreach (self::leaves(self::normalize($conditions)) as $leaf) {
            foreach (self::leafPaths($leaf) as $path) {
                [$var] = ExecutionContext::splitPath($path);
                if ($var !== ExecutionContext::OBJECT) {
                    $vars[$var] = true;
                }
            }
        }
        return array_keys($vars);
    }

    /**
     * @param array<mixed>|null $conditions
     * @return array{logical_operator: string, conditions: list<mixed>, groups: list<mixed>}
     */
    public static function normalize(?array $conditions): array
    {
        if ($conditions === null || $conditions === []) {
            return ['logical_operator' => 'AND', 'conditions' => [], 'groups' => []];
        }
        if (array_is_list($conditions)) {
            return ['logical_operator' => 'AND', 'conditions' => $conditions, 'groups' => []];
        }
        return [
            'logical_operator' => strtoupper((string)($conditions['logical_operator'] ?? $conditions['operator'] ?? 'AND')),
            'conditions' => array_values((array)($conditions['conditions'] ?? [])),
            'groups' => array_values((array)($conditions['groups'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    public static function leaves(array $node): array
    {
        $result = [];
        foreach ($node['conditions'] as $item) {
            if (is_array($item) && (isset($item['conditions']) || isset($item['groups']))) {
                $result = array_merge($result, self::leaves(self::normalize($item)));
            } elseif (is_array($item)) {
                $result[] = $item;
            }
        }
        foreach ($node['groups'] as $group) {
            if (is_array($group)) {
                $result = array_merge($result, self::leaves(self::normalize($group)));
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $leaf
     * @return string[]
     */
    private static function leafPaths(array $leaf): array
    {
        $paths = [];
        if (isset($leaf['field']) && is_string($leaf['field']) && trim($leaf['field']) !== '') {
            $paths[] = $leaf['field'];
        }
        if (($leaf['value_type'] ?? self::VALUE_STATIC) === self::VALUE_FROM_FIELD
            && is_string($leaf['value'] ?? null) && trim($leaf['value']) !== '') {
            $paths[] = $leaf['value'];
        }
        return $paths;
    }

    /**
     * @param array{logical_operator: string, conditions: list<mixed>, groups: list<mixed>} $node
     * @param list<array<string, mixed>> $trace
     */
    private function evaluateNode(array $node, ExecutionContext $context, bool $allowUnknown, array &$trace): ?bool
    {
        $operator = $node['logical_operator'];
        if ($operator !== 'AND' && $operator !== 'OR') {
            throw new ConfigurationException("Unsupported logical operator \"{$operator}\"");
        }

        $results = [];
        foreach ($node['conditions'] as $item) {
            if (!is_array($item)) {
                throw new ConfigurationException('Condition row must be an object');
            }
            $results[] = (isset($item['conditions']) || isset($item['groups']))
                ? $this->evaluateNode(self::normalize($item), $context, $allowUnknown, $trace)
                : $this->evaluateLeaf($item, $context, $allowUnknown, $trace);
        }
        foreach ($node['groups'] as $group) {
            $results[] = $this->evaluateNode(self::normalize((array)$group), $context, $allowUnknown, $trace);
        }

        if ($operator === 'AND') {
            if (in_array(false, $results, true)) {
                return false;
            }
            return in_array(null, $results, true) ? null : true;
        }
        if ($results === []) {
            return true;
        }
        if (in_array(true, $results, true)) {
            return true;
        }
        return in_array(null, $results, true) ? null : false;
    }

    /**
     * @param array<string, mixed> $leaf
     * @param list<array<string, mixed>> $trace
     */
    private function evaluateLeaf(array $leaf, ExecutionContext $context, bool $allowUnknown, array &$trace): ?bool
    {
        $field = (string)($leaf['field'] ?? '');
        $operator = (string)($leaf['operator'] ?? '');
        $valueType = (string)($leaf['value_type'] ?? self::VALUE_STATIC);
        $value = $leaf['value'] ?? null;
        $comparator = $this->comparators->get($operator);

        $row = [
            'field' => $field,
            'operator' => $operator,
            'value_type' => $valueType,
            'value' => $value,
            'actual' => null,
            'expected' => null,
            'result' => null,
        ];

        // left side
        if ($this->isUnknown($field, $context, $allowUnknown)) {
            $trace[] = $row;
            return null;
        }
        [$found, $actual] = $context->resolve($field);
        $row['actual'] = $actual;

        // right side
        if ($valueType === self::VALUE_FROM_FIELD) {
            $path = (string)$value;
            if ($this->isUnknown($path, $context, $allowUnknown)) {
                $trace[] = $row;
                return null;
            }
            [$expectedFound, $expected] = $context->resolve($path);
        } elseif ($valueType === self::VALUE_STATIC) {
            $expectedFound = true;
            $expected = $value;
        } else {
            throw new ConfigurationException("Unknown value_type \"{$valueType}\"");
        }
        $row['expected'] = $expected;

        // a missing field never satisfies a condition (customer answer #10)
        $result = $found && $expectedFound
            && $comparator->compare(ComparatorRegistry::stringify($actual), ComparatorRegistry::stringify($expected));

        $row['result'] = $result;
        $trace[] = $row;
        return $result;
    }

    private function isUnknown(string $path, ExecutionContext $context, bool $allowUnknown): bool
    {
        if (!$allowUnknown) {
            return false;
        }
        [$var] = ExecutionContext::splitPath($path);
        return $var !== ExecutionContext::OBJECT && !$context->hasVariable($var);
    }
}
