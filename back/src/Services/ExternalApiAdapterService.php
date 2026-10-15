<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Http\Client;
use Wps\Config\AppConfig;
use Wps\Exceptions\CrmApiException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Support\Json;

/**
 * HTTP adapter to the external CRM API (SuiteCRM v8-like, TS §9).
 * Uses the coroutine HTTP client, so calls never block the worker.
 *
 * Authentication: header X-Auth-Key = CRM_API_STATIC_KEY . gmdate("Y.m.d H").
 */
final class ExternalApiAdapterService
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authKey(): string
    {
        return $this->config->crmStaticKey . gmdate('Y.m.d H');
    }

    /**
     * GET /{module}/{id}. Returns the flattened object or null when it does not exist (404).
     *
     * @return array<string, mixed>|null
     */
    public function getById(string $module, string $id): ?array
    {
        $this->assertModule($module);
        $response = $this->request('GET', $this->config->crmUrl(rawurlencode($module) . '/' . rawurlencode($id)));
        if ($response['status'] === 404) {
            return null;
        }
        $data = $response['body']['data'] ?? $response['body'];
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }
        return is_array($data) && $data !== [] ? self::flatten($data) : null;
    }

    /**
     * GET /{module}?filter[{field}]={value}&size=1. Returns the first match (duplicates are possible —
     * customer answer #12: "в процесс подгружается первая из них").
     *
     * @return array<string, mixed>|null
     */
    public function findOneByField(string $module, string $field, string $value): ?array
    {
        $this->assertModule($module);
        $query = http_build_query(['filter' => [$field => $value], 'size' => 1]);
        $response = $this->request('GET', $this->config->crmUrl(rawurlencode($module)) . '?' . $query);
        if ($response['status'] === 404) {
            return null;
        }
        $data = $response['body']['data'] ?? [];
        if (!is_array($data) || $data === []) {
            return null;
        }
        $first = array_is_list($data) ? $data[0] : $data;
        return is_array($first) ? self::flatten($first) : null;
    }

    /**
     * PUT /{module}/{id}
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed> flattened object returned by the CRM (may be empty)
     */
    public function update(string $module, string $id, array $attributes): array
    {
        $this->assertModule($module);
        $body = ['data' => ['type' => $module, 'id' => $id, 'attributes' => (object)$attributes]];
        $response = $this->request('PUT', $this->config->crmUrl(rawurlencode($module) . '/' . rawurlencode($id)), $body);
        $data = $response['body']['data'] ?? null;
        return is_array($data) ? self::flatten($data) : [];
    }

    /**
     * POST /{module}
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function create(string $module, array $attributes): array
    {
        $this->assertModule($module);
        $body = ['data' => ['type' => $module, 'attributes' => (object)$attributes]];
        $response = $this->request('POST', $this->config->crmUrl(rawurlencode($module)), $body);
        $data = $response['body']['data'] ?? null;
        return is_array($data) ? self::flatten($data) : [];
    }

    /**
     * Modules dictionary: from MOCK_SERVICE_URL/modules when configured, otherwise GET /api/v8/modules.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getModules(): array
    {
        $urls = [];
        if ($this->config->mockServiceUrl !== null) {
            $urls[] = $this->config->mockServiceUrl . '/modules';
        }
        $urls[] = $this->config->crmUrl('modules');

        $lastError = null;
        foreach ($urls as $url) {
            try {
                $response = $this->request('GET', $url);
                $items = self::normalizeModules($response['body']['data'] ?? $response['body'] ?? []);
                if ($items !== []) {
                    return $items;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->warning('Modules dictionary source unavailable', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }
        if ($lastError !== null) {
            throw $lastError;
        }
        return [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<mixed>|null}
     */
    public function request(string $method, string $url, ?array $body = null): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new NonRecoverableException("Invalid CRM URL: {$url}");
        }
        $ssl = ($parts['scheme'] ?? 'http') === 'https';
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $client = new Client($parts['host'], $port, $ssl);
        $client->set(['timeout' => $this->config->crmTimeout]);
        $client->setHeaders([
            'Host' => $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Auth-Key' => $this->authKey(),
        ]);
        $client->setMethod($method);
        if ($body !== null) {
            $client->setData(Json::encode($body));
        }

        $started = microtime(true);
        $client->execute($path);
        $status = (int)$client->statusCode;
        $raw = (string)$client->body;
        $errCode = (int)$client->errCode;
        $errMsg = (string)$client->errMsg;
        $client->close();

        $this->logger->debug('CRM request', [
            'method' => $method, 'url' => $url, 'status' => $status,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ]);

        if ($status <= 0) {
            $reason = match ($status) {
                -1 => 'connection failed',
                -2 => 'request timeout',
                -3 => 'connection reset by server',
                default => $errMsg !== '' ? $errMsg : 'transport error',
            };
            throw new CrmApiException("CRM {$method} {$url}: {$reason}", $status, true, ['errno' => $errCode]);
        }

        $decoded = Json::decode($raw);
        if ($status >= 500) {
            throw new CrmApiException("CRM {$method} {$url} returned HTTP {$status}", $status, true, ['response' => $decoded ?? mb_substr($raw, 0, 500)]);
        }
        if ($status === 404 && $method === 'GET') {
            return ['status' => 404, 'body' => is_array($decoded) ? $decoded : null];
        }
        if ($status >= 400) {
            throw new CrmApiException("CRM {$method} {$url} returned HTTP {$status}", $status, false, ['response' => $decoded ?? mb_substr($raw, 0, 500)]);
        }
        if ($raw !== '' && !is_array($decoded)) {
            throw new CrmApiException("CRM {$method} {$url} returned invalid JSON", $status, false, ['response' => mb_substr($raw, 0, 500)]);
        }
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
    }

    /**
     * JSON:API resource { id, type, attributes: {...} } → flat object { id, ...attributes }.
     *
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public static function flatten(array $resource): array
    {
        if (isset($resource['attributes']) && is_array($resource['attributes'])) {
            $object = $resource['attributes'];
            if (isset($resource['id'])) {
                $object = ['id' => (string)$resource['id']] + $object;
            }
            return $object;
        }
        return $resource;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function normalizeModules(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $key => $item) {
            if (is_string($item)) {
                $value = is_string($key) ? $key : $item;
                $result[] = ['value' => $value, 'label' => $item];
            } elseif (is_array($item)) {
                $value = $item['value'] ?? $item['name'] ?? $item['id'] ?? null;
                $label = $item['label'] ?? $item['attributes']['label'] ?? $item['attributes']['name'] ?? $value;
                if (is_string($value) && $value !== '') {
                    $result[] = ['value' => $value, 'label' => (string)$label];
                }
            }
        }
        return $result;
    }

    private function assertModule(string $module): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $module)) {
            throw new NonRecoverableException("Invalid CRM module \"{$module}\"");
        }
    }
}
