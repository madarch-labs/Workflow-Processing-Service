<?php

declare(strict_types=1);

/**
 * Mock Event Service (MES) — router for the PHP built-in server:
 *   php -S 0.0.0.0:9502 -t . mock_server.php
 *
 * Emulates the external CRM for WPS:
 *   GET  /health                         service + Redis status
 *   GET  /test-event?module=Deals        publish a random object to the Redis Stream (XADD crm_events * data '{json}')
 *                    &set[status]=Active  force field values; &count=N publish several events
 *   GET  /modules, /api/dictionaries/modules, /api/dictionaries/operators, /api/dictionaries/action-types
 *                    &event_type=create|update|delete &before[field]=old   an event of contract 2.0
 *   GET  /modules, /api/dictionaries/modules, /api/dictionaries/operators, /api/dictionaries/action-types
 *   CRM API of the WPS contract 2.0 under /api/v8 and /api/v9 (X-Auth-Key = MOCK_CRM_STATIC_KEY . gmdate("Y.m.d H")):
 *   GET    /api/v9/capabilities                 404 in the contract 1.0 mode
 *   GET    /api/v9/modules
 *   GET    /api/v9/{module}/{id}                id "fail500..." → HTTP 500, "slow..." → 3 s delay (for retry tests),
 *                                                "fail<N>x500..." → the first N requests fail with HTTP 500
 *   GET    /api/v9/{module}?filter[f][op]=v&sort=&fields=&page[size]=&page[after]=&count=1   (1.0 mode: filter[f]=v, first key)
 *   PUT    /api/v9/{module}/{id}                {data:{type,id,attributes}}
 *   POST   /api/v9/{module}                     {data:{type,attributes}}
 *   DELETE /api/v9/{module}/{id}
 *   GET|POST /api/v9/{module}/{id}/relationships/{link}, DELETE …/{related_id}
 *   Test helpers (no auth): GET|PUT /mock/objects/{module}/{id}; GET|PUT /mock/config {"contract": "1.0"|"2.0",
 *   "publish_on_write": bool}; GET /mock/relationships/{module}/{id}/{link}, PUT …/{related_id} {related_module, fields}
 * Env: MOCK_CRM_CONTRACT (default 2.0), MOCK_PUBLISH_ON_WRITE (0/1) — defaults of /mock/config.
 */

require_once __DIR__ . '/MockModulesList.php';
require_once __DIR__ . '/MockCrmStorage.php';
require_once __DIR__ . '/MockEventService.php';

