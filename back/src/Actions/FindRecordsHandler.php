<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Comparators\ComparatorRegistry;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Crm\EmptyFilterValue;
use Wps\Crm\FilterRulesValidator;
use Wps\Crm\FilterTranslator;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\ConditionEngineService;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\TypeCaster;

/**
 * "Найти записи" (md/tasks_list/done/4.10-find-records.md): a list of CRM records by a filter (module mode, filtered by
 * the CRM) or the records linked to a record (related mode, filtered in WPS with every condition operator). The variable
 * is {items, count, has_more, ids}: deals.count, deals.ids, deals.items.0.name. Reading only — safe to repeat.
 */
final class FindRecordsHandler implements ActionHandlerInterface
{
    private const FIELD = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const ITEM = '__item';
    private readonly FilterRulesValidator $filterValidator;

    public function __construct(
        private readonly ExternalApiAdapterService $crm,
        private readonly FilterTranslator $translator,
        private readonly ConditionEngineService $conditions,
        private readonly ComparatorRegistry $comparators,
        private readonly TypeCaster $caster,
        private readonly AppConfig $config,
    ) {
        $this->filterValidator = new FilterRulesValidator($comparators, $caster);
    }

    public function type(): string
    {
        return 'find_records';
    }

    public function label(): string
    {
        return 'Найти записи';
    }

