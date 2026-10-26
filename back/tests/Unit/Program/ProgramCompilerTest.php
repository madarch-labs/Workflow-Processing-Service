<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Program;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Persistence\Process;
use Wps\Program\Blocks\ConditionBlock;
use Wps\Program\Blocks\StopProcessBlock;
use Wps\Program\BlockTypeRegistry;
use Wps\Program\Instruction;
use Wps\Program\ProgramCompiler;

final class ProgramCompilerTest extends TestCase
{
    public static function compiler(int $maxDepth = 5): ProgramCompiler
    {
        $blocks = new BlockTypeRegistry();
        $blocks->register(new ConditionBlock());
        $blocks->register(new StopProcessBlock());
        return new ProgramCompiler($blocks, $maxDepth);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function linearDefinitions(): iterable
    {
        $all = json_decode((string)file_get_contents(__DIR__ . '/fixtures/linear_definitions.json'), true);
        foreach ($all as $name => $definition) {
            yield $name => [$definition];
        }
    }

    /**
     * Compatibility invariant: a definition without blocks compiles one-to-one into the old step list.
     *
     * @param array<string, mixed> $definition
     */
    #[DataProvider('linearDefinitions')]
    public function testLinearDefinitionMatchesOldSteps(array $definition): void
    {
        $normalized = Process::normalizeDefinition($definition);
        $program = self::compiler()->compile($normalized);
        $steps = Process::stepsOf($normalized);
        self::assertSame(count($steps), $program->count());
        foreach ($steps as $index => $step) {
            $instruction = $program->at($index);
            self::assertSame(Instruction::ACTION, $instruction->op);
            self::assertSame($step, $instruction->config);
            self::assertSame($index, $instruction->topLevel);
            self::assertSame(0, $instruction->depth);
        }
        self::assertSame('preload[0]', $normalized['preload'] !== [] ? $program->at(0)->path : 'preload[0]');
    }

    /**
     * @return array<string, mixed>
     */
    private static function conditionDefinition(bool $withElse = true): array
    {
        $condition = [
            'type' => 'condition', 'id' => 'big_deal',
            'branches' => [['conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '1']],
                'actions' => [['type' => 'modify', 'target' => 'object.priority', 'change_type' => 'set_value', 'value' => 'High']]]],
        ];
        if ($withElse) {
            $condition['else'] = [['type' => 'modify', 'target' => 'object.priority', 'change_type' => 'set_value', 'value' => 'Normal']];
        }
        return Process::normalizeDefinition([
            'preload' => [['var' => 'acc', 'source' => 'object_field', 'value' => 'object.account_id', 'module' => 'Accounts']],
            'actions' => [
                ['type' => 'modify', 'target' => 'object.description', 'change_type' => 'set_value', 'value' => 'x'],
                $condition,
                ['type' => 'save_object', 'target' => 'object'],
            ],
        ]);
    }

    public function testConditionWithElse(): void
    {
        $program = self::compiler()->compile(self::conditionDefinition());
        $ops = array_map(static fn (Instruction $i) => $i->op . ' ' . $i->path, $program->instructions);
        self::assertSame([
            'action preload[0]',
            'action actions[0]',
            'branch actions[1]',
            'action actions[1].branches[0].actions[0]',
            'jump actions[1].branches[0]',
            'action actions[1].else[0]',
            'action actions[2]',
        ], $ops);
        $branch = $program->at(2);
        self::assertSame(3, $branch->config['branches'][0]['target']);
        self::assertSame(5, $branch->config['else_target']);
        self::assertSame(6, $branch->config['end_target']);
        self::assertSame(6, $program->at(4)->config['target']);
        self::assertSame('big_deal', $branch->stepId);
        self::assertSame(2, $program->pcOf('big_deal'));
        self::assertSame(2, $program->at(3)->topLevel);
        self::assertSame(1, $program->at(3)->depth);
        self::assertSame(2, $program->at(3)->parent);
        self::assertSame(3, $program->at(6)->topLevel);
    }

    public function testConditionWithoutElseJumpsToTheEnd(): void
    {
        $program = self::compiler()->compile(self::conditionDefinition(false));
        $branch = $program->at(2);
        self::assertSame(5, $branch->config['else_target']);
        self::assertSame(5, $branch->config['end_target']);
        self::assertFalse($branch->config['has_else']);
    }

    public function testEmptyBranchAndNestedCondition(): void
    {
        $inner = ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'object.a', 'operator' => 'equals', 'value' => '1']], 'actions' => []]]];
        $outer = ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'object.b', 'operator' => 'equals', 'value' => '1']], 'actions' => [$inner]]]];
        $program = self::compiler()->compile(Process::normalizeDefinition(['actions' => [$outer]]));
        self::assertSame(['branch', 'branch', 'jump', 'jump'], array_map(static fn (Instruction $i) => $i->op, $program->instructions));
        self::assertSame(1, $program->at(1)->depth, 'the inner block is an element of the outer branch');
        self::assertSame(0, $program->at(1)->parent);
        self::assertSame(4, $program->at(0)->config['end_target']);
    }

    public function testCompilationIsDeterministic(): void
    {
        $a = self::compiler()->compile(self::conditionDefinition());
        $b = self::compiler()->compile(self::conditionDefinition());
        self::assertSame($a->hash, $b->hash);
        self::assertEquals($a->toArray(), $b->toArray());
        $compiler = self::compiler();
        self::assertSame($compiler->compile(self::conditionDefinition()), $compiler->compile(self::conditionDefinition()), 'cached');
        self::assertSame(1, $compiler->compiledCount());
    }

    public function testTooDeepNesting(): void
    {
        $block = ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1'];
        for ($i = 0; $i < 3; $i++) {
            $block = ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'object.a', 'operator' => 'equals', 'value' => '1']], 'actions' => [$block]]]];
        }
        self::compiler(3)->compile(Process::normalizeDefinition(['actions' => [$block]]));
        $this->expectException(ConfigurationException::class);
        self::compiler(2)->compile(Process::normalizeDefinition(['actions' => [$block]]));
    }

    public function testRemainingAfter(): void
    {
        $program = self::compiler()->compile(self::conditionDefinition());
        // a failure in the "then" branch: the rest of that branch (nothing) + the following top-level step
        self::assertSame([6], $program->remainingAfter(3));
        // a failure on a top-level step before the block: the block itself and the step after it, not the branches
        self::assertSame([2, 6], $program->remainingAfter(1));
        // preload failure: everything on the top level
        self::assertSame([1, 2, 6], $program->remainingAfter(0));
        self::assertSame([], $program->remainingAfter(6));
    }

    public function testCollectChildIdsWalksNestedBlocks(): void
    {
        $definition = ['actions' => [
            ['type' => 'start_process', 'process_id' => 3],
            ['type' => 'condition', 'branches' => [['conditions' => [], 'actions' => [['type' => 'start_process', 'process_ids' => [4, '5']]]]],
                'else' => [['type' => 'start_process', 'process_id' => 3]]],
        ]];
        self::assertSame([3, 4, 5], ProgramCompiler::collectChildIds($definition));
    }
}
