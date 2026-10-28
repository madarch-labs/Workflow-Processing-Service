<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;
use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * Numeric functions over exact decimals (md/tasks_list/done/5.2.3-expr-numbers.md §4.5). An empty argument is 0,
 * a non-numeric one is an error; results are int / float when lossless, a numeric string otherwise.
 */
final class NumberFunctions
{
    public static function register(ExpressionEngine $engine): void
    {
        $spec = static fn (string $name, int $min, ?int $max, string $signature, string $description, string $example, string $returns = 'number', ?\Closure $validator = null): FunctionSpec
            => new FunctionSpec($name, $min, $max, 'number', $signature, $description, $example, $returns, true, $validator);
        $out = static fn (string $d): int|float|string => Decimal::toNative($d);

        $rounding = [
            'round' => ['Округление до digits знаков, половина — от нуля (2.5 → 3); digits может быть отрицательным (1250, -2 → 1300)', 'round(object.amount * 1.2, 2)', static fn (string $x, int $p) => Decimal::round($x, $p)],
            'floor' => ['Округление вниз (к −∞) до digits знаков', 'floor(object.amount / object.qty, 2)', static fn (string $x, int $p) => Decimal::floor($x, $p)],
            'ceil' => ['Округление вверх (к +∞) до digits знаков', 'ceil(object.hours)', static fn (string $x, int $p) => Decimal::ceil($x, $p)],
            'trunc' => ['Отбрасывает дробную часть (к нулю) после digits знаков', 'trunc(object.amount)', static fn (string $x, int $p) => Decimal::trunc($x, $p)],
        ];
        foreach ($rounding as $name => [$description, $example, $operation]) {
            $engine->registerFunction($name, static function (mixed $x, mixed $precision = 0) use ($name, $operation, $out) {
                return $out($operation(self::number($x, $name), self::precision($precision, $name)));
            }, $spec($name, 1, 2, "{$name}(x[, digits])", $description, $example, validator: self::integerLiteral($name, 1, 'precision')));
        }

        $engine->registerFunction('abs', static fn (mixed $x) => $out(Decimal::abs(self::number($x, 'abs'))),
            $spec('abs', 1, 1, 'abs(x)', 'Модуль числа', 'abs(object.balance)'));
        $engine->registerFunction('sign', static fn (mixed $x) => Decimal::sign(self::number($x, 'sign')),
            $spec('sign', 1, 1, 'sign(x)', 'Знак числа: -1, 0 или 1', 'sign(object.balance)'));
        foreach (['min' => [-1, 'Наименьшее из чисел (или элементов списка)', 'min(object.discount, 15)'], 'max' => [1, 'Наибольшее из чисел (или элементов списка)', 'max(object.amount, 0)']] as $name => [$direction, $description, $example]) {
            $engine->registerFunction($name, static function (mixed ...$values) use ($name, $direction, $out) {
                if (count($values) === 1 && is_array($values[0])) {
                    $values = array_values($values[0]);
                    if ($values === []) {
                        return '';
                    }
                }
                $best = null;
                foreach ($values as $value) {
                    $d = self::number($value, $name);
                    if ($best === null || Decimal::cmp($d, $best) === $direction) {
                        $best = $d;
                    }
                }
                return $out((string)$best);
            }, $spec($name, 1, null, "{$name}(a, b, …)", $description, $example));
        }
        $engine->registerFunction('pow', static fn (mixed $x, mixed $n) => $out(Decimal::pow(self::number($x, 'pow'), self::number($n, 'pow'))),
            $spec('pow', 2, 2, 'pow(x, n)', 'x в степени n (целая степень до 100 — точно)', 'pow(1 + object.rate / 100, object.years)'));
        $engine->registerFunction('sqrt', static fn (mixed $x) => $out(Decimal::sqrt(self::number($x, 'sqrt'))),
            $spec('sqrt', 1, 1, 'sqrt(x)', 'Квадратный корень (10 знаков)', 'sqrt(object.area)'));
        $engine->registerFunction('percent', static fn (mixed $x, mixed $p) => $out(Decimal::div(Decimal::mul(self::number($x, 'percent'), self::number($p, 'percent')), '100')),
            $spec('percent', 2, 2, 'percent(x, p)', 'p процентов от x', 'percent(object.amount, 15)'));

        $engine->registerFunction('to_number', static function (mixed $value) use ($engine, $out) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return '';
            }
            $cast = $engine->caster()->cast($value, new CastOptions(type: CastType::Decimal, scale: Decimal::SCALE));
            if (!$cast->isOk()) {
                throw new NonRecoverableException(sprintf('Cannot convert "%s" to a number', ExpressionEngine::toString($value)));
            }
            return $out((string)$cast->value);
        }, $spec('to_number', 1, 1, 'to_number(s)', "Число из текста: пробелы убираются, запятая — десятичный разделитель ('1 234,56' → 1234.56); пустое остаётся пустым", 'to_number(v1.price_text)'));
        $engine->registerFunction('to_int', static function (mixed $value) use ($engine) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return '';
            }
            $cast = $engine->caster()->cast($value, new CastOptions(type: CastType::Int));
            if (!$cast->isOk()) {
                throw new NonRecoverableException(sprintf('Cannot convert "%s" to an integer', ExpressionEngine::toString($value)));
            }
            return $cast->value;
        }, $spec('to_int', 1, 1, 'to_int(s)', 'Целое из текста; дробная часть — ошибка', "to_int(' 42 ')"));

        $engine->registerFunction('number_format', static function (mixed $x, mixed $decimals = 0, mixed $decimalSeparator = '.', mixed $thousandsSeparator = ' '): string {
            $places = self::precision($decimals, 'number_format');
            if ($places < 0) {
                throw new NonRecoverableException('Function "number_format": decimals must not be negative');
            }
            $rounded = Decimal::round(self::number($x, 'number_format'), $places);
            $negative = str_starts_with($rounded, '-');
            [$int, $frac] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, '');
            $text = self::group($int, ExpressionEngine::toString($thousandsSeparator));
            if ($places > 0) {
                $text .= ExpressionEngine::toString($decimalSeparator) . str_pad($frac, $places, '0');
            }
            return ($negative ? '-' : '') . $text;
        }, $spec('number_format', 1, 4, 'number_format(x[, decimals[, dec_sep[, thousands_sep]]])', "Число как текст с разделителями: 1234567.891, 2, ',', ' ' → '1 234 567,89'",
            "number_format(object.amount, 2, ',', ' ')", 'string', self::integerLiteral('number_format', 1, 'decimals')));
    }

    /**
     * Numeric argument as a decimal string: '' / null are 0.
     */
    private static function number(mixed $value, string $fn): string
    {
        if ($value === '' || $value === null) {
            return '0';
        }
        if (!Decimal::isNumeric($value)) {
            throw new NonRecoverableException(sprintf('Function "%s" requires a number, got "%s"', $fn, mb_substr(ExpressionEngine::toString($value), 0, 100)));
        }
        return Decimal::of($value);
    }

    private static function precision(mixed $value, string $fn): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (!Decimal::isNumeric($value) || str_contains(Decimal::of($value), '.') || abs((int)Decimal::of($value)) > Decimal::MAX_DIGITS) {
            throw new NonRecoverableException(sprintf('Function "%s": precision must be an integer, got "%s"', $fn, ExpressionEngine::toString($value)));
        }
        return (int)Decimal::of($value);
    }

    private static function group(string $int, string $separator): string
    {
        $head = strlen($int) % 3;
        $parts = $head > 0 ? [substr($int, 0, $head)] : [];
        foreach (str_split(substr($int, $head), 3) as $chunk) {
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }
        return implode($separator, $parts);
    }

    private static function integerLiteral(string $fn, int $position, string $what): \Closure
    {
        return static function (array $args) use ($fn, $position, $what): ?string {
            $literal = FunctionSpec::literal($args[$position] ?? null);
            return $literal !== null && !preg_match('/^\d+$/', $literal) ? "Function \"{$fn}\": {$what} must be an integer" : null;
        };
    }
}
