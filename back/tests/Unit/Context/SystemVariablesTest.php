<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Time\FrozenClock;

final class SystemVariablesTest extends TestCase
{
    private static function context(): ExecutionContext
    {
        return ExecutionContext::fromEvent('event-1', [
            'id' => 'record-1',
            'wps_user_id' => 'user-7',
            'event_type' => 'update',
            'changed_fields' => ['status', 'amount'],
            'before' => ['status' => 'New', 'amount' => '10'],
        ], 'Accounts');
    }

    private static function withRuntime(ExecutionContext $context): ExecutionContext
    {
        $context->executionId = 'exec-1';
        $context->processId = 12;
        $context->trigger = 'stream';
        $context->parentExecutionId = 'parent-1';
        $context->processName = 'Account flow';
        $context->processVersion = 4;
        $context->depth = 2;
        $context->startedAt = '2026-10-26 10:00:00';
        $context->setClock(new FrozenClock('2026-10-26 12:34:56'));
        return $context;
    }

    public function testEverySystemFieldIsResolved(): void
    {
        $context = self::withRuntime(self::context());
        $expected = [
            'execution_id' => 'exec-1',
            'parent_execution_id' => 'parent-1',
            'process_id' => 12,
            'process_name' => 'Account flow',
            'process_version' => 4,
            'event_id' => 'event-1',
            'trigger' => 'stream',
            'depth' => 2,
            'module' => 'Accounts',
            'record_id' => 'record-1',
            'event_type' => 'update',
            'changed_fields' => ['status', 'amount'],
            'user_id' => 'user-7',
            'now' => '2026-10-26 12:34:56',
            'started_at' => '2026-10-26 10:00:00',
            'errors_count' => 0,
        ];
        foreach ($expected as $field => $value) {
            self::assertSame([true, $value], $context->resolve("sys.{$field}"), $field);
        }
        self::assertSame(count(ExecutionContext::SYS_FIELDS) - 1, count($context->getVariable('sys')), 'last_error is absent before the first handled error');
        self::assertSame([false, null], $context->resolve('sys.unknown'));
        self::assertTrue($context->hasVariable('sys'));
    }

    public function testEventValuesHaveNeutralFallbacks(): void
    {
        $context = ExecutionContext::fromEvent('event-2', ['id' => 'r2', 'user_id' => 'u2'], 'Leads');
        self::assertSame([true, 'u2'], $context->resolve('sys.user_id'));
        self::assertSame([true, 'update'], $context->resolve('sys.event_type'));
        self::assertSame([true, []], $context->resolve('sys.changed_fields'));
        self::assertSame([true, ''], $context->resolve('sys.execution_id'));
        self::assertSame([true, ''], $context->resolve('sys.parent_execution_id'));
        self::assertSame([true, ''], $context->resolve('sys.started_at'));
        self::assertSame([true, 0], $context->resolve('sys.process_id'));
        self::assertSame([true, 0], $context->resolve('sys.process_version'));
        self::assertSame([true, ''], $context->resolve('sys.process_name'));
    }

    public function testConfigValues(): void
    {
        $constants = ['vip_threshold' => 100000, 'manager_email' => 'head@example.com', 'open_stages' => ['A', 'B']];
        $context = self::context();
        $context->setConstants($constants);
        self::assertTrue($context->hasVariable('config'));
        self::assertSame($constants, $context->getVariable('config'));
        self::assertSame([true, 100000], $context->resolve('config.vip_threshold'));
        self::assertSame([true, ['A', 'B']], $context->resolve('config.open_stages'));
        self::assertSame([false, null], $context->resolve('config.missing'));
        self::assertSame([true, $constants], $context->resolve('config'));

        $empty = self::context();
        self::assertSame([true, []], $empty->resolve('config'));
        self::assertSame([false, null], $empty->resolve('config.any'));
    }

    public function testReservedVariablesCannotBeWritten(): void
    {
        $context = self::context();
        foreach (ExecutionContext::READ_ONLY as $var) {
            try {
                $context->setVariable($var, []);
                self::fail("setVariable(\"{$var}\") must fail");
            } catch (ConfigurationException $e) {
                self::assertSame("Variable \"{$var}\" is read-only", $e->getMessage());
            }
            try {
                $context->set("{$var}.field", 1);
                self::fail("set(\"{$var}.field\") must fail");
            } catch (ConfigurationException $e) {
                self::assertSame("Variable \"{$var}\" is read-only", $e->getMessage());
            }
        }
        self::assertFalse(ExecutionContext::isReadOnly(ExecutionContext::OBJECT), 'object is writable');
        self::assertSame(['object', 'object_before', 'sys', 'config'], ExecutionContext::RESERVED);
    }

    public function testNowIsReadAtTheMomentOfTheCall(): void
    {
        $clock = new FrozenClock('2026-10-26 10:00:00');
        $context = new ExecutionContext('e', [], [], clock: $clock);
        self::assertSame([true, '2026-10-26 10:00:00'], $context->resolve('sys.now'));
        $clock->set('2026-10-26 10:00:05');
        self::assertSame([true, '2026-10-26 10:00:05'], $context->resolve('sys.now'));
    }

    public function testHandledErrorsAreMergedIntoSystemValues(): void
    {
        $context = self::context();
        $context->recordHandledError(['code' => 'HTTP_503', 'message' => 'unavailable']);
        self::assertSame([true, 'HTTP_503'], $context->resolve('sys.last_error.code'));
        self::assertSame([true, 1], $context->resolve('sys.errors_count'));
        self::assertSame(1, $context->handledErrors());
    }

    public function testChildDoesNotInheritRuntimeValuesOrConstants(): void
    {
        $context = self::withRuntime(self::context());
        $context->setConstants(['threshold' => 10]);
        $child = $context->forChild();
        self::assertSame(3, $child->depth);
        self::assertSame([true, ''], $child->resolve('sys.execution_id'));
        self::assertSame([true, ''], $child->resolve('sys.trigger'));
        self::assertSame([true, ''], $child->resolve('sys.parent_execution_id'));
        self::assertSame([false, null], $child->resolve('config.threshold'));
    }
}
