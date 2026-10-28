<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

use Swoole\Coroutine\Http\Client;
use Wps\Exceptions\NonRecoverableException;

/**
 * Coroutine HTTP client for outgoing calls (md/tasks_list/done/4.2-http-request.md §4.2, §4.7): every URL (and every
 * redirect) is checked by EgressGuard and the connection goes to the checked IP with the Host header and SNI of the
 * host; the response body is limited; redirects are followed by hand, credentials are not sent to another origin.
 */
final class OutboundHttpClient
{
    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly EgressGuard $guard,
        private readonly bool $tlsVerify = true,
        private readonly ?string $caFile = null,
        private readonly int $maxResponseBytes = 5242880,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $credentialHeaders headers removed when a redirect leads to another origin
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        float $timeout = 30.0,
        float $connectTimeout = 5.0,
        int $followRedirects = 0,
        array $credentialHeaders = ['authorization'],
    ): OutboundResponse {
        $origin = null;
        for ($hop = 0; ; $hop++) {
            $target = $this->guard->check($url);
            $origin ??= $target->origin();
            if ($target->origin() !== $origin) {
                $headers = array_filter($headers, static fn ($v, $k) => !in_array(strtolower((string)$k), $credentialHeaders, true), ARRAY_FILTER_USE_BOTH);
            }
            $response = $this->once($target, $method, $headers, $body, $timeout, $connectTimeout, $url);
            if ($followRedirects <= $hop || !in_array($response->status, self::REDIRECTS, true) || !isset($response->headers['location'])) {
                return $response;
            }
            $url = self::resolveLocation($url, $response->headers['location']);
            if ($response->status === 303 || (in_array($response->status, [301, 302], true) && $method === 'POST')) {
                $method = 'GET';
                $body = null;
                unset($headers['Content-Type'], $headers['content-type']);
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function once(ResolvedTarget $target, string $method, array $headers, ?string $body, float $timeout, float $connectTimeout, string $url): OutboundResponse
    {
        $client = new Client($target->ip, $target->port, $target->ssl());
        $received = '';
        $size = 0;
        $tooLarge = false;
        $max = $this->maxResponseBytes;
        $settings = [
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'write_func' => static function (Client $c, string $data) use (&$received, &$size, &$tooLarge, $max): void {
                $size += strlen($data);
                if ($size > $max) {
                    $tooLarge = true;
                    $c->close();
                    return;
                }
                $received .= $data;
            },
        ];
        if ($target->ssl()) {
            $settings += ['ssl_host_name' => $target->host, 'ssl_verify_peer' => $this->tlsVerify, 'ssl_allow_self_signed' => !$this->tlsVerify]
                + ($this->caFile !== null && $this->caFile !== '' ? ['ssl_cafile' => $this->caFile] : []);
        }
        $client->set($settings);
        $client->setMethod($method);
        $client->setHeaders(['Host' => $target->hostHeader()] + $headers);
        if ($body !== null) {
            $client->setData($body);
        }
        $client->execute($target->path);
        $status = (int)$client->statusCode;
        $responseHeaders = array_change_key_case((array)($client->headers ?? []), CASE_LOWER);
        $errMsg = (string)$client->errMsg;
        $client->close();

        if ($tooLarge) {
            throw new NonRecoverableException("Response is too large (more than {$max} bytes)", ['error_code' => 'RESPONSE_TOO_LARGE']);
        }
        if ($status === -1) {
            throw new HttpRequestException("{$method} {$url}: connection failed" . ($errMsg !== '' ? " ({$errMsg})" : ''), -1, true, false);
        }
        if ($status < 0) {
            throw new HttpRequestException("{$method} {$url}: " . ($status === -2 ? 'request timeout' : 'connection reset by server'), $status, true, true);
        }
        return new OutboundResponse($status, array_map('strval', $responseHeaders), $received !== '' ? $received : (string)$client->body, $url);
    }

    public static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $location;
    }
}
