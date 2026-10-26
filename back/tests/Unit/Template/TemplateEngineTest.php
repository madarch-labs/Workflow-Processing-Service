<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Actions\ChangeTypes\TemplateChangeType;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\Functions\DateFunctions;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\EscapeMode;
use Wps\Template\MissingPolicy;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;

final class TemplateEngineTest extends TestCase
{
    private const VARS = [
        'object' => [
            'first_name' => 'Иван', 'last_name' => 'Петров', 'name' => 'Big deal', 'amount' => '1234567.891',
            'date_closed' => '2026-10-31', 'empty' => '', 'null' => null, 'flag' => true, 'ratio' => 90.0,
            'tags' => ['a', 'b'], 'html' => '<script>alert(1)</script>', 'multi' => "line1\r\nline2", 'htmlmulti' => "<script>alert(1)</script>\r\nline2",
            'industries' => '^Banking^,^Retail^', 'template_like' => '{{ object.name }}', 'qty' => 3,
            'address' => ['city' => 'Москва'],
        ],
        'owner' => ['last_name' => ''],
    ];

    private TemplateEngine $templates;

    protected function setUp(): void
    {
        $expressions = new ExpressionEngine();
        DateFunctions::register($expressions, new DateMath(new \DateTimeZone('Europe/Moscow')), new FrozenClock('2026-10-16 07:30:00'));
        $this->templates = new TemplateEngine($expressions);
    }

    private static function resolver(): \Closure
    {
        $context = new ExecutionContext('e1', [], self::VARS);
        return static fn (string $path) => $context->resolve($path);
    }

    private function render(string $template, EscapeMode $mode = EscapeMode::Text, MissingPolicy $missing = MissingPolicy::Empty): mixed
    {
        return $this->templates->render($template, self::resolver(), $mode, $missing)->value;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textCases(): iterable
    {
        yield 'plain text' => ['Без тегов', 'Без тегов'];
        yield 'empty template' => ['', ''];
        yield 'paths' => ['Позвонить: {{ object.first_name }} {{object.last_name}}', 'Позвонить: Иван Петров'];
        yield 'nested path' => ['{{ object.address.city }}', 'Москва'];
        yield 'filters chain' => ["{{ '  abc ' | trim | upper }}", 'ABC'];
        yield 'number filter' => ["{{ object.amount | number:2:',':' ' }}", '1 234 567,89'];
        yield 'format_date' => ["{{ object.date_closed | format_date:'d.m.Y' }}", '31.10.2026'];
        yield 'default on empty' => ["Ответственный: {{ owner.last_name | default:'не назначен' }}", 'Ответственный: не назначен'];
        yield 'default on missing' => ["{{ object.none | default:'—' }}", '—'];
        yield 'filter argument path' => ['{{ object.name | truncate:object.qty:"" }}', 'Big'];
        yield 'truncate' => ['{{ object.name | truncate:5 }}', 'Big …'];
        yield 'expression' => ["Скидка: {{= if(object.amount > 100000, '10%', '0%') }}", 'Скидка: 10%'];
        yield 'expression with }} in a string' => ["{{= concat('}}', object.first_name) }}", '}}Иван'];
        yield 'escaped tag' => ['\{{ object.name }}', '{{ object.name }}'];
        yield 'double backslash: literal backslash and a tag' => ['\\\\{{ object.first_name }}', '\\Иван'];
        yield 'comment' => ['a{{! пометка }}b', 'ab'];
        yield 'null field' => ['[{{ object.null }}]', '[]'];
        yield 'bool' => ['{{ object.flag }}', 'true'];
        yield 'float without trailing zeros' => ['{{ object.ratio }}', '90'];
        yield 'array as json' => ['{{ object.tags }}', '["a","b"]'];
        yield 'join multienum' => ['{{ object.industries | join }}', 'Banking, Retail'];
        yield 'no recursion' => ['{{ object.template_like }}', '{{ object.name }}'];
        yield 'stray closing braces are text' => ['{"a":{"b":1}}', '{"a":{"b":1}}'];
        yield 'literal number' => ['{{ 5 }}', '5'];
        yield 'colon inside a quoted argument' => ["{{ '2026-10-16 07:30:00' | format_date:'H:i' }}", '10:30'];
        yield 'capitalize' => ["{{ 'иван' | capitalize }}", 'Иван'];
        yield 'urlencode' => ["{{ 'a b&c' | urlencode }}", 'a%20b%26c'];
        yield 'json filter' => ['{{ object.tags | json }}', '["a","b"]'];
    }

    #[DataProvider('textCases')]
    public function testText(string $template, string $expected): void
    {
        self::assertSame($expected, $this->render($template));
    }

    public function testMissingPolicies(): void
    {
        $result = $this->templates->render('[{{ object.none }}][{{ v9.x }}][{{ object.empty }}]', self::resolver());
        self::assertSame('[][][]', $result->value);
        self::assertSame(['object.none', 'v9.x'], $result->missing, 'an empty field is not missing');
        self::assertSame('[{{ object.none }}]', $this->render('[{{ object.none }}]', missing: MissingPolicy::Keep));
        self::assertSame('x', $this->render("{{ object.none | default:'x' }}", missing: MissingPolicy::Error));
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('Template references missing value "v1.email"');
        $this->render('{{ v1.email }}', missing: MissingPolicy::Error);
    }

    public function testEscapeModes(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $this->render('<p>{{ object.html }}</p>', EscapeMode::Html));
        self::assertSame('<script>alert(1)</script>', $this->render('{{ object.html | raw }}', EscapeMode::Html));
        self::assertSame("line1<br>\r\nline2", $this->render('{{ object.multi | nl2br }}'));
        self::assertSame("&lt;script&gt;alert(1)&lt;/script&gt;<br>\r\nline2", $this->render('{{ object.htmlmulti | nl2br }}', EscapeMode::Html));
        $header = $this->templates->render('Subject: {{ object.multi }}', self::resolver(), EscapeMode::Header);
        self::assertSame('Subject: line1 line2', $header->value);
        self::assertSame(['Line breaks removed from a header value'], $header->warnings);
        self::assertSame('https://api.example.com/deals/Big%20deal?q=a%20b', $this->render("https://api.example.com/deals/{{ object.name }}?q={{ 'a b' }}", EscapeMode::Url));
    }

