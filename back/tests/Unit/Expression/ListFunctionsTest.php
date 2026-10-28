<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;

final class ListFunctionsTest extends TestCase
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
        yield 'empty literal' => ['[]', []];
        yield 'numeric literal' => ['[1, 2, 3]', [1, 2, 3]];
        yield 'mixed literal' => ["['a', 1, true, null]", ['a', 1, true, null]];
        yield 'nested literal' => ['[[1], [2, 3]]', [[1], [2, 3]]];
        yield 'list()' => ["list('vip', 'partner')", ['vip', 'partner']];
        yield 'list() empty' => ['list()', []];
        yield 'list() keeps nested values' => ['list([1], 2)', [[1], 2]];
    }

    #[DataProvider('literalCases')]
    public function testLiterals(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function toListCases(): iterable
    {
        yield 'multienum' => ["to_list('^a^,^b^', ',', '^')", ['a', 'b']];
        yield 'multienum value with separator' => ["to_list('^a,b^,^c^', ',', '^')", ['a,b', 'c']];
        yield 'multienum empty' => ["to_list('^^', ',', '^')", []];
        yield 'comma separated' => ["to_list('a, b', ',')", ['a', 'b']];
        yield 'empty items dropped' => ["to_list('a,,b,', ',')", ['a', 'b']];
        yield 'trimmed' => ["to_list(' a , b ', ',')", ['a', 'b']];
        yield 'json array' => ["to_list('[1, 2]')", [1, 2]];
        yield 'plain string is one item' => ["to_list('a,b')", ['a,b']];
        yield 'empty string' => ["to_list('')", []];
        yield 'missing path' => ['to_list(object.missing)', []];
        yield 'scalar' => ['to_list(5)', [5]];
        yield 'array as is' => ['to_list(object.list)', ['x', 'y']];
        yield 'record list as is' => ['to_list(object.records)', [['id' => '1'], ['id' => '2']]];
    }

    #[DataProvider('toListCases')]
    public function testToList(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression, [
            'object.list' => ['x', 'y'],
            'object.records' => [['id' => '1'], ['id' => '2']],
        ]));
    }

    /**
     * @return iterable<string, array{string, mixed, array<string, mixed>}>
     */
    public static function listCases(): iterable
    {
        $records = [
            'tasks.items' => [
                ['name' => 'Позвонить', 'status' => 'Not Started', 'address' => ['city' => 'Москва']],
                ['name' => 'Отправить КП', 'status' => 'Completed'],
                ['name' => 'Подписать', 'status' => 'Not Started'],
            ],
        ];

        yield 'count empty' => ['count([])', 0, []];
        yield 'count missing' => ['count(object.missing)', 0, []];
        yield 'count scalar' => ["count('abc')", 1, []];
        yield 'count list' => ['count([1, 2, 3])', 3, []];
        yield 'count records' => ['count(tasks.items)', 3, $records];
        yield 'in found' => ["in('vip', ['vip', 'x'])", true, []];
        yield 'in number and string' => ["in('1', [1, 2])", true, []];
        yield 'in string is not a substring' => ["in('a', 'abc')", false, []];
        yield 'in empty list' => ["in('x', [])", false, []];
        yield 'first' => ['first([3, 1])', 3, []];
        yield 'first empty' => ['first([])', '', []];
        yield 'last' => ['last([3, 1])', 1, []];
        yield 'last empty' => ['last([])', '', []];
        yield 'nth' => ["nth(['a', 'b', 'c'], 1)", 'b', []];
        yield 'nth negative' => ["nth(['a', 'b', 'c'], -1)", 'c', []];
        yield 'nth out of range' => ["nth(['a'], 3)", '', []];
        yield 'nth negative out of range' => ["nth(['a'], -3)", '', []];
        yield 'slice' => ['slice([1, 2, 3, 4], 1, 2)', [2, 3], []];
        yield 'slice to the end' => ['slice([1, 2, 3], 1)', [2, 3], []];
        yield 'slice negative start' => ['slice([1, 2, 3], -2)', [2, 3], []];
        yield 'unique' => ["unique([1, '1', 2, 1])", [1, 2], []];
        yield 'unique is case sensitive' => ["unique(['a', 'A'])", ['a', 'A'], []];
        yield 'sort numbers' => ['sort([10, 2, 1])', [1, 2, 10], []];
        yield 'sort numeric strings' => ["sort(['10', '2'])", ['2', '10'], []];
        yield 'sort strings' => ["sort(['b', 'a'])", ['a', 'b'], []];
        yield 'sort mixed: numbers first' => ["sort(['b', 10, 'a', 2])", [2, 10, 'a', 'b'], []];
        yield 'sort desc' => ["sort([1, 3, 2], 'desc')", [3, 2, 1], []];
        yield 'reverse' => ['reverse([1, 2, 3])', [3, 2, 1], []];
        yield 'concat_lists' => ['concat_lists([1, 2], [3], [4])', [1, 2, 3, 4], []];
        yield 'concat_lists scalars' => ["concat_lists('a', 'b')", ['a', 'b'], []];
        yield 'without' => ["without([1, 2, '2', 3], 2)", [1, 3], []];
        yield 'without nothing' => ["without([1, 2], 5)", [1, 2], []];
        yield 'sum' => ['sum([1, 2, 3])', 6, []];
        yield 'sum decimals' => ["sum(['0.1', '0.2'])", 0.3, []];
        yield 'sum skips empty' => ["sum([1, '', null, 2])", 3, []];
        yield 'sum empty list' => ['sum([])', 0, []];
        yield 'sum scalar' => ["sum('5')", 5, []];
        yield 'avg' => ['avg([1, 2])', 1.5, []];
        yield 'avg skips empty' => ["avg(['', 2])", 2, []];
        yield 'avg empty list' => ['avg([])', '', []];
        yield 'pluck' => ["pluck(tasks.items, 'name')", ['Позвонить', 'Отправить КП', 'Подписать'], $records];
        yield 'pluck missing field' => ["pluck(tasks.items, 'nope')", ['', '', ''], $records];
        yield 'pluck nested field' => ["pluck(tasks.items, 'address.city')", ['Москва', '', ''], $records];
        yield 'pluck scalar' => ["pluck('abc', 'x')", [], []];
        yield 'where' => ["count(where(tasks.items, 'status', 'Not Started'))", 2, $records];
        yield 'where nothing' => ["count(where(tasks.items, 'status', 'Unknown'))", 0, $records];
        yield 'where missing field' => ["count(where(tasks.items, 'nope', 'X'))", 0, $records];
        yield 'index_of_item' => ["index_of_item([1, '2', 3], '2')", 1, []];
        yield 'index_of_item missing' => ['index_of_item([1, 2], 5)', -1, []];
        yield 'min of list' => ['min([5, 2, 9])', 2, []];
        yield 'max of list' => ['max([5, 2, 9])', 9, []];
    }

    /**
     * @param array<string, mixed> $vars
     */
    #[DataProvider('listCases')]
    public function testFunctions(string $expression, mixed $expected, array $vars): void
    {
        self::assertSame($expected, self::eval($expression, $vars));
    }

    public function testMultienumRoundTrip(): void
    {
        self::assertSame(['partner', 'vip'], self::eval("unique(concat_lists(to_list(object.tags_c, ',', '^'), ['vip']))", ['object.tags_c' => '^partner^,^vip^']));
        self::assertSame('^partner^,^vip^', self::eval("concat('^', join(unique(concat_lists(to_list(object.tags_c, ',', '^'), ['vip'])), '^,^'), '^')", ['object.tags_c' => '^partner^,^vip^']));
    }

    public function testListSizeLimit(): void
    {
        $big = range(1, ListFunctionsTest::MAX_ITEMS + 1);
        foreach (['count(object.big)', 'unique(object.big)', 'to_list(object.big)', 'sum(object.big)'] as $expression) {
            try {
                self::eval($expression, ['object.big' => $big]);
                self::fail("expected the list limit for {$expression}");
            } catch (NonRecoverableException $e) {
                self::assertSame('List is too large (max 10000 items)', $e->getMessage());
            }
        }
    }

    public const MAX_ITEMS = 10000;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'sum not a number' => ["sum([1, 'abc'])", 'sum(): element 2 is not a number: "abc"'];
        yield 'avg not a number' => ["avg(['1 234,56'])", 'avg(): element 1 is not a number: "1 234,56"'];
        yield 'nth not an integer' => ["nth([1], 'x')", 'nth(): index must be an integer, got "x"'];
        yield 'slice not an integer' => ["slice([1], 'x')", 'slice(): start must be an integer, got "x"'];
        yield 'wrapper without separator' => ["to_list('a', '', '^')", 'to_list(): separator is required when wrapper is given'];
    }

    #[DataProvider('runtimeErrors')]
    public function testRuntimeErrors(string $expression, string $message): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage($message);
        self::eval($expression);
    }

    public function testSaveErrors(): void
    {
        $engine = new ExpressionEngine();
        foreach ([
            "sort([1], 'up')" => 'Function "sort": order must be "asc" or "desc"',
            'to_list()' => 'Function "to_list" expects 1..3 arguments, got 0',
            'in(1)' => 'Function "in" expects 2 arguments, got 1',
            'where([], "a")' => 'Function "where" expects 3 arguments, got 2',
        ] as $expression => $message) {
            try {
                $engine->parse($expression);
                self::fail("expected an error for {$expression}");
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertIsArray($engine->parse("sort([1], object.order)"));
    }

    public function testDictionary(): void
    {
        $names = [];
        foreach ((new ExpressionEngine())->functionSpecs() as $spec) {
            if ($spec->category === 'list') {
                $names[] = $spec->name;
            }
        }
        sort($names);
        self::assertSame([
            'avg', 'concat_lists', 'count', 'first', 'in', 'index_of_item', 'last', 'list', 'nth', 'pluck',
            'reverse', 'slice', 'sort', 'sum', 'to_list', 'unique', 'where', 'without',
        ], $names);
    }
}