    public function description(): string
    {
        return 'Находит записи CRM по фильтру или связанные записи и сохраняет список в переменную';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $var = (string)$actionConfig['var'];
        $limit = $this->limit($actionConfig);
        $fields = isset($actionConfig['fields']) ? array_values(array_unique(array_merge(['id'], (array)$actionConfig['fields']))) : null;
        $request = ['var' => $var, 'limit' => $limit] + array_intersect_key($actionConfig, array_flip(['module', 'related_to', 'filter', 'sort', 'fields']));
        $module = null;
        $warnings = [];
        if (isset($actionConfig['related_to'])) {
            [$items, $hasMore, $module, $reason] = $this->related($context, $actionConfig, $fields, $limit, $warnings);
        } else {
            $module = (string)$actionConfig['module'];
            $reason = null;
            try {
                $filter = $this->translator->translate(array_values((array)($actionConfig['filter'] ?? [])), $context, (string)($actionConfig['on_empty_value'] ?? 'empty_result'));
                $sort = array_map(static fn (array $s) => ['field' => (string)$s['field'], 'desc' => ($s['direction'] ?? 'asc') === 'desc'], array_values((array)($actionConfig['sort'] ?? [])));
                $found = $this->crm->findMany($module, $filter, $sort, $fields, $limit + 1);
                $items = array_slice($found['items'], 0, $limit);
                $hasMore = $found['has_more'] || count($found['items']) > $limit;
                $request['crm_filter'] = $filter->toQuery();
                $request['crm_requests'] = $found['requests'];
            } catch (EmptyFilterValue $e) {
                [$items, $hasMore, $reason] = [[], false, $e->getMessage()];
            }
        }
        if ($hasMore && ($actionConfig['on_limit'] ?? 'truncate') === 'error') {
            throw new NonRecoverableException("find_records: more than {$limit} records match (on_limit=error)");
        }
        $value = ['items' => $items, 'count' => count($items), 'has_more' => $hasMore, 'ids' => array_values(array_map(static fn ($r) => (string)($r['id'] ?? ''), $items))];
        $bytes = strlen((string)json_encode($value));
        if ($bytes > $this->config->findRecordsMaxBytes) {
            throw new NonRecoverableException(sprintf('find_records: result is too large (%.1f MB); narrow "fields" or "limit"', $bytes / 1048576));
        }
        $context->setVariable($var, $value, $module, ExecutionContext::SOURCE_LIST);
        return ActionResult::success($request, ['count' => $value['count'], 'has_more' => $hasMore, 'ids' => array_slice($value['ids'], 0, 100)]
            + ($reason !== null ? ['reason' => $reason] : []) + ($warnings !== [] ? ['warnings' => $warnings] : []));
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>|null $fields
     * @param list<string> $warnings
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: string|null, 3: string|null}
     */
    private function related(ExecutionContext $context, array $config, ?array $fields, int $limit, array &$warnings): array
    {
        $record = (string)($config['related_to']['record'] ?? '');
        $link = (string)($config['related_to']['link'] ?? '');
        $id = $context->get("{$record}.id");
        if ($id === null || $id === '') {
            return [[], false, null, "Record variable \"{$record}\" has no id"];
        }
        $module = (string)($config['related_to']['module'] ?? $context->getModule($record) ?? '');
        if ($module === '') {
            throw new NonRecoverableException("find_records: CRM module of \"{$record}\" is unknown (set related_to.module)");
        }
        // the contract does not filter links: pages are scanned and filtered here with the condition engine
        $rows = array_map(static fn (array $r) => ['field' => self::ITEM . '.' . $r['field']] + $r, array_values((array)($config['filter'] ?? [])));
        $probe = $context->forChild();
        $items = [];
        $scanned = 0;
        $cursor = null;
        $relatedModule = null;
        do {
            $page = $this->crm->listRelated($module, (string)$id, $link, $fields, 100, $cursor);
            $relatedModule ??= $page->module;
            foreach ($page->items as $item) {
                $scanned++;
                $probe->setVariable(self::ITEM, $item, null, 'filter');
                if ($rows === [] || $this->conditions->evaluate($rows, $probe)->isMet()) {
                    $items[] = $item;
                }
                if (count($items) > $limit || $scanned >= $this->config->findRecordsScanMax) {
                    break 2;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $hasMore = count($items) > $limit || ($scanned >= $this->config->findRecordsScanMax && $cursor !== null);
        if ($scanned >= $this->config->findRecordsScanMax && $cursor !== null) {
            $warnings[] = "Scanned {$scanned} related records (FIND_RECORDS_SCAN_MAX), more were not checked";
        }
        return [array_slice($items, 0, $limit), $hasMore, $relatedModule, null];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function limit(array $config): int
    {
        return max(1, min($this->config->findRecordsMax, (int)($config['limit'] ?? 100)));
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $var = $actionConfig['var'] ?? null;
        if (!is_string($var) || !ExecutionContext::isValidVariableName($var)) {
            $scope->error("{$path}.var", 'Variable name is required (letters, digits, "_"; e.g. deals)');
        } elseif ($var === ExecutionContext::OBJECT || ExecutionContext::isReadOnly($var) || $var === 'config') {
            $scope->error("{$path}.var", "Name \"{$var}\" is reserved");
        } elseif ($scope->isDeclared($var) && $scope->kind($var) !== ExecutionContext::SOURCE_LIST) {
            $scope->error("{$path}.var", "Variable \"{$var}\" is already defined");
        }
        $related = array_key_exists('related_to', $actionConfig);
        if ($related === array_key_exists('module', $actionConfig)) {
            $scope->error($path, 'Either "module" or "related_to" is required');
        } elseif ($related) {
            $r = $actionConfig['related_to'];
            if (!is_array($r) || !is_string($r['record'] ?? null) || !ExecutionContext::isValidVariableName($r['record'])) {
                $scope->error("{$path}.related_to.record", 'Record variable is required (e.g. account)');
            } elseif (!$scope->isDeclared($r['record']) && !$scope->inheritsVariables) {
                $scope->error("{$path}.related_to.record", "Variable \"{$r['record']}\" is not defined at this point");
            }
            if (!is_array($r) || !is_string($r['link'] ?? null) || !preg_match(self::FIELD, $r['link'])) {
                $scope->error("{$path}.related_to.link", 'Link name is required (e.g. contacts)');
            }
            if (array_key_exists('sort', $actionConfig)) {
                $scope->error("{$path}.sort", 'Sorting is not available for related records');
            }
        } elseif (!is_string($actionConfig['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $actionConfig['module'])) {
            $scope->error("{$path}.module", 'Invalid CRM module');
        }
        $this->filterValidator->validate($actionConfig['filter'] ?? [], !$related, $scope, "{$path}.filter");
        if (array_key_exists('sort', $actionConfig) && !$related) {
            $sort = $actionConfig['sort'];
            if (!is_array($sort) || !array_is_list($sort) || count($sort) > 2) {
                $scope->error("{$path}.sort", 'At most 2 sort fields: [{"field": ..., "direction": "asc|desc"}]');
            } else {
                foreach ($sort as $k => $s) {
                    if (!is_array($s) || !is_string($s['field'] ?? null) || !preg_match(self::FIELD, $s['field']) || !in_array($s['direction'] ?? 'asc', ['asc', 'desc'], true)) {
                        $scope->error("{$path}.sort[{$k}]", 'Use {"field": "<name>", "direction": "asc" | "desc"}');
                    }
                }
            }
        }
        if (array_key_exists('limit', $actionConfig) && (!is_int($actionConfig['limit']) || $actionConfig['limit'] < 1 || $actionConfig['limit'] > $this->config->findRecordsMax)) {
            $scope->error("{$path}.limit", "Must be an integer between 1 and {$this->config->findRecordsMax}");
        }
        if (array_key_exists('fields', $actionConfig)) {
            $f = $actionConfig['fields'];
            if (!is_array($f) || $f === [] || !array_is_list($f) || array_filter($f, static fn ($n) => !is_string($n) || !preg_match(self::FIELD, $n)) !== []) {
                $scope->error("{$path}.fields", 'Must be a non-empty list of field names');
            }
        }
        foreach (['on_empty_value' => ['empty_result', 'ignore_condition', 'error'], 'on_limit' => ['truncate', 'error']] as $key => $allowed) {
            if (isset($actionConfig[$key]) && !in_array($actionConfig[$key], $allowed, true)) {
                $scope->error("{$path}.{$key}", 'Must be one of: ' . implode(', ', $allowed));
            }
        }
        if (is_string($var) && ExecutionContext::isValidVariableName($var)) {
            $scope->declare($var, ExecutionContext::SOURCE_LIST);
        }
    }
}
