<?php

declare(strict_types=1);

namespace Wps\Program\Blocks;

use Wps\Program\BlockTypeInterface;
use Wps\Program\BlockValidationContext;
use Wps\Program\Instruction;
use Wps\Program\ProgramEmitter;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * "Завершить процесс" (md/tasks_list/done/4.14-stop-process.md): ends the whole execution from any branch —
 * "completed" or "failed" with a message (a template) and a machine code. Compiled into a "halt" instruction;
 * on_error does not catch it (an intended end, not a step error).
 */
final class StopProcessBlock implements BlockTypeInterface
{
    public const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{0,63}$/';

    public function __construct(private readonly ?TemplateEngine $templates = null)
    {
    }

    public function type(): string
    {
        return 'stop_process';
    }

    public function label(): string
    {
        return 'Завершить процесс';
    }

    public function description(): string
    {
        return 'Досрочно завершает выполнение — успешно или с ошибкой и сообщением';
    }

    public function compile(array $config, string $path, ProgramEmitter $emitter): void
    {
        $emitter->emit(Instruction::HALT, [
            'type' => $this->type(),
            'status' => ($config['status'] ?? 'completed') === 'failed' ? 'failed' : 'completed',
        ] + (isset($config['message']) ? ['message' => (string)$config['message']] : [])
          + (isset($config['code']) ? ['code' => (string)$config['code']] : []), $path, ProgramEmitter::stepId($config));
    }

    public function validate(array $config, DefinitionScope $scope, string $path, BlockValidationContext $context): void
    {
        $status = $config['status'] ?? 'completed';
        if (!in_array($status, ['completed', 'failed'], true)) {
            $scope->error("{$path}.status", 'Status must be "completed" or "failed"');
        }
        $message = $config['message'] ?? null;
        if ($message === null || $message === '') {
            if ($status === 'failed') {
                $scope->error("{$path}.message", 'Message is required for status "failed"');
            }
        } elseif (!is_string($message) || mb_strlen($message) > 1000) {
            $scope->error("{$path}.message", 'Message must be a string up to 1000 characters');
        } elseif ($this->templates !== null) {
            (new TemplateValidator($this->templates))->validate($message, $scope, "{$path}.message");
        }
        if (isset($config['code']) && (!is_string($config['code']) || !preg_match(self::CODE_PATTERN, $config['code']))) {
            $scope->error("{$path}.code", 'Code must match ^[A-Z][A-Z0-9_]{0,63}$');
        }
        if (array_key_exists('on_error', $config)) {
            $scope->error("{$path}.on_error", 'on_error is not allowed for stop_process');
        }
    }
}
