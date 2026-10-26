<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Events\EventEnvelope;
use Wps\Services\ConditionEngineService;
use Wps\Types\CastOptions;
use Wps\Types\TypeCaster;

final class CaseInsensitiveTest extends TestCase
{
    private static function met(array $row, array $object): bool
    {
        return (new ConditionEngineService(ComparatorRegistry::default()))->evaluate([$row], ExecutionContext::fromEvent('e', $object + ['id' => '1'], 'Leads'))->isMet();
    }

    public function testNormalization(): void
    {
        $caster = new TypeCaster();
        $ci = CastOptions::fromDefinition('string', ['case_sensitive' => false, 'trim' => true]);
        self::assertSame($caster->cast('МОСКВА ', $ci)->value, $caster->cast("\u{00A0}Москва", $ci)->value, 'case and no-break space');
        self::assertNotSame($caster->cast('Straße', $ci)->value, $caster->cast('STRASSE', $ci)->value, 'simple folding does not expand ß');
        self::assertNotSame($caster->cast('ёлка', $ci)->value, $caster->cast('елка', $ci)->value, 'ё and е stay different');
        self::assertSame("Active", $caster->cast("\u{FEFF}Active ", CastOptions::fromDefinition('string', ['trim' => true]))->value);
        self::assertSame('Active ', $caster->cast('Active ', CastOptions::fromDefinition('string', []))->value, 'defaults keep the old behaviour');
        self::assertSame(['web', 'partner'], $caster->cast('^Web^,^PARTNER^', CastOptions::fromDefinition('list', ['case_sensitive' => false, 'wrapper' => '^']))->value);
    }

    public function testOperators(): void
    {
        $ci = ['cast' => 'string', 'cast_options' => ['case_sensitive' => false, 'trim' => true]];
        self::assertTrue(self::met(['field' => 'object.city', 'operator' => 'equals', 'value' => 'Москва'] + $ci, ['city' => 'МОСКВА ']));
        self::assertFalse(self::met(['field' => 'object.city', 'operator' => 'equals', 'value' => 'Москва'], ['city' => 'МОСКВА ']));
        self::assertTrue(self::met(['field' => 'object.email1', 'operator' => 'contains', 'value' => '@company.ru'] + $ci, ['email1' => 'Ivan@Company.RU']));
        self::assertTrue(self::met(['field' => 'object.lead_source', 'operator' => 'in', 'value' => ['Web', 'Partner']] + $ci, ['lead_source' => 'web']));
        $context = ExecutionContext::fromEnvelope('e', EventEnvelope::fromArray(['id' => '1', 'city' => 'москва', 'event_type' => 'update', 'before' => ['city' => 'Москва'], 'changed_fields' => ['city']], 'Leads'));
        $changed = (new ConditionEngineService(ComparatorRegistry::default()))->evaluate([['field' => 'object.city', 'operator' => 'changed'] + $ci], $context);
        self::assertFalse($changed->isMet(), 'Москва → москва is not a change without case');
    }
}