    public function testRenderStructure(): void
    {
        $result = $this->templates->renderStructure([
            'amount' => '{{ object.qty }}',
            'tags' => '{{ object.tags }}',
            'title' => 'Сделка {{ object.name }}',
            'nested' => ['flag' => '{{ object.flag }}', 'n' => 5, 'expr' => '{{= object.qty * 2 }}'],
            'whole' => '{{ object.address }}',
            'missing' => '{{ object.none }}',
        ], self::resolver());
        self::assertSame([
            'amount' => 3,
            'tags' => ['a', 'b'],
            'title' => 'Сделка Big deal',
            'nested' => ['flag' => true, 'n' => 5, 'expr' => 6],
            'whole' => ['city' => 'Москва'],
            'missing' => null,
        ], $result->value);
        self::assertSame(['object.none'], $result->missing);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'unclosed (position in characters)' => ['Привет {{ object.name', 'Unclosed "{{" at position 7'];
        yield 'empty tag' => ['a {{ }}', 'Empty template tag at position 2'];
        yield 'unknown filter' => ['{{ object.name | foo }}', 'Unknown template filter "foo"'];
        yield 'filter arity' => ['{{ object.name | truncate }}', 'Template filter "truncate" expects 1..2 arguments, got 0'];
        yield 'expression error' => ['{{= 1 + }}', 'Unexpected end of expression'];
        yield 'expression in a pipeline' => ['{{ object.a * 2 }}', 'Invalid value "object.a * 2"'];
        yield 'unterminated string' => ["{{ 'abc | upper }}", 'Unclosed "{{"'];
        yield 'raw with args' => ['{{ object.a | raw:1 }}', 'Template filter "raw" takes no arguments'];
        yield 'empty expression' => ['{{= }}', 'Empty expression in template tag'];
    }

    #[DataProvider('syntaxErrors')]
    public function testSyntaxErrors(string $template, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->templates->parse($template);
    }

    public function testTooLong(): void
    {
        $this->expectExceptionMessage('Template is too long (max 65536 characters)');
        $this->templates->parse(str_repeat('a', 65537));
    }

    public function testRuntimeFilterError(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('Function "number_format" requires a number, got "Big deal"');
        $this->render('{{ object.name | number:2 }}');
    }

