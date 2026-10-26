<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Program;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Persistence\Process;
use Wps\Program\Instruction;
use Wps\Program\Interpreter;
use Wps\Program\Program;
use Wps\Services\ConditionEngineService;
use Wps\Services\StepRecord;

/**
 * The interpreter loop as the engine runs it, with a fake action executor.
 */
final class InterpreterTest extends TestCase
{
    /** @var list<string> */
    private array $executed = [];

    private function interpreter(?string $failPath = null): Interpreter
    {
        return new Interpreter(
            new ConditionEngineService(ComparatorRegistry::default()),
            function (Instruction $i, int $pc, int $seq, ExecutionContext $ctx, mixed $owner) use ($failPath): StepRecord {
                $this->executed[] = $i->path;
                return new StepRecord($seq, (string)$i->config['type'], $i->path === $failPath ? 'failed' : 'success', 1, microtime(true), 0, pc: $pc, path: $i->path);
            },
        );
    }

    /**
     * @return array{0: list<StepRecord>, 1: string} records and the final state (completed / failed / halt:<status>)
     */
    private function runProgram(Program $program, ExecutionContext $context, Interpreter $interpreter): array
    {
        $records = [];
        $pc = 0;
        $seq = 0;
        while ($pc < $program->count()) {
            $outcome = $interpreter->step($program->at($pc), $pc, $seq, $context);
            if ($outcome->record !== null) {
                $records[] = $outcome->record;
                $seq++;
            }
            if ($outcome->halt !== null) {
                return [$records, 'halt:' . $outcome->halt['status']];
            }
            if ($outcome->failed) {
                return [$records, 'failed'];
            }
            $pc = $outcome->nextPc;
        }
        return [$records, 'completed'];
    }

    private static function program(): Program
    {
        $definition = Process::normalizeDefinition(['actions' => [
            ['type' => 'modify', 'id' => 'first'],
            ['type' => 'condition', 'branches' => [
                ['conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '1000']], 'actions' => [['type' => 'modify', 'id' => 'big']]],
                ['conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '10']], 'actions' => [['type' => 'modify', 'id' => 'small']]],
            ], 'else' => [['type' => 'modify', 'id' => 'other']]],
            ['type' => 'save_object'],
        ]]);
        return ProgramCompilerTest::compiler()->compile($definition);
    }

    public function testFirstBranch(): void
    {
        [$records, $state] = $this->runProgram(self::program(), ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '1000'], 'Deals'), $this->interpreter());
        self::assertSame('completed', $state);
        self::assertSame(['actions[0]', 'actions[1].branches[0].actions[0]', 'actions[2]'], $this->executed);
        self::assertSame('condition', $records[1]->actionType);
        self::assertSame(0, $records[1]->result['selected']);
        self::assertSame([0, 1, 2, 3], array_map(static fn (StepRecord $r) => $r->stepIndex, $records), 'seq grows by audited records');
    }

    public function testElseIfBranchRecordsBothChecks(): void
    {
        [$records] = $this->runProgram(self::program(), ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '10'], 'Deals'), $this->interpreter());
        self::assertSame(['actions[0]', 'actions[1].branches[1].actions[0]', 'actions[2]'], $this->executed);
        self::assertSame(1, $records[1]->result['selected']);
        self::assertCount(2, $records[1]->result['branches']);
        self::assertFalse($records[1]->result['branches'][0]['result']);
    }

    public function testElseBranch(): void
    {
        [$records] = $this->runProgram(self::program(), ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '5'], 'Deals'), $this->interpreter());
        self::assertSame(['actions[0]', 'actions[1].else[0]', 'actions[2]'], $this->executed);
        self::assertSame('else', $records[1]->result['selected']);
    }

    public function testFailureInABranchStops(): void
    {
        $program = self::program();
        [$records, $state] = $this->runProgram($program, ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '1000'], 'Deals'), $this->interpreter('actions[1].branches[0].actions[0]'));
        self::assertSame('failed', $state);
        self::assertSame('actions[1].branches[0].actions[0]', end($records)->path);
        self::assertSame([7], $program->remainingAfter(3), 'only the following top-level step is skipped');
    }

    public function testHalt(): void
    {
        $program = new Program([
            new Instruction(Instruction::HALT, ['type' => 'stop_process', 'status' => 'failed', 'message' => 'No email'], 'actions[0]', null, 0, 0),
            new Instruction(Instruction::ACTION, ['type' => 'modify'], 'actions[1]', null, 0, 1),
        ], [], 'h');
        [$records, $state] = $this->runProgram($program, ExecutionContext::fromEvent('e', ['id' => '1'], 'Deals'), $this->interpreter());
        self::assertSame('halt:failed', $state);
        self::assertSame('stop_process', $records[0]->actionType);
        self::assertSame([], $this->executed);
    }

    public function testJumpIfFalse(): void
    {
        $program = new Program([
            new Instruction(Instruction::JUMP_IF_FALSE, ['conditions' => [['field' => 'object.a', 'operator' => 'equals', 'value' => '1']], 'target' => 2], 'actions[0].where', null, 0, 0),
            new Instruction(Instruction::ACTION, ['type' => 'modify'], 'actions[0].body[0]', null, 1, 0),
            new Instruction(Instruction::ACTION, ['type' => 'save_object'], 'actions[1]', null, 0, 1),
        ], [], 'h');
        $this->runProgram($program, ExecutionContext::fromEvent('e', ['id' => '1', 'a' => '2'], 'Deals'), $this->interpreter());
        self::assertSame(['actions[1]'], $this->executed);
    }

    public function testBranchWithoutConditionsMatchesAndNamesAreTraced(): void
    {
        $program = ProgramCompilerTest::compiler()->compile(Process::normalizeDefinition(['actions' => [
            ['type' => 'condition', 'branches' => [
                ['name' => 'Крупная', 'conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '1000']], 'actions' => [['type' => 'modify', 'id' => 'big']]],
                ['name' => 'Остальные', 'conditions' => [], 'actions' => []],
            ]],
            ['type' => 'save_object'],
        ]]));
        [$records, $state] = $this->runProgram($program, ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '5'], 'Deals'), $this->interpreter());
        self::assertSame('completed', $state);
        self::assertSame(1, $records[0]->result['selected']);
        self::assertSame(['Крупная', 'Остальные'], array_column($records[0]->result['branches'], 'name'));
        self::assertSame(['condition', 'save_object'], array_map(static fn (StepRecord $r) => $r->actionType, $records), 'an empty branch runs nothing');
    }
}
