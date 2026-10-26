<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\Validation\DefinitionScope;
use Wps\Time\Clock;
use Wps\Time\DateMath;
use Wps\Time\DateUnit;
use Wps\Time\ParsedDate;
use Wps\Types\CastOptions;

/**
 * "Сдвиг даты": base (now / today / a field) + calendar shifts, optional time of day, e.g.
 * {"base": "now", "shift": "+2 day", "set_time": "18:00"}. Days, months and set_time work in WPS_TIMEZONE,
 * minutes and hours are absolute; months clip to the end of the month. Result: datetime "Y-m-d H:i:s" UTC or
 * date "Y-m-d". Only calendar units — working days are out of scope (md/tasks_list/done/5.1.2-date-shift.md).
 */
final class DateShiftChangeType implements ChangeTypeInterface, ReportsDetails
{
    public const MAX_AMOUNT = 100000;
    private const SHORT_FORM = '/^\s*(?:[+-]?\s*\d+\s*[a-z]+\s*)+$/i';
    private const SHORT_PART = '/([+-]?)\s*(\d+)\s*([a-z]+)/i';
    private const ON_MISSING = ['null', 'error', 'now'];

    public function __construct(
        private readonly DateMath $math,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return 'date_shift';
    }

    public function label(): string
    {
        return 'Сдвиг даты';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return $this->computeWithDetails($context, $config)[0];
    }

