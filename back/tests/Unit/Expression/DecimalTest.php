<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;

final class DecimalTest extends TestCase
{
    public function testArithmetic(): void
    {
        self::assertSame('0.3', Decimal::add('0.1', '0.2'));
        self::assertSame('59.97', Decimal::mul('19.99', '3'));
        self::assertSame('-1.5', Decimal::sub('1', '2.5'));
        self::assertSame('0.3333333333', Decimal::div('1', '3'));
        self::assertSame('0.6666666667', Decimal::div('2', '3'), 'division rounds HALF_UP to 10 digits');
        self::assertSame('2.5', Decimal::div('10', '4'));
        self::assertSame('1', Decimal::mod('7', '3'));
        self::assertSame('-1', Decimal::mod('-7', '3'), 'sign of the dividend, like fmod');
        self::assertSame('0.5', Decimal::mod('5.5', '1'));
        self::assertSame('-3', Decimal::neg('3'));
        self::assertSame('0', Decimal::neg('0'));
        self::assertSame('9007199254740994', Decimal::add('9007199254740993', '1'));
    }

    public function testDivisionByZero(): void
    {
        $this->expectExceptionMessage('Division by zero in expression');
        Decimal::div('1', '0.0');
    }

    public function testModuloByZero(): void
    {
        $this->expectExceptionMessage('Modulo by zero in expression');
        Decimal::mod('1', '0');
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function rounding(): iterable
    {
        yield 'half up' => ['2.5', 0, '3'];
        yield 'half away from zero' => ['-2.5', 0, '-3'];
        yield '1.005 (float gives 1)' => ['1.005', 2, '1.01'];
        yield '10.455' => ['10.455', 2, '10.46'];
        yield 'below half' => ['10.454', 2, '10.45'];
        yield 'negative precision' => ['1250', -2, '1300'];
        yield 'negative precision down' => ['1249', -2, '1200'];
        yield 'negative value negative precision' => ['-1250', -2, '-1300'];
        yield 'no change' => ['3', 2, '3'];
        yield 'to zero' => ['-0.4', 0, '0'];
    }

    #[DataProvider('rounding')]
    public function testRound(string $value, int $precision, string $expected): void
    {
        self::assertSame($expected, Decimal::round($value, $precision));
    }

    public function testFloorCeilTrunc(): void
    {
        self::assertSame('-2', Decimal::floor('-1.5'));
        self::assertSame('1', Decimal::floor('1.9'));
        self::assertSame('10.45', Decimal::floor('10.459', 2));
        self::assertSame('-10.46', Decimal::floor('-10.451', 2));
        self::assertSame('1200', Decimal::floor('1299', -2));
        self::assertSame('2', Decimal::ceil('1.01'));
        self::assertSame('-1', Decimal::ceil('-1.5'));
        self::assertSame('10.46', Decimal::ceil('10.451', 2));
        self::assertSame('-1', Decimal::trunc('-1.7'));
        self::assertSame('1.7', Decimal::trunc('1.79', 1));
        self::assertSame('5', Decimal::floor('5'));
    }

    public function testPowSqrt(): void
    {
        self::assertSame('1.21', Decimal::pow('1.1', '2'));
        self::assertSame('0.25', Decimal::pow('2', '-2'));
        self::assertSame('1024', Decimal::pow('2', '10'));
        self::assertSame('1.4142135624', Decimal::sqrt('2'));
        self::assertSame('3', Decimal::sqrt('9'));
        self::assertSame('2', Decimal::pow('4', '0.5'), 'fractional exponent goes through float');
        $this->expectExceptionMessage('sqrt() of a negative number');
        Decimal::sqrt('-1');
    }

    public function testOfAndIsNumeric(): void
    {
        self::assertSame('0.1', Decimal::of(0.1));
        self::assertSame('0.3', Decimal::of(0.1 + 0.2));
        self::assertSame('100000000000000000000', Decimal::of(1e20));
        self::assertSame('0.00001', Decimal::of(1e-5));
        self::assertSame('12', Decimal::of(' 12 '));
        self::assertSame('-0.5', Decimal::of('-.50'));
        self::assertSame('5', Decimal::of('+5.'));
        foreach (['1 234,56', '1e3', 'abc', '', '1.2.3', true] as $bad) {
            self::assertFalse(Decimal::isNumeric($bad), json_encode($bad));
        }
        self::assertTrue(Decimal::isNumeric(' 42 '));
        self::assertFalse(Decimal::isNumeric(NAN));
    }

    /**
     * @return iterable<string, array{string, int|float|string}>
     */
    public static function natives(): iterable
    {
        yield 'integer' => ['450', 450];
        yield 'trailing zeros' => ['450.000', 450];
        yield 'fraction' => ['2.5', 2.5];
        yield 'small fraction' => ['0.3', 0.3];
        yield 'negative' => ['-0.5', -0.5];
        yield '15 digits' => ['999999999999999', 999999999999999];
        yield 'integer 10^15 stays text' => ['1000000000000000', '1000000000000000'];
        yield 'big integer stays text' => ['9007199254740993', '9007199254740993'];
        yield 'precise fraction stays text' => ['12345678901234567.89', '12345678901234567.89'];
        yield '15 significant digits fraction' => ['1234567.12345678', 1234567.12345678];
        yield '16 significant digits fraction' => ['0.1234567890123456', '0.1234567890123456'];
    }

    #[DataProvider('natives')]
    public function testToNative(string $decimal, int|float|string $expected): void
    {
        self::assertSame($expected, Decimal::toNative($decimal));
    }

    public function testTooLarge(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('Number is too large');
        Decimal::pow('10', '100');
    }
}
