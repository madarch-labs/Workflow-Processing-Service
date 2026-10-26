<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Wps\Comparators\ComparatorInterface;
use Wps\Comparators\ComparatorRegistry;
use Wps\Comparators\FieldChange;
use Wps\Comparators\OperandKind;
use Wps\Comparators\PresenceValue;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConditionCastException;
use Wps\Exceptions\ConfigurationException;
use Wps\Services\Conditions\ListOperand;
use Wps\Types\CastOptions;
use Wps\Types\CastResult;
use Wps\Types\CastType;
use Wps\Types\RelativeDate;
use Wps\Time\Clock;
use Wps\Time\DateMath;
use Wps\Time\SystemClock;
use Wps\Types\TypeCaster;
use Wps\Expression\ExpressionEngine;

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
 *
 * Typed comparison (md/tasks_list/done/3.0-type-cast.md): both sides are cast with the row's "cast" /
 * "cast_options" before the comparator is called. A missing field, an empty value (non-string types) or a failed
 * cast make the row false; with "on_cast_error": "error" a failed cast throws ConditionCastException.
 */
final class ConditionEngineService
{
    public const VALUE_STATIC = 'static';
    public const VALUE_FROM_FIELD = 'from_object_field';
    /** the right side is a formula (md/tasks_list/done/3.10-expression-value.md) */
    public const VALUE_EXPRESSION = 'expression';
    public const VALUE_TYPES = [self::VALUE_STATIC => 'Значение', self::VALUE_FROM_FIELD => 'Из поля объекта/переменной', self::VALUE_EXPRESSION => 'Выражение'];
    public const ON_CAST_ERROR_FALSE = 'false';
    public const ON_CAST_ERROR_ERROR = 'error';

    private readonly TypeCaster $caster;
    private readonly Clock $clock;
    private readonly DateMath $dates;

    /** @var array<string, float> process id => time of the last "no change info" warning */
    private array $lastNoChangeWarning = [];

    public function __construct(
        private readonly ComparatorRegistry $comparators,
        ?TypeCaster $caster = null,
        private readonly ?MetricsService $metrics = null,
        private readonly ?LoggerInterface $logger = null,
        ?Clock $clock = null,
        private readonly ?ExpressionEngine $expressions = null,
    ) {
        $this->caster = $caster ?? new TypeCaster();
        $this->clock = $clock ?? new SystemClock();
        $this->dates = new DateMath($this->caster->businessTimezone());
    }

    /**
     * @param array<mixed>|null $conditions
     * @param \DateTimeImmutable|null $now "now" of relative dates (3.7): one moment for all rows; the clock by default
     */
    public function evaluate(?array $conditions, ExecutionContext $context, bool $allowUnknown = false, ?\DateTimeImmutable $now = null): ConditionResult
    {
        $trace = [];
        $now ??= $this->clock->now();
        $result = $this->evaluateNode(self::normalize($conditions), $context, $allowUnknown, $trace, $now);
        return new ConditionResult($result, $trace);
    }

    /** "Now" of the clock the conditions use. */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
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
            foreach ($this->leafPaths($leaf) as $path) {
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
    private function leafPaths(array $leaf): array
    {
        $paths = [];
        if (isset($leaf['field']) && is_string($leaf['field']) && trim($leaf['field']) !== '') {
            $paths[] = $leaf['field'];
        }
        if (($leaf['value_type'] ?? null) === self::VALUE_EXPRESSION && $this->expressions !== null) {
            foreach (is_array($leaf['value'] ?? null) ? $leaf['value'] : [$leaf['value'] ?? null] as $expression) {
                try {
                    array_push($paths, ...$this->expressions->paths((string)$expression));
                } catch (\Throwable) {
                    // a broken expression is reported when the row is evaluated
                }
            }
        }
        if (($leaf['value_type'] ?? self::VALUE_STATIC) === self::VALUE_FROM_FIELD) {
            // a range [from, to] of paths gives both
            foreach (is_array($leaf['value'] ?? null) ? $leaf['value'] : [$leaf['value'] ?? null] as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $paths[] = $path;
                }
            }
        }
        return $paths;
    }

