<?php

declare(strict_types=1);

namespace Wps\Program;

/**
 * A compiled process: a flat list of instructions with jumps. The execution cursor is one integer (pc).
 */
final class Program
{
    /**
     * @param list<Instruction> $instructions
     * @param array<string, int> $labels step id => pc
     */
    public function __construct(
        public readonly array $instructions,
        public readonly array $labels,
        public readonly string $hash,
    ) {
    }

    public function count(): int
    {
        return count($this->instructions);
    }

    public function at(int $pc): Instruction
    {
        return $this->instructions[$pc] ?? throw new \OutOfRangeException("No instruction at pc {$pc}");
    }

    public function pcOf(string $stepId): ?int
    {
        return $this->labels[$stepId] ?? null;
    }

    /**
     * Audited instructions that will not run because the instruction at $pc failed or stopped the process:
     * the following elements of the list the instruction is in and of every enclosing list up to the top level
     * (preload and actions form one top-level sequence). Branches that were not chosen and the nested lists of
     * later blocks are not included.
     *
     * @return list<int>
     */
    public function remainingAfter(int $pc): array
    {
        $chain = [$this->at($pc)];
        for ($parent = $chain[0]->parent; $parent >= 0; $parent = $this->at($parent)->parent) {
            $chain[] = $this->at($parent);
        }
        $result = [];
        $count = $this->count();
        for ($i = $pc + 1; $i < $count; $i++) {
            $candidate = $this->instructions[$i];
            if (!in_array($candidate->op, Instruction::AUDITED, true)) {
                continue;
            }
            foreach ($chain as $member) {
                if ($candidate->parent === $member->parent
                    && ($member->parent === -1 || $candidate->listPath() === $member->listPath())) {
                    $result[] = $i;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Instruction $i) => $i->toArray(), $this->instructions);
    }
}
