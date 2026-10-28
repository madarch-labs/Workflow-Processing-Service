<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Functions\ListFunctions;
use Wps\Expression\Functions\LogicFunctions;
use Wps\Expression\Functions\NumberFunctions;
use Wps\Expression\Functions\TextFunctions;
use Wps\Types\TypeCaster;

/**
 * Safe expression language for "calculate", templates and conditions (no eval()). Grammar — ExpressionParser.
 *
 * Values: numbers, strings, true / false / null, lists [a, b]; PATH is "object.amount" / "v1.price" (a missing path
 * gives ''). '+' adds numbers and concatenates otherwise. Comparisons — ValueComparator; "and" / "or" / "not" and if()
 * need booleans (truthy()). Special forms (if, coalesce, ifs, switch …) get their arguments lazily.
 * New functions are registered with registerFunction() / registerSpecialForm() (extension point); the arity from the
 * FunctionSpec is checked when an expression is parsed, i.e. when a process is saved.
 */
final class ExpressionEngine
{
    public const MAX_LENGTH = 4000;
    public const MAX_NODES = 500;
    public const MAX_DEPTH = 32;
    public const MAX_RESULT_BYTES = 1048576;

    /** @var array<string, callable(mixed...): mixed> */
    private array $functions = [];

    /** @var array<string, \Closure(list<\Closure(): mixed>): mixed> */
    private array $specialForms = [];

    /** @var array<string, FunctionSpec> */
    private array $specs = [];

    /** @var array<string, array<int, mixed>> parsed AST cache (definitions are reused for many events) */
    private array $cache = [];

    private readonly TypeCaster $caster;
    private readonly ValueComparator $comparator;

    public function __construct(?TypeCaster $caster = null)
    {
        $this->caster = $caster ?? new TypeCaster();
        $this->comparator = new ValueComparator($this->caster);
        $this->registerDefaultFunctions();
        LogicFunctions::register($this);
        NumberFunctions::register($this);
        TextFunctions::register($this);
        ListFunctions::register($this);
    }

    public function caster(): TypeCaster
    {
        return $this->caster;
    }

    public function comparator(): ValueComparator
    {
        return $this->comparator;
    }

    /**
     * @param callable(mixed...): mixed $fn
     * @param FunctionSpec|null $spec without a spec the arity is taken from the callable
     */
    public function registerFunction(string $name, callable $fn, ?FunctionSpec $spec = null): void
    {
        $name = $this->assertName($name);
        $this->functions[$name] = $fn;
        unset($this->specialForms[$name]);
        $this->specs[$name] = $spec ?? self::specFromCallable($name, $fn);
    }

    /**
     * A function that receives its arguments unevaluated (closures): if(), coalesce() …
     *
     * @param \Closure(list<\Closure(): mixed>): mixed $fn
     */
    public function registerSpecialForm(string $name, \Closure $fn, FunctionSpec $spec): void
    {
        $name = $this->assertName($name);
        $this->specialForms[$name] = $fn;
        unset($this->functions[$name]);
        $this->specs[$name] = $spec;
    }

    /**
     * Calls a registered function with evaluated arguments (used by templates and other functions).
     */
    public function call(string $name, mixed ...$args): mixed
    {
        $name = strtolower($name);
        if (isset($this->specialForms[$name])) {
            return ($this->specialForms[$name])(array_map(static fn ($a) => static fn () => $a, $args));
        }
        if (!isset($this->functions[$name])) {
            throw new NonRecoverableException("Unknown function \"{$name}\"");
        }
        return ($this->functions[$name])(...$args);
    }

    public function spec(string $name): ?FunctionSpec
    {
        return $this->specs[strtolower($name)] ?? null;
    }

    public function hasFunction(string $name): bool
    {
        $name = strtolower($name);
        return isset($this->functions[$name]) || isset($this->specialForms[$name]);
    }

    /**
     * @return string[]
     */
    public function functionNames(): array
    {
        return array_keys($this->specs);
    }

    /**
     * @return list<FunctionSpec>
     */
    public function functionSpecs(): array
    {
        return array_values($this->specs);
    }

    /**
     * Parses the expression and returns the AST; throws ConfigurationException on syntax errors and on
     * unknown functions / wrong argument counts.
     *
     * @return array<int, mixed>
     */
    public function parse(string $expression): array
    {
        if (isset($this->cache[$expression])) {
            return $this->cache[$expression];
        }
        if (strlen($expression) > self::MAX_LENGTH) {
            throw new ConfigurationException('Expression is too long (max ' . self::MAX_LENGTH . ' characters)');
        }
        $parser = new ExpressionParser(ExpressionLexer::tokenize($expression), $expression);
        $ast = $parser->parse();
        $this->assertCalls($ast);
        if (count($this->cache) > 1000) {
            $this->cache = [];
        }
        return $this->cache[$expression] = $ast;
    }

