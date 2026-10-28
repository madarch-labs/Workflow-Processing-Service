<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\AssignHandler;
use Wps\Comparators\ComparatorRegistry;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Crm\CrmFilter;
use Wps\Crm\FilterRulesValidator;
use Wps\Crm\FilterTranslator;
use Wps\Services\Assign\AssignmentStrategies;
use Wps\Services\Assign\UserPoolResolver;
use Wps\Services\CrmObjectSaver;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\TypeCaster;

final class FakePools extends UserPoolResolver
{
    /** @param array<string, array<string, mixed>> $users */
    public function __construct(public array $users)
    {
    }

    public function resolve(array $pool, ExecutionContext $context, bool $onlyActive, array $exclude): array
    {
        $ids = array_map(static fn ($s) => (string)($s['value'] ?? $context->get((string)$s['path'])), (array)($pool['users'] ?? []));
        $result = array_values(array_filter($ids, fn ($id) => !in_array($id, $exclude, true) && (!$onlyActive || self::isActive($this->users[$id] ?? []))));
        sort($result, SORT_STRING);
        return ['pool' => $result, 'inactive' => [], 'excluded' => [], 'source' => 'users'];
    }

    public function user(string $id): ?array
    {
        return $this->users[$id] ?? null;
    }
}

final class FakeStrategies extends AssignmentStrategies
{
    public ?string $last = null;

    public function __construct()
    {
    }

    public function roundRobin(string $queueKey, array $pool): array
    {
        $previous = $this->last;
        $chosen = $pool[0];
        foreach ($pool as $id) {
            if ($previous !== null && strcmp($id, $previous) > 0) {
                $chosen = $id;
                break;
            }
        }
        $this->last = $chosen;
        return [$chosen, $previous];
    }

    public function leastBusy(array $pool, string $module, string $userField, CrmFilter $open, string $queueKey): array
    {
        return [$pool[1], array_combine($pool, range(5, 4 + count($pool)))];
    }
}

