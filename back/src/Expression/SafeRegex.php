<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\NonRecoverableException;

/**
 * User regular expressions with hard limits (md/tasks_list/done/5.2.4-expr-strings.md §4.1).
 *
 * A pattern is written without delimiters; the engine wraps it in "~…~u" and escapes "~" inside. Flags are limited to
 * i / m / s. The pattern is at most 500 characters and the subject at most 100 000 characters. Backtracking and
 * recursion limits are lowered around a single preg_* call and restored afterwards (a Swoole worker is shared).
 * Compilation errors are reported at save time with the PCRE message, run-time PCRE errors as a step error.
 */
final class SafeRegex
{
    public const MAX_PATTERN_LENGTH = 500;
    public const MAX_SUBJECT_LENGTH = 100000;
    public const FLAGS = 'ims';

    /**
     * Validates literal arguments when a process is saved.
     *
     * @return string|null an error message or null when the pattern and flags are valid
     */
    public static function validate(string $pattern, string $flags, string $fn): ?string
    {
        if (($error = self::flagsError($flags, $fn)) !== null) {
            return $error;
        }
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return sprintf('Regular expression in %s() is too long (max %d characters)', $fn, self::MAX_PATTERN_LENGTH);
        }
        $regex = self::compile($pattern, $flags);
        if (($error = self::compileError($regex)) !== null) {
            return sprintf('Invalid regular expression in %s(): %s', $fn, $error);
        }
        return null;
    }

    /**
     * @return string|null an error message or null when the flags are valid
     */
    public static function flagsError(string $flags, string $fn): ?string
    {
        foreach (str_split($flags) as $flag) {
            if (!str_contains(self::FLAGS, $flag)) {
                return sprintf('Function "%s": flags may contain only i, m, s, got "%s"', $fn, $flags);
            }
        }
        return null;
    }

    public static function match(string $subject, string $pattern, string $flags, string $fn = 'regex_match'): bool
    {
        $regex = self::prepare($subject, $pattern, $flags, $fn);
        $result = self::run(static fn () => preg_match($regex, $subject), $fn);
        return $result === 1;
    }

    public static function extract(string $subject, string $pattern, string $flags, int $group = 0, string $fn = 'regex_extract'): string
    {
        $regex = self::prepare($subject, $pattern, $flags, $fn);
        $matches = [];
        self::run(static function () use ($regex, $subject, &$matches): int|false {
            return preg_match($regex, $subject, $matches);
        }, $fn);
        return isset($matches[$group]) && is_string($matches[$group]) ? $matches[$group] : '';
    }

    public static function replace(string $subject, string $pattern, string $replacement, string $flags, string $fn = 'regex_replace'): string
    {
        $regex = self::prepare($subject, $pattern, $flags, $fn);
        $result = self::run(static fn () => preg_replace($regex, $replacement, $subject), $fn);
        return is_string($result) ? $result : '';
    }

    private static function prepare(string $subject, string $pattern, string $flags, string $fn): string
    {
        if (($error = self::flagsError($flags, $fn)) !== null) {
            throw new NonRecoverableException($error);
        }
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new NonRecoverableException(sprintf('Regular expression in %s() is too long (max %d characters)', $fn, self::MAX_PATTERN_LENGTH));
        }
        if (strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new NonRecoverableException(sprintf('Regular expression input in %s() is too long (max %d characters)', $fn, self::MAX_SUBJECT_LENGTH));
        }
        $regex = self::compile($pattern, $flags);
        if (($error = self::compileError($regex)) !== null) {
            throw new NonRecoverableException(sprintf('Invalid regular expression in %s(): %s', $fn, $error));
        }
        return $regex;
    }

    /**
     * @param callable(): (int|string|false|null) $call
     */
    private static function run(callable $call, string $fn): int|string|false|null
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', '100000');
        ini_set('pcre.recursion_limit', '10000');
        try {
            $result = $call();
        } finally {
            ini_set('pcre.backtrack_limit', $backtrack === false ? '1000000' : $backtrack);
            ini_set('pcre.recursion_limit', $recursion === false ? '100000' : $recursion);
        }
        if (preg_last_error() !== PREG_NO_ERROR) {
            throw new NonRecoverableException('Regular expression failed: ' . self::runtimeError($fn));
        }
        return $result;
    }

    private static function compile(string $pattern, string $flags): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~u' . $flags;
    }

    /**
     * Compiles the wrapped pattern; PCRE reports syntax errors as a warning, not as preg_last_error().
     */
    private static function compileError(string $regex): ?string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $result = preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
        if ($result !== false) {
            return null;
        }
        if ($warning === null) {
            return preg_last_error_msg();
        }
        $message = preg_replace('/^preg_match\(\): (Compilation failed: )?/', '', $warning) ?? $warning;
        return preg_replace('/ at offset \d+$/', '', $message) ?? $message;
    }

    private static function runtimeError(string $fn): string
    {
        return match (preg_last_error()) {
            PREG_BACKTRACK_LIMIT_ERROR => 'backtrack limit exceeded',
            PREG_RECURSION_LIMIT_ERROR => 'recursion limit exceeded',
            PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exceeded',
            default => preg_last_error_msg() . " in {$fn}()",
        };
    }
}
