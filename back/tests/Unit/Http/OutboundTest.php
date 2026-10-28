<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\NonRecoverableException;
use Wps\Exceptions\RecoverableException;
use Wps\Http\Outbound\Auth\AuthApplier;
use Wps\Http\Outbound\Auth\OAuth2TokenProvider;
use Wps\Http\Outbound\OutboundResponse;
use Wps\Http\Outbound\CidrList;
use Wps\Http\Outbound\EgressGuard;
use Wps\Http\Outbound\JsonPathExtractor;
use Wps\Http\Outbound\OutboundHttpClient;

final class OutboundTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield '10.x' => ['10.1.2.3', true];
        yield 'loopback' => ['127.0.0.1', true];
        yield 'link-local metadata' => ['169.254.169.254', true];
        yield '172.16/12' => ['172.20.0.5', true];
        yield 'public' => ['93.184.216.34', false];
        yield 'cgnat' => ['100.64.1.1', true];
        yield 'ipv6 loopback' => ['::1', true];
        yield 'ipv6 unique local' => ['fd00::1', true];
        yield 'ipv6 link-local' => ['fe80::1', true];
        yield 'ipv4-mapped private' => ['::ffff:10.0.0.1', true];
        yield 'ipv4-mapped public' => ['::ffff:93.184.216.34', false];
        yield 'public ipv6' => ['2606:4700:4700::1111', false];
        yield 'garbage' => ['not-an-ip', false];
    }

    #[DataProvider('addresses')]
    public function testPrivateNetworks(string $ip, bool $private): void
    {
        self::assertSame($private, (new CidrList(CidrList::PRIVATE))->contains($ip));
    }

    private static function guard(array $dns, array $allowPrivate = [], array $allowHosts = [], bool $plain = true): EgressGuard
    {
        return new EgressGuard($plain, [22, 6379], [], $allowHosts, ['localhost', 'postgres', '*.internal.lan'], $allowPrivate,
            static fn (string $host): array => $dns[$host] ?? []);
    }

    public function testGuardAllowsPublicHostsAndConnectsToTheCheckedIp(): void
    {
        $target = self::guard(['erp.company.ru' => ['93.184.216.34', '2606:4700::1']])->check('https://erp.company.ru/api/orders?x=1');
        self::assertSame('93.184.216.34', $target->ip, 'IPv4 first');
        self::assertSame(443, $target->port);
        self::assertSame('/api/orders?x=1', $target->path);
        self::assertSame('erp.company.ru', $target->hostHeader());
        self::assertSame('erp.company.ru:8443', self::guard(['erp.company.ru' => ['93.184.216.34']])->check('https://erp.company.ru:8443/')->hostHeader());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function denied(): iterable
    {
        yield 'private by DNS' => ['http://evil.example/', 'Destination address is not allowed (private network)'];
        yield 'one private address of many' => ['http://mixed.example/', 'Destination address is not allowed (private network)'];
        yield 'ip literal' => ['http://169.254.169.254/latest/meta-data', 'Destination address is not allowed (private network)'];
        yield 'ipv6 literal' => ['http://[::1]:8080/', 'Destination address is not allowed (private network)'];
        yield 'denied host' => ['http://postgres/', 'Host "postgres" is not allowed'];
        yield 'denied wildcard' => ['http://db.internal.lan/', 'Host "db.internal.lan" is not allowed'];
        yield 'denied port' => ['http://public.example:6379/', 'Port 6379 is not allowed'];
        yield 'scheme' => ['ftp://public.example/', 'http or https with a host is required'];
    }

    #[DataProvider('denied')]
    public function testGuardDenies(string $url, string $message): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage($message);
        self::guard(['evil.example' => ['10.0.0.5'], 'mixed.example' => ['93.184.216.34', '192.168.1.1'], 'public.example' => ['93.184.216.34']])->check($url);
    }

    public function testGuardOptions(): void
    {
        self::assertSame('10.20.0.7', self::guard(['erp.lan' => ['10.20.0.7']], ['10.20.0.0/16'])->check('http://erp.lan/')->ip, 'an allowed private network');
        try {
            self::guard(['other.example' => ['93.184.216.34']], [], ['*.company.ru'])->check('https://other.example/');
            self::fail('allow list');
        } catch (NonRecoverableException $e) {
            self::assertStringContainsString('HTTP_ALLOW_HOSTS', $e->getMessage());
        }
        self::assertSame('api.company.ru', self::guard(['api.company.ru' => ['93.184.216.34']], [], ['*.company.ru'])->check('https://api.company.ru/')->host);
        try {
            self::guard(['public.example' => ['93.184.216.34']], [], [], false)->check('http://public.example/');
            self::fail('plain http');
        } catch (NonRecoverableException $e) {
            self::assertStringContainsString('Plain http is not allowed', $e->getMessage());
        }
        $this->expectException(RecoverableException::class);
        $this->expectExceptionMessage('DNS lookup failed');
        self::guard([])->check('https://nowhere.example/');
    }

    public function testRedirectLocation(): void
    {
        self::assertSame('https://b.example/x', OutboundHttpClient::resolveLocation('https://a.example/p/q', 'https://b.example/x'));
        self::assertSame('https://a.example/root', OutboundHttpClient::resolveLocation('https://a.example/p/q', '/root'));
        self::assertSame('https://a.example/p/next', OutboundHttpClient::resolveLocation('https://a.example/p/q', 'next'));
        self::assertSame('https://c.example/z', OutboundHttpClient::resolveLocation('https://a.example:8443/p', '//c.example/z'));
        self::assertSame('http://a.example:8080/r', OutboundHttpClient::resolveLocation('http://a.example:8080/p', '/r'));
    }

    public function testJsonPath(): void
    {
        $data = ['data' => ['order_id' => 'A-1', 'items' => [['id' => 1, 'qty' => 2], ['id' => 2, 'qty' => 5]]]];
        self::assertSame('A-1', JsonPathExtractor::extract($data, '$.data.order_id'));
        self::assertSame(['id' => 1, 'qty' => 2], JsonPathExtractor::extract($data, '$.data.items[0]'));
        self::assertSame([1, 2], JsonPathExtractor::extract($data, '$..id'));
        self::assertSame(2, JsonPathExtractor::extract($data, '$.data.items[?(@.qty > 3)].id'));
        self::assertNull(JsonPathExtractor::extract($data, '$.data.missing'));
        self::assertNull(JsonPathExtractor::extract(null, '$.a'));
        self::assertNull(JsonPathExtractor::error('$.data.items[0].id'));
        self::assertSame('JSONPath must start with "$"', JsonPathExtractor::error('data.id'));
        self::assertNotNull(JsonPathExtractor::error('$.data[?(@.x'));
    }

    public function testAuthValidation(): void
    {
        self::assertSame([], AuthApplier::errors(['type' => 'bearer', 'token' => 'secret://erp']));
        self::assertSame(['token' => 'Credentials must reference a secret (secret://name)'], AuthApplier::errors(['type' => 'bearer', 'token' => 'abc123']));
        self::assertSame(['type' => 'Must be one of: none, bearer, basic, api_key, oauth2_client_credentials'], AuthApplier::errors(['type' => 'digest']));
        self::assertSame(['in' => 'Must be "header" or "query"'], AuthApplier::errors(['type' => 'api_key', 'key' => 'secret://erp_key', 'in' => 'cookie']));
        self::assertSame(['credentials' => 'Credentials must reference a secret (secret://name)'], AuthApplier::errors(['type' => 'basic']));
        self::assertSame(['' => 'Must be an object {"type": ...}'], AuthApplier::errors('bearer'));
    }

    public function testOAuth2TokenIsCachedAndErrorsAreClassified(): void
    {
        $calls = [];
        $provider = new OAuth2TokenProvider(static function (string $url, array $headers, string $body) use (&$calls): OutboundResponse {
            $calls[] = [$url, $headers, $body];
            return new OutboundResponse(200, ['content-type' => 'application/json'], json_encode(['access_token' => 'tok-' . count($calls), 'expires_in' => 3600]), $url);
        });
        $client = ['token_url' => 'https://auth.example/token', 'client_id' => 'wps', 'client_secret' => 's e/cret', 'scope' => 'orders'];
        self::assertSame('tok-1', $provider->token($client, 'secret://erp'));
        self::assertSame('tok-1', $provider->token($client, 'secret://erp'), 'cached');
        self::assertCount(1, $calls);
        self::assertSame('Basic ' . base64_encode('wps:s%20e%2Fcret'), $calls[0][1]['Authorization']);
        self::assertSame('grant_type=client_credentials&scope=orders', $calls[0][2]);
        $provider->forget($client, 'secret://erp');
        self::assertSame('tok-2', $provider->token($client, 'secret://erp'));
        $provider->forget($client, 'secret://erp');
        self::assertSame('tok-3', $provider->token($client, 'secret://erp', 'body'));
        self::assertArrayNotHasKey('Authorization', $calls[2][1]);
        self::assertSame('grant_type=client_credentials&scope=orders&client_id=wps&client_secret=s%20e%2Fcret', $calls[2][2]);

        $failing = new OAuth2TokenProvider(static fn (string $url) => new OutboundResponse(401, [], '{"error":"invalid_client"}', $url));
        try {
            $failing->token($client, 'secret://x');
            self::fail('expected an error');
        } catch (NonRecoverableException $e) {
            self::assertSame('OAuth2 token request failed: invalid_client', $e->getMessage());
        }
        $down = new OAuth2TokenProvider(static fn (string $url) => new OutboundResponse(503, [], '', $url));
        $this->expectException(\Wps\Http\Outbound\HttpRequestException::class);
        $down->token($client, 'secret://y');
    }
}
