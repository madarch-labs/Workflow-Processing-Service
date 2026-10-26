<?php

declare(strict_types=1);

namespace Wps\Services\Errors;

use Wps\Config\AppConfig;
use Wps\Time\Duration;

/**
 * Retries of a failed step (md/tasks_list/done/7.1-on-error.md §4.1–4.3): the step's "retry" profile or the
 * ACTION_RETRY_* defaults. Delay of attempt n: min(max_delay, base · backoff^(n-1)), then jitter; Retry-After raises it.
 */
final class RetryPolicy
{
    public const JITTERS = ['none', 'full', 'equal'];
    public const CLASSES = ['transient', 'any', '4xx', '5xx'];
    public const MAX_ATTEMPTS = 20;
    public const MAX_DELAY_MS = 604800000; // 7 days

    /**
     * @param list<string> $retryOn
     */
    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $baseDelayMs,
        private readonly float $backoff,
        private readonly int $maxDelayMs,
        private readonly string $jitter,
        private readonly array $retryOn,
        private readonly bool $respectRetryAfter,
        private readonly \Random\Randomizer $random = new \Random\Randomizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function fromStep(array $step, AppConfig $config, ?\Random\Randomizer $random = null): self
    {
        $retry = is_array($step['retry'] ?? null) ? $step['retry'] : [];
        return new self(
            maxAttempts: isset($retry['max_attempts']) ? (int)$retry['max_attempts'] : $config->retryMax + 1,
            baseDelayMs: Duration::toMs($retry['base_delay'] ?? null) ?? $config->retryBaseDelayMs,
            backoff: isset($retry['backoff']) ? (float)$retry['backoff'] : $config->retryBackoff,
            maxDelayMs: Duration::toMs($retry['max_delay'] ?? null) ?? $config->retryMaxDelayMs,
            jitter: in_array($retry['jitter'] ?? null, self::JITTERS, true) ? $retry['jitter'] : $config->retryJitter,
            retryOn: is_array($retry['retry_on'] ?? null) ? array_map('strval', $retry['retry_on']) : ['transient'],
            respectRetryAfter: (bool)($retry['respect_retry_after'] ?? true),
            random: $random ?? new \Random\Randomizer(),
        );
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @param int $attempt number of the attempt that has just failed (1 = the first one)
     */
    public function shouldRetry(ErrorInfo $error, int $attempt): bool
    {
        if ($error->permanent || $attempt >= $this->maxAttempts) {
            return false;
        }
        foreach ($this->retryOn as $class) {
            $matches = match (true) {
                $class === 'transient' => $error->transient,
                $class === 'any' => true,
                $class === '5xx' => $error->httpStatus !== null && $error->httpStatus >= 500 && $error->httpStatus <= 599,
                $class === '4xx' => $error->httpStatus !== null && $error->httpStatus >= 400 && $error->httpStatus <= 499,
                (bool)preg_match('/^\d{3}$/', $class) => $error->httpStatus === (int)$class,
                default => false,
            };
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    /**
     * Delay before the next attempt after attempt $attempt failed.
     */
    public function delayMs(int $attempt, ?int $retryAfterSeconds = null): int
    {
        $delay = (int)min($this->maxDelayMs, $this->baseDelayMs * ($this->backoff ** max(0, $attempt - 1)));
        $delay = match ($this->jitter) {
            'full' => $delay > 0 ? $this->random->getInt(0, $delay) : 0,
            'equal' => $delay > 1 ? intdiv($delay, 2) + $this->random->getInt(0, intdiv($delay, 2)) : $delay,
            default => $delay,
        };
        if ($retryAfterSeconds !== null && $this->respectRetryAfter) {
            $delay = max($delay, min($retryAfterSeconds * 1000, $this->maxDelayMs));
        }
        return $delay;
    }

    /**
     * Save-time check of a "retry" profile: field → message.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $retry): array
    {
        if (!is_array($retry) || ($retry !== [] && array_is_list($retry))) {
            return ['' => 'Must be an object'];
        }
        $errors = [];
        foreach (array_keys($retry) as $key) {
            if (!in_array($key, ['max_attempts', 'base_delay', 'max_delay', 'backoff', 'jitter', 'retry_on', 'respect_retry_after'], true)) {
                $errors[(string)$key] = 'Unknown retry option';
            }
        }
        if (isset($retry['max_attempts']) && (!is_int($retry['max_attempts']) || $retry['max_attempts'] < 1 || $retry['max_attempts'] > self::MAX_ATTEMPTS)) {
            $errors['max_attempts'] = 'Must be an integer between 1 and ' . self::MAX_ATTEMPTS;
        }
        $base = null;
        foreach (['base_delay', 'max_delay'] as $key) {
            if (!array_key_exists($key, $retry)) {
                continue;
            }
            $ms = Duration::toMs($retry[$key]);
            if ($ms === null || $ms > self::MAX_DELAY_MS) {
                $errors[$key] = 'Invalid duration (e.g. 2s, 10m, 1h; at most 7 days)';
            } elseif ($key === 'base_delay') {
                $base = $ms;
            } elseif ($base !== null && $ms < $base) {
                $errors[$key] = 'Must be greater than or equal to base_delay';
            }
        }
        if (isset($retry['backoff']) && (!is_numeric($retry['backoff']) || $retry['backoff'] < 1 || $retry['backoff'] > 10)) {
            $errors['backoff'] = 'Must be a number between 1 and 10';
        }
        if (isset($retry['jitter']) && !in_array($retry['jitter'], self::JITTERS, true)) {
            $errors['jitter'] = 'Must be one of: ' . implode(', ', self::JITTERS);
        }
        if (array_key_exists('retry_on', $retry)) {
            if (!is_array($retry['retry_on']) || !array_is_list($retry['retry_on']) || $retry['retry_on'] === []) {
                $errors['retry_on'] = 'Must be a non-empty list';
            } else {
                foreach ($retry['retry_on'] as $i => $class) {
                    if (!is_string($class) || (!in_array($class, self::CLASSES, true) && !preg_match('/^[1-5]\d{2}$/', $class))) {
                        $errors["retry_on[{$i}]"] = sprintf('Unknown retry class "%s"', is_scalar($class) ? (string)$class : gettype($class));
                    }
                }
            }
        }
        if (isset($retry['respect_retry_after']) && !is_bool($retry['respect_retry_after'])) {
            $errors['respect_retry_after'] = 'Must be true or false';
        }
        return $errors;
    }
}