function env(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

/**
 * @param mixed $payload
 */
function respond(int $status, mixed $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ok(mixed $data, int $status = 200): void
{
    respond($status, ['success' => true, 'data' => $data, 'error' => null]);
}

function fail(int $status, string $code, string $message): void
{
    respond($status, ['success' => false, 'data' => null, 'error' => ['code' => $code, 'message' => $message]]);
}

function redis(): Redis
{
    static $redis = null;
    if ($redis === null) {
        $redis = new Redis();
        $redis->connect(env('MOCK_REDIS_HOST', '127.0.0.1'), (int)env('MOCK_REDIS_PORT', '6379'), 2.0);
        $password = env('MOCK_REDIS_PASSWORD', '');
        if ($password !== '') {
            $redis->auth($password);
        }
    }
    return $redis;
}

/**
 * @return array<string, mixed>
 */
function jsonBody(): array
{
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $object
 * @return array<string, mixed>
 */
function resource(string $module, array $object): array
{
    $id = (string)($object['id'] ?? '');
    unset($object['id']);
    return ['type' => $module, 'id' => $id, 'attributes' => (object)$object];
}

function crmAuthorized(): bool
{
    $key = env('MOCK_CRM_STATIC_KEY', '');
    if ($key === '') {
        return true;
    }
    $given = $_SERVER['HTTP_X_AUTH_KEY'] ?? '';
    // accept the previous hour too (clock skew at the hour boundary)
    return hash_equals($key . gmdate('Y.m.d H'), $given) || hash_equals($key . gmdate('Y.m.d H', time() - 3600), $given);
}

function operators(): array
{
    return [
        ['value' => 'equals', 'label' => 'Равен'],
        ['value' => 'not_equals', 'label' => 'Не равен'],
        ['value' => 'contains', 'label' => 'Включает'],
        ['value' => 'not_contains', 'label' => 'Не включает'],
    ];
}

function actionTypes(): array
{
    return [
        ['value' => 'preload', 'label' => 'Предзагрузка объекта'],
        ['value' => 'modify', 'label' => 'Изменить объект/переменную'],
        ['value' => 'save_object', 'label' => 'Сохранить объект в CRM'],
        ['value' => 'start_process', 'label' => 'Запустить следующий процесс'],
    ];
}

/**
 * @return array{contract: string, publish_on_write: bool}
 */
function mockConfig(): array
{
    $stored = redis()->hGetAll('mock_config') ?: [];
    return [
        'contract' => (string)($stored['contract'] ?? env('MOCK_CRM_CONTRACT', '2.0')),
        'publish_on_write' => (($stored['publish_on_write'] ?? env('MOCK_PUBLISH_ON_WRITE', '0')) === '1'),
    ];
}

/**
 * Targets of http_request tests (md/tasks_list/done/4.2-http-request.md §5); requests are counted in Redis by key
 * (header X-Mock-Key or ?key=):
 *   ANY  /mock/echo[/...]?delay_ms=&status=   the request back as JSON (method, path, query, headers, body, json, count)
 *   GET  /mock/echo-last?key=                 the last echoed request and the count of the key
 *   GET  /mock/status/{code}?retry_after=     the status code (with Retry-After)
 *   GET  /mock/redirect?to=&code=302          a redirect
 *   GET  /mock/big?bytes=                     a body of N bytes
 *   GET  /mock/text?charset=windows-1251      a non-UTF-8 text body
 *   POST /mock/oauth/token                    client credentials (wps / wps-secret) → access token, counts tokens
 *   GET  /mock/protected                      200 with "Authorization: Bearer tok-…", otherwise 401
 */
function outboundTestEndpoint(string $method, array $segments): bool
{
    $what = $segments[1] ?? '';
    $key = (string)($_SERVER['HTTP_X_MOCK_KEY'] ?? $_GET['key'] ?? 'default');
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    switch ($what) {
        case 'slack':
        case 'teams':
        case 'telegram':
            // messenger webhooks of notify (4.8): /mock/slack/{id}, /mock/teams/{id}, /mock/telegram/bot{token}/sendMessage
            $id = (string)($segments[2] ?? '');
            $json = json_decode((string)file_get_contents('php://input'), true);
            $count = (int)redis()->incr("mock:notify:count:{$what}:{$id}");
            if ($what === 'slack' && str_starts_with($id, 'fail429') && $count === 1) {
                http_response_code(429);
                header('Retry-After: 1');
                echo 'rate_limited';
                return true;
            }
            if ($what === 'slack' && str_starts_with($id, 'gone')) {
                http_response_code(404);
                echo 'no_service';
                return true;
            }
            if ($what === 'telegram' && (string)($json['chat_id'] ?? '') === 'forbidden') {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the channel chat']);
                return true;
            }
            redis()->rPush('mock:notify', json_encode(['service' => $what, 'id' => $id, 'body' => $json, 'count' => $count], JSON_UNESCAPED_UNICODE));
            if ($what === 'telegram') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'result' => ['message_id' => $count]]);
            } elseif ($what === 'teams') {
                http_response_code(202);
            } else {
                echo 'ok';
            }
            return true;
        case 'notify':
            // GET /mock/notify — received messenger messages; DELETE clears
            if ($method === 'DELETE') {
                redis()->del('mock:notify');
            }
            ok(array_map(static fn ($m) => json_decode((string)$m, true), redis()->lRange('mock:notify', 0, -1) ?: []));
            return true;
        case 'echo':
            $body = (string)file_get_contents('php://input');
            $count = (int)redis()->incr("mock:echo:count:{$key}");
            $request = ['method' => $method, 'path' => '/' . implode('/', $segments), 'query' => $_GET, 'headers' => $headers,
                'body' => $body, 'json' => json_decode($body, true), 'count' => $count];
            redis()->set("mock:echo:last:{$key}", json_encode($request, JSON_UNESCAPED_UNICODE));
            if (isset($_GET['delay_ms'])) {
                usleep(min(30000, (int)$_GET['delay_ms']) * 1000);
            }
            http_response_code((int)($_GET['status'] ?? 200));
            header('Content-Type: application/json');
            echo json_encode(['data' => $request + ['order_id' => 'A-' . (1000 + $count)]], JSON_UNESCAPED_UNICODE);
            return true;
        case 'echo-last':
            $last = redis()->get("mock:echo:last:{$key}");
            ok(['last' => $last ? json_decode((string)$last, true) : null, 'count' => (int)redis()->get("mock:echo:count:{$key}")]);
            return true;
        case 'status':
            $code = (int)($segments[2] ?? 500);
            redis()->incr("mock:echo:count:{$key}");
            if (isset($_GET['retry_after'])) {
                header('Retry-After: ' . (int)$_GET['retry_after']);
            }
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode(['status' => $code]);
            return true;
        case 'redirect':
            http_response_code((int)($_GET['code'] ?? 302));
            header('Location: ' . (string)($_GET['to'] ?? '/mock/echo'));
            return true;
        case 'big':
            header('Content-Type: text/plain');
            echo str_repeat('x', max(0, min(20000000, (int)($_GET['bytes'] ?? 1000))));
            return true;
        case 'text':
            $charset = (string)($_GET['charset'] ?? 'utf-8');
            header("Content-Type: text/plain; charset={$charset}");
            echo mb_convert_encoding('Привет', $charset, 'UTF-8');
            return true;
        case 'oauth':
            $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            parse_str((string)file_get_contents('php://input'), $form);
            $client = str_starts_with($auth, 'Basic ') ? explode(':', (string)base64_decode(substr($auth, 6)), 2) : [$form['client_id'] ?? '', $form['client_secret'] ?? ''];
            if (($client[0] ?? '') !== 'wps' || ($client[1] ?? '') !== 'wps-secret' || ($form['grant_type'] ?? '') !== 'client_credentials') {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'invalid_client']);
                return true;
            }
            $n = (int)redis()->incr('mock:oauth:issued');
            header('Content-Type: application/json');
            echo json_encode(['access_token' => "tok-{$n}", 'token_type' => 'Bearer', 'expires_in' => 3600]);
            return true;
        case 'protected':
            $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            $ok = str_starts_with($auth, 'Bearer tok-');
            http_response_code($ok ? 200 : 401);
            header('Content-Type: application/json');
            echo json_encode($ok ? ['data' => ['token' => substr($auth, 7), 'issued' => (int)redis()->get('mock:oauth:issued')]] : ['error' => 'unauthorized']);
            return true;
    }
    return false;
}

