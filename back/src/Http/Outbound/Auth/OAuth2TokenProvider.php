<?php

declare(strict_types=1);

namespace Wps\Http\Outbound\Auth;

use Wps\Exceptions\NonRecoverableException;
use Wps\Http\Outbound\HttpRequestException;
use Wps\Http\Outbound\OutboundHttpClient;
use Wps\Infrastructure\RedisConnection;

/**
 * OAuth2 client credentials (md/tasks_list/done/4.2-http-request.md §4.4.1): the token is cached in Redis until shortly
 * before it expires; one worker requests it at a time (lock), the others wait for the cache.
 */
final class OAuth2TokenProvider
{
    /** @var array<string, array{0: string, 1: float}> token cache of this worker when there is no Redis */
    private array $memory = [];

    /**
     * @param OutboundHttpClient|\Closure(string, array<string, string>, string): \Wps\Http\Outbound\OutboundResponse $http
     */
    public function __construct(
        private readonly OutboundHttpClient|\Closure $http,
        private readonly ?RedisConnection $redis = null,
    ) {
    }

    /**
     * @param array{token_url: string, client_id: string, client_secret: string, scope?: string} $client
     */
    public function token(array $client, string $cacheKey, string $authStyle = 'header'): string
    {
        $key = 'oauth:token:' . sha1($cacheKey . '|' . ($client['scope'] ?? ''));
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $lock = $key . ':lock';
        $owner = bin2hex(random_bytes(8));
        if ($this->redis !== null && !$this->redis->run(static fn (\Redis $r) => (bool)$r->set($lock, $owner, ['nx', 'ex' => 15]))) {
            for ($i = 0; $i < 50; $i++) {
                usleep(100000);
                if (($cached = $this->get($key)) !== null) {
                    return $cached;
                }
            }
        }
        try {
            [$token, $ttl] = $this->request($client, $authStyle);
            if ($this->redis !== null) {
                $this->redis->run(static fn (\Redis $r) => $r->setex($key, max(30, $ttl), $token));
            } else {
                $this->memory[$key] = [$token, microtime(true) + max(30, $ttl)];
            }
            return $token;
        } finally {
            $this->redis?->run(static fn (\Redis $r) => $r->get($lock) === $owner ? $r->del($lock) : 0);
        }
    }

    public function forget(array $client, string $cacheKey): void
    {
        $key = 'oauth:token:' . sha1($cacheKey . '|' . ($client['scope'] ?? ''));
        $this->redis?->run(static fn (\Redis $r) => $r->del($key));
        unset($this->memory[$key]);
    }

    private function get(string $key): ?string
    {
        if ($this->redis === null) {
            return isset($this->memory[$key]) && $this->memory[$key][1] > microtime(true) ? $this->memory[$key][0] : null;
        }
        $value = $this->redis?->run(static fn (\Redis $r) => $r->get($key));
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, string> $client
     * @return array{0: string, 1: int} [access token, cache ttl seconds]
     */
    private function request(array $client, string $authStyle): array
    {
        if (!str_starts_with(strtolower($client['token_url']), 'https://') && !str_starts_with(strtolower($client['token_url']), 'http://')) {
            throw new NonRecoverableException('OAuth2 token_url must be an http(s) URL');
        }
        $form = ['grant_type' => 'client_credentials'] + (isset($client['scope']) && $client['scope'] !== '' ? ['scope' => $client['scope']] : []);
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($authStyle === 'body') {
            $form += ['client_id' => $client['client_id'], 'client_secret' => $client['client_secret']];
        } else {
            $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode($client['client_id']) . ':' . rawurlencode($client['client_secret']));
        }
        $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $response = $this->http instanceof \Closure
            ? ($this->http)($client['token_url'], $headers, $body)
            : $this->http->send('POST', $client['token_url'], $headers, $body, 15.0);
        $data = json_decode($response->body, true);
        if ($response->status >= 500 || in_array($response->status, [408, 429], true)) {
            throw new HttpRequestException("OAuth2 token request failed: HTTP {$response->status}", $response->status, true, true);
        }
        if ($response->status >= 400 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            $error = is_array($data) ? (string)($data['error'] ?? "HTTP {$response->status}") : "HTTP {$response->status}";
            throw new NonRecoverableException("OAuth2 token request failed: {$error}", ['error_code' => 'OAUTH2_TOKEN']);
        }
        $expires = isset($data['expires_in']) ? (int)$data['expires_in'] : 360;
        return [$data['access_token'], $expires - 60];
    }
}
