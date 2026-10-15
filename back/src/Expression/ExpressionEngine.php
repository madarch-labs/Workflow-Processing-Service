<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;

/**
 * Safe expression language for the "calculate" change type (no eval()).
 *
 *   expr    := term (('+' | '-') term)*
 *   term    := unary (('*' | '/' | '%') unary)*
 *   unary   := '-' unary | primary
 *   primary := NUMBER | STRING | PATH | IDENT '(' [expr (',' expr)*] ')' | '(' expr ')'
 *
 * PATH is "object.amount" / "v1.price". '+' adds numbers and concatenates otherwise.
 * New functions are registered with registerFunction() (extension point).
 */
final class ExpressionEngine
{
    /** @var array<string, callable(mixed...): mixed> */
    private array $functions = [];

    /** @var array<string, array<int, mixed>> parsed AST cache (definitions are reused for many events) */
    private array $cache = [];

    public function __construct()
    {
        $this->registerDefaultFunctions();
    }

    /**
     * @param callable(mixed...): mixed $fn
     */
    public function registerFunction(string $name, callable $fn): void
    {
        $this->functions[strtolower($name)] = $fn;
    }

    /**
     * @return string[]
     */
    public function functionNames(): array
    {
        return array_keys($this->functions);
    }

    /**
     * Parses the expression and returns the AST; throws ConfigurationException on syntax errors.
     *
     * @return array<int, mixed>
     */
    public function parse(string $expression): array
    {
        if (isset($this->cache[$expression])) {
            return $this->cache[$expression];
        }
        $parser = new ExpressionParser(ExpressionLexer::tokenize($expression), $expression);
        $ast = $parser->parse();
        $this->assertFunctionsExist($ast);
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
     * AST nodes: ['num', v] | ['str', v] | ['path', p] | ['neg', node] | ['bin', op, l, r] | ['call', name, [args]]
     *
     * @param array<int, mixed> $node
     * @param string[] $paths
     */
    private function collectPaths(array $node, array &$paths): void
    {
        switch ($node[0]) {
            case 'path':
                $paths[] = $node[1];
                break;
            case 'neg':
                $this->collectPaths($node[1], $paths);
                break;
            case 'bin':
                $this->collectPaths($node[2], $paths);
                $this->collectPaths($node[3], $paths);
                break;
            case 'call':
                foreach ($node[2] as $arg) {
                    $this->collectPaths($arg, $paths);
                }
                break;
        }
    }

    /**
     * @param callable(string): mixed $resolver returns the value for a path (null when missing)
     */
    public function evaluate(string $expression, callable $resolver): mixed
    {
        return self::normalizeNumber($this->evaluateNode($this->parse($expression), $resolver));
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
                return $node[1];
            case 'path':
                $value = $resolver($node[1]);
                return $value ?? '';
            case 'neg':
                return -$this->toNumber($this->evaluateNode($node[1], $resolver), '-');
            case 'bin':
                $left = $this->evaluateNode($node[2], $resolver);
                $right = $this->evaluateNode($node[3], $resolver);
                return $this->binary($node[1], $left, $right);
            case 'call':
                $args = array_map(fn (array $arg) => $this->evaluateNode($arg, $resolver), $node[2]);
                try {
                    return ($this->functions[$node[1]])(...$args);
                } catch (\ArgumentCountError) {
                    throw new NonRecoverableException("Wrong number of arguments for function {$node[1]}()");
                }
        }
        throw new NonRecoverableException('Unknown expression node');
    }

    private function binary(string $op, mixed $left, mixed $right): mixed
    {
        if ($op === '+') {
            if (self::isNumeric($left) && self::isNumeric($right)) {
                return $left + $right;
            }
            return self::toString($left) . self::toString($right);
        }
        $a = $this->toNumber($left, $op);
        $b = $this->toNumber($right, $op);
        switch ($op) {
            case '-':
                return $a - $b;
            case '*':
                return $a * $b;
            case '/':
                if ((float)$b === 0.0) {
                    throw new NonRecoverableException('Division by zero in expression');
                }
                return $a / $b;
            case '%':
                if ((float)$b === 0.0) {
                    throw new NonRecoverableException('Modulo by zero in expression');
                }
                return fmod((float)$a, (float)$b);
        }
        throw new NonRecoverableException("Unknown operator {$op}");
    }

    private function toNumber(mixed $value, string $op): int|float
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (!self::isNumeric($value)) {
            throw new NonRecoverableException(sprintf('Operator "%s" requires numbers, got "%s"', $op, self::toString($value)));
        }
        return $value + 0;
    }

    private static function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }

    public static function toString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => (string)self::normalizeNumber($value),
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * 90.0 → 90, 0.1 + 0.2 → 0.3 (rounded to 10 decimals).
     */
    private static function normalizeNumber(mixed $value): mixed
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (!is_float($value)) {
            return $value;
        }
        if (is_nan($value) || is_infinite($value)) {
            throw new NonRecoverableException('Expression result is not a finite number');
        }
        $rounded = round($value, 10);
        if (floor($rounded) === $rounded && abs($rounded) < 1e15) {
            return (int)$rounded;
        }
        return $rounded;
    }

    /**
     * @param array<int, mixed> $node
     */
    private function assertFunctionsExist(array $node): void
    {
        if ($node[0] === 'call') {
            if (!isset($this->functions[$node[1]])) {
                throw new ConfigurationException("Unknown function \"{$node[1]}\"");
            }
            foreach ($node[2] as $arg) {
                $this->assertFunctionsExist($arg);
            }
        } elseif ($node[0] === 'bin') {
            $this->assertFunctionsExist($node[2]);
            $this->assertFunctionsExist($node[3]);
        } elseif ($node[0] === 'neg') {
            $this->assertFunctionsExist($node[1]);
        }
    }

    private function registerDefaultFunctions(): void
    {
        $str = static fn (mixed $v): string => self::toString($v);
        $num = function (mixed $v, string $fn): int|float {
            return $this->toNumber($v, $fn . '()');
        };

        $this->registerFunction('concat', static fn (mixed ...$a) => implode('', array_map($str, $a)));
        $this->registerFunction('upper', static fn (mixed $s) => mb_strtoupper($str($s)));
        $this->registerFunction('lower', static fn (mixed $s) => mb_strtolower($str($s)));
        $this->registerFunction('trim', static fn (mixed $s) => trim($str($s)));
        $this->registerFunction('len', static fn (mixed $s) => mb_strlen($str($s)));
        $this->registerFunction('substr', static fn (mixed $s, mixed $start, mixed $length = null) => mb_substr(
            $str($s),
            (int)$start,
            $length === null ? null : (int)$length,
        ));
        $this->registerFunction('round', static fn (mixed $x, mixed $precision = 0) => round((float)$num($x, 'round'), (int)$precision));
        $this->registerFunction('abs', static fn (mixed $x) => abs($num($x, 'abs')));
        $this->registerFunction('min', static fn (mixed $first, mixed ...$rest) => min(array_map(
            static fn ($v) => $num($v, 'min'),
            [$first, ...$rest],
        )));
        $this->registerFunction('max', static fn (mixed $first, mixed ...$rest) => max(array_map(
            static fn ($v) => $num($v, 'max'),
            [$first, ...$rest],
        )));
    }
}
