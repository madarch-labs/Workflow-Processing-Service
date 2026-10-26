<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Actions\Support\RelatedIdsResolver;
use Wps\Context\ExecutionContext;
use Wps\Crm\CrmCapabilitiesService;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Связать записи" / "Отвязать записи" (md/tasks_list/done/4.12-link-records.md): links of a record variable
 * (object, a preload, a created and saved record) through a link field of its CRM module, e.g. Meetings.users with
 * accept_status. Linking is idempotent (an existing link gets its fields updated), so a repeated step is safe.
 * One class, registered twice: mode "link" and mode "unlink".
 */
final class LinkRecordsHandler implements ActionHandlerInterface
{
    public const LINK = 'link';
    public const UNLINK = 'unlink';
    private const AUDIT_IDS = 100;

    public function __construct(
        private readonly ExternalApiAdapterService $crm,
        private readonly CrmCapabilitiesService $capabilities,
        private readonly int $maxIds,
        private readonly string $mode,
        private readonly ?MetricsService $metrics = null,
    ) {
    }

    public function type(): string
    {
        return $this->mode === self::LINK ? 'link_records' : 'unlink_records';
    }

    public function label(): string
    {
        return $this->mode === self::LINK ? 'Связать записи' : 'Отвязать записи';
    }

    public function description(): string
    {
        return $this->mode === self::LINK
            ? 'Связывает запись с другими записями через link-поле CRM (участники встречи, контакты контрагента), можно с полями связи'
            : 'Удаляет связи записи с другими записями через link-поле CRM';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $record = (string)($actionConfig['record'] ?? '');
        $link = (string)($actionConfig['link'] ?? '');
        $recordId = $context->get("{$record}.id");
        $request = ['record' => $record, 'link' => $link];
        if ($recordId === null || $recordId === '') {
            $message = "Record \"{$record}\" has no id — save it before linking";
            if (($actionConfig['on_missing_record'] ?? 'skip') === 'error') {
                throw new NonRecoverableException($message);
            }
            return ActionResult::skipped($message, $request);
        }
        $module = (string)($actionConfig['module'] ?? $context->getModule($record) ?? '');
        if ($module === '') {
            throw new NonRecoverableException("Cannot link \"{$record}\": CRM module is unknown (set \"module\")");
        }
        $resolved = RelatedIdsResolver::resolve(array_values((array)($actionConfig['related'] ?? [])), $context);
        $ids = $resolved['ids'];
        $request += [
            'module' => $module, 'record_id' => (string)$recordId,
            'related_module' => $actionConfig['related_module'] ?? null,
            'related_ids' => array_slice($ids, 0, self::AUDIT_IDS), 'related_count' => count($ids),
        ];
        if ($ids === []) {
            return ActionResult::skipped('No related ids to ' . $this->mode, $request + ['missing_paths' => $resolved['missing_paths']]);
        }
        if (count($ids) > $this->maxIds) {
            throw new NonRecoverableException(sprintf('Too many related ids (%d > %d)', count($ids), $this->maxIds));
        }
        $result = $this->mode === self::LINK
            ? $this->link($module, (string)$recordId, $link, $ids, $actionConfig, $request)
            : $this->unlink($module, (string)$recordId, $link, $ids);
        if ($resolved['missing_paths'] !== []) {
            $result['missing_paths'] = $resolved['missing_paths'];
        }
        $this->metrics?->inc(MetricsService::CRM_LINKS);
        if (!empty($result['not_found']) && ($actionConfig['on_not_found'] ?? 'warn') === 'error') {
            throw new NonRecoverableException('Related records not found in the CRM: ' . implode(', ', $result['not_found']), ['result' => $result]);
        }
        return ActionResult::success($request, $result);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $config
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function link(string $module, string $recordId, string $link, array $ids, array $config, array &$request): array
    {
        $fields = array_map('strval', (array)($config['fields'] ?? []));
        $relatedModule = isset($config['related_module']) ? (string)$config['related_module'] : null;
        $batch = max(1, $this->capabilities->get()->limit('max_link_batch', 100));
        $result = ['linked' => [], 'updated' => [], 'not_found' => [], 'warnings' => []];
        $chunks = array_chunk($ids, $batch);
        $request['fields'] = $fields;
        $request['batches'] = count($chunks);
        foreach ($chunks as $chunk) {
            $items = array_map(static fn (string $id) => ['id' => $id] + ($relatedModule !== null ? ['module' => $relatedModule] : []) + ($fields !== [] ? ['fields' => $fields] : []), $chunk);
            $part = $this->crm->link($module, $recordId, $link, $items);
            foreach ($result as $key => $values) {
                $result[$key] = array_values(array_unique(array_merge($values, $part[$key] ?? [])));
            }
        }
        if ($result['warnings'] === []) {
            unset($result['warnings']);
        }
        return $result;
    }

    /**
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    private function unlink(string $module, string $recordId, string $link, array $ids): array
    {
        $removed = [];
        $notLinked = [];
        foreach ($ids as $id) {
            if ($this->crm->unlink($module, $recordId, $link, $id)) {
                $removed[] = $id;
            } else {
                $notLinked[] = $id;
            }
        }
        return ['removed' => $removed, 'not_linked' => $notLinked];
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $record = $actionConfig['record'] ?? null;
        if (!is_string($record) || !ExecutionContext::isValidVariableName($record)) {
            $scope->error("{$path}.record", 'Record variable is required (e.g. meeting)');
        } elseif (!$scope->isDeclared($record) && !$scope->inheritsVariables) {
            $scope->error("{$path}.record", "Variable \"{$record}\" is not defined at this point");
        }
        if (isset($actionConfig['module']) && (!is_string($actionConfig['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $actionConfig['module']))) {
            $scope->error("{$path}.module", 'Invalid CRM module');
        }
        if (isset($actionConfig['related_module']) && (!is_string($actionConfig['related_module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $actionConfig['related_module']))) {
            $scope->error("{$path}.related_module", 'Invalid CRM module');
        }
        if (!is_string($actionConfig['link'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $actionConfig['link'])) {
            $scope->error("{$path}.link", 'Link name is required (e.g. contacts)');
        }
        $related = $actionConfig['related'] ?? null;
        if (!is_array($related) || $related === [] || !array_is_list($related)) {
            $scope->error("{$path}.related", 'At least one related id source is required');
        } else {
            foreach ($related as $k => $source) {
                $field = "{$path}.related[{$k}]";
                if (!is_array($source) || count($source) !== 1 || !(array_key_exists('path', $source) || array_key_exists('value', $source))) {
                    $scope->error($field, 'Use {"path": ...} or {"value": ...}');
                } elseif (array_key_exists('path', $source)) {
                    $scope->checkReadPath($source['path'], "{$field}.path");
                } elseif (!(is_string($source['value']) && trim($source['value']) !== '') && !is_int($source['value'])) {
                    $scope->error("{$field}.value", 'Related id must be a non-empty string');
                }
            }
        }
        if (array_key_exists('fields', $actionConfig)) {
            if ($this->mode === self::UNLINK) {
                $scope->error("{$path}.fields", 'Fields are not used when unlinking');
            } else {
                $fields = $actionConfig['fields'];
                $ok = is_array($fields) && ($fields === [] || !array_is_list($fields));
                foreach ($ok ? $fields : [] as $name => $value) {
                    if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string)$name) || !is_scalar($value)) {
                        $ok = false;
                    }
                }
                if (!$ok) {
                    $scope->error("{$path}.fields", 'Relationship fields must be an object of scalar values');
                }
            }
        }
        foreach (['on_not_found' => ['warn', 'error'], 'on_missing_record' => ['skip', 'error']] as $key => $allowed) {
            if (isset($actionConfig[$key]) && !in_array($actionConfig[$key], $allowed, true)) {
                $scope->error("{$path}.{$key}", sprintf('Must be "%s" or "%s"', ...$allowed));
            }
        }
    }
}