    /**
     * Paths referenced in the expression (for validation of variables).
     *
     * @return string[]
     */
    public function paths(string $expression): array
    {
        $paths = [];
        $this->collectPaths($this->parse($expression), $paths);
        return array_values(array_unique($paths));
    }

    /**
     * AST nodes: ['num', v] | ['str', v] | ['bool', v] | ['null'] | ['path', p] | ['neg', n] | ['bin', op, l, r]
     *            | ['cmp', op, l, r] | ['and', l, r] | ['or', l, r] | ['not', n] | ['list', [n]] | ['call', name, [n]]
     *
     * @param array<int, mixed> $node
     * @param string[] $paths
     */
    private function collectPaths(array $node, array &$paths): void
    {
        foreach (self::children($node) as $child) {
            $this->collectPaths($child, $paths);
        }
        if ($node[0] === 'path') {
            $paths[] = $node[1];
        }
    }

    /**
     * @param array<int, mixed> $node
     * @return list<array<int, mixed>>
     */
    private static function children(array $node): array
    {
        return match ($node[0]) {
            'neg', 'not' => [$node[1]],
            'bin', 'cmp' => [$node[2], $node[3]],
            'and', 'or' => [$node[1], $node[2]],
            'list' => $node[1],
            'call' => $node[2],
            default => [],
        };
    }

    /**
     * @param callable(string): mixed $resolver returns the value for a path (null when missing)
     */
    public function evaluate(string $expression, callable $resolver): mixed
    {
        $result = self::normalizeNumber($this->evaluateNode($this->parse($expression), $resolver));
        if (is_string($result) && strlen($result) > self::MAX_RESULT_BYTES) {
            throw new NonRecoverableException('Expression result is too long');
        }
        return $result;
    }

    /**
     * Value of a condition: true/1/yes/on/да or false/0/no/off/нет/''/null; anything else is an error.
     */
    public function truthy(mixed $value): bool
    {
        return $this->comparator->truthy($value);
    }

    /**
     * @param array<int, mixed> $node
     * @param callable(string): mixed $resolver
     */
    private function evaluateNode(array $node, callable $resolver): mixed
    {
        switch ($node[0]) {
            case 'num':
            case 'str':
            case 'bool':
                return $node[1];
            case 'null':
                return null;
            case 'path':
                $value = $resolver($node[1]);
                return $value ?? '';
            case 'neg':
                return Decimal::toNative(Decimal::neg($this->toDecimal($this->evaluateNode($node[1], $resolver), '-')));
            case 'bin':
                $left = $this->evaluateNode($node[2], $resolver);
                $right = $this->evaluateNode($node[3], $resolver);
                return $this->binary($node[1], $left, $right);
            case 'cmp':
                return $this->comparator->compare($node[1], $this->evaluateNode($node[2], $resolver), $this->evaluateNode($node[3], $resolver));
            case 'and':
                return $this->truthy($this->evaluateNode($node[1], $resolver)) && $this->truthy($this->evaluateNode($node[2], $resolver));
            case 'or':
                return $this->truthy($this->evaluateNode($node[1], $resolver)) || $this->truthy($this->evaluateNode($node[2], $resolver));
            case 'not':
                return !$this->truthy($this->evaluateNode($node[1], $resolver));
            case 'list':
                return array_map(fn (array $item) => $this->evaluateNode($item, $resolver), $node[1]);
            case 'call':
                if (isset($this->specialForms[$node[1]])) {
                    $thunks = array_map(fn (array $arg) => fn () => $this->evaluateNode($arg, $resolver), $node[2]);
                    return ($this->specialForms[$node[1]])($thunks);
                }
                $args = array_map(fn (array $arg) => $this->evaluateNode($arg, $resolver), $node[2]);
                try {
                    return ($this->functions[$node[1]])(...$args);
                } catch (\ArgumentCountError) {
                    throw new NonRecoverableException("Wrong number of arguments for function {$node[1]}()");
                }
        }
        throw new NonRecoverableException('Unknown expression node');
    }

    /**
     * Arithmetic is exact (Decimal, bcmath); the result is converted back to int / float when lossless.
     */
    private function binary(string $op, mixed $left, mixed $right): mixed
    {
        if ($op === '+' && !(Decimal::isNumeric($left) && Decimal::isNumeric($right))) {
            return self::toString($left) . self::toString($right);
        }
        $a = $this->toDecimal($left, $op);
        $b = $this->toDecimal($right, $op);
        return Decimal::toNative(match ($op) {
            '+' => Decimal::add($a, $b),
            '-' => Decimal::sub($a, $b),
            '*' => Decimal::mul($a, $b),
            '/' => Decimal::div($a, $b),
            '%' => Decimal::mod($a, $b),
            default => throw new NonRecoverableException("Unknown operator {$op}"),
        });
    }

