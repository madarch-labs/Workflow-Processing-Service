<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Program;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Expression\ExpressionEngine;
use Wps\Persistence\Process;
use Wps\Program\BlockTypeRegistry;
use Wps\Program\BlockValidationContext;
use Wps\Program\Blocks\ConditionBlock;
use Wps\Program\Blocks\ForeachBlock;
use Wps\Program\Blocks\StopProcessBlock;
use Wps\Program\Instruction;
use Wps\Program\Interpreter;
use Wps\Program\LoopItemsStore;
use Wps\Program\LoopRunner;
use Wps\Program\ProgramCompiler;
use Wps\Services\ConditionEngineService;
use Wps\Services\ExecutionHandle;
use Wps\Services\StepRecord;
use Wps\Services\Validation\DefinitionScope;

/**
 * In-memory snapshot of loop items.
 */
final class MemoryLoopItems extends LoopItemsStore
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    public function __construct()
    {
    }

    public function save(string $executionId, int $pc, array $items): string
    {
        $key = self::key($executionId, $pc);
        $this->lists[$key] = array_map(static fn ($i) => json_encode($i), $items);
        return $key;
    }

    public function item(string $key, int $index): array
    {
        return isset($this->lists[$key][$index]) ? [true, json_decode($this->lists[$key][$index], true)] : [false, null];
    }

    public function delete(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->lists[$key]);
        }
    }
}

/**
 * foreach (md/tasks_list/4.11-foreach.md): compilation, the loop instructions as the engine runs them, item errors,
 * resuming in the middle of a loop, validation.
 */
final class ForeachTest extends TestCase
{
    private MemoryLoopItems $store;
    /** @var list<string> path@iteration of executed actions */
    private array $executed = [];

    protected function setUp(): void
    {
        $this->store = new MemoryLoopItems();
    }

    private static function compiler(): ProgramCompiler
    {
        $blocks = new BlockTypeRegistry();
        $blocks->register(new ConditionBlock());
        $blocks->register(new StopProcessBlock());
        $blocks->register(new ForeachBlock(new ExpressionEngine(), 500));
        return new ProgramCompiler($blocks);
    }

    private function runner(int $max = 500): LoopRunner
    {
        return new LoopRunner(new ConditionEngineService(ComparatorRegistry::default()), new ExpressionEngine(), $this->store, $max);
    }

    /**
     * @param array<mixed> $actions
     */
    private function handle(array $actions, array $object = ['id' => '1']): ExecutionHandle
    {
        $definition = Process::normalizeDefinition(['actions' => $actions]);
        $process = Process::fromArray(['id' => 1, 'name' => 't', 'object_type' => 'Accounts', 'is_child' => false, 'is_active' => true,
            'priority' => 0, 'version' => 1, 'definition' => $definition]);
        return new ExecutionHandle('exec-1', $process, self::compiler()->compile($definition), ExecutionContext::fromEvent('e', $object, 'Accounts'),
            'manual', null, 't', 0, 0, [], 0, [], microtime(true));
    }