    public function computeWithDetails(ExecutionContext $context, array $config): array
    {
        $details = ['missing_base' => false];
        $date = $this->base($context, $config);
        if ($date === null) {
            $details['missing_base'] = true;
            $policy = (string)($config['on_missing'] ?? 'null');
            if ($policy === 'error') {
                throw new NonRecoverableException(sprintf('date_shift: base "%s" has no value', (string)($config['base'] ?? '')));
            }
            if ($policy !== 'now') {
                return [null, $details];
            }
            $date = new ParsedDate($this->clock->now(), false);
        }
        $details['base'] = $this->math->toCanonical($date);
        $applied = [];
        foreach (self::shifts($config['shift'] ?? []) as [$amount, $unitName]) {
            $unit = DateUnit::parse($unitName) ?? throw new NonRecoverableException("date_shift: unknown unit \"{$unitName}\"");
            $value = self::amount($amount, $context);
            $date = $unit->apply($this->math, $date, $value);
            $applied[] = ['amount' => $value, 'unit' => $unit->value];
        }
        $details['shifts'] = $applied;
        if (isset($config['set_time']) && is_string($config['set_time']) && preg_match('/^(\d{2}):(\d{2})$/', $config['set_time'], $m)) {
            $date = $this->math->setTime($date, (int)$m[1], (int)$m[2]);
        }
        $date = match ($config['result'] ?? null) {
            'date' => $this->math->toDate($date),
            'datetime' => $this->math->toDateTime($date),
            default => $date,
        };
        return [$this->math->toCanonical($date), $details];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $base = $config['base'] ?? 'now';
        if (!is_string($base) || trim($base) === '') {
            $scope->error("{$path}.base", 'Base must be "now", "today" or a field path');
        } elseif (!in_array($base, ['now', 'today'], true)) {
            $scope->checkReadPath($base, "{$path}.base");
        }
        if (isset($config['base_cast'])) {
            $cast = $config['base_cast'];
            $options = is_string($cast) ? ['type' => $cast] : $cast;
            if (!is_array($options) || !in_array($options['type'] ?? null, ['date', 'datetime'], true)) {
                $scope->error("{$path}.base_cast", 'Base cast must be "date" or "datetime"');
            } else {
                $rest = array_diff_key($options, ['type' => true]);
                foreach (CastOptions::errors($options['type'], $rest === [] ? null : $rest) as $error) {
                    $scope->error("{$path}.base_cast" . ($error['field'] === '' ? '' : '.' . substr($error['field'], strlen('cast_options.'))), $error['message']);
                }
            }
        }
        $this->validateShift($config['shift'] ?? [], $scope, "{$path}.shift");
        if (isset($config['set_time']) && (!is_string($config['set_time']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $config['set_time']))) {
            $scope->error("{$path}.set_time", 'Time must be HH:MM');
        }
        if (isset($config['result']) && !in_array($config['result'], ['date', 'datetime'], true)) {
            $scope->error("{$path}.result", 'Result must be "date" or "datetime"');
        }
        if (isset($config['on_missing']) && !in_array($config['on_missing'], self::ON_MISSING, true)) {
            $scope->error("{$path}.on_missing", 'Must be one of: ' . implode(', ', self::ON_MISSING));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function base(ExecutionContext $context, array $config): ?ParsedDate
    {
        $base = (string)($config['base'] ?? 'now');
        if ($base === 'now') {
            return new ParsedDate($this->clock->now(), false);
        }
        if ($base === 'today') {
            return $this->math->toDate(new ParsedDate($this->clock->now(), false));
        }
        [$found, $value] = $context->resolve($base);
        if (!$found) {
            return null;
        }
        $cast = $config['base_cast'] ?? null;
        $options = is_string($cast) ? ['type' => $cast] : (is_array($cast) ? $cast : []);
        $formats = (array)($options['format'] ?? $options['formats'] ?? []);
        $zone = isset($options['timezone']) ? $this->math->zone((string)$options['timezone'], 'date_shift') : null;
        $parsed = null;
        $errors = [];
        foreach ($formats !== [] ? $formats : [null] as $format) {
            try {
                $parsed = $this->math->parse($value, 'date_shift', $zone, $format === null ? null : (string)$format);
                $errors = [];
                break;
            } catch (NonRecoverableException $e) {
                $errors[] = $e;
            }
        }
        if ($errors !== []) {
            throw new NonRecoverableException(sprintf('date_shift: cannot parse "%s" as %s', is_scalar($value) ? (string)$value : get_debug_type($value), ($options['type'] ?? null) === 'date' ? 'date' : 'datetime'));
        }
        if ($parsed === null) {
            return null;
        }
        return match ($options['type'] ?? null) {
            'date' => $this->math->toDate($parsed),
            'datetime' => $this->math->toDateTime($parsed),
            default => $parsed,
        };
    }

    /**
     * @return list<array{0: mixed, 1: string}> [amount, unit]
     */
    private static function shifts(mixed $shift): array
    {
        if (is_string($shift)) {
            preg_match_all(self::SHORT_PART, $shift, $matches, PREG_SET_ORDER);
            return array_map(static fn (array $m) => [($m[1] === '-' ? -1 : 1) * (int)$m[2], $m[3]], $matches);
        }
        if (is_array($shift) && isset($shift['unit'])) {
            $shift = [$shift];
        }
        return array_map(static fn ($s) => [$s['amount'] ?? 0, (string)($s['unit'] ?? '')], is_array($shift) ? array_values($shift) : []);
    }

    private static function amount(mixed $amount, ExecutionContext $context): int
    {
        if (is_string($amount) && preg_match('/^[A-Za-z_]\w*\./', $amount)) {
            $amount = $context->get($amount);
        }
        if (is_int($amount)) {
            $value = $amount;
        } elseif (is_string($amount) && preg_match('/^\s*[+-]?\d{1,9}\s*$/', $amount)) {
            $value = (int)trim($amount);
        } elseif (is_float($amount) && floor($amount) === $amount) {
            $value = (int)$amount;
        } else {
            throw new NonRecoverableException(sprintf('date_shift: amount must be an integer, got "%s"', is_scalar($amount) ? (string)$amount : get_debug_type($amount)));
        }
        if (abs($value) > self::MAX_AMOUNT) {
            throw new NonRecoverableException("date_shift: amount {$value} is out of range");
        }
        return $value;
    }

    private function validateShift(mixed $shift, DefinitionScope $scope, string $field): void
    {
        if (is_string($shift)) {
            if (!preg_match(self::SHORT_FORM, $shift)) {
                $scope->error($field, 'Shift must be a list of {amount, unit} or a string like "+2 day"');
                return;
            }
            foreach (self::shifts($shift) as [$amount, $unit]) {
                if (DateUnit::parse($unit) === null) {
                    $scope->error($field, 'Unit must be one of: ' . implode(', ', DateUnit::values()));
                } elseif (abs($amount) > self::MAX_AMOUNT) {
                    $scope->error($field, 'Amount is out of range');
                }
            }
            return;
        }
        if (is_array($shift) && isset($shift['unit'])) {
            $shift = [$shift];
        }
        if (!is_array($shift) || !array_is_list($shift)) {
            $scope->error($field, 'Shift must be a list of {amount, unit} or a string like "+2 day"');
            return;
        }
        foreach ($shift as $k => $item) {
            if (!is_array($item)) {
                $scope->error("{$field}[{$k}]", 'Shift must be a list of {amount, unit} or a string like "+2 day"');
                continue;
            }
            if (!is_string($item['unit'] ?? null) || DateUnit::parse($item['unit']) === null) {
                $scope->error("{$field}[{$k}].unit", 'Unit must be one of: ' . implode(', ', DateUnit::values()));
            }
            $amount = $item['amount'] ?? null;
            if (is_string($amount) && preg_match('/^[A-Za-z_]\w*\./', $amount)) {
                $scope->checkReadPath($amount, "{$field}[{$k}].amount");
            } elseif (!is_int($amount) && !(is_string($amount) && preg_match('/^[+-]?\d+$/', $amount))) {
                $scope->error("{$field}[{$k}].amount", 'Amount must be an integer or a field path');
            } elseif (abs((int)$amount) > self::MAX_AMOUNT) {
                $scope->error("{$field}[{$k}].amount", 'Amount is out of range');
            }
        }
    }
}
