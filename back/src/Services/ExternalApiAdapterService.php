<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Http\Client;
use Wps\Config\AppConfig;
use Wps\Crm\CrmCallOrigin;
use Wps\Crm\CrmCapabilities;
use Wps\Crm\CrmFilter;
use Wps\Crm\CrmPage;
use Wps\Exceptions\CrmApiException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Support\Json;

/**
 * HTTP adapter to the CRM API of the WPS contract (doc/{ru,en}/04-operations/crm-integration.md):
 * contract 1.0 — read by id, search by equality, create, update; contract 2.0 (md/tasks_list/done/0.2-crm-contract-v9.md) —
 * filter operators, sort, cursor pages, fields, count, delete, relationships. Features of 2.0 are checked against the
 * capabilities of the plugin: with an old plugin they fail with CRM_CAPABILITY_MISSING, the 1.0 subset keeps working.
 * Uses the coroutine HTTP client, so calls never block the worker.
 *
 * Authentication: header X-Auth-Key = CRM_API_STATIC_KEY . gmdate("Y.m.d H").
 * Calls made inside an execution carry X-WPS-Execution-Id / X-WPS-Process-Id / X-WPS-Depth (CrmCallOrigin).
 */
final class ExternalApiAdapterService
{
    public const LEGACY_MAX_SIZE = 100;