    /**
     * Engine loop in short: records (overwrite keeps seq), item errors through LoopRunner::itemError.
     *
     * @param \Closure(Instruction, ExecutionContext): ?string|null $action returns an error message to fail the step
     * @return array{0: array<int, StepRecord>, 1: string}
     */
    private function execute(ExecutionHandle $handle, ?\Closure $action = null, int $maxSteps = 500): array
    {
        $runner = $this->runner();
        $interpreter = new Interpreter(new ConditionEngineService(ComparatorRegistry::default()),
            function (Instruction $i, int $pc, int $seq, ExecutionContext $ctx) use ($action): StepRecord {
                $this->executed[] = $i->path . ($ctx->iteration !== '' ? "@{$ctx->iteration}" : '');
                $error = $action !== null ? $action($i, $ctx) : null;
                if ($error === null && ($i->config['type'] ?? '') === 'modify') {
                    $ctx->set((string)$i->config['target'], $ctx->get((string)($i->config['value'] ?? '')) ?? $i->config['value']);
                }
                return new StepRecord($seq, (string)$i->config['type'], $error === null ? 'success' : 'failed', 1, microtime(true), 0,
                    error: $error, pc: $pc, path: $i->path, iteration: $ctx->iteration);
            }, null, $runner);
        $records = [];
        while ($handle->pc < $handle->program->count() && $maxSteps-- > 0) {
            $pc = $handle->pc;
            $instruction = $handle->program->at($pc);
            $outcome = $interpreter->step($instruction, $pc, $handle->seq, $handle->context, $handle);
            $next = $outcome->nextPc;
            $failed = $outcome->failed;
            if ($failed && ($continued = $runner->itemError($handle, $instruction)) !== null) {
                [$next, $closed] = $continued;
                foreach ($closed as $r) {
                    $records[$r->stepIndex] = $r;
                }
                $failed = false;
            }
            if ($outcome->record !== null) {
                $records[$outcome->record->stepIndex] = $outcome->record;
                if (!$outcome->overwrite) {
                    $handle->seq++;
                }
            }
            if ($failed) {
                foreach ($runner->closeAll($handle, 'failed') as $r) {
                    $records[$r->stepIndex] = $r;
                }
                return [$records, 'failed'];
            }
            $handle->pc = $next;
        }
        return [$records, 'completed'];
    }

    private static function deals(): array
    {
        return ['items' => [['id' => 'd1', 'stage' => 'Open', 'amount' => '10'], ['id' => 'd2', 'stage' => 'Closed', 'amount' => '20'], ['id' => 'd3', 'stage' => 'Open', 'amount' => '30']], 'count' => 3];
    }

    public function testCompile(): void
    {
        $program = self::compiler()->compile(Process::normalizeDefinition(['actions' => [
            ['type' => 'foreach', 'id' => 'each', 'source' => 'deals.items', 'as' => 'deal', 'actions' => [
                ['type' => 'modify', 'target' => 'deal.x', 'value' => '1'],
                ['type' => 'foreach', 'source' => 'deal.lines', 'as' => 'line', 'actions' => [['type' => 'save_object', 'target' => 'line']]],
            ]],
            ['type' => 'save_object', 'target' => 'object'],
        ]]));
        self::assertSame(['loop_begin', 'action', 'loop_begin', 'action', 'loop_next', 'loop_next', 'action'], array_map(static fn (Instruction $i) => $i->op, $program->instructions));
        self::assertEquals(['next' => 5, 'end' => 6, 'loop_var' => 'deal_loop'], array_intersect_key($program->at(0)->config, ['next' => 1, 'end' => 1, 'loop_var' => 1]));
        self::assertSame([4, 5], [$program->at(2)->config['next'], $program->at(2)->config['end']]);
        self::assertSame(0, $program->at(5)->config['begin']);
        self::assertSame(0, $program->pcOf('each'));
        self::assertSame([2, 6], $program->remainingAfter(1), 'the rest of the body and the next top-level step; loop_next is not audited');
    }

