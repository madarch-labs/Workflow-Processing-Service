<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Program;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Expression\ExpressionEngine;
use Wps\Persistence\Process;
use Wps\Program\BlockValidationContext;
use Wps\Program\Blocks\StopProcessBlock;
use Wps\Program\Instruction;
use Wps\Program\Interpreter;
use Wps\Services\ConditionEngineService;
use Wps\Services\StepRecord;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\TemplateEngine;

final class StopProcessTest extends TestCase
{
    private static function interpreter(): Interpreter
    {
        return new Interpreter(
            new ConditionEngineService(ComparatorRegistry::default()),
            static fn (Instruction $i, int $pc, int $seq, ExecutionContext $ctx, mixed $owner): StepRecord
                => new StepRecord($seq, (string)$i->config['type'], 'success', 1, microtime(true), 0, pc: $pc, path: $i->path),
            new TemplateEngine(new ExpressionEngine()),
        );
    }

    /**
     * @return array{0: list<StepRecord>, 1: array<string, mixed>|null}
     */
    private static function runActions(array $actions, array $object): array
    {
        $program = ProgramCompilerTest::compiler()->compile(Process::normalizeDefinition(['actions' => $actions]));
        $context = ExecutionContext::fromEvent('e', ['id' => '1'] + $object, 'Leads');
        $records = [];
        $pc = 0;
        $seq = 0;
        while ($pc < $program->count()) {
            $outcome = self::interpreter()->step($program->at($pc), $pc, $seq, $context);
            if ($outcome->record !== null) {
                $records[] = $outcome->record;
                $seq++;
            }
            if ($outcome->halt !== null) {
                return [$records, $outcome->halt];
            }
            $pc = $outcome->nextPc;
        }
        return [$records, null];
    }

    public function testStopInsideABranch(): void
    {
        $actions = [
            ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'object.email', 'operator' => 'is_empty']], 'actions' => [
                ['type' => 'stop_process', 'status' => 'failed', 'code' => 'NO_CONTACTS', 'message' => 'У лида {{ object.last_name }} нет email'],
            ]]]],
            ['type' => 'save_object'],
        ];
        [$records, $halt] = self::runActions($actions, ['email' => '', 'last_name' => 'Иванов']);
        self::assertSame(['status' => 'failed', 'message' => 'У лида Иванов нет email', 'code' => 'NO_CONTACTS'], $halt);
        self::assertSame('stop_process', $records[1]->actionType);
        self::assertSame('success', $records[1]->status);
        self::assertSame('У лида Иванов нет email', $records[1]->result['message']);
        [$records, $halt] = self::runActions($actions, ['email' => 'a@b.ru', 'last_name' => 'Иванов']);
        self::assertNull($halt);
        self::assertSame('save_object', end($records)->actionType);
    }

    public function testCompletedAndBrokenTemplate(): void
    {
        [, $halt] = self::runActions([['type' => 'stop_process', 'message' => 'Сделка уже закрыта'], ['type' => 'save_object']], []);
        self::assertSame(['status' => 'completed', 'message' => 'Сделка уже закрыта', 'code' => null], $halt);
        [$records, $halt] = self::runActions([['type' => 'stop_process', 'status' => 'failed', 'message' => 'x {{ object.a | number }} y']], ['a' => 'abc']);
        self::assertSame('x {{ object.a | number }} y', $halt['message'], 'the raw text is used when the template fails');
        self::assertArrayHasKey('message_error', $records[0]->result);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $context = new BlockValidationContext(static fn () => null, static fn () => null);
        $block = new StopProcessBlock(new TemplateEngine(new ExpressionEngine()));
        $block->validate(['status' => 'aborted'], $scope, 'a', $context);
        $block->validate(['status' => 'failed'], $scope, 'b', $context);
        $block->validate(['message' => str_repeat('x', 1001), 'code' => 'no-code', 'on_error' => 'continue'], $scope, 'c', $context);
        $block->validate(['message' => '{{ v9.x }}'], $scope, 'd', $context);
        $block->validate(['status' => 'completed', 'code' => 'ALREADY_CLOSED'], $scope, 'ok', $context);
        self::assertSame([
            'a.status: Status must be "completed" or "failed"',
            'b.message: Message is required for status "failed"',
            'c.message: Message must be a string up to 1000 characters',
            'c.code: Code must match ^[A-Z][A-Z0-9_]{0,63}$',
            'c.on_error: on_error is not allowed for stop_process',
            'd.message: Variable "v9" is not defined at this point',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
