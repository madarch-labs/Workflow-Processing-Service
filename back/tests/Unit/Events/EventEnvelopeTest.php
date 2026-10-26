<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Context\EventInfo;
use Wps\Context\ExecutionContext;
use Wps\Events\EventEnvelope;
use Wps\Exceptions\ConfigurationException;
use Wps\Services\EventProcessorService;
use Wps\Services\Validation\DefinitionScope;

final class EventEnvelopeTest extends TestCase
{
    public function testFullFormat(): void
    {
        $e = EventEnvelope::fromArray([
            'id' => '8f1c', 'module' => 'Opportunities', 'event_type' => 'update',
            'before' => ['sales_stage' => 'Prospecting', 'amount' => '1000.00'],
            'changed_fields' => ['sales_stage', 'amount'],
            'wps_origin' => ['execution_id' => '6c1e', 'process_id' => 12, 'depth' => 1],
            'sales_stage' => 'Closed Won', 'amount' => '1200.00', 'wps_is_update' => '1',
        ]);
        self::assertSame('Opportunities', $e->module);
        self::assertSame(['id' => '8f1c', 'module' => 'Opportunities', 'sales_stage' => 'Closed Won', 'amount' => '1200.00', 'wps_is_update' => '1'], $e->object, 'service keys removed, old wps_* kept');
        self::assertSame('update', $e->eventType);
        self::assertSame(['sales_stage' => 'Prospecting', 'amount' => '1000.00'], $e->before);
        self::assertSame(['sales_stage', 'amount'], $e->changedFields);
        self::assertSame(['execution_id' => '6c1e', 'process_id' => 12, 'depth' => 1], $e->origin);
        self::assertFalse($e->legacy);
        self::assertSame([], $e->warnings);
    }

    public function testLegacyFormat(): void
    {
        $e = EventEnvelope::fromArray(['id' => '1', 'module' => 'Leads', 'status' => 'New', 'wps_is_update' => '1']);
        self::assertTrue($e->legacy);
        self::assertNull($e->before);
        self::assertNull($e->changedFields);
        self::assertSame('update', $e->eventType);
        self::assertNull($e->eventInfo()->isChanged('status'), 'unknown, not "not changed"');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed, mixed}>
     */
    public static function derivations(): iterable
    {
        yield 'before without changed_fields' => [['event_type' => 'update', 'before' => ['status' => 'New']], ['status' => 'New'], ['status']];
        yield 'changed_fields without before' => [['event_type' => 'update', 'changed_fields' => ['status']], null, ['status']];
        yield 'create without before' => [['event_type' => 'create'], [], null];
        yield 'create with changed fields' => [['event_type' => 'create', 'changed_fields' => ['name']], [], ['name']];
        yield 'empty before on update: nothing changed' => [['event_type' => 'update', 'before' => []], [], []];
        yield 'before as a JSON string (flat XADD)' => [['event_type' => 'update', 'before' => '{"status":"New"}', 'changed_fields' => '["status"]'], ['status' => 'New'], ['status']];
        yield 'extra keys in before stay' => [['event_type' => 'update', 'before' => ['a' => 1, 'b' => 2], 'changed_fields' => ['a']], ['a' => 1, 'b' => 2], ['a']];
        yield 'null in before is a value' => [['event_type' => 'update', 'before' => ['phone' => null]], ['phone' => null], ['phone']];
        yield 'id and module in before ignored' => [['event_type' => 'update', 'before' => ['id' => 'x', 'module' => 'y', 'name' => 'n']], ['name' => 'n'], ['name']];
        yield 'changed_fields cleaned' => [['event_type' => 'update', 'changed_fields' => ['a', 'a', '', 5, null, 'b']], null, ['a', 'b']];
    }