    public function testLoopWithWhereAndTotals(): void
    {
        $handle = $this->handle([
            ['type' => 'foreach', 'source' => 'deals.items', 'as' => 'deal', 'where' => [['field' => 'deal.stage', 'operator' => 'equals', 'value' => 'Open']], 'actions' => [
                ['type' => 'modify', 'target' => 'deal.seen', 'value' => 'deal_loop.number'],
                ['type' => 'save_object', 'target' => 'deal'],
                ['type' => 'modify', 'target' => 'total.last', 'value' => 'deal.id'],
            ]],
            ['type' => 'save_object', 'target' => 'object'],
        ]);
        $handle->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        [$records, $state] = $this->execute($handle);
        self::assertSame('completed', $state);
        self::assertSame(['actions[0].actions[0]@0', 'actions[0].actions[1]@0', 'actions[0].actions[2]@0',
            'actions[0].actions[0]@2', 'actions[0].actions[1]@2', 'actions[0].actions[2]@2', 'actions[1]'], $this->executed);
        $foreach = $records[0];
        self::assertSame('foreach', $foreach->actionType);
        self::assertSame(['count' => 3, 'processed' => 2, 'skipped_by_where' => 1, 'failed_items' => 0, 'failed_indexes' => [], 'unsaved_items' => 2,
            'truncated' => false, 'source_missing' => false], array_diff_key($foreach->result, ['where_traces' => 1]));
        self::assertSame(1, $foreach->result['where_traces'][0]['index']);
        self::assertSame('', $foreach->iteration);
        self::assertSame(8, $handle->seq, '1 foreach + 2×3 body records + save_object; the totals overwrite record 0');
        self::assertFalse($handle->context->hasVariable('deal'), 'loop variables are removed after the loop');
        self::assertFalse($handle->context->hasVariable('deal_loop'));
        self::assertSame('d3', $handle->context->get('total.last'), 'variables of the body stay');
        self::assertSame([], $this->store->lists, 'the snapshot is removed');
        self::assertSame([], $handle->frames);
    }

    public function testSourcesAndLimits(): void
    {
        $empty = $this->handle([['type' => 'foreach', 'source' => 'nothing.items', 'as' => 'x', 'actions' => [['type' => 'modify', 'target' => 'x.a', 'value' => '1']]]]);
        [$records, $state] = $this->execute($empty);
        self::assertSame(['completed', true, 0], [$state, $records[0]->result['source_missing'], $records[0]->result['count']]);
        self::assertSame([], $this->executed);

        $scalars = $this->handle([['type' => 'foreach', 'source' => ['expression' => "split(object.tags, ',')"], 'as' => 'tag', 'loop_var' => 'i',
            'actions' => [['type' => 'modify', 'target' => 'acc.t', 'value' => 'tag.value']]]], ['id' => '1', 'tags' => 'a,b']);
        $expressions = new ExpressionEngine();
        if ($expressions->hasFunction('split')) {
            [, $state] = $this->execute($scalars);
            self::assertSame('completed', $state);
            self::assertSame('b', $scalars->context->get('acc.t'), 'a scalar item is {value}');
        }

        $notList = $this->handle([['type' => 'foreach', 'source' => 'object', 'as' => 'x', 'actions' => []]]);
        [$records, $state] = $this->execute($notList);
        self::assertSame(['failed', 'foreach: source "object" is not a list'], [$state, $records[0]->error]);

        $over = $this->handle([['type' => 'foreach', 'source' => 'deals.items', 'as' => 'd', 'max_items' => 2, 'actions' => []]]);
        $over->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        [$records, $state] = $this->execute($over);
        self::assertSame(['failed', 'foreach: 3 items exceed max_items (2)'], [$state, $records[0]->error]);

        $truncate = $this->handle([['type' => 'foreach', 'source' => 'deals.items', 'as' => 'd', 'max_items' => 2, 'on_overflow' => 'truncate', 'actions' => []]]);
        $truncate->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        [$records, $state] = $this->execute($truncate);
        self::assertSame(['completed', 2, 2, true], [$state, $records[0]->result['count'], $records[0]->result['processed'], $records[0]->result['truncated']]);
    }