    /**
     * @param array{logical_operator: string, conditions: list<mixed>, groups: list<mixed>} $node
     * @param list<array<string, mixed>> $trace
     */
    private function evaluateNode(array $node, ExecutionContext $context, bool $allowUnknown, array &$trace, \DateTimeImmutable $now): ?bool
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
                ? $this->evaluateNode(self::normalize($item), $context, $allowUnknown, $trace, $now)
                : $this->evaluateLeaf($item, $context, $allowUnknown, $trace, $now);
        }
        foreach ($node['groups'] as $group) {
            $results[] = $this->evaluateNode(self::normalize((array)$group), $context, $allowUnknown, $trace, $now);
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
    private function evaluateLeaf(array $leaf, ExecutionContext $context, bool $allowUnknown, array &$trace, \DateTimeImmutable $now): ?bool
    {
        $field = (string)($leaf['field'] ?? '');
        $operator = (string)($leaf['operator'] ?? '');
        $valueType = (string)($leaf['value_type'] ?? self::VALUE_STATIC);
        $value = $leaf['value'] ?? null;
        $comparator = $this->comparators->get($operator);
        $options = CastOptions::fromDefinition($leaf['cast'] ?? null, $leaf['cast_options'] ?? null);
        $typed = isset($leaf['cast']) || isset($leaf['cast_options']);

        $row = [
            'field' => $field,
            'operator' => $operator,
            'value_type' => $valueType,
            'value' => $value,
        ];
        if ($typed) {
            $row['cast'] = $options->type->value;
            if ($options->toArray() !== []) {
                $row['cast_options'] = $options->toArray();
            }
        }
        $row += ['actual' => null, 'expected' => null, 'result' => null];

        $kind = $comparator->operandKind();
        if ($kind === OperandKind::Change || $kind === OperandKind::ChangeValue) {
            $result = $this->evaluateChange($comparator, $leaf, $options, $typed, $context, $allowUnknown, $row);
            $trace[] = $row;
            return $result;
        }

        // left side
        if ($this->isUnknown($field, $context, $allowUnknown)) {
            $trace[] = $row;
            return null;
        }
        [$found, $actual] = $context->resolve($field);
        $row['actual'] = $actual;

        if ($comparator->operandKind() === OperandKind::None || $comparator->operandKind() === OperandKind::Presence) {
            // no right-hand value, no cast; the comparator decides about a missing field itself
            // (is_empty / is_not_empty: false, is_null: true — md/tasks_list/done/3.2-is-empty.md)
            $row['found'] = $found;
            if (!$found) {
                $row['missing'] = 'actual';
            }
            $row['result'] = $comparator->compare(new PresenceValue($found, $actual), null, $options);
            $trace[] = $row;
            return $row['result'];
        }

        if ($comparator->operandKind() === OperandKind::Range) {
            $result = $this->evaluateRange($comparator, $found, $actual, $options, $typed, $leaf, $context, $allowUnknown, $row, $now);
            $trace[] = $row;
            return $result;
        }

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
            $expected = $this->relative($value, $options, $now, $row);
        } elseif ($valueType === self::VALUE_EXPRESSION) {
            $computed = $this->expression((string)$value, $leaf, $context, $allowUnknown, $row);
            if ($computed === null) {
                $trace[] = $row;
                return null;
            }
            [$expectedFound, $expected] = $computed;
            if (isset($row['expression_error'])) {
                $row['result'] = false;
                $trace[] = $row;
                return false;
            }
        } else {
            throw new ConfigurationException("Unknown value_type \"{$valueType}\"");
        }
        $row['expected'] = $expected;

        // a missing field never satisfies a condition (customer answer #10)
        if (!$found || !$expectedFound) {
            $row['result'] = false;
            $row['missing'] = !$found ? 'actual' : 'expected';
            $trace[] = $row;
            return false;
        }

        $result = $comparator->operandKind() === OperandKind::List
            ? $this->compareList($comparator, $actual, $expected, $options, $typed, $leaf, $context, $row)
            : $this->compareCast($comparator, $actual, $expected, $options, $typed, $leaf, $context, $row);
        $row['result'] = $result;
        $trace[] = $row;
        return $result;
    }

    /**
     * The right side computed by a formula: null — a variable of it is not loaded yet (lazy preload);
     * [false, null] — the result is null (the right side is missing); an error is kept in the trace and makes the row
     * false, with "on_cast_error": "error" it throws like a failed cast.
     *
     * @param array<string, mixed> $leaf
     * @param array<string, mixed> $row
     * @return array{0: bool, 1: mixed}|null
     */
    private function expression(string $expression, array $leaf, ExecutionContext $context, bool $allowUnknown, array &$row): ?array
    {
        if ($this->expressions === null) {
            throw new ConfigurationException('Expressions are not available in conditions');
        }
        try {
            foreach ($this->expressions->paths($expression) as $path) {
                if ($this->isUnknown($path, $context, $allowUnknown)) {
                    return null;
                }
            }
            $value = $this->expressions->evaluate($expression, static fn (string $path) => $context->get($path));
        } catch (ConditionCastException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $row['expression_error'] = $e->getMessage();
            $this->metrics?->inc(MetricsService::CONDITION_EXPRESSION_ERRORS);
            $this->logger?->warning('Condition expression failed', ['process_id' => $context->processId, 'field' => $row['field'], 'error' => $e->getMessage()]);
            if (($leaf['on_cast_error'] ?? self::ON_CAST_ERROR_FALSE) === self::ON_CAST_ERROR_ERROR) {
                $row['result'] = false;
                throw new ConditionCastException("Condition on {$row['field']}: {$e->getMessage()}", $row);
            }
            return [false, null];
        }
        if ($value === null) {
            $row['missing'] = 'expected';
            return [false, null];
        }
        return [true, $value];
    }

    /**
     * A static value of a date / datetime row that is a relative date (today+30d) becomes a moment computed from "now"
     * in WPS_TIMEZONE (md/tasks_list/done/3.7-relative-dates.md); the trace shows what it resolved to.
     *
     * @param array<string, mixed> $row
     */
    private function relative(mixed $value, CastOptions $options, \DateTimeImmutable $now, array &$row): mixed
    {
        if (!in_array($options->type, [CastType::Date, CastType::DateTime], true) || !RelativeDate::looksRelative($value)) {
            return $value;
        }
        $moment = RelativeDate::resolve((string)$value, $now, $this->dates);
        $resolved = $options->type === CastType::Date
            ? $moment->setTimezone($this->dates->local())->format('Y-m-d')
            : $moment->setTimezone($this->dates->local())->format(\DATE_ATOM);
        $row['expected_resolved'] = isset($row['expected_resolved']) ? array_merge((array)$row['expected_resolved'], [$resolved]) : $resolved;
        $row['evaluated_at'] = $now->setTimezone($this->dates->local())->format(\DATE_ATOM);
        $row['timezone'] = $this->dates->local()->getName();
        return $moment;
    }

    /**
     * between / not_between (md/tasks_list/done/3.6-between.md): both bounds are static or both are paths; the field and
     * the bounds are cast with one type. A missing or empty side, a cast error or an inverted range make the row false.
     *
     * @param array<string, mixed> $leaf
     * @param array<string, mixed> $row
     */
    private function evaluateRange(
        ComparatorInterface $comparator,
        bool $found,
        mixed $actual,
        CastOptions $options,
        bool $typed,
        array $leaf,
        ExecutionContext $context,
        bool $allowUnknown,
        array &$row,
        \DateTimeImmutable $now,
    ): ?bool {
        $pair = array_values(is_array($leaf['value'] ?? null) ? $leaf['value'] : []);
        if (count($pair) !== 2) {
            throw new ConfigurationException("Operator \"{$row['operator']}\" expects a pair [from, to]");
        }
        $bounds = [];
        foreach ($pair as $i => $bound) {
            if ($row['value_type'] === self::VALUE_EXPRESSION) {
                $computed = $this->expression((string)$bound, $leaf, $context, $allowUnknown, $row);
                if ($computed === null) {
                    return null;
                }
                if (isset($row['expression_error']) || !$computed[0]) {
                    $row['missing'] ??= "expected[{$i}]";
                    return $row['result'] = false;
                }
                $bound = $computed[1];
            } elseif ($row['value_type'] === self::VALUE_FROM_FIELD) {
                if ($this->isUnknown((string)$bound, $context, $allowUnknown)) {
                    return null;
                }
                [$boundFound, $bound] = $context->resolve((string)$bound);
                if (!$boundFound) {
                    $row['missing'] = "expected[{$i}]";
                    return $row['result'] = false;
                }
            } else {
                $bound = $this->relative($bound, $options, $now, $row);
            }
            $bounds[] = $bound;
        }
        $row['expected'] = $bounds;
        if (!$found) {
            $row['missing'] = 'actual';
            return $row['result'] = false;
        }
        $sides = ['actual' => $this->caster->cast($actual, $options), 'from' => $this->caster->cast($bounds[0], $options), 'to' => $this->caster->cast($bounds[1], $options)];
        foreach ($sides as $side => $cast) {
            if ($typed && $cast->isOk()) {
                $row[$side . '_cast'] = TypeCaster::export($cast->value, $options->type);
            }
        }
        foreach ($sides as $side => $cast) {
            if ($cast->isEmpty()) {
                $row['empty'] = $side;
                return $row['result'] = false;
            }
            if ($cast->isError()) {
                $this->castFailed($side === 'actual' ? 'actual' : 'expected', $cast, $leaf, $context, $row);
                return $row['result'] = false;
            }
        }
        if ($this->caster->compare($sides['from']->value, $sides['to']->value, $options) > 0) {
            // the bounds are not swapped silently: that would hide an error in the data
            $row['warnings'][] = 'range_inverted';
            return $row['result'] = false;
        }
        return $row['result'] = $comparator->compare($sides['actual']->value, [$sides['from']->value, $sides['to']->value], $options);
    }

    /**
     * changed / not_changed / changed_to / changed_from (md/tasks_list/done/3.3-changed-operators.md): the new value
     * is taken from the event (not from "object", which actions may have modified), the old one from object_before;
     * the change is known only for events of format 2.0 that are not deletions.
     *
     * @param array<string, mixed> $leaf
     * @param array<string, mixed> $row
     */
    private function evaluateChange(
        ComparatorInterface $comparator,
        array $leaf,
        CastOptions $options,
        bool $typed,
        ExecutionContext $context,
        bool $allowUnknown,
        array &$row,
    ): ?bool {
        [$var, $name] = ExecutionContext::splitPath($row['field']);
        $info = $context->eventInfo;
        $row['event_type'] = $info->eventType;
        $expected = null;
        if ($comparator->operandKind() === OperandKind::ChangeValue) {
            if ($row['value_type'] === self::VALUE_FROM_FIELD) {
                $path = (string)$row['value'];
                if ($this->isUnknown($path, $context, $allowUnknown)) {
                    return null;
                }
                [$expectedFound, $expectedRaw] = $context->resolve($path);
                if (!$expectedFound) {
                    $row['missing'] = 'expected';
                    return $row['result'] = false;
                }
            } else {
                $expectedRaw = $row['value'];
            }
            $row['expected'] = $expectedRaw;
            $expected = $this->caster->cast($expectedRaw, $comparator->expectedCast($options));
            if ($expected->isError()) {
                $this->castFailed('expected', $expected, $leaf, $context, $row);
                return $row['result'] = false;
            }
            if ($expected->isOk()) {
                $row['expected_cast'] = TypeCaster::export($expected->value, $options->type);
            }
        }
        if ($var !== ExecutionContext::OBJECT || $name === null || $info->changedFields === null || $info->eventType === 'delete') {
            $row['change_info'] = $info->eventType === 'delete' ? 'delete' : 'missing';
            if ($row['change_info'] === 'missing') {
                $this->warnNoChangeInfo($context);
            }
            return $row['result'] = $comparator->compare(FieldChange::unknown(), [null, true], $options);
        }

        $afterRaw = $context->event[$name] ?? null;
        if (!array_key_exists($name, $context->event)) {
            $row['missing'] = 'actual';
        }
        [, $beforeRaw] = $context->resolve(ExecutionContext::OBJECT_BEFORE . '.' . $name);
        $row['actual'] = $afterRaw;
        $row['before'] = $beforeRaw;
        $after = $this->caster->cast($afterRaw, $options);
        $before = $this->caster->cast($beforeRaw, $options);
        foreach (['actual' => $after, 'before' => $before] as $side => $cast) {
            if ($cast->isError()) {
                $this->castFailed($side, $cast, $leaf, $context, $row);
                return $row['result'] = false;
            }
            if ($typed && $cast->isOk()) {
                $row[$side . '_cast'] = TypeCaster::export($cast->value, $options->type);
            }
        }
        $differs = $after->isEmpty() || $before->isEmpty()
            ? $after->isEmpty() !== $before->isEmpty()
            : !$this->caster->equals($after->value, $before->value, $options);
        $change = new FieldChange(
            known: true,
            changed: in_array($name, $info->changedFields, true) && $differs,
            before: $before->isOk() ? $before->value : null,
            beforeEmpty: !$before->isOk(),
            after: $after->isOk() ? $after->value : null,
            afterEmpty: !$after->isOk(),
        );
        $row['changed'] = $change->changed;
        return $row['result'] = $comparator->compare($change, [$expected?->isOk() ? $expected->value : null, $expected === null || !$expected->isOk()], $options);
    }

    /**
     * Change operators on an event without change info: one warning per process and worker a minute.
     */
    private function warnNoChangeInfo(ExecutionContext $context): void
    {
        $this->metrics?->inc(MetricsService::CONDITION_CHANGE_INFO_MISSING);
        $key = (string)($context->processId ?? 0);
        $now = microtime(true);
        if (($this->lastNoChangeWarning[$key] ?? 0.0) > $now - 60) {
            return;
        }
        $this->lastNoChangeWarning[$key] = $now;
        $this->logger?->warning('Change operators used but the event has no change info', [
            'process_id' => $context->processId, 'event_id' => $context->eventId, 'event_type' => $context->eventInfo->eventType,
        ]);
    }

    /**
     * Casts both sides and calls the comparator; empty values and cast errors make the row false.
     *
     * @param array<string, mixed> $leaf
     * @param array<string, mixed> $row trace row, completed with *_cast, empty, cast_error
     */
    private function compareCast(
        ComparatorInterface $comparator,
        mixed $actual,
        mixed $expected,
        CastOptions $options,
        bool $typed,
        array $leaf,
        ExecutionContext $context,
        array &$row,
    ): bool {
        $expectedOptions = $comparator->expectedCast($options);
        $sides = [
            'actual' => $this->caster->cast($actual, $options),
            'expected' => $this->caster->cast($expected, $expectedOptions),
        ];
        if ($typed) {
            foreach ($sides as $side => $cast) {
                if ($cast->isOk()) {
                    $row[$side . '_cast'] = TypeCaster::export($cast->value, $side === 'actual' ? $options->type : $expectedOptions->type);
                }
            }
        }
        foreach ($sides as $side => $cast) {
            if ($cast->isEmpty()) {
                $row['empty'] = $side;
                return false;
            }
        }
        foreach ($sides as $side => $cast) {
            if ($cast->isError()) {
                $this->castFailed($side, $cast, $leaf, $context, $row);
                return false;
            }
        }
        return $comparator->compare($sides['actual']->value, $sides['expected']->value, $options);
    }

    /**
     * in / not_in: the right side is a list (static array or a field), every item cast like the field;
     * items that do not cast are skipped (or fail the row in the strict mode).
     *
     * @param array<string, mixed> $leaf
     * @param array<string, mixed> $row
     */
    private function compareList(
        ComparatorInterface $comparator,
        mixed $actual,
        mixed $expected,
        CastOptions $options,
        bool $typed,
        array $leaf,
        ExecutionContext $context,
        array &$row,
    ): bool {
        $left = $this->caster->cast($actual, $options);
        if ($left->isOk() && $typed) {
            $row['actual_cast'] = TypeCaster::export($left->value, $options->type);
        }
        // a list field (contains_any / all / none, 3.9): a string on the right is parsed like the field
        $listOptions = (array)($leaf['value_list_options'] ?? ($options->type === CastType::List ? array_filter(['separator' => $options->separator, 'wrapper' => $options->wrapper], static fn ($v) => $v !== null) : []));
        $list = ListOperand::resolve($this->caster, $expected, $comparator->expectedCast($options), $listOptions);
        $list['items'] = $options->type === CastType::List ? array_values(array_unique($list['items'])) : $list['items'];
        $row['expected_cast'] = array_map(static fn ($v) => TypeCaster::export($v, $options->type), $list['items']);
        if ($options->type === CastType::List && $list['items'] === []) {
            $row['empty'] = 'expected'; // "contains all of nothing" is almost always an error in the data
            return false;
        }
        if ($left->isEmpty()) {
            if ($options->type === CastType::List) {
                // an empty list is a value ("no tags"): contains_none is true for it
                $row['actual_cast'] = [];
                return $comparator->compare([], $list['items'], $options);
            }
            $row['empty'] = 'actual';
            return false;
        }
        if ($left->isError()) {
            $this->castFailed('actual', $left, $leaf, $context, $row);
            return false;
        }
        foreach ($list['errors'] as $error) {
            $this->castFailed('expected', CastResult::error("Item {$error['index']}: {$error['message']}"), $leaf, $context, $row);
        }
        if ($options->type === CastType::List && in_array($comparator->name(), ['contains_any', 'contains_none'], true)) {
            $row['matched'] = array_values(array_intersect((array)$left->value, $list['items']));
        }
        return $comparator->compare($left->value, $list['items'], $options);
    }

    /**
     * @param array<string, mixed> $leaf
     * @param array<string, mixed> $row
     */
    private function castFailed(string $side, CastResult $cast, array $leaf, ExecutionContext $context, array &$row): void
    {
        $row['cast_error'] = ['side' => $side, 'message' => (string)$cast->error];
        $this->metrics?->inc(MetricsService::CONDITION_CAST_ERRORS);
        $strict = ($leaf['on_cast_error'] ?? self::ON_CAST_ERROR_FALSE) === self::ON_CAST_ERROR_ERROR;
        $log = [
            'process_id' => $context->processId, 'execution_id' => $context->executionId, 'field' => $row['field'],
            'cast' => $row['cast'] ?? 'string', 'side' => $side,
            'value' => mb_substr(TypeCaster::stringify($row[$side]), 0, 200), 'message' => $cast->error,
        ];
        if ($strict) {
            $row['result'] = false;
            $this->logger?->error('Condition cast failed', $log);
            throw new ConditionCastException("Condition on {$row['field']}: {$cast->error}", $row);
        }
        $this->logger?->warning('Condition cast failed', $log);
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
