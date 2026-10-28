<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Wps\Persistence\Process;

final class ProcessTest extends TestCase
{
    public function testNormalizeDefinitionKeepsConstants(): void
    {
        $constants = ['vip_threshold' => 100000, 'open_stages' => ['A', 'B']];
        $normalized = Process::normalizeDefinition(['actions' => [], 'constants' => $constants]);
        self::assertSame($constants, $normalized['constants']);
        self::assertSame([], Process::normalizeDefinition(['actions' => []])['constants']);
        self::assertSame([], Process::normalizeDefinition(['constants' => 'bad'])['constants']);
    }

    public function testDefinitionKeysMatchNormalizedDefinition(): void
    {
        // the validator rejects keys outside DEFINITION_KEYS, normalizeDefinition() keeps only them: the lists must agree
        self::assertSame(Process::DEFINITION_KEYS, array_keys(Process::normalizeDefinition([])));
        $full = array_fill_keys(Process::DEFINITION_KEYS, []);
        self::assertSame(Process::DEFINITION_KEYS, array_keys(Process::normalizeDefinition($full + ['extra' => 1])));
    }

    public function testToArrayRoundTripKeepsConstants(): void
    {
        $data = [
            'id' => 1,
            'name' => 'p',
            'object_type' => 'Accounts',
            'is_child' => false,
            'is_active' => true,
            'priority' => 0,
            'version' => 2,
            'definition' => ['conditions' => [], 'preload' => [], 'actions' => [], 'constants' => ['threshold' => 10]],
        ];
        $process = Process::fromArray($data);
        self::assertSame(['threshold' => 10], $process->definition['constants']);
        self::assertSame(['threshold' => 10], $process->toArray()['definition']['constants']);
        self::assertSame($process->definition, Process::fromArray($process->toArray())->definition);
    }

    public function testFromRowKeepsConstants(): void
    {
        $row = [
            'id' => 1, 'name' => 'p', 'object_type' => 'Accounts', 'is_child' => 'f', 'is_active' => 't',
            'priority' => 0, 'version' => 2,
            'definition' => '{"conditions":[],"preload":[],"actions":[],"constants":{"limit":5}}',
        ];
        self::assertSame(['limit' => 5], Process::fromRow($row)->definition['constants']);
    }
}