    public function testNestedIterationAndModule(): void
    {
        $handle = $this->handle([['type' => 'foreach', 'source' => 'deals.items', 'as' => 'deal', 'max_items' => 2, 'on_overflow' => 'truncate', 'actions' => [
            ['type' => 'foreach', 'source' => ['expression' => 'deal_loop.count'], 'as' => 'n', 'actions' => []],
        ]]]);
        $handle->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        [$records, $state] = $this->execute($handle);
        self::assertSame('failed', $state, 'a number is not a list');
        self::assertSame('0', $records[1]->iteration);
        self::assertSame('failed', $records[0]->status);
        self::assertSame(0, $records[0]->result['failed_at_index']);

        $nested = $this->handle([['type' => 'foreach', 'source' => 'deals.items', 'as' => 'deal', 'actions' => [
            ['type' => 'foreach', 'source' => 'lines.items', 'as' => 'line', 'actions' => [['type' => 'save_object', 'target' => 'line']]],
        ]]]);
        $nested->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        $nested->context->setVariable('lines', ['items' => [['id' => 'l1'], ['id' => 'l2']]], 'AOS_Products_Quotes', ExecutionContext::SOURCE_LIST);
        $modules = [];
        [, $state] = $this->execute($nested, function (Instruction $i, ExecutionContext $ctx) use (&$modules): ?string {
            $modules[] = $ctx->getModule('line');
            return null;
        });
        self::assertSame('completed', $state);
        self::assertSame(['actions[0].actions[0].actions[0]@0/0', 'actions[0].actions[0].actions[0]@0/1', 'actions[0].actions[0].actions[0]@1/0',
            'actions[0].actions[0].actions[0]@1/1', 'actions[0].actions[0].actions[0]@2/0', 'actions[0].actions[0].actions[0]@2/1'], $this->executed);
        self::assertSame('AOS_Products_Quotes', $modules[0], 'the module of the source list');
    }

    public function testItemErrors(): void
    {
        $failSecond = static fn (Instruction $i, ExecutionContext $ctx): ?string => ($i->config['type'] === 'save_object' && $ctx->get('deal.id') === 'd2') ? 'HTTP 500' : null;
        $body = [['type' => 'save_object', 'target' => 'deal'], ['type' => 'modify', 'target' => 'deal.after', 'value' => '1']];

        $continue = $this->handle([['type' => 'foreach', 'source' => 'deals.items', 'as' => 'deal', 'on_item_error' => 'continue', 'actions' => $body], ['type' => 'save_object', 'target' => 'object']]);
        $continue->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        [$records, $state] = $this->execute($continue, $failSecond);
        self::assertSame('completed', $state);
        self::assertSame(['actions[0].actions[0]@0', 'actions[0].actions[1]@0', 'actions[0].actions[0]@1', 'actions[0].actions[0]@2', 'actions[0].actions[1]@2', 'actions[1]'], $this->executed,
            'the rest of the failed iteration is not run');
        self::assertSame(['success', 2, 1, [1]], [$records[0]->status, $records[0]->result['processed'], $records[0]->result['failed_items'], $records[0]->result['failed_indexes']]);

        $this->executed = [];
        $stop = $this->handle([['type' => 'foreach', 'source' => 'deals.items', 'as' => 'deal', 'actions' => $body], ['type' => 'save_object', 'target' => 'object']]);
        $stop->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        [$records, $state] = $this->execute($stop, $failSecond);
        self::assertSame('failed', $state);
        self::assertSame(['failed', 1, 1], [$records[0]->status, $records[0]->result['failed_at_index'], $records[0]->result['processed']]);
        self::assertSame([], $stop->frames);
        self::assertSame([], $this->store->lists);
    }

    public function testResumeInTheMiddle(): void
    {
        $actions = [['type' => 'foreach', 'source' => 'deals.items', 'as' => 'deal', 'actions' => [['type' => 'save_object', 'target' => 'deal']]]];
        $handle = $this->handle($actions);
        $handle->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        // stop after the body of item 1 (pc 0 begin, 1 body, 2 next): begin, body0, next, body1
        $this->execute($handle, null, 4);
        self::assertSame(2, $handle->pc);
        $frames = json_decode((string)json_encode($handle->frames), true);
        self::assertSame(1, $frames[0]['index']);

        // a new worker: frames and context from the saved state, items from the snapshot
        $resumed = $this->handle($actions);
        $resumed->context->setVariable('deals', self::deals(), 'Opportunities', ExecutionContext::SOURCE_LIST);
        $resumed->frames = $frames;
        $resumed->pc = 2;
        $resumed->seq = $handle->seq;
        $resumed->context->iteration = LoopRunner::iteration($frames);
        $this->executed = [];
        [$records, $state] = $this->execute($resumed);
        self::assertSame('completed', $state);
        self::assertSame(['actions[0].actions[0]@2'], $this->executed);
        self::assertSame(3, $records[0]->result['processed']);

        $lost = $this->handle($actions);
        $lost->frames = $frames;
        $lost->frames[0]['items_key'] = 'gone';
        $lost->pc = 2;
        [$records, $state] = $this->execute($lost);
        self::assertSame(['failed', 'Loop items snapshot is lost; the execution cannot be resumed'], [$state, $records[0]->error]);
    }

