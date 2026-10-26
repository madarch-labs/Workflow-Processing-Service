<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;

final class ExpressionEngineTest extends TestCase
{
    private ExpressionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ExpressionEngine();
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function eval(string $expression, array $vars = []): mixed
    {
        return $this->engine->evaluate($expression, static fn (string $path) => $vars[$path] ?? null);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function literalCases(): iterable
    {
        yield 'precedence' => ['1 + 2 * 3', 7];
        yield 'brackets' => ['(1 + 2) * 3', 9];
        yield 'unary minus' => ['-2 * 3', -6];
        yield 'modulo' => ['7 % 3', 1];
        yield 'float normalized to int' => ['0.5 * 4', 2];
        yield 'float rounding noise removed' => ['0.1 + 0.2', 0.3];
        yield 'plus concatenates non-numbers' => ["'a' + 1", 'a1'];
        yield 'concat' => ["concat('a', 'b', 1)", 'ab1'];
        yield 'upper' => ["upper('abc')", 'ABC'];
        yield 'lower unicode' => ["lower('ПРИВЕТ')", 'привет'];
        yield 'trim' => ["trim('  x  ')", 'x'];
        yield 'len unicode' => ["len('привет')", 6];
        yield 'substr' => ["substr('hello', 1, 3)", 'ell'];
        yield 'substr without length' => ["substr('hello', 2)", 'llo'];
        yield 'round' => ['round(2.345, 2)', 2.35];
        yield 'round to int' => ['round(2.5)', 3];
        yield 'abs' => ['abs(-5)', 5];
        yield 'min' => ['min(3, 1, 2)', 1];
        yield 'max' => ['max(3, 1, 2)', 3];
        yield 'function names are case-insensitive' => ["UPPER('a')", 'A'];
    }

    #[DataProvider('literalCases')]
    public function testEvaluate(string $expression, mixed $expected): void
    {
        self::assertSame($expected, $this->eval($expression));
    }

    public function testPathsAreResolved(): void
    {
        self::assertSame(90, $this->eval('object.amount * 0.9', ['object.amount' => '100']));
        self::assertSame('Ivan Petrov', $this->eval("concat(v1.first, ' ', v1.last)", ['v1.first' => 'Ivan', 'v1.last' => 'Petrov']));
    }

    public function testMissingPathIsEmptyString(): void
    {
        self::assertSame('x', $this->eval("concat(object.nothing, 'x')"));
        // current behaviour: '' is not numeric, so '+' concatenates ('' . 5); other operators treat '' as 0
        self::assertSame('5', $this->eval('object.nothing + 5'));
        self::assertSame(0, $this->eval('object.nothing * 5'));
    }

    public function testPathsListsReferencedPaths(): void
    {
        self::assertSame(['object.a', 'v1.b'], $this->engine->paths('object.a + max(v1.b, object.a)'));
    }

    public function testDivisionByZero(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->eval('10 / 0');
    }

    public function testArithmeticOnTextFails(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->eval("'abc' * 2");
    }

    public function testUnknownFunction(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->engine->parse('foo(1)');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'empty' => [''];
        yield 'dangling operator' => ['1 +'];
        yield 'unclosed bracket' => ['(1 + 2'];
        yield 'unterminated string' => ["'abc"];
        yield 'bare identifier' => ['amount * 2'];
        yield 'unexpected character' => ['1 $ 2'];
    }

    #[DataProvider('syntaxErrors')]
    public function testSyntaxErrors(string $expression): void
    {
        $this->expectException(ConfigurationException::class);
        $this->engine->parse($expression);
    }

    public function testWrongArgumentCountIsASaveError(): void
    {
        // since 5.2.1 the arity is checked when the expression is parsed (process save), not at run time
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Function "upper" expects 1 argument, got 0');
        $this->engine->parse('upper()');
    }
}