function crmError(int $status, string $title): void
{
    respond($status, ['errors' => [['status' => (string)$status, 'title' => $title]]]);
}

/**
 * wps_origin of the events caused by this request (headers X-WPS-*).
 *
 * @return array<string, mixed>|null
 */
function requestOrigin(): ?array
{
    $executionId = (string)($_SERVER['HTTP_X_WPS_EXECUTION_ID'] ?? '');
    if ($executionId === '') {
        return null;
    }
    $processId = (string)($_SERVER['HTTP_X_WPS_PROCESS_ID'] ?? '');
    return ['execution_id' => $executionId, 'process_id' => ctype_digit($processId) ? (int)$processId : null,
        'depth' => (int)($_SERVER['HTTP_X_WPS_DEPTH'] ?? 0)];
}

/**
 * Filter of the query string: filter[f]=v (eq) or filter[f][op]=v.
 *
 * @return list<array{field: string, op: string, value: mixed}>
 */
function parseFilter(array $filter): array
{
    $ops = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'nin', 'null', 'notnull'];
    $conditions = [];
    foreach ($filter as $field => $spec) {
        if (!is_array($spec)) {
            $conditions[] = ['field' => (string)$field, 'op' => 'eq', 'value' => (string)$spec];
            continue;
        }
        foreach ($spec as $op => $value) {
            if (!in_array($op, $ops, true)) {
                throw new InvalidArgumentException("Unknown filter operator \"{$op}\"");
            }
            $conditions[] = ['field' => (string)$field, 'op' => (string)$op, 'value' => in_array($op, ['in', 'nin'], true) ? (array)$value : (is_array($value) ? '' : (string)$value)];
        }
    }
    return $conditions;
}

