<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;

final class ExecutionContextTest extends TestCase
{
    public function testSplitPath(): void
    {
        self::assertSame(['object', 'a.b'], ExecutionContext::splitPath('object.a.b'));
        self::assertSame(['v1', null], ExecutionContext::splitPath('v1'));
        self::assertSame(['v1', 'x'], ExecutionContext::splitPath('  v1.x '));
    }

    public function testSplitPathRejectsEmptyParts(): void
    {
        $this->expectException(ConfigurationException::class);
        ExecutionContext::splitPath('v1.');
    }

    public function testResolve(): void
    {
        $context = ExecutionContext::fromEvent('e1', ['id' => '1', 'address' => ['city' => 'Riga'], 'empty' => ''], 'Accounts');
        self::assertSame([true, 'Riga'], $context->resolve('object.address.city'));
        self::assertSame([true, ''], $context->resolve('object.empty'));
        self::assertSame([false, null], $context->resolve('object.nothing'));
        self::assertSame([false, null], $context->resolve('v9.x'));

        $context->setVariable('v1', null, 'Accounts');
        self::assertSame([false, null], $context->resolve('v1.x'), 'a variable that was not found has no fields');
        self::assertSame('Accounts', $context->getModule('v1'));
    }

    public function testSetCreatesVariableAndTracksDirtyFields(): void
    {
        $context = ExecutionContext::fromEvent('e1', ['id' => '1'], 'Deals');
        $context->set('task.name', 'Call');
        self::assertSame(['name' => 'Call'], $context->getVariable('task'));
        self::assertSame(['module' => null, 'source' => 'created'], $context->meta()['task']);
        self::assertSame(['name'], $context->dirtyFields('task'));

        $context->setClean('task', 'id', 't1');
        self::assertSame(['name'], $context->dirtyFields('task'), 'setClean does not mark the field dirty');
        $context->clearDirty('task');
        self::assertSame([], $context->dirtyFields('task'));
    }

    public function testSetRequiresAField(): void
    {
        $this->expectException(ConfigurationException::class);
        ExecutionContext::fromEvent('e1', ['id' => '1'], 'Deals')->set('task', 'x');
    }

    public function testSnapshotAndRestore(): void
    {
        $context = ExecutionContext::fromEvent('e1', ['id' => '1', 'amount' => 10], 'Deals');
        $snapshot = $context->snapshotState();
        $context->set('object.amount', 20);
        $context->restoreState($snapshot);
        self::assertSame(10, $context->get('object.amount'));
        self::assertSame([], $context->dirtyFields('object'));
    }

    public function testArrayRoundTrip(): void
    {
        $context = ExecutionContext::fromEvent('e1', ['id' => '1'], 'Deals');
        $context->set('object.status', 'Won');
        $copy = ExecutionContext::fromArray($context->toArray());
        self::assertSame($context->toArray(), $copy->toArray());
        self::assertSame(['status'], $copy->dirtyFields('object'));
    }

    public function testForChildCopiesVariablesWithoutDirtyFields(): void
    {
        $context = ExecutionContext::fromEvent('e1', ['id' => '1'], 'Deals');
        $context->set('object.status', 'Won');
        $child = $context->forChild();
        self::assertSame('Won', $child->get('object.status'));
        self::assertSame([], $child->dirtyFields('object'));
        self::assertSame(1, $child->depth);
    }
}
