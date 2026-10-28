<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Crm\EmptyFilterValue;
use Wps\Crm\FilterRulesValidator;
use Wps\Crm\FilterTranslator;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * "Агрегат по записям" (md/tasks_list/5.1.7-aggregate.md): count / count_distinct / sum / avg / min / max over CRM
 * records found by a filter (the format of find_records) or linked to a record; the result goes to the target field.
 *
 *   { "change_type": "aggregate", "function": "sum", "field": "amount", "cast": "decimal",
 *     "source": { "module": "Opportunities", "filter": [ ... ] }, "limit": 1000, "on_limit": "error" }
 *   { "change_type": "aggregate", "function": "count",
 *     "source": { "relationship": { "module": "Accounts", "id": "object.account_id", "link": "contacts" } } }
 *
 * WPS reads the records page by page and folds them (decimal arithmetic); count by a filter uses meta.total of the
 * CRM (one request). The result is not transactional: records may change while the pages are read.
 */
final class AggregateChangeType implements ChangeTypeInterface, ReportsDetails
{
    public const FUNCTIONS = [
        'count' => 'Количество', 'count_distinct' => 'Количество различных', 'sum' => 'Сумма', 'avg' => 'Среднее',
        'min' => 'Минимум', 'max' => 'Максимум',
    ];
    private const ORDER_CASTS = ['int', 'decimal', 'date', 'datetime', 'string'];
    private const NUMBER_CASTS = ['int', 'decimal'];
    private const PAGE = 100;
    public const DEFAULT_LIMIT = 1000;

    public function __construct(
        private readonly ExternalApiAdapterService $crm,
        private readonly FilterTranslator $translator,
        private readonly FilterRulesValidator $filters,
        private readonly TypeCaster $caster,
        private readonly int $maxRecords,
        private readonly ?MetricsService $metrics = null,
    ) {
    }

    public function name(): string
    {
        return 'aggregate';
    }

    public function label(): string
    {
        return 'Агрегат по записям';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return $this->computeWithDetails($context, $config)[0];
    }

