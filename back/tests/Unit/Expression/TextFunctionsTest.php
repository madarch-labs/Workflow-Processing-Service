<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\SafeRegex;
use Wps\Support\Transliterator;

final class TextFunctionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $vars
     */
    private static function eval(string $expression, array $vars = []): mixed
    {
        return (new ExpressionEngine())->evaluate($expression, static fn (string $path) => $vars[$path] ?? null);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function literalCases(): iterable
    {
        yield 'replace' => ["replace('a-b-c', '-', ' ')", 'a b c'];
        yield 'replace empty search keeps the string' => ["replace('abc', '', 'x')", 'abc'];
        yield 'replace is case sensitive' => ["replace('AaA', 'a', 'b')", 'AbA'];
        yield 'replace_ci' => ["replace_ci('AaA', 'a', 'b')", 'bbb'];
        yield 'replace_ci unicode' => ["replace_ci('Привет МИР', 'мир', 'world')", 'Привет world'];
        yield 'replace_ci empty search' => ["replace_ci('abc', '', 'x')", 'abc'];
        yield 'contains' => ["contains('abc', 'b')", true];
        yield 'contains is not empty' => ["contains('abc', 'z')", false];
        yield 'contains empty part' => ["contains('abc', '')", true];
        yield 'starts_with' => ["starts_with('abc', 'ab')", true];
        yield 'starts_with no' => ["starts_with('abc', 'bc')", false];
        yield 'ends_with' => ["ends_with('abc', 'bc')", true];
        yield 'ends_with no' => ["ends_with('abc', 'ab')", false];
        yield 'index_of' => ["index_of('a-b-c', '-')", 1];
        yield 'index_of last' => ["index_of('a-b-c', '-')", 1];
        yield 'index_of missing' => ["index_of('abc', 'z')", -1];
        yield 'index_of unicode' => ["index_of('Привет мир', 'мир')", 7];
        yield 'before' => ["before('a@b.c', '@')", 'a'];
        yield 'before missing' => ["before('abc', '@')", 'abc'];
        yield 'before empty separator' => ["before('abc', '')", ''];
        yield 'after' => ["after('a@b.c', '@')", 'b.c'];
        yield 'after missing' => ["after('abc', '@')", ''];
        yield 'after empty separator' => ["after('abc', '')", 'abc'];
        yield 'pad_left' => ["pad_left('42', 6, '0')", '000042'];
        yield 'pad_left default char' => ["pad_left('ab', 4)", '  ab'];
        yield 'pad_left unicode' => ["pad_left('я', 3, '-')", '--я'];
        yield 'pad_left emoji is one character' => ["pad_left('🙂', 3, '·')", '··🙂'];
        yield 'pad_left longer' => ["pad_left('abc', 2)", 'abc'];
        yield 'pad_right' => ["pad_right('42', 6, '0')", '420000'];
        yield 'pad_right unicode' => ["pad_right('я', 3, '-')", 'я--'];
        yield 'capitalize' => ["capitalize('иван')", 'Иван'];
        yield 'capitalize empty' => ["capitalize('')", ''];
        yield 'title_case' => ["title_case('иван петров')", 'Иван Петров'];
        yield 'title_case latin' => ["title_case('hello world')", 'Hello World'];
        yield 'repeat' => ["repeat('ab', 3)", 'ababab'];
        yield 'repeat zero' => ["repeat('ab', 0)", ''];
        yield 'split multienum' => ["split('^vip^,^partner^', ',', '^')", ['vip', 'partner']];
        yield 'split multienum with a comma inside' => ["split('^a,b^,^c^', ',', '^')", ['a,b', 'c']];
        yield 'split comma separated' => ["split('a, b', ',')", ['a', 'b']];
        yield 'split empty string' => ["split('', ',')", []];
        yield 'split missing' => ['split(object.missing, ",")', []];
        yield 'split drops empty items' => ["split('a,,b,', ',')", ['a', 'b']];
        yield 'join list' => ["join(['vip', 'partner'], ', ')", 'vip, partner'];
        yield 'join default separator' => ["join(['a', 'b'])", 'a, b'];
        yield 'join wrapper' => ["join(['vip', 'partner'], ',', '^')", '^vip^,^partner^'];
        yield 'join multienum string as is' => ["join('^Banking^,^Retail^')", 'Banking, Retail'];
        yield 'join scalar' => ["join('abc', ',')", 'abc'];
        yield 'multienum round trip' => ["join(split('^vip^,^partner^', ',', '^'), ',', '^')", '^vip^,^partner^'];
        yield 'regex_match' => ["regex_match('+7 999 123-45-67', '^\\+7\\d{10}$')", false];
        yield 'regex_match digits' => ["regex_match('79991234567', '^\\d{11}$')", true];
        yield 'regex_match flags i' => ["regex_match('ABC', '^abc$', 'i')", true];
        yield 'regex_match utf8' => ["regex_match('Привет', '^\\p{Lu}\\p{Ll}+$')", true];
        yield 'regex_match utf8 case differs' => ["regex_match('привет', '^\\p{Lu}\\p{Ll}+$')", false];
        yield 'regex_extract' => ["regex_extract('order #42', '#(\\d+)', 1)", '42'];
        yield 'regex_extract whole match' => ["regex_extract('order #42', '#\\d+')", '#42'];
        yield 'regex_extract missing' => ["regex_extract('abc', '\\d+')", ''];
        yield 'regex_extract missing group' => ["regex_extract('a1', 'a(\\d)', 5)", ''];
        yield 'regex_replace' => ["regex_replace('+7 (999) 123-45-67', '[^0-9+]', '')", '+79991234567'];
        yield 'regex_replace with a reference' => ["regex_replace('2026-10-26', '^(\\d+)-(\\d+)-(\\d+)$', '$3.$2.$1')", '26.10.2026'];
        yield 'regex_replace flags' => ["regex_replace('aBc', 'b', 'x', 'i')", 'axc'];
        yield 'slug' => ["slug('Заказ №42')", 'zakaz-42'];
        yield 'slug punctuation' => ["slug('  Hello, World!  ')", 'hello-world'];
        yield 'slug empty' => ["slug('')", ''];
        yield 'translit' => ["translit('Щука')", 'Shchuka'];
        yield 'translit yo' => ["translit('Ёж')", 'Yozh'];
        yield 'translit signs' => ["translit('съезд')", 'sezd'];
        yield 'translit latin unchanged' => ["translit('Hello, Ёж!')", 'Hello, Yozh!'];
        yield 'strip_tags' => ["strip_tags('<p>Привет <b>мир</b></p>')", 'Привет мир'];
        yield 'left' => ["left('Привет', 3)", 'При'];
        yield 'left zero' => ["left('abc', 0)", ''];
        yield 'left longer' => ["left('abc', 10)", 'abc'];
        yield 'right' => ["right('Привет', 3)", 'вет'];
        yield 'right zero' => ["right('abc', 0)", ''];
        yield 'right longer' => ["right('abc', 10)", 'abc'];
        yield 'right emoji' => ["right('a🙂', 1)", '🙂'];
    }

    #[DataProvider('literalCases')]
    public function testFunctions(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'pad character is empty' => ["pad_left('1', 3, '')", 'pad_left(): pad character must be exactly one character'];
        yield 'pad character is long' => ["pad_left('1', 3, '00')", 'pad_left(): pad character must be exactly one character'];
        yield 'pad length is not a number' => ["pad_right('1', 'x')", 'Function "pad_right": length must be an integer between 0 and 1000, got "x"'];
        yield 'pad length is a fraction' => ["pad_left('1', 1.5)", 'Function "pad_left": length must be an integer between 0 and 1000, got "1.5"'];
        yield 'pad length is too big' => ["pad_left('1', 1001)", 'Function "pad_left": length must be an integer between 0 and 1000, got "1001"'];
        yield 'repeat count is negative' => ["repeat('x', -1)", 'Function "repeat": count must be an integer between 0 and 1000, got "-1"'];
        yield 'repeat count is not a number' => ["repeat('x', 'x')", 'Function "repeat": count must be an integer between 0 and 1000, got "x"'];
        yield 'left negative' => ["left('abc', -1)", 'Function "left": count must be a non-negative integer, got "-1"'];
        yield 'right fraction' => ["right('abc', 1.5)", 'Function "right": count must be a non-negative integer, got "1.5"'];
        yield 'split empty separator' => ["split('abc', '')", 'split(): separator must not be empty'];
        yield 'regex invalid at run time' => ["regex_match('abc', '(')", 'Invalid regular expression in regex_match(): missing closing parenthesis'];
        yield 'regex flags at run time' => ["regex_match('abc', 'a', 'x')", 'Function "regex_match": flags may contain only i, m, s, got "x"'];
        yield 'regex group is not a number' => ["regex_extract('a1', '(\\d)', 'x')", 'Function "regex_extract": group must be a non-negative integer, got "x"'];
    }

    #[DataProvider('runtimeErrors')]
    public function testRuntimeErrors(string $expression, string $message): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage($message);
        self::eval($expression);
    }

    public function testRegexInputLimits(): void
    {
        $cases = [
            ["regex_match(object.big, 'a')", ['object.big' => str_repeat('a', SafeRegex::MAX_SUBJECT_LENGTH + 1)], 'Regular expression input in regex_match() is too long (max 100000 characters)'],
            ["regex_match('a', object.pattern)", ['object.pattern' => str_repeat('a', SafeRegex::MAX_PATTERN_LENGTH + 1)], 'Regular expression in regex_match() is too long (max 500 characters)'],
        ];
        foreach ($cases as [$expression, $vars, $message]) {
            try {
                self::eval($expression, $vars);
                self::fail("expected an error for {$expression}");
            } catch (NonRecoverableException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testCatastrophicBacktrackingIsStopped(): void
    {
        $started = microtime(true);
        try {
            self::eval("regex_match(object.value, '(a+)+$')", ['object.value' => str_repeat('a', 30) . 'b']);
            self::fail('expected the backtrack limit error');
        } catch (NonRecoverableException $e) {
            self::assertSame('Regular expression failed: backtrack limit exceeded', $e->getMessage());
        }
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testRepeatResultLimit(): void
    {
        try {
            self::eval('repeat(object.big, 1000)', ['object.big' => str_repeat('a', 2000)]);
            self::fail('expected the result length error');
        } catch (NonRecoverableException $e) {
            self::assertSame('Expression result is too long', $e->getMessage());
        }
    }

    public function testSplitListLimit(): void
    {
        $value = implode(',', array_fill(0, 10001, 'a'));
        try {
            self::eval('split(object.big, ",")', ['object.big' => $value]);
            self::fail('expected the list limit');
        } catch (NonRecoverableException $e) {
            self::assertSame('List is too large (max 10000 items)', $e->getMessage());
        }
    }

    public function testSaveErrors(): void
    {
        $engine = new ExpressionEngine();
        $cases = [
            "regex_match('abc', '(')" => 'Invalid regular expression in regex_match(): missing closing parenthesis',
            "regex_replace('abc', 'a', 'b', 'x')" => 'Function "regex_replace": flags may contain only i, m, s, got "x"',
            "regex_extract('abc', 'a', 'x')" => 'Function "regex_extract": group must be a non-negative integer, got "x"',
            "regex_replace('abc', '(', '')" => 'Invalid regular expression in regex_replace(): missing closing parenthesis',
            "pad_left('1', 2000)" => 'Function "pad_left": length must be an integer between 0 and 1000, got "2000"',
            "repeat('x', 3.5)" => 'Function "repeat": count must be an integer between 0 and 1000, got "3.5"',
            'replace(1)' => 'Function "replace" expects 3 arguments, got 1',
            'split(1)' => 'Function "split" expects 2..3 arguments, got 1',
        ];
        foreach ($cases as $expression => $message) {
            try {
                $engine->parse($expression);
                self::fail("expected an error for {$expression}");
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertIsArray($engine->parse("regex_match(object.text, object.pattern, object.flags)"));
        self::assertIsArray($engine->parse("pad_left(object.number, object.length)"));
    }

    public function testDictionary(): void
    {
        $names = [];
        foreach ((new ExpressionEngine())->functionSpecs() as $spec) {
            if ($spec->category === 'string') {
                $names[] = $spec->name;
            }
        }
        foreach (['replace', 'replace_ci', 'contains', 'starts_with', 'ends_with', 'index_of', 'before', 'after', 'pad_left', 'pad_right', 'title_case', 'repeat', 'split', 'join', 'left', 'right', 'strip_tags', 'translit', 'slug', 'regex_match', 'regex_extract', 'regex_replace'] as $name) {
            self::assertContains($name, $names);
        }
    }

    public function testTransliterator(): void
    {
        self::assertSame('Shchuka', Transliterator::toLatin('Щука'));
        self::assertSame('Zakaz', Transliterator::toLatin('Заказ'));
        self::assertSame('sezd', Transliterator::toLatin('съезд'));
        self::assertSame('Hello', Transliterator::toLatin('Hello'));
        self::assertSame('', Transliterator::toLatin('ъь'));
    }
}
