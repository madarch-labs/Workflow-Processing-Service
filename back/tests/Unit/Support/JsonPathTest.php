<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Wps\Http\Outbound\JsonPathExtractor;
use Wps\Support\JsonPath;

/**
 * The shared JSONPath adapter (md/tasks_list/done/5.2.6-expr-types.md §4.3): validation and the first / all modes
 * used by json_get / json_get_all, plus the delegation of the http_request extractor.
 */
final class JsonPathTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private const DATA = [
        'data' => [
            'order_id' => 'A-1',
            'customer' => ['id' => 42, 'name' => 'Acme'],
            'items' => [['id' => 1, 'qty' => 2], ['id' => 2, 'qty' => 5]],
        ],
        'ids' => [1, 2],
        'nothing' => null,
    ];

    public function testFirst(): void
    {
        self::assertSame('A-1', JsonPath::first(self::DATA, '$.data.order_id'));
        self::assertSame(42, JsonPath::first(self::DATA, '$.data.customer.id'));
        self::assertSame(['id' => 1, 'qty' => 2], JsonPath::first(self::DATA, '$.data.items[0]'));
        self::assertSame(1, JsonPath::first(self::DATA, '$.ids[*]'));
        self::assertSame(2, JsonPath::first(self::DATA, '$.data.items[?(@.qty > 3)].id'));
    }

    public function testFirstReturnsNullWhenNothingIsFound(): void
    {
        self::assertNull(JsonPath::first(self::DATA, '$.data.missing'));
        self::assertNull(JsonPath::first(self::DATA, '$.nothing'));
        self::assertNull(JsonPath::first(self::DATA, '$.data.items[*].unknown'));
    }

    public function testAll(): void
    {
        self::assertSame(['A-1'], JsonPath::all(self::DATA, '$.data.order_id'));
        self::assertSame([[1, 2]], JsonPath::all(self::DATA, '$.ids'));
        self::assertSame([1, 2], JsonPath::all(self::DATA, '$.ids[*]'));
        self::assertSame([1, 2], JsonPath::all(self::DATA, '$.data.items[*].id'));
        self::assertSame([42, 1, 2], JsonPath::all(self::DATA, '$..id'));
    }

    public function testAllReturnsAnEmptyListWhenNothingIsFound(): void
    {
        self::assertSame([], JsonPath::all(self::DATA, '$.data.missing'));
        self::assertSame([], JsonPath::all(self::DATA, '$.data.items[*].unknown'));
        self::assertSame([], JsonPath::all([], '$.a'));
    }

    public function testNonArrayDocument(): void
    {
        foreach ([null, '', 'text', 42, 1.5, true] as $value) {
            self::assertSame([], JsonPath::all($value, '$.a'), 'all: ' . var_export($value, true));
            self::assertNull(JsonPath::first($value, '$.a'), 'first: ' . var_export($value, true));
        }
    }

    public function testValidPaths(): void
    {
        foreach (['$', '$.data', '$.data.items[0]', '$.data.items[?(@.qty > 3)].id', '$..id', '$.ids[*]'] as $path) {
            self::assertNull(JsonPath::error($path), 'path: ' . $path);
        }
    }

    public function testPathMustStartWithDollar(): void
    {
        self::assertSame('JSONPath must start with "$"', JsonPath::error('data.id'));
        self::assertSame('JSONPath must start with "$"', JsonPath::error(''));
    }

    public function testMalformedPath(): void
    {
        $error = JsonPath::error('$.data[?(@.x');
        self::assertNotNull($error);
        self::assertStringStartsWith('Invalid JSONPath:', $error);
    }

    public function testExtractorDelegates(): void
    {
        self::assertSame('A-1', JsonPathExtractor::extract(self::DATA, '$.data.order_id'));
        self::assertSame(['id' => 1, 'qty' => 2], JsonPathExtractor::extract(self::DATA, '$.data.items[0]'));
        self::assertSame([42, 1, 2], JsonPathExtractor::extract(self::DATA, '$..id'));
        self::assertSame([1, 2], JsonPathExtractor::extract(self::DATA, '$.ids'));
        self::assertNull(JsonPathExtractor::extract(self::DATA, '$.data.missing'));
        self::assertNull(JsonPathExtractor::extract(null, '$.a'));
        self::assertNull(JsonPathExtractor::error('$.data.items[0].id'));
        self::assertSame(JsonPath::error('data.id'), JsonPathExtractor::error('data.id'));
        self::assertSame(JsonPath::error('$.data[?(@.x'), JsonPathExtractor::error('$.data[?(@.x'));
    }
}
