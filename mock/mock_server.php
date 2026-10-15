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
 *   CRM API v8 (X-Auth-Key = MOCK_CRM_STATIC_KEY . gmdate("Y.m.d H")):
 *   GET  /api/v8/modules
 *   GET  /api/v8/{module}/{id}           id "fail500..." → HTTP 500, "slow..." → 3 s delay (for retry tests)
 *   GET  /api/v8/{module}?filter[field]=value&size=1
 *   PUT  /api/v8/{module}/{id}           {data:{type,id,attributes}}
 *   POST /api/v8/{module}                {data:{type,attributes}}
 *   Test helpers (no auth): GET|PUT /mock/objects/{module}/{id}
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
        $sent = [];
        for ($i = 0; $i < $count; $i++) {
            $sent[] = $events->sendTestEvent($module, $overrides);
        }
        ok($count === 1 ? $sent[0] : $sent);
        return;
    }

    if (in_array($path, ['/modules', '/api/dictionaries/modules', '/api/v8/modules'], true) && $method === 'GET') {
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

    // ---- CRM API v8
    if (($segments[0] ?? '') === 'api' && ($segments[1] ?? '') === 'v8' && isset($segments[2])) {
        if (!crmAuthorized()) {
            respond(401, ['errors' => [['status' => '401', 'title' => 'Invalid X-Auth-Key']]]);
            return;
        }
        $module = $segments[2];
        $id = $segments[3] ?? null;

        if ($id !== null && str_starts_with($id, 'fail500')) {
            respond(500, ['errors' => [['status' => '500', 'title' => 'Simulated CRM failure']]]);
            return;
        }
        if ($id !== null && str_starts_with($id, 'slow')) {
            sleep(3);
        }

        if ($method === 'GET' && $id !== null) {
            $object = $storage->get($module, $id);
            if ($object === null) {
                respond(404, ['errors' => [['status' => '404', 'title' => "{$module} {$id} not found"]]]);
                return;
            }
            respond(200, ['data' => resource($module, $object)]);
            return;
        }
        if ($method === 'GET') {
            $filter = isset($_GET['filter']) && is_array($_GET['filter']) ? $_GET['filter'] : [];
            $size = max(1, min(100, (int)($_GET['size'] ?? 20)));
            $field = (string)array_key_first($filter);
            $items = $field !== '' ? $storage->findBy($module, $field, (string)$filter[$field], $size) : [];
            respond(200, ['data' => array_map(static fn (array $o) => resource($module, $o), $items)]);
            return;
        }
        if ($method === 'PUT' && $id !== null) {
            $current = $storage->get($module, $id);
            if ($current === null) {
                respond(404, ['errors' => [['status' => '404', 'title' => "{$module} {$id} not found"]]]);
                return;
            }
            $attributes = (array)(jsonBody()['data']['attributes'] ?? []);
            $updated = array_merge($current, $attributes, ['id' => $id, 'updated_at' => gmdate('c')]);
            $storage->put($module, $updated);
            respond(200, ['data' => resource($module, $updated)]);
            return;
        }
        if ($method === 'POST' && $id === null) {
            $attributes = (array)(jsonBody()['data']['attributes'] ?? []);
            $object = ['id' => (string)random_int(100000, 999999)] + $attributes + ['created_at' => gmdate('c')];
            $storage->put($module, $object);
            respond(201, ['data' => resource($module, $object)]);
            return;
        }
        respond(405, ['errors' => [['status' => '405', 'title' => 'Method not allowed']]]);
        return;
    }

    fail(404, 'NOT_FOUND', 'Route not found');
} catch (Throwable $e) {
    fail(500, 'INTERNAL_ERROR', $e->getMessage());
}
