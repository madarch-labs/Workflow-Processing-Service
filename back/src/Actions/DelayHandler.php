<?php

declare(strict_types=1);

namespace Wps\Actions;

use Swoole\Coroutine;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\Validation\DefinitionScope;
use Wps\Time\Clock;
use Wps\Time\DateMath;
use Wps\Time\Duration;
use Wps\Time\ParsedDate;

/**
 * "Пауза" (md/tasks_list/done/4.7-delay.md): waits for a duration ("P3D", "2h") or until a date (a field, a literal or an
 * expression, with an offset and a time of day). Short pauses (≤ WAIT_INLINE_MAX_SECONDS) sleep in the coroutine,
 * longer ones park the execution (7.2) — the worker is free, the execution survives restarts. Only calendar time:
 * working days and hours are out of scope.
 */
final class DelayHandler implements ActionHandlerInterface, ResumableActionInterface
{
    public const MODES = ['duration', 'until'];

    public function __construct(
        private readonly ExpressionEngine $expressions,
        private readonly DateMath $math,
        private readonly Clock $clock,
        private readonly AppConfig $config,
    ) {
    }

    public function type(): string
    {
        return 'delay';
    }

    public function label(): string
    {
        return 'Пауза';
    }

    public function description(): string
    {
        return 'Приостанавливает выполнение на срок или до даты; длинная пауза не занимает воркер и переживает перезапуск';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $now = $this->clock->now();
        $request = ['mode' => (string)($actionConfig['mode'] ?? '')] + array_intersect_key($actionConfig, array_flip(
            ['duration', 'duration_expression', 'until', 'until_expression', 'offset', 'time', 'timezone', 'cast']));
        try {
            $dueAt = $this->dueAt($context, $actionConfig, $now);
        } catch (NonRecoverableException $e) {
            if (($actionConfig['on_invalid'] ?? 'fail') === 'continue') {
                return ActionResult::skipped('Delay skipped: ' . $e->getMessage(), $request);
            }
            throw $e;
        }
        $request['due_at'] = $dueAt->format(\DATE_ATOM);
        $request['due_at_local'] = $dueAt->setTimezone($this->math->local())->format(\DATE_ATOM);
        $seconds = (float)$dueAt->format('U.u') - (float)$now->format('U.u');
        $maxSeconds = $this->maxWaitSeconds($actionConfig, $now);
        if ($seconds > $maxSeconds) {
            throw new NonRecoverableException(sprintf('delay: wait of %d days exceeds the limit %s', (int)ceil($seconds / 86400), (string)($actionConfig['max_wait'] ?? $this->config->delayMax)));
        }
        if ($seconds <= 0) {
            if (($actionConfig['if_past'] ?? 'continue') === 'fail') {
                throw new NonRecoverableException('delay: the moment ' . $request['due_at'] . ' has already passed');
            }
            return ActionResult::success($request, ['waited' => false, 'past_due' => true]);
        }
        if ($seconds <= $this->config->waitInlineMaxSeconds) {
            // a short pause stays in the coroutine: cheaper than parking; repeated harmlessly after a crash
            Coroutine::getCid() > 0 ? Coroutine::sleep($seconds) : usleep((int)($seconds * 1000000));
            return ActionResult::success($request, ['waited' => true, 'waited_seconds' => round($seconds, 3), 'inline' => true]);
        }
        return ActionResult::wait($dueAt, 'delay', $request, [], ['mode' => $request['mode'], 'due_at' => $request['due_at']]);
    }

