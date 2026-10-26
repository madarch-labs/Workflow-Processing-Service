<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Actions\StartProcessHandler;
use Wps\Services\MetricsService;

/**
 * Compiles a process definition (tree) into a flat program (md/tasks_list/done/0.1-program-compiler.md).
 *
 * preload rows become the first "action" instructions (path preload[i]), then the actions list follows.
 * A definition without blocks compiles one-to-one into the old step list: pc == old step index (compatibility
 * invariant). Compilation is deterministic; results are cached by the definition hash (one definition runs for
 * thousands of events). Launch conditions are not part of the program — they are checked before an execution exists.
 */
final class ProgramCompiler
{
    public const DEFAULT_MAX_DEPTH = 5;
    private const CACHE_LIMIT = 500;

    /** @var array<string, Program> */
    private array $cache = [];
    private int $compiled = 0;

    public function __construct(
        private readonly BlockTypeRegistry $blocks,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private readonly ?MetricsService $metrics = null,
    ) {
    }

    /**
     * @param array{conditions?: array<mixed>, preload?: array<mixed>, actions?: array<mixed>} $definition normalized definition
     */
    public function compile(array $definition): Program
    {
        $preload = array_values((array)($definition['preload'] ?? []));
        $actions = array_values((array)($definition['actions'] ?? []));
        $key = sha1((string)json_encode([$preload, $actions]));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $emitter = new ProgramEmitter($this->blocks, $this->maxDepth);
        $emitter->compileList(array_map(static fn ($row) => ['type' => 'preload'] + (array)$row, $preload), 'preload');
        $emitter->compileList($actions, 'actions', null, count($preload));
        $program = $emitter->build();

        $this->compiled++;
        $this->metrics?->inc(MetricsService::PROGRAM_COMPILE);
        if (count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }
        return $this->cache[$key] = $program;
    }

    /** Number of compilations that missed the cache (metric wps_program_compile_total). */
    public function compiledCount(): int
    {
        return $this->compiled;
    }

    /**
     * Child processes referenced by start_process anywhere in the definition, nested blocks included.
     *
     * @param array<mixed> $definition
     * @return list<int>
     */
    public static function collectChildIds(array $definition): array
    {
        $ids = [];
        $walk = static function (mixed $node) use (&$walk, &$ids): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'start_process') {
                array_push($ids, ...StartProcessHandler::processIds($node));
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($definition['actions'] ?? []);
        return array_values(array_unique($ids));
    }
}