    /**
     * Operand of an arithmetic operator: '' and null are 0, anything not numeric is an error.
     */
    private function toDecimal(mixed $value, string $op): string
    {
        if ($value === '' || $value === null) {
            return '0';
        }
        if (!Decimal::isNumeric($value)) {
            throw new NonRecoverableException(sprintf('Operator "%s" requires numbers, got "%s"', $op, self::toString($value)));
        }
        return Decimal::of($value);
    }

    public static function toString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => is_finite($value) ? Decimal::of($value) : (string)$value,
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * A float result (custom functions) is normalized like arithmetic: 90.0 → 90, 0.30000000000000004 → 0.3.
     */
    private static function normalizeNumber(mixed $value): mixed
    {
        if (!is_float($value)) {
            return $value;
        }
        if (is_nan($value) || is_infinite($value)) {
            throw new NonRecoverableException('Expression result is not a finite number');
        }
        return Decimal::toNative(Decimal::round(Decimal::of($value), Decimal::SCALE));
    }

    /**
     * Every called function exists and gets an allowed number of arguments.
     *
     * @param array<int, mixed> $node
     */
    private function assertCalls(array $node): void
    {
        if ($node[0] === 'call') {
            $spec = $this->specs[$node[1]] ?? throw new ConfigurationException("Unknown function \"{$node[1]}\"");
            $count = count($node[2]);
            if ($count < $spec->minArgs || ($spec->maxArgs !== null && $count > $spec->maxArgs)) {
                $range = $spec->maxArgs === null ? "at least {$spec->minArgs}" : ($spec->minArgs === $spec->maxArgs ? (string)$spec->minArgs : "{$spec->minArgs}..{$spec->maxArgs}");
                throw new ConfigurationException("Function \"{$node[1]}\" expects {$range} argument" . ($range === '1' ? '' : 's') . ", got {$count}");
            }
            if ($spec->validator !== null && ($error = ($spec->validator)($node[2])) !== null) {
                throw new ConfigurationException($error);
            }
        }
        foreach (self::children($node) as $child) {
            $this->assertCalls($child);
        }
    }

    private function assertName(string $name): string
    {
        $name = strtolower($name);
        if (in_array($name, ExpressionLexer::KEYWORDS, true)) {
            throw new \InvalidArgumentException("\"{$name}\" is a reserved word and cannot be used as a function name");
        }
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid function name \"{$name}\"");
        }
        return $name;
    }

    private static function specFromCallable(string $name, callable $fn): FunctionSpec
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($fn));
        return new FunctionSpec(
            $name,
            $reflection->getNumberOfRequiredParameters(),
            $reflection->isVariadic() ? null : $reflection->getNumberOfParameters(),
            'custom',
            "{$name}(…)",
            '',
        );
    }

    private function registerDefaultFunctions(): void
    {
        $str = static fn (mixed $v): string => self::toString($v);
        $spec = static fn (string $name, int $min, ?int $max, string $category, string $signature, string $description, string $example, string $returns): FunctionSpec
            => new FunctionSpec($name, $min, $max, $category, $signature, $description, $example, $returns);

        $this->registerFunction('concat', static fn (mixed ...$a) => implode('', array_map($str, $a)),
            $spec('concat', 1, null, 'string', 'concat(a, b, …)', 'Склеивает значения в одну строку', "concat(v1.first_name, ' ', v1.last_name)", 'string'));
        $this->registerFunction('upper', static fn (mixed $s) => mb_strtoupper($str($s)),
            $spec('upper', 1, 1, 'string', 'upper(s)', 'Строка в верхнем регистре', 'upper(object.code)', 'string'));
        $this->registerFunction('lower', static fn (mixed $s) => mb_strtolower($str($s)),
            $spec('lower', 1, 1, 'string', 'lower(s)', 'Строка в нижнем регистре', 'lower(object.email)', 'string'));
        $this->registerFunction('trim', static fn (mixed $s) => trim($str($s)),
            $spec('trim', 1, 1, 'string', 'trim(s)', 'Убирает пробелы по краям', 'trim(object.name)', 'string'));
        $this->registerFunction('len', static fn (mixed $s) => mb_strlen($str($s)),
            $spec('len', 1, 1, 'string', 'len(s)', 'Длина строки в символах', 'len(object.phone)', 'number'));
        $this->registerFunction('substr', static fn (mixed $s, mixed $start, mixed $length = null) => mb_substr(
            $str($s),
            (int)$start,
            $length === null ? null : (int)$length,
        ), $spec('substr', 2, 3, 'string', 'substr(s, start[, length])', 'Часть строки с позиции start (с нуля)', "substr(object.name, 0, 50)", 'string'));
    }
}