    public function resume(ExecutionContext $context, array $actionConfig, array $waitInfo): ActionResult
    {
        $due = strtotime((string)($waitInfo['due_at'] ?? 'now'));
        $lateness = (int)round((microtime(true) - (float)$due) * 1000);
        return ActionResult::success(['mode' => (string)($actionConfig['mode'] ?? ''), 'due_at' => (string)($waitInfo['due_at'] ?? '')],
            ['waited' => true, 'lateness_ms' => max(0, $lateness)]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dueAt(ExecutionContext $context, array $config, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $resolver = static fn (string $path) => $context->get($path);
        if (($config['mode'] ?? null) === 'duration') {
            $spec = isset($config['duration_expression'])
                ? ExpressionEngine::toString($this->expressions->evaluate((string)$config['duration_expression'], $resolver))
                : (string)($config['duration'] ?? '');
            return self::shift($now, $spec, $this->math) ?? throw new NonRecoverableException("delay: invalid duration \"{$spec}\"");
        }
        // until: a field (CRM values are UTC), a literal (WPS_TIMEZONE or "timezone") or an expression
        if (isset($config['until_expression'])) {
            $value = $this->expressions->evaluate((string)$config['until_expression'], $resolver);
            $source = (string)$config['until_expression'];
            $zone = null;
        } else {
            $source = (string)($config['until'] ?? '');
            $isPath = (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z0-9_.]+$/', $source) && !preg_match('/^\d/', $source);
            $value = $isPath ? $context->get($source) : $source;
            $zone = $isPath ? null : $this->math->zone(isset($config['timezone']) ? (string)$config['timezone'] : null, 'delay');
        }
        $parsed = $this->math->parse($value, 'delay', $zone);
        if ($parsed === null) {
            throw new NonRecoverableException(sprintf('delay: cannot resolve a date from %s (value "%s")', $source, is_scalar($value) ? (string)$value : ''));
        }
        if (($config['cast'] ?? null) === 'date') {
            $parsed = $this->math->toDate($parsed);
        }
        if (isset($config['time']) && preg_match('/^(\d{2}):(\d{2})$/', (string)$config['time'], $m)) {
            $parsed = $this->math->setTime($parsed, (int)$m[1], (int)$m[2]);
        }
        $moment = $this->math->toDateTime($parsed)->value;
        if (isset($config['offset']) && (string)$config['offset'] !== '') {
            $moment = self::shift($moment, (string)$config['offset'], $this->math)
                ?? throw new NonRecoverableException("delay: invalid offset \"{$config['offset']}\"");
        }
        return $moment;
    }

    /**
     * $base + a signed duration: "-P30D", "PT2H30M", "P1M" (calendar months with end-of-month clipping), "3d", "-2h".
     */
    public static function shift(\DateTimeImmutable $base, string $spec, DateMath $math): ?\DateTimeImmutable
    {
        $spec = trim($spec);
        $sign = 1;
        if (str_starts_with($spec, '-') || str_starts_with($spec, '+')) {
            $sign = $spec[0] === '-' ? -1 : 1;
            $spec = substr($spec, 1);
        }
        if ($spec === '') {
            return null;
        }
        if (strtoupper($spec[0]) === 'P') {
            try {
                $interval = new \DateInterval(strtoupper($spec));
            } catch (\Exception) {
                return null;
            }
            $months = $interval->y * 12 + $interval->m;
            $moment = $months !== 0 ? $math->addMonths(new ParsedDate($base, false), $sign * $months)->value : $base;
            $seconds = (($interval->d * 24 + $interval->h) * 60 + $interval->i) * 60 + $interval->s;
            if ($months === 0 && $seconds === 0) {
                return null;
            }
            return $moment->modify(sprintf('%+d seconds', $sign * $seconds));
        }
        $ms = Duration::toMs($spec);
        if ($ms === null || $ms <= 0 || ctype_digit($spec)) {
            return null; // a plain number is ambiguous for a delay
        }
        return $base->modify(sprintf('%+d milliseconds', $sign * $ms));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function maxWaitSeconds(array $config, \DateTimeImmutable $now): float
    {
        $limit = self::shift($now, (string)($config['max_wait'] ?? $this->config->delayMax), $this->math)
            ?? self::shift($now, 'P400D', $this->math);
        return (float)$limit->format('U.u') - (float)$now->format('U.u');
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $mode = $actionConfig['mode'] ?? null;
        if (!in_array($mode, self::MODES, true)) {
            $scope->error("{$path}.mode", 'Delay mode must be one of: duration, until');
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $max = (string)($actionConfig['max_wait'] ?? $this->config->delayMax);
        $globalMax = self::shift($now, $this->config->delayMax, $this->math) ?? self::shift($now, 'P400D', $this->math);
        $maxMoment = self::shift($now, $max, $this->math);
        if (isset($actionConfig['max_wait']) && ($maxMoment === null || $maxMoment > $globalMax)) {
            $scope->error("{$path}.max_wait", $maxMoment === null ? "Invalid duration \"{$max}\"" : "Must not exceed {$this->config->delayMax}");
        }
        if ($mode === 'duration') {
            $this->exactlyOne($actionConfig, 'duration', 'duration_expression', 'Duration is required', $scope, $path);
            if (isset($actionConfig['duration'])) {
                $moment = is_string($actionConfig['duration']) ? self::shift($now, $actionConfig['duration'], $this->math) : null;
                if ($moment === null || $moment <= $now) {
                    $scope->error("{$path}.duration", sprintf('Invalid duration "%s" (use ISO 8601 like P3D or 3d, 2h, 30m)', is_scalar($actionConfig['duration']) ? (string)$actionConfig['duration'] : ''));
                } elseif ($maxMoment !== null && $moment > $maxMoment) {
                    $scope->error("{$path}.duration", "Delay exceeds the limit {$max}");
                }
            }
            $this->checkExpression($actionConfig['duration_expression'] ?? null, $scope, "{$path}.duration_expression");
        } else {
            $this->exactlyOne($actionConfig, 'until', 'until_expression', 'Date is required', $scope, $path);
            $until = $actionConfig['until'] ?? null;
            if (is_string($until) && $until !== '') {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z0-9_.]+$/', $until)) {
                    $scope->checkReadPath($until, "{$path}.until");
                } else {
                    try {
                        $this->math->parse($until, 'delay', $this->math->zone(isset($actionConfig['timezone']) ? (string)$actionConfig['timezone'] : null, 'delay'));
                    } catch (NonRecoverableException) {
                        $scope->error("{$path}.until", "Invalid date \"{$until}\"");
                    }
                }
            } elseif ($until !== null) {
                $scope->error("{$path}.until", 'Date is required');
            }
            $this->checkExpression($actionConfig['until_expression'] ?? null, $scope, "{$path}.until_expression");
            if (isset($actionConfig['offset']) && (!is_string($actionConfig['offset']) || self::shift($now, $actionConfig['offset'], $this->math) === null)) {
                $scope->error("{$path}.offset", sprintf('Invalid offset "%s"', is_scalar($actionConfig['offset']) ? (string)$actionConfig['offset'] : ''));
            }
            if (isset($actionConfig['time']) && (!is_string($actionConfig['time']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $actionConfig['time']))) {
                $scope->error("{$path}.time", 'Time must be HH:MM');
            }
            if (isset($actionConfig['cast']) && !in_array($actionConfig['cast'], ['date', 'datetime'], true)) {
                $scope->error("{$path}.cast", 'Cast must be "date" or "datetime"');
            }
            if (isset($actionConfig['timezone']) && (!is_string($actionConfig['timezone']) || DateMath::zoneError($actionConfig['timezone']) !== null)) {
                $scope->error("{$path}.timezone", sprintf('Unknown timezone "%s"', is_scalar($actionConfig['timezone']) ? (string)$actionConfig['timezone'] : ''));
            }
        }
        foreach (['if_past' => ['continue', 'fail'], 'on_invalid' => ['fail', 'continue']] as $key => $allowed) {
            if (isset($actionConfig[$key]) && !in_array($actionConfig[$key], $allowed, true)) {
                $scope->error("{$path}.{$key}", sprintf('Must be "%s" or "%s"', ...$allowed));
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function exactlyOne(array $config, string $a, string $b, string $missing, DefinitionScope $scope, string $path): void
    {
        $hasA = array_key_exists($a, $config);
        $hasB = array_key_exists($b, $config);
        if (!$hasA && !$hasB) {
            $scope->error("{$path}.{$a}", $missing);
        } elseif ($hasA && $hasB) {
            $scope->error("{$path}.{$a}", "Use either {$a} or {$b}");
        }
    }

    private function checkExpression(mixed $expression, DefinitionScope $scope, string $field): void
    {
        if ($expression === null) {
            return;
        }
        if (!is_string($expression) || trim($expression) === '') {
            $scope->error($field, 'Expression is required');
            return;
        }
        try {
            foreach ($this->expressions->paths($expression) as $ref) {
                $scope->checkReadPath($ref, $field);
            }
        } catch (ConfigurationException $e) {
            $scope->error($field, $e->getMessage());
        }
    }
}