final class AssignTest extends TestCase
{
    private static function handler(FakePools $pools, ?FakeStrategies $strategies = null): AssignHandler
    {
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AppConfig::class, 'assignLeastBusyMaxPool'))->setValue($config, 2);
        $crm = (new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor();
        return new AssignHandler($pools, $strategies ?? new FakeStrategies(), new FilterTranslator(new TypeCaster()),
            new FilterRulesValidator(ComparatorRegistry::default(), new TypeCaster()), new CrmObjectSaver($crm), null, $config);
    }

    private static function pools(): FakePools
    {
        return new FakePools([
            'u1' => ['status' => 'Active', 'reports_to_id' => 'boss'], 'u2' => ['status' => 'Active', 'reports_to_id' => ''],
            'u3' => ['status' => 'Active', 'employee_status' => 'Leave of Absence'], 'boss' => ['status' => 'Active'],
            'gone' => ['status' => 'Inactive'], 'u9' => ['status' => 'Active', 'reports_to_id' => 'gone'],
        ]);
    }

    public function testIsActive(): void
    {
        self::assertTrue(UserPoolResolver::isActive(['status' => 'Active', 'employee_status' => '']));
        self::assertFalse(UserPoolResolver::isActive(['status' => 'Inactive']));
        self::assertFalse(UserPoolResolver::isActive(['status' => 'Active', 'employee_status' => 'Terminated']));
        self::assertFalse(UserPoolResolver::isActive(['status' => 'Active', 'is_group' => '1']));
        self::assertFalse(UserPoolResolver::isActive(['status' => 'Active', 'portal_only' => '1']));
    }

    public function testStrategies(): void
    {
        $strategies = new FakeStrategies();
        $handler = self::handler(self::pools(), $strategies);
        $pool = ['users' => [['value' => 'u2'], ['value' => 'u1'], ['value' => 'u3'], ['value' => 'gone']]];
        $assigned = [];
        foreach (range(1, 3) as $i) {
            $context = ExecutionContext::fromEvent("e{$i}", ['id' => "L{$i}", 'assigned_user_id' => 'x'], 'Leads');
            $r = $handler->execute($context, ['target' => 'object', 'strategy' => 'round_robin', 'pool' => $pool]);
            $assigned[] = $context->get('object.assigned_user_id');
        }
        self::assertSame(['u1', 'u2', 'u1'], $assigned, 'inactive and absent users are skipped, round and round');
        self::assertSame('round_robin: next after u2', $r->result['reason']);
        self::assertSame(['assigned_user_id'], $context->dirtyFields('object'), 'the field is changed: save_object sends it');

        $context = ExecutionContext::fromEvent('e', ['id' => 'C1', 'assigned_user_id' => 'u1'], 'Cases');
        $r = $handler->execute($context, ['target' => 'object', 'strategy' => 'manager']);
        self::assertSame(['boss', 'manager of u1', 'u1'], [$context->get('object.assigned_user_id'), $r->result['reason'], $r->result['previous_user_id']]);

        $context = ExecutionContext::fromEvent('e', ['id' => 'C1', 'assigned_user_id' => 'u9'], 'Cases');
        $r = $handler->execute($context, ['target' => 'object', 'strategy' => 'manager', 'fallback' => ['mode' => 'user', 'user' => ['value' => 'u2']]]);
        self::assertSame(['u2', 'fallback: user — manager gone is not active'], [$context->get('object.assigned_user_id'), $r->result['reason']]);

        $context = ExecutionContext::fromEvent('e', ['id' => 'C1', 'assigned_user_id' => 'u2'], 'Cases');
        $r = $handler->execute($context, ['target' => 'object', 'strategy' => 'manager']);
        self::assertSame(['skipped', 'u2', 'fallback: keep — user u2 has no manager'], [$r->status, $context->get('object.assigned_user_id'), $r->result['reason']]);

        $this->expectExceptionMessage('assign: fallback: error — pool is empty');
        $handler->execute($context, ['target' => 'object', 'strategy' => 'random', 'pool' => ['users' => [['value' => 'gone']]], 'fallback' => ['mode' => 'error']]);
    }

    public function testLeastBusyPoolLimit(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => 'C1'], 'Cases');
        $this->expectExceptionMessage('Pool is too large for least_busy (3 > 2)');
        self::handler(new FakePools(['a' => [], 'b' => [], 'c' => []]))->execute($context, ['target' => 'object', 'strategy' => 'least_busy',
            'pool' => ['users' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']]], 'least_busy' => ['module' => 'Cases', 'filter' => [['field' => 'state', 'operator' => 'equals', 'value' => 'Open']]]]);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $scope->declare('deals', ExecutionContext::SOURCE_LIST);
        $h = self::handler(self::pools());
        $h->validate(['target' => 'deals', 'field' => 'a.b', 'strategy' => 'queue'], $scope, 'a');
        $h->validate(['target' => 'object', 'strategy' => 'round_robin', 'pool' => ['users' => [['x' => 1]], 'security_group' => 'g'], 'queue_key' => 'bad key', 'fallback' => ['mode' => 'nobody'], 'save_to_crm' => 'yes'], $scope, 'b');
        $h->validate(['target' => 'object', 'strategy' => 'least_busy', 'pool' => ['users' => [['path' => 'v9.id']]], 'least_busy' => ['module' => 'Cases']], $scope, 'c');
        $h->validate(['target' => 'object', 'strategy' => 'user', 'exclude' => [], 'fallback' => ['mode' => 'user']], $scope, 'd');
        $h->validate(['target' => 'task', 'strategy' => 'round_robin', 'pool' => ['security_group' => ['path' => 'object.group_id']], 'queue_key' => 'web_leads'], $scope, 'ok');
        self::assertSame([
            'a.target: Variable "deals" is not a CRM record',
            'a.field: Invalid field name',
            'a.strategy: Strategy must be one of: round_robin, least_busy, random, manager, user',
            'b.pool: Pool is required: {"users": [...]} or {"security_group": ...}',
            'b.queue_key: Invalid queue key',
            'b.fallback: Fallback must be keep, error or user',
            'b.save_to_crm: Must be true or false',
            'c.pool.users[0].path: Variable "v9" is not defined at this point',
            'c.least_busy.filter: Filter of "open" records is required for least_busy',
            'd.user: Use {"path": ...} or {"value": ...}',
            'd.exclude: Must be a non-empty list of id sources',
            'd.fallback.user: Use {"path": ...} or {"value": ...}',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