    public function testLoopInfoIsReadOnly(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1'], 'Accounts');
        $context->setVariable('d_loop', ['index' => 0], null, ExecutionContext::SOURCE_LOOP_INFO);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Variable "d_loop" is read-only');
        $context->set('d_loop.index', 5);
    }

    public function testValidation(): void
    {
        $context = new BlockValidationContext(
            static function (array $list, DefinitionScope $s, string $p): void {
                foreach ($list as $i => $row) {
                    if (isset($row['target'])) {
                        $s->checkWritePath($row['target'], "{$p}[{$i}].target");
                    }
                    if (isset($row['read'])) {
                        $s->checkReadPath($row['read'], "{$p}[{$i}].read");
                    }
                }
            },
            static function (array $conditions, DefinitionScope $s, string $p): void {
                foreach ($conditions as $i => $row) {
                    $s->checkReadPath($row['field'] ?? null, "{$p}[{$i}].field");
                }
            },
        );
        $block = new ForeachBlock(new ExpressionEngine(), 500);
        $scope = new DefinitionScope();
        $scope->declare('deals', ExecutionContext::SOURCE_LIST);
        $block->validate(['source' => '', 'as' => 'sys', 'actions' => 'x', 'max_items' => 0, 'on_overflow' => 'drop', 'on_item_error' => 'skip', 'module' => '1x'], $scope, 'a', $context);
        $block->validate(['source' => 'v9.items', 'as' => 'deals', 'actions' => []], $scope, 'b', $context);
        $block->validate(['source' => ['expression' => 'split(v8.tags'], 'as' => 'x', 'loop_var' => 'x', 'actions' => []], $scope, 'c', $context);
        $block->validate(['source' => 'deals.items', 'as' => 'deal', 'where' => [['field' => 'deal.stage'], ['field' => 'other.x']], 'actions' => [
            ['target' => 'deal_loop.index'],
            ['target' => 'sum.total'],
            ['read' => 'deal.id'],
        ]], $scope, 'd', $context);
        self::assertSame([
            'a.source: List source is required (e.g. deals.items)',
            'a.as: Name "sys" is reserved',
            'a.max_items: max_items must be an integer between 1 and 500',
            'a.on_overflow: Must be one of: fail, truncate',
            'a.on_item_error: Must be one of: stop, continue',
            'a.module: Invalid CRM module',
            'a.actions: Must be an array',
            'b.source: Variable "v9" is not defined at this point',
            'b.as: Variable "deals" is already defined',
            'c.source.expression: ' . self::parseError('split(v8.tags'),
            'c.loop_var: "loop_var" must differ from "as"',
            'd.where[1].field: Variable "other" is not defined at this point',
            'd.actions[0].target: Variable "deal_loop" is read-only',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
        self::assertFalse($scope->isDeclared('deal'), 'the item variable is not visible after the loop');
        self::assertFalse($scope->isDeclared('deal_loop'));
        self::assertSame(DefinitionScope::CONDITIONAL, $scope->kind('sum'), 'a variable of the body may stay undefined');
    }

    private static function parseError(string $expression): string
    {
        try {
            (new ExpressionEngine())->paths($expression);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return '';
    }
}