    public function testPathsFiltersAndCache(): void
    {
        $template = "{{ object.name | truncate:v1.len }} {{= concat(v2.a, object.b) }} {{ 'x' | default:v3.c }}";
        self::assertSame(['object.name', 'v1.len', 'v2.a', 'object.b', 'v3.c'], $this->templates->paths($template));
        self::assertSame(['truncate', 'default'], $this->templates->filters($template));
        self::assertSame($this->templates->parse($template), $this->templates->parse($template));
    }

    public function testValidator(): void
    {
        $validator = new TemplateValidator($this->templates);
        $scope = new DefinitionScope();
        $scope->declare('object', 'event');
        $validator->validate('{{ object.name }} {{ v9.x }}', $scope, 'f1');
        $validator->validate('{{ object }}', $scope, 'f2');
        $validator->validate('{{ object.html | raw }}', $scope, 'f3', EscapeMode::Html);
        $validator->validate('{{ object.html | raw }}', $scope, 'f4', EscapeMode::Html, allowRaw: true);
        $validator->validate('https://{{ object.host }}/x', $scope, 'f5', EscapeMode::Url);
        $validator->validate('{{ object.base }}/x', $scope, 'f6', EscapeMode::Url);
        $validator->validate('https://api.example.com:{{ object.port }}/x', $scope, 'f7', EscapeMode::Url);
        $validator->validate('https://api.example.com/deals/{{ object.id }}?q={{ object.name }}', $scope, 'f8', EscapeMode::Url);
        $validator->validate(['a' => '{{ object }}', 'b' => ['c' => 'x {{ v8.y }}']], $scope, 'f9', EscapeMode::Json);
        $validator->validate(42, $scope, 'f10');
        $validator->validate('{{ object.name', $scope, 'f11');
        $messages = [];
        foreach ($scope->errors() as $error) {
            $messages[$error['field']][] = $error['message'];
        }
        self::assertSame(['Variable "v9" is not defined at this point'], $messages['f1']);
        self::assertSame(['"object" must point to a field, e.g. object.name'], $messages['f2']);
        self::assertSame(['Template filter "raw" is not allowed here'], $messages['f3']);
        self::assertArrayNotHasKey('f4', $messages);
        self::assertSame(['Substitutions are not allowed in the scheme, host or port of a URL'], $messages['f5']);
        self::assertStringStartsWith('URL must start with a fixed scheme and host', $messages['f6'][0]);
        self::assertSame(['Substitutions are not allowed in the scheme, host or port of a URL'], $messages['f7']);
        self::assertArrayNotHasKey('f8', $messages);
        self::assertSame(['Variable "v8" is not defined at this point'], $messages['f9'], 'a whole variable is allowed in a single JSON leaf');
        self::assertSame(['Template must be a string'], $messages['f10']);
        self::assertSame(['Unclosed "{{" at position 0'], $messages['f11']);
    }

    public function testChangeType(): void
    {
        $type = new TemplateChangeType($this->templates, new TemplateValidator($this->templates));
        $context = new ExecutionContext('e1', [], self::VARS);
        [$value, $details] = $type->computeWithDetails($context, ['value' => 'Позвонить: {{ object.first_name }} {{ object.none }}']);
        self::assertSame('Позвонить: Иван ', $value);
        self::assertSame(['missing' => ['object.none']], $details);
        self::assertSame('Иван', $type->compute($context, ['template' => '{{ object.first_name }}']));
        $scope = new DefinitionScope();
        $scope->declare('object', 'event');
        $type->validate(['value' => '{{ object.x }}', 'missing' => 'loud'], $scope, 'a');
        $type->validate(['value' => ['x']], $scope, 'b');
        self::assertSame([
            ['field' => 'a.missing', 'message' => 'Must be one of: empty, error, keep'],
            ['field' => 'b.value', 'message' => 'Template must be a string'],
        ], array_map(static fn (array $e) => ['field' => $e['field'], 'message' => $e['message']], $scope->errors()));
        $this->expectExceptionMessage('Template references missing value "object.none"');
        $type->compute($context, ['value' => '{{ object.none }}', 'missing' => 'error']);
    }

    public function testFilterDictionary(): void
    {
        $byName = array_column($this->templates->filterDictionary(), null, 'value');
        self::assertSame(1, $byName['truncate']['min_args']);
        self::assertSame("{{ object.amount | number:2:',':' ' }}", $byName['number']['example']);
        self::assertArrayHasKey('raw', $byName);
        self::assertArrayNotHasKey('now', $byName);
    }
}
