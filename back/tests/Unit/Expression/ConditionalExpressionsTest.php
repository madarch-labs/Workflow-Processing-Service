<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\ExpressionLexer;

final class ConditionalExpressionsTest extends TestCase
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

    public function testLexer(): void
    {
        $types = array_map(static fn (array $t) => $t[0] . ':' . json_encode($t[1]), ExpressionLexer::tokenize('a == 1 AND object.and <> null || !True'));
        self::assertSame(['ident:"a"', 'op:"=="', 'num:1', 'kw:"and"', 'path:"object.and"', 'op:"<>"', 'kw:"null"', 'op:"||"', 'op:"!"', 'kw:"true"', 'eof:null'], $types);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function cases(): iterable
    {
        // precedence
        yield 'or below and' => ['true or false and false', true];
        yield 'not binds tighter than and' => ['not false and false', false];
        yield 'not over comparison' => ['not 1 == 2', true];
        yield 'arithmetic before comparison' => ['1 + 2 > 2', true];
        yield 'synonyms' => ['(1 = 1) && !(2 <> 2) || false', true];
        // comparisons (§4.3, §4.8)
        yield 'number strings equal' => ["'100' == 100.00", true];
        yield 'numeric order' => ["'9' < '10'", true];
        yield 'string order' => ["'abc' < 'abd'", true];
        yield 'case matters' => ["'Active' == 'active'", false];
        yield 'null equals empty' => ["'' == null", true];
        yield 'empty not equal to 0' => ["'' == 0", false];
        yield 'ordering with empty is false' => ["'' > 0", false];
        yield 'bool and 1' => ["true == '1'", true];
        yield 'bool and text' => ["true == 'abc'", false];
        yield 'bool and text not equal' => ["true != 'abc'", true];
        yield 'formatted numbers are strings' => ["'1 000' > '999'", false];
        yield 'text vs number is string order' => ["'abc' > 5", true];
        yield 'iso dates as strings' => ["'2026-10-16' < '2026-10-17'", true];
        yield 'lists equal' => ['[1, 2] == [1, 2.0]', true];
        yield 'lists differ' => ["[1, 2] != [1, '3']", true];
        // missing paths
        yield 'missing path equals empty' => ["object.missing == ''", true];
        yield 'missing path ordering' => ['object.missing > 5', false];
        yield 'not of empty' => ['not object.missing', true];
        // if & co (§4.5)
        yield 'if then' => ["if(5 > 3, 'yes', 'no')", 'yes'];
        yield 'if else' => ["if(5 < 3, 'yes', 'no')", 'no'];
        yield 'if without else' => ['if(false, 1)', ''];
        yield 'if with string truthiness' => ["if('да', 1, 2)", 1];
        yield 'coalesce' => ["coalesce(object.missing, '', 'x', 'y')", 'x'];
        yield 'coalesce all empty' => ["coalesce(object.missing, '')", ''];
        yield 'ifempty' => ["ifempty(object.missing, 'default')", 'default'];
        yield 'ifs with default' => ["ifs(1 > 2, 'a', 2 > 3, 'b', 'c')", 'c'];
        yield 'ifs match' => ["ifs(1 > 2, 'a', 3 > 2, 'b')", 'b'];
        yield 'ifs no match no default' => ["ifs(1 > 2, 'a')", ''];
        yield 'switch' => ["switch('Warm', 'Hot', 'High', 'Warm', 'Medium', 'Low')", 'Medium'];
        yield 'switch default' => ["switch('Cold', 'Hot', 'High', 'Low')", 'Low'];
        yield 'switch numeric keys' => ["switch('2', 1, 'one', 2, 'two')", 'two'];
        yield 'functions are case-insensitive' => ["IF(TRUE, 'a', 'b')", 'a'];
        yield 'bool plus number concatenates (legacy)' => ['true + 1', 'true1'];
        yield 'keyword-like path' => ['object.or', ''];
    }

    #[DataProvider('cases')]
    public function testEvaluate(string $expression, mixed $expected): void
    {
        self::assertSame($expected, $this->eval($expression));
    }

    public function testDiscountScenario(): void
    {
        $vars = ['object.amount' => '150000'];
        self::assertSame(135000, $this->eval('if(object.amount > 100000, object.amount * 0.9, object.amount)', $vars));
        self::assertSame('VIP', $this->eval("if(object.amount >= 100000 and object.status == 'Active', 'VIP', 'Regular')", $vars + ['object.status' => 'Active']));
    }

    public function testLaziness(): void
    {
        self::assertSame(1, $this->eval('if(true, 1, 1 / 0)'));
        self::assertSame('x', $this->eval("coalesce('x', 1 / 0)"));
        self::assertFalse($this->eval('false and 1 / 0'));
        self::assertTrue($this->eval('true or 1 / 0'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truthinessErrors(): iterable
    {
        yield 'text' => ["if('abc', 1, 2)"];
        yield 'number 2' => ['if(2, 1, 2)'];
        yield 'and with text' => ["'a' or 'b'"];
        yield 'list' => ['not [1]'];
    }

    #[DataProvider('truthinessErrors')]
    public function testTruthinessErrors(string $expression): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessageMatches('/Condition must be boolean/');
        $this->eval($expression);
    }

    public function testOrderingListsIsAnError(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->eval('[1] < [2]');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function saveErrors(): iterable
    {
        yield 'chained comparison' => ['1 < 2 < 3', 'Comparison operators cannot be chained'];
        yield 'if arity' => ['if(1)', 'Function "if" expects 2..3 arguments, got 1'];
        yield 'substr arity' => ['substr(1)', 'Function "substr" expects 2..3 arguments, got 1'];
        yield 'switch arity' => ["switch('a', 'b')", 'Function "switch" expects at least 3 arguments, got 2'];
        yield 'single equals in a wrong place' => ['= 1', 'Unexpected "="'];
        yield 'keyword as a value' => ['1 + and', 'Unexpected "and"'];
        yield 'unclosed list' => ['[1, 2', 'Expected "]"'];
    }

    #[DataProvider('saveErrors')]
    public function testSaveErrors(string $expression, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->engine->parse($expression);
    }

    public function testLimits(): void
    {
        $this->engine->parse(str_repeat('(', 30) . '1' . str_repeat(')', 30));
        try {
            $this->engine->parse(str_repeat('(', 40) . '1' . str_repeat(')', 40));
            self::fail('depth limit expected');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('nested too deeply', $e->getMessage());
        }
        try {
            $this->engine->parse(implode(' + ', array_fill(0, 300, '1')));
            self::fail('node limit expected');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('too complex', $e->getMessage());
        }
        try {
            $this->engine->parse("'" . str_repeat('x', 4001) . "'");
            self::fail('length limit expected');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('too long', $e->getMessage());
        }
    }

    public function testReservedFunctionNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->registerFunction('and', static fn () => 1);
    }

    public function testPathsInsideNewNodes(): void
    {
        self::assertSame(['object.a', 'object.b', 'v1.c', 'v1.d'], $this->engine->paths('if(object.a > 1 and not object.b, [v1.c], v1.d)'));
    }

    public function testSpecsAndCustomFunctionsWithoutSpec(): void
    {
        $names = array_map(static fn ($s) => $s->name, $this->engine->functionSpecs());
        foreach (['if', 'coalesce', 'ifempty', 'ifs', 'switch', 'concat', 'substr'] as $name) {
            self::assertContains($name, $names);
        }
        $this->engine->registerFunction('repeat', static fn (mixed $s, mixed $n) => str_repeat((string)$s, (int)$n));
        self::assertSame('abab', $this->eval("repeat('ab', 2)"));
        $this->expectException(ConfigurationException::class);
        $this->engine->parse("repeat('ab')");
    }
}
