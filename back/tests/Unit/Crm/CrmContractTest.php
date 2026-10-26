<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Crm;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Wps\Crm\CrmCallOrigin;
use Wps\Crm\CrmCapabilities;
use Wps\Crm\CrmCapabilityMissingException;
use Wps\Crm\CrmFilter;

final class CrmContractTest extends TestCase
{
    public function testFilterQuery(): void
    {
        $filter = (new CrmFilter())
            ->add('account_id', 'eq', '42')
            ->add('sales_stage', 'in', ['Prospecting', 'Qualification'])
            ->add('amount', 'gte', '1000.5')
            ->add('amount', 'lt', '5000')
            ->add('description', 'null');
        self::assertSame(['filter' => [
            'account_id' => ['eq' => '42'],
            'sales_stage' => ['in' => ['Prospecting', 'Qualification']],
            'amount' => ['gte' => '1000.5', 'lt' => '5000'],
            'description' => ['null' => '1'],
        ]], $filter->toQuery());
        self::assertFalse($filter->isEqualityOnly());
        self::assertSame('a=1', urldecode(http_build_query(CrmFilter::eq('a', '1')->toLegacyQuery()['filter'])));
    }

    public function testEqualityOnly(): void
    {
        self::assertTrue((new CrmFilter())->add('a', 'eq', '1')->add('b', 'eq', '2')->isEqualityOnly());
        self::assertFalse((new CrmFilter())->add('a', 'eq', '1')->add('a', 'eq', '2')->isEqualityOnly(), 'a field twice is not expressible in 1.0');
        self::assertTrue((new CrmFilter())->isEmpty());
        self::assertSame([], (new CrmFilter())->toQuery());
    }

    public function testFilterIsImmutable(): void
    {
        $base = CrmFilter::eq('a', '1');
        $extended = $base->add('b', 'eq', '2');
        self::assertCount(1, $base->conditions());
        self::assertCount(2, $extended->conditions());
    }

    public function testUnknownOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CrmFilter())->add('a', 'between', '1');
    }

    public function testCapabilities(): void
    {
        $caps = CrmCapabilities::fromArray(['contract_version' => '2.0', 'features' => ['relationships', 'count'], 'limits' => ['max_page_size' => 50]]);
        self::assertFalse($caps->isLegacy());
        self::assertTrue($caps->has('relationships'));
        self::assertFalse($caps->has('delete'));
        self::assertSame(50, $caps->limit('max_page_size', 100));
        self::assertSame(100, $caps->limit('max_link_batch', 100));
        $caps->require('count');
        try {
            $caps->require('delete');
            self::fail('exception expected');
        } catch (CrmCapabilityMissingException $e) {
            self::assertSame('CRM_CAPABILITY_MISSING', $e->getDetails()['error_code']);
            self::assertSame('CRM does not support "delete" (contract 2.0); update the CRM plugin', $e->getMessage());
            self::assertFalse($e->isRecoverable());
        }
        self::assertTrue(CrmCapabilities::legacy()->isLegacy());
        self::assertSame([], CrmCapabilities::legacy()->features);
    }

    public function testCallOriginDoesNotLeakBetweenCoroutines(): void
    {
        $seen = [];
        Coroutine\run(function () use (&$seen): void {
            $wg = new Coroutine\WaitGroup();
            foreach (['11111111-1111-1111-1111-111111111111' => 1, '22222222-2222-2222-2222-222222222222' => 2] as $execution => $process) {
                $wg->add();
                Coroutine::create(function () use ($execution, $process, &$seen, $wg): void {
                    CrmCallOrigin::set($execution, $process, $process);
                    Coroutine::sleep(0.01); // let the other coroutine set its own origin
                    $seen[$process] = CrmCallOrigin::headers();
                    $wg->done();
                });
            }
            $wg->add();
            Coroutine::create(function () use (&$seen, $wg): void {
                $seen['none'] = CrmCallOrigin::headers();
                $wg->done();
            });
            $wg->wait();
        });
        self::assertSame(['X-WPS-Execution-Id' => '11111111-1111-1111-1111-111111111111', 'X-WPS-Process-Id' => '1', 'X-WPS-Depth' => '1'], $seen[1]);
        self::assertSame('2', $seen[2]['X-WPS-Process-Id']);
        self::assertSame([], $seen['none'], 'a coroutine outside of an execution sends no origin');
    }
}