    /**
     * @param array<string, mixed> $service
     */
    #[DataProvider('derivations')]
    public function testDerivations(array $service, mixed $before, mixed $changed): void
    {
        $e = EventEnvelope::fromArray(['id' => '1', 'module' => 'Leads'] + $service);
        self::assertSame($before, $e->before);
        self::assertSame($changed, $e->changedFields);
        self::assertSame([], $e->warnings);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformed(): iterable
    {
        yield 'before string' => [['before' => 'abc'], 'Invalid "before"'];
        yield 'before list' => [['before' => [1, 2]], 'Invalid "before"'];
        yield 'before number' => [['before' => 5], 'Invalid "before"'];
        yield 'changed_fields object' => [['changed_fields' => ['a' => 1]], 'Invalid "changed_fields"'];
        yield 'changed_fields string' => [['changed_fields' => 'status'], 'Invalid "changed_fields"'];
        yield 'origin with a text depth' => [['wps_origin' => ['execution_id' => 'x', 'depth' => 'deep']], 'Invalid "wps_origin"'];
        yield 'origin list' => [['wps_origin' => ['x']], 'Invalid "wps_origin"'];
    }

    /**
     * @param array<string, mixed> $service
     */
    #[DataProvider('malformed')]
    public function testMalformedFieldsAreDropped(array $service, string $warning): void
    {
        $e = EventEnvelope::fromArray($service + ['id' => '1', 'module' => 'Leads', 'event_type' => 'update']);
        self::assertCount(1, $e->warnings);
        self::assertStringStartsWith($warning, $e->warnings[0]);
        self::assertSame('update', $e->eventType);
        self::assertArrayNotHasKey('before', $e->object);
        self::assertArrayNotHasKey('wps_origin', $e->object);
    }

    public function testOriginFromStrings(): void
    {
        $e = EventEnvelope::fromArray(['id' => '1', 'module' => 'Leads', 'wps_origin' => '{"execution_id":"e","process_id":"7","depth":"2"}']);
        self::assertSame(['execution_id' => 'e', 'process_id' => 7, 'depth' => 2], $e->origin);
    }

    public function testParseInStream(): void
    {
        $parsed = EventProcessorService::parse(['data' => '{"id":"5","module":"Leads","event_type":"update","before":{"status":"New"},"status":"Assigned"}']);
        $envelope = $parsed['envelope'] ?? null;
        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame(['status' => 'New'], $envelope->before);
        $flat = EventProcessorService::parse(['id' => '5', 'module' => 'Leads', 'event_type' => 'update', 'before' => '{"status":"New"}', 'status' => 'Assigned']);
        self::assertSame(['status'], $flat['envelope']->changedFields);
        self::assertSame(['error' => 'Event object has no "id"'], EventProcessorService::parse(['module' => 'Leads', 'event_type' => 'update']));
    }

    public function testContext(): void
    {
        $envelope = EventEnvelope::fromArray(['id' => '1', 'module' => 'Leads', 'event_type' => 'update', 'before' => ['status' => 'New', 'phone' => null, 'address' => ['city' => 'Riga']], 'status' => 'Assigned']);
        $context = ExecutionContext::fromEnvelope('e1', $envelope);
        self::assertSame([true, 'New'], $context->resolve('object_before.status'));
        self::assertSame([true, null], $context->resolve('object_before.phone'), 'was empty — found');
        self::assertSame([false, null], $context->resolve('object_before.name'), 'not changed — not found');
        self::assertSame([true, 'Riga'], $context->resolve('object_before.address.city'));
        self::assertSame('Leads', $context->getModule('object_before'));
        self::assertArrayNotHasKey('event_type', $context->event);
        self::assertTrue($context->eventInfo->isChanged('status'));
        self::assertFalse($context->eventInfo->isChanged('name'));

        $restored = ExecutionContext::fromArray(json_decode((string)json_encode($context->toArray()), true));
        self::assertSame('New', $restored->get('object_before.status'));
        self::assertEquals($context->eventInfo, $restored->eventInfo);
        self::assertSame(['status', 'phone', 'address'], $restored->eventInfo->changedFields);

        $child = $context->forChild();
        self::assertSame('New', $child->get('object_before.status'));
        self::assertSame($context->eventInfo, $child->eventInfo);

        $legacy = ExecutionContext::fromEvent('e2', ['id' => '1', 'status' => 'x'], 'Leads');
        self::assertSame([false, null], $legacy->resolve('object_before.status'));
        self::assertTrue($legacy->eventInfo->legacy);
        self::assertEquals(new EventInfo(), ExecutionContext::fromArray(['event_id' => 'old-state'])->eventInfo, 'state saved before 6.1');
    }

    /**
     * @return iterable<string, array{\Closure(ExecutionContext): void}>
     */
    public static function writes(): iterable
    {
        yield 'set' => [static fn (ExecutionContext $c) => $c->set('object_before.status', 'x')];
        yield 'setVariable' => [static fn (ExecutionContext $c) => $c->setVariable('object_before', [])];
        yield 'setClean' => [static fn (ExecutionContext $c) => $c->setClean('object_before', 'id', '1')];
        yield 'setModule' => [static fn (ExecutionContext $c) => $c->setModule('object_before', 'Leads')];
    }

    /**
     * @param \Closure(ExecutionContext): void $write
     */
    #[DataProvider('writes')]
    public function testObjectBeforeIsReadOnly(\Closure $write): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Variable "object_before" is read-only');
        $write(ExecutionContext::fromEvent('e', ['id' => '1', 'event_type' => 'update', 'before' => ['a' => 1]], 'Leads'));
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        self::assertTrue($scope->checkReadPath('object_before.status', 'a'));
        self::assertFalse($scope->checkWritePath('object_before.status', 'b'));
        self::assertSame([['field' => 'b', 'message' => 'Variable "object_before" is read-only']], array_map(
            static fn (array $e) => ['field' => $e['field'], 'message' => $e['message']],
            $scope->errors(),
        ));
    }
}