/**
 * @param list<string> $route segments after /api/v9: {module}[/{id}[/relationships/{link}[/{related_id}]]] | capabilities
 */
function crmApi(string $method, array $route, MockCrmStorage $storage, MockEventService $events): void
{
    $config = mockConfig();
    $legacy = $config['contract'] === '1.0';
    if ($route === ['capabilities']) {
        if ($legacy) {
            crmError(404, 'Route not found');
            return;
        }
        respond(200, ['data' => [
            'contract_version' => '2.0',
            'crm' => ['name' => 'WPS mock CRM', 'version' => '2.0', 'plugin_version' => 'mock'],
            'features' => ['filter_operators', 'filter_nin', 'sort', 'cursor_pagination', 'fields', 'count', 'delete', 'relationships',
                'relationship_fields', 'event_v2', 'delete_events', 'origin_headers'],
            'limits' => ['max_page_size' => 100, 'max_link_batch' => 100, 'max_in_values' => 100],
        ]]);
        return;
    }
    $module = $route[0];
    $id = $route[1] ?? null;
    if ($id !== null && str_starts_with($id, 'fail500')) {
        crmError(500, 'Simulated CRM failure');
        return;
    }
    if ($id !== null && preg_match('/^fail(\d+)x500/', $id, $failN)) {
        // "fail2x500-<key>": the first N requests for this id fail with 500, then the request is served normally
        $counter = sys_get_temp_dir() . '/mock_fail_' . md5($id);
        $seen = (int)@file_get_contents($counter);
        if ($seen < (int)$failN[1]) {
            file_put_contents($counter, (string)($seen + 1));
            crmError(500, 'Simulated CRM failure ' . ($seen + 1) . ' of ' . $failN[1]);
            return;
        }
    }
    if ($id !== null && str_starts_with($id, 'slow')) {
        sleep(3);
    }
    $publish = $config['publish_on_write'];

    if (count($route) >= 4) {
        if ($legacy || $route[2] !== 'relationships') {
            crmError(404, 'Route not found');
            return;
        }
        if ($storage->get($module, (string)$id) === null) {
            crmError(404, "{$module} {$id} not found");
            return;
        }
        $link = $route[3];
        if ($method === 'GET' && !isset($route[4])) {
            $data = [];
            foreach ($storage->relations($module, (string)$id, $link) as $rid => $rel) {
                $object = $storage->get($rel['related_module'], (string)$rid) ?? ['id' => (string)$rid];
                $data[] = resource($rel['related_module'], $object) + ['meta' => ['relationship' => (object)$rel['fields']]];
            }
            respond(200, ['data' => $data, 'meta' => ['size' => count($data), 'count' => count($data), 'next_cursor' => null]]);
            return;
        }
        if ($method === 'POST' && !isset($route[4])) {
            $result = ['linked' => [], 'updated' => [], 'not_found' => []];
            foreach ((array)(jsonBody()['data'] ?? []) as $item) {
                $rid = (string)($item['id'] ?? '');
                $relatedModule = (string)($item['type'] ?? '');
                if ($rid === '' || str_starts_with($rid, 'missing')) {
                    $result['not_found'][] = $rid;
                    continue;
                }
                $new = $storage->link($module, (string)$id, $link, $rid, $relatedModule, array_map('strval', (array)($item['meta'] ?? [])));
                $result[$new ? 'linked' : 'updated'][] = $rid;
            }
            respond(200, ['meta' => $result]);
            return;
        }
        if ($method === 'DELETE' && isset($route[4])) {
            respond(200, ['meta' => ['removed' => $storage->unlink($module, (string)$id, $link, $route[4])]]);
            return;
        }
        crmError(405, 'Method not allowed');
        return;
    }

    if ($method === 'GET' && $id !== null) {
        $object = $storage->get($module, $id);
        $object === null ? crmError(404, "{$module} {$id} not found") : respond(200, ['data' => resource($module, $object)]);
        return;
    }
    if ($method === 'GET') {
        $filter = isset($_GET['filter']) && is_array($_GET['filter']) ? $_GET['filter'] : [];
        if ($legacy) {
            // an old plugin: equality, the first key only, no cursor
            $size = max(1, min(100, (int)($_GET['size'] ?? 20)));
            $field = (string)array_key_first($filter);
            $items = $field !== '' && !is_array($filter[$field]) ? $storage->findBy($module, $field, (string)$filter[$field], $size) : [];
            respond(200, ['data' => array_map(static fn (array $o) => resource($module, $o), $items), 'meta' => ['size' => $size, 'count' => count($items)]]);
            return;
        }
        try {
            $conditions = parseFilter($filter);
            $sort = [];
            foreach (array_filter(explode(',', (string)($_GET['sort'] ?? ''))) as $part) {
                $sort[] = ['field' => ltrim($part, '-'), 'desc' => str_starts_with($part, '-')];
            }
            if (count($sort) > 2) {
                throw new InvalidArgumentException('Sort by up to 2 fields');
            }
            $fields = isset($_GET['fields']) && $_GET['fields'] !== '' ? explode(',', (string)$_GET['fields']) : null;
            $size = max(1, min(100, (int)($_GET['page']['size'] ?? $_GET['size'] ?? 20)));
            $page = $storage->find($module, $conditions, $sort, $fields, $size, isset($_GET['page']['after']) ? (string)$_GET['page']['after'] : null);
        } catch (InvalidArgumentException $e) {
            crmError(422, $e->getMessage());
            return;
        }
        $meta = ['size' => $size, 'count' => count($page['items']), 'next_cursor' => $page['next_cursor']];
        if (!empty($_GET['count'])) {
            $meta['total'] = $page['total'];
        }
        respond(200, ['data' => array_map(static fn (array $o) => resource($module, $o), $page['items']), 'meta' => $meta]);
        return;
    }
    if ($method === 'PUT' && $id !== null) {
        $current = $storage->get($module, $id);
        if ($current === null) {
            crmError(404, "{$module} {$id} not found");
            return;
        }
        $attributes = (array)(jsonBody()['data']['attributes'] ?? []);
        $updated = array_merge($current, $attributes, ['id' => $id, 'updated_at' => gmdate('c')]);
        $storage->put($module, $updated);
        if ($publish) {
            $before = [];
            foreach ($attributes as $field => $value) {
                if ((string)($current[$field] ?? '') !== (string)$value) {
                    $before[$field] = isset($current[$field]) ? (string)$current[$field] : null;
                }
            }
            $events->publishWrite($module, 'update', $updated, $before, requestOrigin());
        }
        respond(200, ['data' => resource($module, $updated)]);
        return;
    }
    if ($method === 'POST' && $id === null) {
        $attributes = (array)(jsonBody()['data']['attributes'] ?? []);
        $object = ['id' => (string)random_int(100000, 999999)] + $attributes + ['created_at' => gmdate('c')];
        $storage->put($module, $object);
        if ($publish) {
            $events->publishWrite($module, 'create', $object, [], requestOrigin());
        }
        respond(201, ['data' => resource($module, $object)]);
        return;
    }
    if ($method === 'DELETE' && $id !== null && !$legacy) {
        $current = $storage->get($module, $id);
        if ($current === null) {
            crmError(404, "{$module} {$id} not found");
            return;
        }
        $storage->delete($module, $id);
        if ($publish) {
            $events->publishWrite($module, 'delete', ['deleted' => '1'] + $current, [], requestOrigin());
        }
        respond(200, ['meta' => ['deleted' => true]]);
        return;
    }
    crmError(405, 'Method not allowed');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));

