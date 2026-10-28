<?php

declare(strict_types=1);

namespace Wps\Program\Blocks;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Expression\ExpressionEngine;
use Wps\Program\BlockTypeInterface;
use Wps\Program\BlockValidationContext;
use Wps\Program\Instruction;
use Wps\Program\ProgramEmitter;
use Wps\Services\Validation\DefinitionScope;

/**
 * A loop over a list (md/tasks_list/4.11-foreach.md):
 *
 *   { "type": "foreach", "id": "each_deal", "source": "deals.items", "as": "deal",
 *     "where": [...], "max_items": 200, "on_overflow": "fail", "on_item_error": "stop",
 *     "actions": [ ... ] }
 *
 * Compiles into loop_begin, the body and loop_next (LoopRunner). "where" is checked by the loop itself while it
 * looks for the next item, so rejected items are only counted. The item variable is a copy: changes reach the CRM
 * through save_object of the item. Variables created in the body are kept between iterations and after the loop.
 */
final class ForeachBlock implements BlockTypeInterface
{
    private const RESERVED = ExecutionContext::RESERVED;

    public function __construct(
        private readonly ExpressionEngine $expressions,
        private readonly int $maxItems,
    ) {
    }

    public function type(): string
    {
        return 'foreach';
    }

    public function label(): string
    {
        return 'Для каждого элемента';
    }

    public function description(): string
    {
        return 'Выполняет вложенные действия для каждого элемента списка';
    }

    public function compile(array $config, string $path, ProgramEmitter $emitter): void
    {
        $as = (string)($config['as'] ?? 'item');
        $begin = $emitter->emit(Instruction::LOOP_BEGIN, [
            'type' => $this->type(),
            'source' => $config['source'] ?? null,
            'as' => $as,
            'loop_var' => (string)($config['loop_var'] ?? "{$as}_loop"),
            'module' => $config['module'] ?? null,
            'where' => is_array($config['where'] ?? null) ? $config['where'] : [],
            'max_items' => $config['max_items'] ?? null,
            'on_overflow' => $config['on_overflow'] ?? 'fail',
            'on_item_error' => $config['on_item_error'] ?? 'stop',
            'next' => -1,
            'end' => -1,
        ] + (isset($config['on_error']) ? ['on_error' => $config['on_error']] : []), $path, ProgramEmitter::stepId($config));
        $emitter->compileList(is_array($config['actions'] ?? null) ? $config['actions'] : [], "{$path}.actions", $begin);
        $next = $emitter->emit(Instruction::LOOP_NEXT, ['type' => $this->type(), 'begin' => $begin], "{$path}.next");
        $emitter->patch($begin, 'next', $next);
        $emitter->patch($begin, 'end', $emitter->nextPc());
    }

    public function validate(array $config, DefinitionScope $scope, string $path, BlockValidationContext $context): void
    {
        $this->validateSource($config['source'] ?? null, $scope, "{$path}.source");
        $as = $config['as'] ?? null;
        $asValid = $this->checkName($as, $scope, "{$path}.as");
        $loopVar = $config['loop_var'] ?? (is_string($as) ? "{$as}_loop" : null);
        $loopVarValid = $this->checkName($loopVar, $scope, "{$path}.loop_var");
        if ($asValid && $loopVarValid && $as === $loopVar) {
            $scope->error("{$path}.loop_var", '"loop_var" must differ from "as"');
            $loopVarValid = false;
        }
        if (array_key_exists('max_items', $config) && (!is_int($config['max_items']) || $config['max_items'] < 1 || $config['max_items'] > $this->maxItems)) {
            $scope->error("{$path}.max_items", "max_items must be an integer between 1 and {$this->maxItems}");
        }
        foreach (['on_overflow' => ['fail', 'truncate'], 'on_item_error' => ['stop', 'continue']] as $key => $allowed) {
            if (array_key_exists($key, $config) && !in_array($config[$key], $allowed, true)) {
                $scope->error("{$path}.{$key}", 'Must be one of: ' . implode(', ', $allowed));
            }
        }
        if (isset($config['module']) && (!is_string($config['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $config['module']))) {
            $scope->error("{$path}.module", 'Invalid CRM module');
        }
        $actions = $config['actions'] ?? null;
        if (!is_array($actions) || !array_is_list($actions)) {
            $scope->error("{$path}.actions", 'Must be an array');
            $actions = [];
        }

        // the body: the loop variables are known inside only; what the body creates may stay undefined (zero items)
        $body = $scope->fork();
        if ($asValid) {
            $body->declare((string)$as, ExecutionContext::SOURCE_LOOP);
        }
        if ($loopVarValid) {
            $body->declare((string)$loopVar, ExecutionContext::SOURCE_LOOP_INFO);
        }
        if (array_key_exists('where', $config)) {
            if (!is_array($config['where'])) {
                $scope->error("{$path}.where", 'Must be an array or a condition group');
            } elseif ($config['where'] !== []) {
                $context->validateConditions($config['where'], $body, "{$path}.where");
            }
        }
        $context->validateList($actions, $body, "{$path}.actions");
        foreach ([$as, $loopVar] as $name) {
            if (is_string($name) && $scope->kind($name) === null) {
                $body->undeclare($name);
            }
        }
        $scope->mergeBranches([$body], exhaustive: false);
    }

    private function validateSource(mixed $source, DefinitionScope $scope, string $field): void
    {
        if (is_array($source) && array_key_exists('expression', $source)) {
            $expression = $source['expression'];
            if (!is_string($expression) || trim($expression) === '') {
                $scope->error("{$field}.expression", 'Expression is required');
                return;
            }
            try {
                foreach ($this->expressions->paths($expression) as $ref) {
                    $scope->checkReadPath($ref, "{$field}.expression", allowWholeVariable: true);
                }
            } catch (ConfigurationException $e) {
                $scope->error("{$field}.expression", $e->getMessage());
            }
            return;
        }
        if (!is_string($source) || trim($source) === '') {
            $scope->error($field, 'List source is required (e.g. deals.items)');
            return;
        }
        $scope->checkReadPath($source, $field, allowWholeVariable: true);
    }

    private function checkName(mixed $name, DefinitionScope $scope, string $field): bool
    {
        if (!is_string($name) || !ExecutionContext::isValidVariableName($name)) {
            $scope->error($field, 'Variable name is required (letters, digits, "_"; e.g. item)');
            return false;
        }
        if (in_array($name, self::RESERVED, true)) {
            $scope->error($field, "Name \"{$name}\" is reserved");
            return false;
        }
        if ($scope->isDeclared($name)) {
            $scope->error($field, "Variable \"{$name}\" is already defined");
            return false;
        }
        return true;
    }
}
