<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Exceptions\ConfigurationException;

/**
 * Builds a program while the compiler walks the definition tree: emits instructions, patches jump targets
 * once they are known (backpatching) and compiles nested action lists of blocks.
 */
final class ProgramEmitter
{
    /** @var list<array{op: string, config: array<string, mixed>, path: string, stepId: ?string, depth: int, topLevel: int, parent: int}> */
    private array $instructions = [];

    /** @var array<string, int> */
    private array $labels = [];

    private int $depth = 0;
    private int $topLevel = 0;
    private int $parent = -1;

    public function __construct(
        private readonly BlockTypeRegistry $blocks,
        private readonly int $maxDepth,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @return int pc of the instruction
     */
    public function emit(string $op, array $config, string $path, ?string $stepId = null): int
    {
        $pc = count($this->instructions);
        $this->instructions[] = ['op' => $op, 'config' => $config, 'path' => $path, 'stepId' => $stepId,
            'depth' => $this->depth, 'topLevel' => $this->topLevel, 'parent' => $this->parent];
        if ($stepId !== null && !isset($this->labels[$stepId])) {
            $this->labels[$stepId] = $pc;
        }
        return $pc;
    }

    /**
     * Sets a jump target: $key is a dot path inside the instruction config ("target", "branches.0.target").
     */
    public function patch(int $pc, string $key, int $target): void
    {
        $node = &$this->instructions[$pc]['config'];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node)) {
                throw new \LogicException("Cannot patch {$key} of instruction {$pc}");
            }
            $node = &$node[$segment];
        }
        $node = $target;
        unset($node);
    }

    public function nextPc(): int
    {
        return count($this->instructions);
    }

    /**
     * Compiles the elements of an action list ($pathPrefix = "actions", "actions[1].branches[0].actions", ...).
     * Elements of a top-level list advance the top-level index; $owner is the pc of the block that owns a nested list.
     *
     * @param array<mixed> $actions
     */
    public function compileList(array $actions, string $pathPrefix, ?int $owner = null, int $topLevelOffset = 0): void
    {
        $nested = $owner !== null;
        if ($nested && $this->depth + 1 > $this->maxDepth) {
            throw new ConfigurationException("Nesting is too deep at {$pathPrefix} (max {$this->maxDepth})");
        }
        [$savedDepth, $savedParent] = [$this->depth, $this->parent];
        if ($nested) {
            $this->depth++;
            $this->parent = $owner;
        }
        foreach (array_values($actions) as $i => $row) {
            if (!$nested) {
                $this->topLevel = $topLevelOffset + $i;
            }
            $this->compileElement(is_array($row) ? $row : [], "{$pathPrefix}[{$i}]");
        }
        [$this->depth, $this->parent] = [$savedDepth, $savedParent];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function compileElement(array $row, string $path): void
    {
        $type = $row['type'] ?? null;
        if (is_string($type) && $this->blocks->has($type)) {
            $this->blocks->get($type)->compile($row, $path, $this);
            return;
        }
        // an unknown type is an ordinary action: the validator / ActionHandlerRegistry rejects it
        $this->emit(Instruction::ACTION, $row, $path, self::stepId($row));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function stepId(array $row): ?string
    {
        return isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? $row['id'] : null;
    }

    public function build(): Program
    {
        $instructions = array_map(
            static fn (array $i) => new Instruction($i['op'], $i['config'], $i['path'], $i['stepId'], $i['depth'], $i['topLevel'], $i['parent']),
            $this->instructions,
        );
        $hash = sha1((string)json_encode(array_map(static fn (Instruction $i) => $i->toArray(), $instructions)));
        return new Program($instructions, $this->labels, $hash);
    }
}