try {
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: *');
        http_response_code(204);
        return;
    }

    $storage = new MockCrmStorage(redis());
    $events = new MockEventService(redis(), $storage, env('MOCK_REDIS_STREAM', 'crm_events'));

    if ($path === '/health') {
        $redisOk = false;
        try {
            $redisOk = (bool)redis()->ping();
        } catch (Throwable) {
        }
        ok(['status' => $redisOk ? 'ok' : 'degraded', 'redis' => $redisOk ? 'connected' : 'unavailable',
            'stream' => env('MOCK_REDIS_STREAM', 'crm_events')], $redisOk ? 200 : 503);
        return;
    }

    if ($path === '/test-event' && in_array($method, ['GET', 'POST'], true)) {
        $module = isset($_GET['module']) && is_string($_GET['module']) && $_GET['module'] !== '' ? $_GET['module'] : null;
        if ($module !== null && !MockModulesList::exists($module)) {
            fail(422, 'UNKNOWN_MODULE', "Unknown module {$module}");
            return;
        }
        $overrides = isset($_GET['set']) && is_array($_GET['set']) ? $_GET['set'] : [];
        if ($method === 'POST') {
            $overrides = array_merge($overrides, jsonBody());
        }
        $count = max(1, min(100, (int)($_GET['count'] ?? 1)));
        $eventType = isset($_GET['event_type']) && in_array($_GET['event_type'], ['create', 'update', 'delete'], true) ? $_GET['event_type'] : null;
        $before = isset($_GET['before']) && is_array($_GET['before']) ? $_GET['before'] : [];
        $sent = [];
        for ($i = 0; $i < $count; $i++) {
            $sent[] = $events->sendTestEvent($module, $overrides, $eventType, $before);
        }
        ok($count === 1 ? $sent[0] : $sent);
        return;
    }

    if (in_array($path, ['/modules', '/api/dictionaries/modules', '/api/v8/modules', '/api/v9/modules'], true) && $method === 'GET') {
        ok(MockModulesList::dictionary());
        return;
    }
    if ($path === '/api/dictionaries/operators' && $method === 'GET') {
        ok(operators());
        return;
    }
    if ($path === '/api/dictionaries/action-types' && $method === 'GET') {
        ok(actionTypes());
        return;
    }

    // ---- test helpers
    if (($segments[0] ?? '') === 'mock' && outboundTestEndpoint($method, $segments)) {
        return;
    }
    if (($segments[0] ?? '') === 'mock' && ($segments[1] ?? '') === 'objects' && count($segments) === 4) {
        [, , $module, $id] = $segments;
        if ($method === 'PUT') {
            $object = ['id' => $id] + jsonBody();
            $storage->put($module, $object);
            ok($object);
            return;
        }
        $object = $storage->get($module, $id);
        $object === null ? fail(404, 'NOT_FOUND', 'Object not found') : ok($object);
        return;
    }

    if ($path === '/mock/config') {
        if ($method === 'PUT') {
            $body = jsonBody();
            if (isset($body['contract'])) {
                redis()->hSet('mock_config', 'contract', in_array($body['contract'], ['1.0', '2.0'], true) ? $body['contract'] : '2.0');
            }
            if (isset($body['publish_on_write'])) {
                redis()->hSet('mock_config', 'publish_on_write', $body['publish_on_write'] ? '1' : '0');
            }
        }
        ok(mockConfig());
        return;
    }
    if (($segments[0] ?? '') === 'mock' && ($segments[1] ?? '') === 'relationships' && in_array(count($segments), [5, 6], true)) {
        [, , $module, $id, $link] = $segments;
        if ($method === 'PUT' && isset($segments[5])) {
            $body = jsonBody();
            $storage->link($module, $id, $link, $segments[5], (string)($body['related_module'] ?? ''), array_map('strval', (array)($body['fields'] ?? [])));
        }
        ok($storage->relations($module, $id, $link));
        return;
    }

    // ---- CRM API of the WPS contract (v8 = v9)
    if (($segments[0] ?? '') === 'api' && in_array($segments[1] ?? '', ['v8', 'v9'], true) && isset($segments[2])) {
        if (!crmAuthorized()) {
            respond(401, ['errors' => [['status' => '401', 'title' => 'Invalid X-Auth-Key']]]);
            return;
        }
        crmApi($method, array_slice($segments, 2), $storage, $events);
        return;
    }

    fail(404, 'NOT_FOUND', 'Route not found');
} catch (Throwable $e) {
    fail(500, 'INTERNAL_ERROR', $e->getMessage());
}
