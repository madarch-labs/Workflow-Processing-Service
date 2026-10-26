<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Crm\CrmCapabilityMissingException;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\CrmApiException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Exceptions\RecoverableException;
use Wps\Services\Errors\ErrorClassifier;
use Wps\Services\Errors\ErrorInfo;
use Wps\Services\Errors\ErrorPolicy;
use Wps\Services\Errors\RetryPolicy;
use Wps\SideEffects\UnknownOutcomeException;
use Wps\Time\Duration;

final class ErrorHandlingTest extends TestCase
{
    private static function policy(array $retry = [], string $jitter = 'none', int $seed = 1): RetryPolicy
    {
        return RetryPolicy::fromStep(['retry' => $retry + ['jitter' => $jitter]], self::config(), new \Random\Randomizer(new \Random\Engine\Mt19937($seed)));
    }

    private static function config(): AppConfig
    {
        return (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor() instanceof AppConfig
            ? self::configWith(3, 1000, 2.0, 3600000, 'full')
            : throw new \LogicException();
    }

    private static function configWith(int $max, int $base, float $backoff, int $maxDelay, string $jitter): AppConfig
    {
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();
        foreach (['retryMax' => $max, 'retryBaseDelayMs' => $base, 'retryBackoff' => $backoff, 'retryMaxDelayMs' => $maxDelay, 'retryJitter' => $jitter] as $name => $value) {
            (new \ReflectionProperty(AppConfig::class, $name))->setValue($config, $value);
        }
        return $config;
    }

    public function testDelays(): void
    {
        $p = self::policy();
        self::assertSame([1000, 2000, 4000], [$p->delayMs(1), $p->delayMs(2), $p->delayMs(3)]);
        self::assertSame(4, $p->maxAttempts(), 'ACTION_RETRY_MAX + 1');
        $capped = self::policy(['base_delay' => '10s', 'max_delay' => '15s']);
        self::assertSame(15000, $capped->delayMs(3));
        self::assertSame(120000, self::policy(['max_delay' => '1h'])->delayMs(1, 120), 'Retry-After raises the delay');
        self::assertSame(15000, $capped->delayMs(1, 120), 'Retry-After is capped by max_delay');
        self::assertSame(1000, self::policy(['respect_retry_after' => false])->delayMs(1, 120));
        $full = self::policy(['base_delay' => '8s'], 'full');
        for ($i = 1; $i <= 20; $i++) {
            $d = $full->delayMs(1);
            self::assertGreaterThanOrEqual(0, $d);
            self::assertLessThanOrEqual(8000, $d);
        }
        $equal = self::policy(['base_delay' => '8s'], 'equal');
        for ($i = 1; $i <= 20; $i++) {
            self::assertGreaterThanOrEqual(4000, $equal->delayMs(1));
        }
        self::assertSame(self::policy([], 'full', 7)->delayMs(2), self::policy([], 'full', 7)->delayMs(2), 'seeded randomizer is deterministic');
    }

    public function testShouldRetry(): void
    {
        $p = self::policy();
        $e503 = ErrorClassifier::classify(new CrmApiException('x', 503, true));
        $e422 = ErrorClassifier::classify(new CrmApiException('x', 422, false));
        $e429 = new ErrorInfo('HTTP_429', 'x', false, 429);
        self::assertTrue($p->shouldRetry($e503, 1));
        self::assertFalse($p->shouldRetry($e503, 4), 'max_attempts reached');
        self::assertFalse($p->shouldRetry($e422, 1));
        self::assertTrue(self::policy(['retry_on' => ['429']])->shouldRetry($e429, 1));
        self::assertTrue(self::policy(['retry_on' => ['4xx']])->shouldRetry($e422, 1));
        self::assertFalse(self::policy(['retry_on' => ['5xx']])->shouldRetry($e422, 1));
        self::assertTrue(self::policy(['retry_on' => ['any']])->shouldRetry($e422, 1));
        self::assertFalse(self::policy(['retry_on' => ['any']])->shouldRetry(ErrorClassifier::classify(new ConfigurationException('bad')), 1), 'configuration errors never');
        self::assertFalse(self::policy(['retry_on' => ['any']])->shouldRetry(ErrorClassifier::classify(new UnknownOutcomeException('lost', ['effect_key' => 'k'])), 1), 'unknown outcome never');
        self::assertFalse(self::policy(['max_attempts' => 1])->shouldRetry($e503, 1));
    }

    /**
     * @return iterable<string, array{\Throwable, string, bool, ?int}>
     */
    public static function classified(): iterable
    {
        yield 'timeout' => [new CrmApiException('t', -2, true), 'TIMEOUT', true, null];
        yield 'transport' => [new CrmApiException('t', -1, true), 'TRANSPORT', true, null];
        yield '503' => [new CrmApiException('t', 503, true, ['retry_after_seconds' => 30]), 'HTTP_503', true, 503];
        yield '429' => [new CrmApiException('t', 429, true), 'HTTP_429', true, 429];
        yield '422' => [new CrmApiException('t', 422, false), 'HTTP_422', false, 422];
        yield 'config' => [new ConfigurationException('c'), 'CONFIG', false, null];
        yield 'capability' => [CrmCapabilityMissingException::forFeature('relationships', '1.0'), 'CRM_CAPABILITY_MISSING', false, null];
        yield 'recoverable' => [new RecoverableException('r'), 'TRANSIENT', true, null];
        yield 'non-recoverable' => [new NonRecoverableException('n'), 'ERROR', false, null];
        yield 'own code' => [new NonRecoverableException('n', ['error_code' => 'LIMIT']), 'LIMIT', false, null];
        yield 'redis' => [new \RedisException('r'), 'REDIS', true, null];
        yield 'internal' => [new \RuntimeException('boom'), 'INTERNAL', false, null];
    }

    #[DataProvider('classified')]
    public function testClassifier(\Throwable $e, string $code, bool $transient, ?int $status): void
    {
        $info = ErrorClassifier::classify($e);
        self::assertSame($code, $info->code);
        self::assertSame($transient, $info->transient);
        self::assertSame($status, $info->httpStatus);
        if ($code === 'HTTP_503') {
            self::assertSame(30, $info->retryAfterSeconds);
        }
    }

    public function testErrorPolicy(): void
    {
        self::assertSame('stop', ErrorPolicy::fromStep([])->mode());
        self::assertSame('continue', ErrorPolicy::fromStep(['on_error' => 'continue'])->mode());
        $goto = ErrorPolicy::fromStep(['on_error' => ['goto' => 'fallback']]);
        self::assertSame(['goto', 'fallback'], [$goto->mode(), $goto->target()]);
        self::assertSame('stop', ErrorPolicy::fromStep(['on_error' => 'ignore'])->mode());
        self::assertNull(ErrorPolicy::error('stop'));
        self::assertNull(ErrorPolicy::error(['goto' => 'x']));
        foreach (['ignore', ['goto' => ''], ['goto' => 'x', 'extra' => 1], 5, null] as $bad) {
            self::assertSame('Must be "stop", "continue" or {"goto": "<step id>"}', ErrorPolicy::error($bad));
        }
    }

    public function testRetryValidation(): void
    {
        self::assertSame([], RetryPolicy::errors(['max_attempts' => 5, 'base_delay' => '2s', 'max_delay' => '10m', 'backoff' => 2, 'jitter' => 'full', 'retry_on' => ['transient', '429', '5xx'], 'respect_retry_after' => true]));
        self::assertSame([
            'max_attempts' => 'Must be an integer between 1 and 20',
            'base_delay' => 'Invalid duration (e.g. 2s, 10m, 1h; at most 7 days)',
            'backoff' => 'Must be a number between 1 and 10',
            'jitter' => 'Must be one of: none, full, equal',
            'retry_on[1]' => 'Unknown retry class "6xx"',
        ], RetryPolicy::errors(['max_attempts' => 21, 'base_delay' => 'soon', 'backoff' => 0.5, 'jitter' => 'random', 'retry_on' => ['transient', '6xx']]));
        self::assertSame(['max_delay' => 'Must be greater than or equal to base_delay'], RetryPolicy::errors(['base_delay' => '1m', 'max_delay' => '10s']));
        self::assertSame(['max_delay' => 'Invalid duration (e.g. 2s, 10m, 1h; at most 7 days)'], RetryPolicy::errors(['max_delay' => 'P8D']));
        self::assertSame(['' => 'Must be an object'], RetryPolicy::errors('fast'));
        self::assertSame(['speed' => 'Unknown retry option'], RetryPolicy::errors(['speed' => 1]));
    }

    public function testDuration(): void
    {
        self::assertSame(500, Duration::toMs('500ms'));
        self::assertSame(2000, Duration::toMs('2s'));
        self::assertSame(5400000, Duration::toMs('1h30m'));
        self::assertSame(300000, Duration::toMs('PT5M'));
        self::assertSame(604800000, Duration::toMs('P7D'));
        self::assertSame(1500, Duration::toMs(1500));
        self::assertSame(1500, Duration::toMs('1500'));
        foreach (['soon', '', '-1s', 'P1M', -5, null, '2 s'] as $bad) {
            self::assertNull(Duration::toMs($bad), json_encode($bad));
        }
    }

    public function testHandledErrorInContext(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1'], 'Leads');
        self::assertSame(0, $context->handledErrors());
        $context->recordHandledError(['code' => 'HTTP_500', 'message' => 'x']);
        $context->recordHandledError(['code' => 'HTTP_503', 'message' => 'y']);
        self::assertSame('HTTP_503', $context->get('sys.last_error.code'));
        self::assertSame(2, $context->get('sys.errors_count'));
        self::assertSame(2, ExecutionContext::fromArray($context->toArray())->handledErrors());
        $this->expectExceptionMessage('Variable "sys" is read-only');
        $context->set('sys.x', 1);
    }
}
