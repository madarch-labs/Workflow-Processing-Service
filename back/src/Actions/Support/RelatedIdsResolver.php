<?php

declare(strict_types=1);

namespace Wps\Actions\Support;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\TypeCaster;

/**
 * Record ids from sources {"path": "owner.id"} (a value or a list — expanded) and {"value": "1"}: trimmed, empty ones
 * dropped, duplicates removed in order; a missing path is reported, not an error (md/tasks_list/done/4.12-link-records.md §4.2).
 * Shared with assignment pools (4.9) and user sources (3.11).
 */
final class RelatedIdsResolver
{
    /**
     * @param list<mixed> $sources
     * @return array{ids: list<string>, missing_paths: list<string>}
     */
    public static function resolve(array $sources, ExecutionContext $context): array
    {
        $ids = [];
        $missing = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if (array_key_exists('value', $source)) {
                $values = [$source['value']];
            } else {
                $path = (string)($source['path'] ?? '');
                [$found, $value] = $context->resolve($path);
                if (!$found || $value === null || $value === '') {
                    $missing[] = $path;
                    continue;
                }
                $values = is_array($value) ? array_values($value) : [$value];
            }
            foreach ($values as $value) {
                if (is_array($value)) {
                    $value = $value['id'] ?? null; // a list of records: their ids
                }
                $id = trim(TypeCaster::stringify($value));
                if ($id !== '' && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        return ['ids' => $ids, 'missing_paths' => $missing];
    }

    /**
     * Validates a list of sources ({"path"} / {"value"}).
     *
     * @return bool false when the list itself is not a non-empty list
     */
    public static function validateList(mixed $sources, DefinitionScope $scope, string $field, string $emptyMessage = 'At least one id source is required'): bool
    {
        if (!is_array($sources) || $sources === [] || !array_is_list($sources)) {
            $scope->error($field, $emptyMessage);
            return false;
        }
        foreach ($sources as $k => $source) {
            self::validateSource($source, $scope, "{$field}[{$k}]");
        }
        return true;
    }

    public static function validateSource(mixed $source, DefinitionScope $scope, string $field): void
    {
        if (!is_array($source) || count($source) !== 1 || !(array_key_exists('path', $source) || array_key_exists('value', $source))) {
            $scope->error($field, 'Use {"path": ...} or {"value": ...}');
        } elseif (array_key_exists('path', $source)) {
            $scope->checkReadPath($source['path'], "{$field}.path");
        } elseif (!(is_string($source['value']) && trim($source['value']) !== '') && !is_int($source['value'])) {
            $scope->error("{$field}.value", 'Id must be a non-empty string');
        }
    }
}
