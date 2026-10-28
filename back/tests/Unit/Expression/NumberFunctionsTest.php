<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;

final class NumberFunctionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $vars
     */
    private static function eval(string $expression, array $vars = []): mixed
    {
        return (new ExpressionEngine())->evaluate($expression, static fn (string $path) => $vars[$path] ?? null);
    }

    /**
     * Values AND PHP types of the documented examples stay as before the decimal arithmetic.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function regression(): iterable
    {
        yield 'discount (demo process 02)' => ['round(object.amount * 0.9, 2)', 450];
        yield 'discount plus' => ['object.amount * 0.9 + 10', 460];
        yield 'float result' => ['10 / 4', 2.5];
        yield 'float noise' => ['0.1 + 0.2', 0.3];
        yield 'integer stays integer' => ['9.0', 9];
        yield 'string plus number' => ["'10' + 5", 15];
        yield 'concatenation' => ['object.name + 5', 'Deal5'];
        yield 'true + 1' => ['true + 1', 'true1'];
        yield 'empty plus number concatenates' => ["'' + 5", '5'];
        yield 'empty in minus is 0' => ['object.missing - 5', -5];
        yield 'unary minus' => ['-object.amount', -500];
        yield 'brackets' => ['(object.amount - 100) * 2', 800];
        yield 'one third' => ['1 / 3', 0.3333333333];
        yield 'modulo' => ['7 % 3', 1];
        yield 'round doc' => ['round(10.456, 2)', 10.46];
        yield 'abs doc' => ['abs(-5)', 5];
        yield 'min doc' => ['min(object.amount, 1000)', 500];
        yield 'max doc' => ['max(object.amount, 0)', 500];
        yield 'concat of a product' => ["concat('Discount applied, new amount: ', object.amount * 0.9)", 'Discount applied, new amount: 450'];
        yield 'concat of a fraction' => ["concat('x', 2.50)", 'x2.5'];
        yield 'numeric field stays as is' => ['object.code', '00123'];
    }

    #[DataProvider('regression')]
    public function testRegression(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression, ['object.amount' => 500, 'object.name' => 'Deal', 'object.code' => '00123']));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function cases(): iterable
    {
        // precision
        yield 'VAT' => ["round(object.price * 1.2, 2)", 23.99];
        yield '19.99 * 3' => ["object.price * 3", 59.97];
        yield 'big integer is exact' => ['9007199254740993 + 0', '9007199254740993'];
        yield 'big money' => ["'12345678901234567.89' + 0.01", '12345678901234567.9'];
        yield 'chain keeps precision' => ['(0.1 + 0.2) * 3', 0.9];
        yield 'compare exact' => ['0.1 + 0.2 == 0.3', true];
        // functions
        yield 'round half up' => ['round(2.5)', 3];
        yield 'round negative half' => ['round(-2.5)', -3];
        yield 'round 1.005' => ['round(1.005, 2)', 1.01];
        yield 'round 10.455' => ['round(10.455, 2)', 10.46];
        yield 'round to hundreds' => ['round(1250, -2)', 1300];
        yield 'floor negative' => ['floor(-1.5)', -2];
        yield 'floor precision' => ['floor(10.459, 2)', 10.45];
        yield 'unit price' => ['floor(object.total / object.qty * 100) / 100', 33.33];
        yield 'ceil' => ['ceil(1.01)', 2];
        yield 'trunc' => ['trunc(-1.7)', -1];
        yield 'abs' => ['abs(-0.5)', 0.5];
        yield 'sign' => ['sign(-3)', -1];
        yield 'sign zero' => ["sign('')", 0];
        yield 'min of list' => ['min([5, 2, 9])', 2];
        yield 'max of list' => ['max([5, 2, 9])', 9];
        yield 'max empty list' => ['max([])', ''];
        yield 'max exact' => ["max('0.30000000000000001', 0.3)", '0.30000000000000001'];
        yield 'pow' => ['pow(1.1, 2)', 1.21];
        yield 'pow fractional' => ['pow(9, 0.5)', 3];
        yield 'sqrt' => ['sqrt(2)', 1.4142135624];
        yield 'percent' => ['percent(200, 15)', 30];
        yield 'percent of amount' => ['percent(object.price, 10)', 1.999];
        yield 'to_number' => ["to_number('1 234,56')", 1234.56];
        yield 'to_number dot' => ["to_number('1 234.56')", 1234.56];
        yield 'to_number nbsp' => ["to_number(object.nbsp)", 1234];
        yield 'to_number trims' => ["to_number('  42 ')", 42];
        yield 'to_number empty stays empty' => ["to_number('')", ''];
        yield 'to_number missing with coalesce' => ["coalesce(to_number(object.missing), 0)", 0];
        yield 'to_int' => ["to_int(' 42 ')", 42];
        yield 'to_int empty' => ["to_int('')", ''];
        yield 'number_format' => ["number_format(1234567.891, 2, ',', ' ')", '1 234 567,89'];
        yield 'number_format defaults' => ['number_format(1234567.5)', '1 234 568'];
        yield 'number_format negative' => ["number_format(-1234.5, 2)", '-1 234.50'];
        yield 'number_format small' => ["number_format(5, 2, ',')", '5,00'];
        yield 'number_format multibyte separator' => ["number_format(1234567, 0, ',', '’')", '1’234’567'];
        yield 'empty argument is 0' => ['round(object.missing, 2)', 0];
    }

    #[DataProvider('cases')]
    public function testFunctions(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression, ['object.price' => '19.99', 'object.total' => '100', 'object.qty' => '3', 'object.nbsp' => "1\u{00A0}234"]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'not a number' => ["floor('abc')", 'Function "floor" requires a number, got "abc"'];
        yield 'human format without to_number' => ["round('1 234,56')", 'Function "round" requires a number, got "1 234,56"'];
        yield 'operator' => ["'abc' * 2", 'Operator "*" requires numbers, got "abc"'];
        yield 'sqrt negative' => ['sqrt(-1)', 'sqrt() of a negative number'];
        yield 'division by zero' => ['1 / 0', 'Division by zero in expression'];
        yield 'division by empty' => ['1 / object.missing', 'Division by zero in expression'];
        yield 'modulo by zero' => ['5 % 0', 'Modulo by zero in expression'];
        yield 'to_number' => ["to_number('abc')", 'Cannot convert "abc" to a number'];
        yield 'to_number thousands comma' => ["to_number('1,234.56')", 'Cannot convert "1,234.56" to a number'];
        yield 'to_int fraction' => ["to_int('1.5')", 'Cannot convert "1.5" to an integer'];
        yield 'computed precision' => ['round(1.5, object.p)', 'Function "round": precision must be an integer, got "1.5"'];
        yield 'too large' => ['pow(10, 100) * 10', 'Number is too large'];
        yield 'number_format negative decimals' => ['number_format(1, -1)', 'Function "number_format": decimals must not be negative'];
    }

    #[DataProvider('runtimeErrors')]
    public function testRuntimeErrors(string $expression, string $message): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage($message);
        self::eval($expression, ['object.p' => '1.5']);
    }

    public function testSaveErrors(): void
    {
        $engine = new ExpressionEngine();
        foreach ([
            'pow(2)' => 'Function "pow" expects 2 arguments, got 1',
            'round(1.5, 1.5)' => 'Function "round": precision must be an integer',
            "number_format(1, 'x')" => 'Function "number_format": decimals must be an integer',
            '1' . str_repeat('0', 101) => 'Number is too large',
        ] as $expression => $message) {
            try {
                $engine->parse($expression);
                self::fail("expected an error for {$expression}");
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertIsArray($engine->parse('round(object.amount, -2)'));
        self::assertIsArray($engine->parse('round(object.amount, object.p)'));
    }

    public function testDictionary(): void
    {
        $names = [];
        foreach ((new ExpressionEngine())->functionSpecs() as $spec) {
            if ($spec->category === 'number') {
                $names[] = $spec->name;
            }
        }
        sort($names);
        self::assertSame(['abs', 'ceil', 'floor', 'max', 'min', 'number_format', 'percent', 'pow', 'round', 'sign', 'sqrt', 'to_int', 'to_number', 'trunc'], $names);
    }
}
