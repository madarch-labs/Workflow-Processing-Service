<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\LinkRecordsHandler;
use Wps\Actions\Support\RelatedIdsResolver;
use Wps\Context\ExecutionContext;
use Wps\Crm\CrmCapabilitiesService;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;

final class LinkRecordsTest extends TestCase
{
    public function testResolver(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => 'L1', 'assigned_user_id' => ' u1 ', 'empty' => ''], 'Leads');
        $context->setVariable('team', ['ids' => ['u2', 'u1', '', 'u3'], 'records' => [['id' => 'u4'], ['id' => 'u2']]], 'Users');
        $result = RelatedIdsResolver::resolve([
            ['path' => 'object.assigned_user_id'],
            ['path' => 'team.ids'],
            ['path' => 'team.records'],
            ['value' => 5],
            ['path' => 'object.empty'],
            ['path' => 'object.none'],
        ], $context);
        self::assertSame(['u1', 'u2', 'u3', 'u4', '5'], $result['ids']);
        self::assertSame(['object.empty', 'object.none'], $result['missing_paths']);
    }

    private static function handler(string $mode): LinkRecordsHandler
    {
        $make = static fn (string $class) => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        return new LinkRecordsHandler($make(ExternalApiAdapterService::class), $make(CrmCapabilitiesService::class), 500, $mode);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $scope->declare('meeting', 'created');
        self::handler('link')->validate(['record' => 'v9', 'link' => 'bad link', 'related' => [], 'fields' => ['Accept' => ['x']], 'on_not_found' => 'loud'], $scope, 'a');
        self::handler('link')->validate(['record' => 'meeting', 'link' => 'users', 'related' => [['path' => 'v8.id'], ['value' => ''], ['id' => 'x'], ['path' => 'object.id', 'value' => '1']], 'module' => 'bad module'], $scope, 'b');
        self::handler('unlink')->validate(['record' => 'meeting', 'link' => 'users', 'related' => [['value' => 'u1']], 'fields' => ['a' => 'b'], 'on_missing_record' => 'ignore'], $scope, 'c');
        self::handler('link')->validate(['record' => 'meeting', 'link' => 'users', 'related' => [['path' => 'object.assigned_user_id'], ['value' => '1']], 'fields' => ['accept_status' => 'accept']], $scope, 'ok');
        $errors = array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors());
        self::assertSame([
            'a.record: Variable "v9" is not defined at this point',
            'a.link: Link name is required (e.g. contacts)',
            'a.related: At least one related id source is required',
            'a.fields: Relationship fields must be an object of scalar values',
            'a.on_not_found: Must be "warn" or "error"',
            'b.module: Invalid CRM module',
            'b.related[0].path: Variable "v8" is not defined at this point',
            'b.related[1].value: Related id must be a non-empty string',
            'b.related[2]: Use {"path": ...} or {"value": ...}',
            'b.related[3]: Use {"path": ...} or {"value": ...}',
            'c.fields: Fields are not used when unlinking',
            'c.on_missing_record: Must be "skip" or "error"',
        ], $errors);
        self::assertSame(['link_records', 'unlink_records'], [self::handler('link')->type(), self::handler('unlink')->type()]);
    }
}
