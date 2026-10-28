<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\SideEffects;

use PHPUnit\Framework\TestCase;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Exceptions\RecoverableException;
use Wps\Secrets\SecretMasker;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\SideEffectLedger;
use Wps\SideEffects\UnknownOutcomeException;
use Wps\Tests\Unit\Support\InMemorySideEffectStore;

final class SideEffectLedgerTest extends TestCase
{
    private InMemorySideEffectStore $store;
    private SideEffectLedger $ledger;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->store = new InMemorySideEffectStore();
        $this->ledger = new SideEffectLedger($this->store);
    }

    private static function context(string $token = 'token-1', int $pc = 3, string $iteration = ''): ExecutionContext
    {
        $context = ExecutionContext::fromEvent('event-1', ['id' => '1'], 'Leads');
        $context->executionId = 'exec-1';
        $context->pc = $pc;
        $context->iteration = $iteration;
        $context->attemptToken = $token;
        return $context;
    }

    /**
     * @return array{response: array<mixed>, replayed: bool, idempotency_key: string}
     */
    private function send(ExecutionContext $context, string $delivery = DeliveryPolicy::AT_MOST_ONCE, ?\Throwable $error = null): array
    {
        return $this->ledger->run($context, 'send_email', '', $delivery, ['to' => 'a@b.c'], function (string $key) use ($error): array {
            $this->calls++;
            if ($error !== null) {
                throw $error;
            }
            return ['message_id' => "<{$key}>"];
        });
    }

    public function testFirstCallIsRecordedAsDone(): void
    {
        $result = $this->send(self::context());
        self::assertSame(1, $this->calls);
        self::assertFalse($result['replayed']);
        self::assertSame('exec-1:3', $result['idempotency_key']);
        self::assertSame('done', $this->store->rows['exec-1|3']['status']);
    }

    public function testRepeatAfterDoneReplays(): void
    {
        $first = $this->send(self::context());
        $second = $this->send(self::context('token-after-crash'));
        self::assertSame(1, $this->calls, 'the external system is not called again');
        self::assertTrue($second['replayed']);
        self::assertSame($first['response'], $second['response']);
    }

    public function testFailedCallIsRepeated(): void
    {
        try {
            $this->send(self::context(), error: new NonRecoverableException('550 mailbox unavailable'));
            self::fail('exception expected');
        } catch (NonRecoverableException) {
        }
        self::assertSame('failed', $this->store->rows['exec-1|3']['status']);
        $this->send(self::context('token-2'));
        self::assertSame(2, $this->calls);
        self::assertSame('done', $this->store->rows['exec-1|3']['status']);
    }

    public function testRetryInsideTheSameAttemptCallsAgain(): void
    {
        try {
            $this->send(self::context(), error: new RecoverableException('connection refused'));
            self::fail('exception expected');
        } catch (RecoverableException) {
        }
        self::assertSame('started', $this->store->rows['exec-1|3']['status'], 'a recoverable error keeps "started"');
        $this->send(self::context()); // same attempt token = in-step retry
        self::assertSame(2, $this->calls);
    }

    public function testForeignStartedWithAtMostOnceIsUnknown(): void
    {
        $this->store->rows['exec-1|3'] = ['status' => 'started', 'attempt_token' => 'crashed-worker', 'delivery' => 'at_most_once', 'response' => null, 'error' => null, 'attempts' => 1, 'started_at' => '2026-10-19T10:00:00Z'];
        try {
            $this->send(self::context());
            self::fail('UnknownOutcomeException expected');
        } catch (UnknownOutcomeException $e) {
            self::assertSame('UNKNOWN_OUTCOME', $e->getDetails()['error_code']);
            self::assertSame('3', $e->getDetails()['effect_key']);
            self::assertFalse($e->isRecoverable());
        }
        self::assertSame(0, $this->calls);
    }

    public function testForeignStartedWithAtLeastOnceIsRepeated(): void
    {
        $this->store->rows['exec-1|3'] = ['status' => 'started', 'attempt_token' => 'crashed-worker', 'delivery' => 'at_least_once', 'response' => null, 'error' => null, 'attempts' => 1, 'started_at' => '2026-10-19T10:00:00Z'];
        $this->send(self::context(), DeliveryPolicy::AT_LEAST_ONCE);
        self::assertSame(1, $this->calls);
        self::assertSame(2, $this->store->rows['exec-1|3']['attempts']);
        self::assertSame('token-1', $this->store->rows['exec-1|3']['attempt_token']);
    }

    public function testUnknownOutcomeFromTheCallKeepsStarted(): void
    {
        try {
            $this->send(self::context(), error: UnknownOutcomeException::forEffect('3', null, 'timeout after the request was sent'));
            self::fail('exception expected');
        } catch (UnknownOutcomeException) {
        }
        self::assertSame('started', $this->store->rows['exec-1|3']['status']);
    }

    public function testEffectKeys(): void
    {
        self::assertSame('3', $this->ledger->effectKey(self::context()));
        self::assertSame('3@2/0', $this->ledger->effectKey(self::context(iteration: '2/0')));
        self::assertSame('3@2/0#slack', $this->ledger->effectKey(self::context(iteration: '2/0'), 'slack'));
        self::assertSame('exec-1:3@2/0#slack', $this->ledger->externalKey(self::context(iteration: '2/0'), 'slack'));
    }

    public function testDryRunDoesNotCall(): void
    {
        $context = self::context();
        $context->dryRun = true;
        self::assertSame(['dry_run' => true], $this->send($context)['response']);
        self::assertSame(0, $this->calls);
        self::assertSame([], $this->store->rows);
    }

    public function testWithoutExecutionTheCallIsDirect(): void
    {
        $context = self::context();
        $context->executionId = null;
        $this->send($context);
        self::assertSame(1, $this->calls);
        self::assertSame([], $this->store->rows);
    }

    public function testStoredResponseIsMasked(): void
    {
        $context = self::context();
        $context->secretMasker = new SecretMasker();
        $context->secretMasker->register('super-secret-token');
        $this->ledger->run($context, 'http_request', '', DeliveryPolicy::AT_LEAST_ONCE, [], static fn () => ['echo' => 'Bearer super-secret-token']);
        self::assertSame(['echo' => 'Bearer ***'], $this->store->rows['exec-1|3']['response']);
    }
}
