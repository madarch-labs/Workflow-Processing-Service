<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Actions\ActionResult;
use Wps\Context\ExecutionContext;
use Wps\Events\EventEnvelope;
use Wps\Persistence\Process;
use Wps\Services\CrmObjectSaver;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;

final class EventTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, bool, bool}>
     */
    public static function types(): iterable
    {
        yield 'create' => [['event_type' => 'create'], 'create', false, false];
        yield 'upper case' => [['event_type' => 'DELETE'], 'delete', false, false];
        yield 'link is unsupported' => [['event_type' => 'link'], 'link', true, false];
        yield 'empty type is unsupported' => [['event_type' => ''], 'unknown', true, false];
        yield 'legacy new record' => [['wps_is_update' => '0'], 'create', false, true];
        yield 'legacy update' => [['wps_is_update' => '1'], 'update', false, true];
        yield 'legacy without flag' => [[], 'update', false, true];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('types')]
    public function testEventType(array $fields, string $type, bool $unsupported, bool $legacy): void
    {
        $e = EventEnvelope::fromArray(['id' => '1', 'module' => 'Leads'] + $fields);
        self::assertSame($type, $e->eventType);
        self::assertSame($unsupported, $e->unsupportedType);
        self::assertSame($legacy, $e->legacy);
        self::assertSame([], $e->warnings);
    }

    public function testProcessTriggerOn(): void
    {
        $row = ['id' => 1, 'name' => 'p', 'object_type' => 'Leads', 'is_child' => 'f', 'is_active' => 't', 'priority' => 0, 'version' => 1, 'definition' => '{}'];
        self::assertSame(['create'], Process::fromRow($row + ['trigger_on' => '{create}'])->triggerOn);
        self::assertSame([], Process::fromRow($row + ['trigger_on' => '{}'])->triggerOn);
        self::assertSame(['create', 'update', 'delete'], Process::fromRow($row + ['trigger_on' => '{create,update,delete}'])->triggerOn);
        self::assertSame(Process::DEFAULT_TRIGGER_ON, Process::fromRow($row)->triggerOn);
        $process = Process::fromRow($row + ['trigger_on' => '{update,delete}']);
        self::assertTrue($process->triggersOn('delete'));
        self::assertFalse($process->triggersOn('create'));
        $data = $process->toArray();
        self::assertSame(['update', 'delete'], Process::fromArray($data)->triggerOn);
        unset($data['trigger_on']);
        self::assertSame(Process::DEFAULT_TRIGGER_ON, Process::fromArray($data)->triggerOn, 'cache written before 6.2');
        self::assertSame(['create', 'delete'], Process::normalizeTriggerOn(['delete', 'create', 'delete', 'x']));
        self::assertSame('{create,update}', Process::toPgArray(['create', 'update']));
    }

    public function testOnlyOnDelete(): void
    {
        self::assertTrue((new DefinitionScope(triggerOn: ['delete']))->onlyOnDelete());
        self::assertFalse((new DefinitionScope(triggerOn: ['create', 'delete']))->onlyOnDelete());
        self::assertFalse((new DefinitionScope(inheritsVariables: true, triggerOn: ['delete']))->onlyOnDelete(), 'child processes ignore trigger_on');
        self::assertTrue((new DefinitionScope(triggerOn: ['delete']))->fork()->onlyOnDelete());
    }

    public function testDeletedRecordIsNotSaved(): void
    {
        // no CRM call happens in this test: the adapter is never used
        $api = (new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor();
        $saver = new CrmObjectSaver($api);
        $context = ExecutionContext::fromEvent('e', ['id' => '1', 'module' => 'Leads', 'event_type' => 'delete', 'deleted' => '1'], 'Leads');
        $context->set('object.note', 'x');
        $skipped = $saver->save($context, 'object');
        self::assertSame(ActionResult::SKIPPED, $skipped->status);
        self::assertSame('Record was deleted in the CRM — nothing to save', $skipped->result['reason']);
        $context->setVariable('acc', ['id' => 'a1'], 'Accounts');
        $other = $saver->save($context, 'acc');
        self::assertSame('No changed fields to save', $other->result['reason'], 'other variables go the usual way');
    }
}
