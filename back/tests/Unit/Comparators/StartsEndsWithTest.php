<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Crm\FilterTranslator;
use Wps\Services\ConditionEngineService;
use Wps\Types\TypeCaster;

final class StartsEndsWithTest extends TestCase
{
    private static function met(array $row, array $object): bool
    {
        $engine = new ConditionEngineService(ComparatorRegistry::default());
        return $engine->evaluate([$row], ExecutionContext::fromEvent('e', $object + ['id' => '1'], 'Leads'))->isMet();
    }

    public function testOperators(): void
    {
        self::assertTrue(self::met(['field' => 'object.phone', 'operator' => 'starts_with', 'value' => '+7'], ['phone' => '+79001234567']));
        self::assertFalse(self::met(['field' => 'object.phone', 'operator' => 'starts_with', 'value' => '+7'], ['phone' => '89001234567']));
        self::assertTrue(self::met(['field' => 'object.email1', 'operator' => 'ends_with', 'value' => '@gmail.com'], ['email1' => 'ivan@gmail.com']));
        self::assertFalse(self::met(['field' => 'object.email1', 'operator' => 'ends_with', 'value' => '@gmail.com'], ['email1' => 'x@gmail.com.evil.ru']));
        self::assertTrue(self::met(['field' => 'object.name', 'operator' => 'starts_with', 'value' => 'ДОГ', 'cast' => 'string', 'cast_options' => ['case_sensitive' => false]], ['name' => 'Договор №1']));
        self::assertFalse(self::met(['field' => 'object.name', 'operator' => 'starts_with', 'value' => 'ДОГ'], ['name' => 'Договор №1']));
        self::assertTrue(self::met(['field' => 'object.email1', 'operator' => 'ends_with', 'value' => '@COMPANY.RU', 'cast_options' => ['case_sensitive' => false]], ['email1' => 'ivan@company.ru']));
        self::assertFalse(self::met(['field' => 'object.phone', 'operator' => 'starts_with', 'value' => 'a'], ['phone' => '']));
        self::assertTrue(self::met(['field' => 'object.n', 'operator' => 'starts_with', 'value' => '12'], ['n' => 12345]));
        self::assertFalse(self::met(['field' => 'object.missing', 'operator' => 'ends_with', 'value' => 'x'], []));
        self::assertFalse(self::met(['field' => 'object.phone', 'operator' => 'starts_with', 'value_type' => 'from_object_field', 'value' => 'object.none'], ['phone' => '+7']));
        $operators = array_column(ComparatorRegistry::default()->dictionary(), 'casts', 'value');
        self::assertSame(['string'], $operators['starts_with']);
        self::assertSame(['string'], $operators['ends_with']);
    }

    public function testCrmFilter(): void
    {
        $filter = (new FilterTranslator(new TypeCaster()))->translate([
            ['field' => 'email1', 'operator' => 'ends_with', 'value' => '@gmail.com'],
            ['field' => 'name', 'operator' => 'starts_with', 'value' => '50%_'],
        ], ExecutionContext::fromEvent('e', ['id' => '1'], 'Leads'));
        self::assertSame(['%@gmail.com', '50\\%\\_%'], array_column($filter->conditions(), 'value'));
    }
}