    /**
     * @param (\Closure(): CrmCapabilities)|null $capabilities lazy: the capabilities service depends on this adapter
     */
    public function __construct(
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $capabilities = null,
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
        // the contract 1.0 syntax works with every plugin version
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
     * One page of a search. $sort: [['field' => 'date_closed', 'desc' => true]] (≤ 2 fields); $fields: null = all.
     */
    public function findPage(string $module, CrmFilter $filter, array $sort = [], ?array $fields = null, int $size = 100, ?string $cursor = null, bool $withTotal = false): CrmPage
    {
        $this->assertModule($module);
        $caps = $this->caps();
        if ($caps->isLegacy()) {
            $this->assertLegacySearch($caps, $filter, $sort, $cursor, $withTotal, $size);
            $query = $filter->toLegacyQuery() + ['size' => $size];
        } else {
            if (!$filter->isEqualityOnly()) {
                $caps->require('filter_operators');
            }
            if ($filter->hasOperator('nin')) {
                $caps->require('filter_nin');
            }
            if ($sort !== []) {
                $caps->require('sort');
            }
            if ($cursor !== null) {
                $caps->require('cursor_pagination');
            }
            if ($withTotal) {
                $caps->require('count');
            }
            $query = $filter->toQuery() + ['page' => array_filter(['size' => $size, 'after' => $cursor], static fn ($v) => $v !== null)];
            if ($sort !== []) {
                $query['sort'] = implode(',', array_map(static fn (array $s) => (!empty($s['desc']) ? '-' : '') . $s['field'], $sort));
            }
            if ($fields !== null && $caps->has('fields')) {
                $query['fields'] = implode(',', $fields);
            }
            if ($withTotal) {
                $query['count'] = 1;
            }
        }
        $response = $this->request('GET', $this->config->crmUrl(rawurlencode($module)) . '?' . http_build_query($query));
        $body = (array)($response['body'] ?? []);
        $data = $response['status'] === 404 ? [] : (array)($body['data'] ?? []);
        return new CrmPage(
            array_values(array_map(static fn ($r) => self::flatten((array)$r), array_is_list($data) ? $data : [$data])),
            isset($body['meta']['next_cursor']) && is_string($body['meta']['next_cursor']) ? $body['meta']['next_cursor'] : null,
            isset($body['meta']['total']) ? (int)$body['meta']['total'] : null,
        );
    }

    /**
     * Pages until $limit records are collected (or the search ends).
     *
     * @return array{items: list<array<string, mixed>>, has_more: bool, requests: int}
     */
    public function findMany(string $module, CrmFilter $filter, array $sort = [], ?array $fields = null, int $limit = 100): array
    {
        $caps = $this->caps();
        $pageSize = $caps->isLegacy() ? self::LEGACY_MAX_SIZE : $caps->limit('max_page_size', 100);
        $items = [];
        $cursor = null;
        $requests = 0;
        do {
            $page = $this->findPage($module, $filter, $sort, $fields, min($pageSize, $limit - count($items)), $cursor);
            $requests++;
            array_push($items, ...$page->items);
            $cursor = $page->nextCursor;
            if ($caps->isLegacy()) {
                // contract 1.0 has no cursor: one request, "more" when the page is full
                return ['items' => $items, 'has_more' => count($page->items) >= $limit, 'requests' => $requests];
            }
        } while ($cursor !== null && count($items) < $limit);
        return ['items' => array_slice($items, 0, $limit), 'has_more' => $cursor !== null || count($items) > $limit, 'requests' => $requests];
    }

    /**
     * COUNT(*) by a filter (feature "count").
     */
    public function count(string $module, CrmFilter $filter): int
    {
        $this->caps()->require('count');
        return (int)$this->findPage($module, $filter, [], ['id'], 1, null, true)->total;
    }

    /**
     * Soft delete (feature "delete").
     *
     * @return bool false when the record did not exist (404)
     */
    public function delete(string $module, string $id): bool
    {
        $this->assertModule($module);
        $this->caps()->require('delete');
        $response = $this->request('DELETE', $this->config->crmUrl(rawurlencode($module) . '/' . rawurlencode($id)));
        return $response['status'] !== 404;
    }

    /**
     * Related records with the fields of the link (feature "relationships").
     */
    public function listRelated(string $module, string $id, string $link, ?array $fields = null, int $size = 100, ?string $cursor = null): CrmPage
    {
        $this->assertModule($module);
        $this->caps()->require('relationships');
        $query = ['page' => array_filter(['size' => $size, 'after' => $cursor], static fn ($v) => $v !== null)] + ($fields !== null ? ['fields' => implode(',', $fields)] : []);
        $response = $this->request('GET', $this->relationshipUrl($module, $id, $link) . '?' . http_build_query($query));
        if ($response['status'] === 404) {
            throw new NonRecoverableException("{$module} {$id} not found in the CRM");
        }
        $body = (array)($response['body'] ?? []);
        $items = [];
        $relationshipFields = [];
        $relatedModule = null;
        foreach ((array)($body['data'] ?? []) as $resource) {
            $relatedModule ??= isset($resource['type']) && is_string($resource['type']) && $resource['type'] !== '' ? $resource['type'] : null;
            $flat = self::flatten((array)$resource);
            $items[] = $flat;
            $relationshipFields[(string)($flat['id'] ?? '')] = array_map('strval', (array)($resource['meta']['relationship'] ?? []));
        }
        return new CrmPage($items, isset($body['meta']['next_cursor']) && is_string($body['meta']['next_cursor']) ? $body['meta']['next_cursor'] : null, null, $relationshipFields, $relatedModule);
    }

    /**
     * Links records (idempotent; an existing link gets its fields updated). Without the feature "relationship_fields"
     * the fields are dropped and reported in "warnings".
     *
     * @param list<array{id: string, module?: string, fields?: array<string, string>}> $items
     * @return array{linked: list<string>, updated: list<string>, not_found: list<string>, warnings: list<string>}
     */
    public function link(string $module, string $id, string $link, array $items): array
    {
        $this->assertModule($module);
        $caps = $this->caps();
        $caps->require('relationships');
        $warnings = [];
        $data = [];
        foreach ($items as $item) {
            $fields = (array)($item['fields'] ?? []);
            if ($fields !== [] && !$caps->has('relationship_fields')) {
                $warnings[] = 'The CRM does not support relationship fields; ' . implode(', ', array_keys($fields)) . " of {$item['id']} were not set";
                $fields = [];
            }
            $data[] = ['type' => (string)($item['module'] ?? ''), 'id' => $item['id']] + ($fields !== [] ? ['meta' => $fields] : []);
        }
        $response = $this->request('POST', $this->relationshipUrl($module, $id, $link), ['data' => $data]);
        if ($response['status'] === 404) {
            throw new NonRecoverableException("{$module} {$id} not found in the CRM");
        }
        $meta = (array)($response['body']['meta'] ?? []);
        return [
            'linked' => array_values((array)($meta['linked'] ?? [])),
            'updated' => array_values((array)($meta['updated'] ?? [])),
            'not_found' => array_values((array)($meta['not_found'] ?? [])),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return bool true when a link was removed
     */
    public function unlink(string $module, string $id, string $link, string $relatedId): bool
    {
        $this->assertModule($module);
        $this->caps()->require('relationships');
        $response = $this->request('DELETE', $this->relationshipUrl($module, $id, $link) . '/' . rawurlencode($relatedId));
        if ($response['status'] === 404) {
            throw new NonRecoverableException("{$module} {$id} not found in the CRM");
        }
        return (bool)($response['body']['meta']['removed'] ?? false);
    }

    /**
     * Raw GET /capabilities; null for an old plugin without it (404 / 405) — contract 1.0.
     *
     * @return array<string, mixed>|null
     */
    public function capabilities(): ?array
    {
        try {
            $response = $this->request('GET', $this->config->crmUrl('capabilities'));
        } catch (CrmApiException $e) {
            if (!$e->isRecoverable()) {
                return null;
            }
            throw $e;
        }
        $data = $response['body']['data'] ?? null;
        return $response['status'] === 404 || !is_array($data) || !isset($data['contract_version']) ? null : $data;
    }

    private function caps(): CrmCapabilities
    {
        return $this->capabilities !== null ? ($this->capabilities)() : CrmCapabilities::legacy();
    }

    /**
     * Contract 1.0 can only search by equality, without sort / cursor / count, ≤ 100 records.
     *
     * @param array<mixed> $sort
     */
    private function assertLegacySearch(CrmCapabilities $caps, CrmFilter $filter, array $sort, ?string $cursor, bool $withTotal, int $size): void
    {
        if (!$filter->isEqualityOnly()) {
            $caps->require('filter_operators');
        }
        if ($sort !== []) {
            $caps->require('sort');
        }
        if ($cursor !== null || $size > self::LEGACY_MAX_SIZE) {
            $caps->require('cursor_pagination');
        }
        if ($withTotal) {
            $caps->require('count');
        }
    }

    private function relationshipUrl(string $module, string $id, string $link): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $link)) {
            throw new NonRecoverableException("Invalid relationship link \"{$link}\"");
        }
        return $this->config->crmUrl(rawurlencode($module) . '/' . rawurlencode($id) . '/relationships/' . $link);
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
        ] + CrmCallOrigin::headers());
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
        $retryAfter = self::retryAfter($client->headers['retry-after'] ?? null);
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
        if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
            // overload and timeouts are worth a retry; Retry-After of 429 / 503 sets the delay (7.1)
            throw new CrmApiException("CRM {$method} {$url} returned HTTP {$status}", $status, true, ['response' => $decoded ?? mb_substr($raw, 0, 500)]
                + ($retryAfter !== null ? ['retry_after_seconds' => $retryAfter] : []));
        }
        if ($status === 404 && ($method === 'GET' || $method === 'DELETE')) {
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

    /**
     * Retry-After: seconds or an HTTP date.
     */
    private static function retryAfter(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return (int)trim($value);
        }
        $time = strtotime($value);
        return $time === false ? null : max(0, $time - time());
    }
}