    public function computeWithDetails(ExecutionContext $context, array $config): array
    {
        $function = (string)$config['function'];
        $source = (array)($config['source'] ?? []);
        $limit = max(1, min($this->maxRecords, (int)($config['limit'] ?? self::DEFAULT_LIMIT)));
        $details = ['function' => $function, 'records_scanned' => 0, 'pages' => 0, 'truncated' => false];
        $empty = array_key_exists('empty_result', $config) ? $config['empty_result'] : (in_array($function, ['count', 'count_distinct', 'sum'], true) ? 0 : null);

        if (isset($source['relationship'])) {
            $rel = (array)$source['relationship'];
            $module = (string)($rel['module'] ?? '');
            $id = $context->get((string)($rel['id'] ?? ''));
            $details += ['module' => $module, 'link' => (string)($rel['link'] ?? '')];
            if ($id === null || $id === '') {
                return [$empty, $details + ['skipped_reason' => 'relationship record id is empty']];
            }
            $fetch = fn (?string $cursor) => $this->crm->listRelated($module, (string)$id, (string)$rel['link'], $this->fields($config), self::PAGE, $cursor);
        } else {
            $module = (string)$source['module'];
            $details['module'] = $module;
            try {
                $filter = $this->translator->translate(array_values((array)($source['filter'] ?? [])), $context);
            } catch (EmptyFilterValue $e) {
                // account_id = '' would count the records without an account
                return [$empty, $details + ['skipped_reason' => 'filter value is empty: ' . $e->getMessage()]];
            }
            if ($function === 'count' && !isset($config['field'])) {
                $total = $this->crm->count($module, $filter);
                $this->metrics?->observe(MetricsService::AGGREGATE_RECORDS, 0);
                return [$total, array_merge($details, ['pages' => 1, 'total' => $total])];
            }
            $fetch = fn (?string $cursor) => $this->crm->findPage($module, $filter, [], $this->fields($config), self::PAGE, $cursor);
        }

        $records = [];
        $cursor = null;
        do {
            $page = $fetch($cursor);
            $details['pages']++;
            foreach ($page->items as $item) {
                if (count($records) >= $limit) {
                    $details['truncated'] = true;
                    break 2;
                }
                $records[] = $item;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $details['records_scanned'] = count($records);
        $this->metrics?->observe(MetricsService::AGGREGATE_RECORDS, count($records));
        if ($details['truncated'] && ($config['on_limit'] ?? 'error') !== 'partial') {
            throw new NonRecoverableException(sprintf('aggregate: more than %d records of %s; narrow the filter or set "on_limit": "partial"', $limit, $module));
        }
        if ($records === []) {
            return [$empty, $details];
        }
        return [$this->fold($function, $records, $config, $module), $details];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $config
     */
    private function fold(string $function, array $records, array $config, string $module): mixed
    {
        if ($function === 'count') {
            return count($records);
        }
        $field = (string)$config['field'];
        $options = CastOptions::fromDefinition($config['cast'] ?? self::defaultCast($function), $config['cast_options'] ?? null);
        $values = [];
        foreach ($records as $record) {
            $raw = $record[$field] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if ($options->type === CastType::String) {
                $values[] = (string)$raw;
                continue;
            }
            $cast = $this->caster->cast($raw, $options);
            if ($cast->isError()) {
                throw new NonRecoverableException(sprintf('aggregate: value "%s" of %s.%s (id %s) is not a %s',
                    TypeCaster::stringify($raw), $module, $field, (string)($record['id'] ?? '?'), $options->type->value));
            }
            $values[] = $cast->value;
        }
        if ($function === 'count_distinct') {
            return count(array_unique(array_map(fn ($v) => $this->key($v, $options), $values)));
        }
        if ($values === []) {
            return array_key_exists('empty_result', $config) ? $config['empty_result'] : ($function === 'sum' ? 0 : null);
        }
        if ($function === 'sum' || $function === 'avg') {
            $sum = '0';
            foreach ($values as $v) {
                $sum = Decimal::add($sum, (string)$v);
            }
            $result = $function === 'sum' ? $sum : Decimal::div($sum, (string)count($values));
            $scale = isset($config['scale']) ? (int)$config['scale'] : ($function === 'avg' ? 2 : null);
            $result = $scale !== null ? Decimal::round($result, $scale) : $result;
            return Decimal::toNative(Decimal::normalize($result));
        }
        $best = array_shift($values);
        foreach ($values as $v) {
            $cmp = $options->type === CastType::String ? strcmp((string)$v, (string)$best) : $this->caster->compare($v, $best, $options);
            if (($function === 'min' && $cmp < 0) || ($function === 'max' && $cmp > 0)) {
                $best = $v;
            }
        }
        return $this->output($best, $options);
    }

    private function key(mixed $value, CastOptions $options): string
    {
        return $value instanceof \DateTimeInterface ? $this->output($value, $options) : TypeCaster::stringify($value);
    }

    /** Dates in the storage format of the CRM (date "Y-m-d", datetime UTC "Y-m-d H:i:s"), numbers as numbers. */
    private function output(mixed $value, CastOptions $options): mixed
    {
        return match ($options->type) {
            CastType::Date => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
            CastType::DateTime => $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : $value,
            CastType::Decimal => Decimal::toNative(Decimal::normalize((string)$value)),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>|null
     */
    private function fields(array $config): ?array
    {
        return isset($config['field']) ? ['id', (string)$config['field']] : ['id'];
    }

    private static function defaultCast(string $function): ?string
    {
        return in_array($function, ['sum', 'avg'], true) ? 'decimal' : null;
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $function = $config['function'] ?? null;
        if (!is_string($function) || !isset(self::FUNCTIONS[$function])) {
            $scope->error("{$path}.function", 'Function must be one of: ' . implode(', ', array_keys(self::FUNCTIONS)));
            $function = null;
        }
        $field = $config['field'] ?? null;
        if ($function !== null && $function !== 'count' && (!is_string($field) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field))) {
            $scope->error("{$path}.field", "Field is required for \"{$function}\"");
        } elseif (isset($config['field']) && (!is_string($field) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field))) {
            $scope->error("{$path}.field", 'Invalid field name');
        }
        $cast = $config['cast'] ?? null;
        if (in_array($function, ['sum', 'avg'], true) && $cast !== null && !in_array($cast, self::NUMBER_CASTS, true)) {
            $scope->error("{$path}.cast", "Function \"{$function}\" requires cast: int or decimal");
        } elseif (in_array($function, ['min', 'max'], true) && !in_array($cast, self::ORDER_CASTS, true)) {
            $scope->error("{$path}.cast", "Function \"{$function}\" requires cast: int, decimal, date, datetime or string");
        } elseif ($function === 'count_distinct' && $cast !== null && !in_array($cast, self::ORDER_CASTS, true)) {
            $scope->error("{$path}.cast", 'Cast must be one of: ' . implode(', ', self::ORDER_CASTS));
        }
        if (isset($config['scale']) && (!is_int($config['scale']) || $config['scale'] < 0 || $config['scale'] > 10)) {
            $scope->error("{$path}.scale", 'Scale must be an integer between 0 and 10');
        }
        $source = $config['source'] ?? null;
        $hasModule = is_array($source) && array_key_exists('module', $source);
        $hasRel = is_array($source) && array_key_exists('relationship', $source);
        if (!is_array($source) || $hasModule === $hasRel) {
            $scope->error("{$path}.source", 'Specify either "module" with "filter" or "relationship"');
        } elseif ($hasModule) {
            if (!is_string($source['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $source['module'])) {
                $scope->error("{$path}.source.module", 'Invalid CRM module');
            }
            if (empty($source['filter'])) {
                $scope->error("{$path}.source.filter", 'Filter is required (aggregating a whole module is not allowed)');
            } else {
                $this->filters->validate($source['filter'], true, $scope, "{$path}.source.filter", 'aggregate');
            }
        } else {
            $rel = $source['relationship'];
            if (!is_array($rel) || !is_string($rel['module'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $rel['module'])) {
                $scope->error("{$path}.source.relationship.module", 'Invalid CRM module');
            }
            if (!is_array($rel) || !is_string($rel['link'] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $rel['link'])) {
                $scope->error("{$path}.source.relationship.link", 'Link name is required (e.g. contacts)');
            }
            $scope->checkReadPath(is_array($rel) ? ($rel['id'] ?? null) : null, "{$path}.source.relationship.id");
        }
        if (array_key_exists('limit', $config) && (!is_int($config['limit']) || $config['limit'] < 1 || $config['limit'] > $this->maxRecords)) {
            $scope->error("{$path}.limit", "Limit must be between 1 and {$this->maxRecords}");
        }
        if (array_key_exists('on_limit', $config) && !in_array($config['on_limit'], ['error', 'partial'], true)) {
            $scope->error("{$path}.on_limit", 'Must be "error" or "partial"');
        }
    }
}
