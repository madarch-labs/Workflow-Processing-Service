<?php

declare(strict_types=1);

/**
 * WPS API integration tests (doc/concept_apitest_back.md): plain PHP, HTTP requests to a running server.
 *
 *   docker compose up -d --build
 *   docker compose exec backend php tests/IntegrationTest.php
 *
 * Env: API_BASE_URL (default http://localhost:9501), DB_POSTGRES_* / DB_* (cleanup),
 *      REDIS_STREAM_* (stream test), CRM_API_BASE_URL (mock CRM used for seeding objects).
 * Exit code 0 when all tests pass, 1 otherwise.
 */
final class IntegrationTest
{
    private string $baseUrl;
    private string $crmUrl;

    /** @var array{passed: int, failed: int, skipped: int} */
    private array $results = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
    /** @var list<string> */
    private array $failures = [];

    private ?string $adminToken = null;
    private ?string $editorToken = null;
    private ?string $viewerToken = null;
    private ?string $refreshToken = null;

    /** @var array<string, int> */
    private array $createdUsers = [];
    /** @var array<string, int> */
    private array $createdProcesses = [];
    private ?string $executionId = null;
    private string $accountId;

    public function __construct()
    {
        $this->baseUrl = rtrim(self::env('API_BASE_URL', 'http://localhost:9501'), '/');
        $this->crmUrl = rtrim(self::env('CRM_API_BASE_URL', 'http://localhost:9502'), '/');
        $this->accountId = 'test-acc-' . random_int(1000, 9999);
    }

    public function run(): int
    {
        echo "WPS integration tests against {$this->baseUrl}\n\n";
        $this->cleanupTestData();
        $this->testHealth();
        $this->testLogin();
        $this->testUserCrud();
        $this->testDictionaries();
        $this->testProcessCrud();
        $this->testProcessValidation();
        $this->testAuthMiddleware();
        $this->testManualRun();
        $this->testExecutions();
        $this->testStreamFlow();
        $this->testDeleteRules();
        $this->testRunOnce();

        echo sprintf("\nPassed: %d, Failed: %d, Skipped: %d\n", $this->results['passed'], $this->results['failed'], $this->results['skipped']);
        foreach ($this->failures as $failure) {
            echo "  ✗ {$failure}\n";
        }
        return $this->results['failed'] === 0 ? 0 : 1;
    }

    // ------------------------------------------------------------------ tests

    private function cleanupTestData(): void
    {
        echo "[cleanup]\n";
        $this->test('Remove data left by previous runs', function () {
            $pdo = $this->pdo();
            $pdo->exec("DELETE FROM process_object_runs WHERE process_id IN (SELECT id FROM processes WHERE name LIKE 'TEST %')");
            $pdo->exec("DELETE FROM executions WHERE process_id IN (SELECT id FROM processes WHERE name LIKE 'TEST %')");
            $pdo->exec("DELETE FROM processes WHERE name LIKE 'TEST %'");
            $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.local'");
            $this->assertTrue(true);
        });
    }

    private function testHealth(): void
    {
        echo "[health]\n";
        $this->test('GET /api/health is public and healthy', function () {
            $res = $this->request('GET', '/api/health', expectedStatus: 200);
            $this->assertTrue($res['success']);
            $this->assertEquals('ok', $res['data']['status']);
            $this->assertEquals('ok', $res['data']['checks']['postgres']['status']);
        });
        $this->test('GET /metrics exposes Prometheus counters', function () {
            $raw = $this->raw('GET', '/metrics');
            $this->assertEquals(200, $raw['status']);
            $this->assertTrue(str_contains($raw['body'], 'wps_events_received_total'));
        });
    }

    private function testLogin(): void
    {
        echo "[auth]\n";
        $this->test('Login with valid admin credentials', function () {
            $res = $this->request('POST', '/api/auth/login', ['email' => 'admin@wps.local', 'password' => 'admin'], expectedStatus: 200);
            $this->assertTrue($res['success']);
            $this->assertNotNull($res['data']['access_token']);
            $this->assertEquals('admin', $res['data']['user']['role']);
            $this->assertArrayNotHasKey('password_hash', $res['data']['user']);
            $this->adminToken = $res['data']['access_token'];
            $this->refreshToken = $res['data']['refresh_token'];
        });
        $this->test('Login with wrong password returns 401', function () {
            $res = $this->request('POST', '/api/auth/login', ['email' => 'admin@wps.local', 'password' => 'nope'], expectedStatus: 401);
            $this->assertFalse($res['success']);
            $this->assertEquals('INVALID_CREDENTIALS', $res['error']['code']);
        });
        $this->test('Login without fields returns 422', function () {
            $res = $this->request('POST', '/api/auth/login', [], expectedStatus: 422);
            $this->assertEquals('VALIDATION_ERROR', $res['error']['code']);
        });
        $this->test('Refresh token issues a new pair', function () {
            $this->requireValue($this->refreshToken);
            $res = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $this->refreshToken], expectedStatus: 200);
            $this->assertNotNull($res['data']['access_token']);
        });
        $this->test('Access token cannot be used as refresh token', function () {
            $this->requireValue($this->adminToken);
            $res = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $this->adminToken], expectedStatus: 401);
            $this->assertEquals('INVALID_TOKEN', $res['error']['code']);
        });
        $this->test('GET /api/auth/me returns current user with valid token', function () {
            $res = $this->request('GET', '/api/auth/me', token: $this->adminToken, expectedStatus: 200);
            $this->assertEquals('admin@wps.local', $res['data']['email']);
        });
    }

    private function testUserCrud(): void
    {
        echo "[users]\n";
        foreach (['editor', 'viewer'] as $role) {
            $this->test("Create user ({$role})", function () use ($role) {
                $res = $this->request('POST', '/api/users', [
                    'email' => "{$role}@test.local", 'password' => 'secret123', 'role' => $role,
                ], $this->adminToken, 201);
                $this->assertNotNull($res['data']['id']);
                $this->assertArrayNotHasKey('password_hash', $res['data']);
                $this->createdUsers[$role] = $res['data']['id'];
                $login = $this->request('POST', '/api/auth/login', ['email' => "{$role}@test.local", 'password' => 'secret123'], expectedStatus: 200);
                if ($role === 'editor') {
                    $this->editorToken = $login['data']['access_token'];
                } else {
                    $this->viewerToken = $login['data']['access_token'];
                }
            });
        }
        $this->test('Duplicate email returns 409', function () {
            $res = $this->request('POST', '/api/users', ['email' => 'editor@test.local', 'password' => 'secret123', 'role' => 'viewer'], $this->adminToken, 409);
            $this->assertEquals('EMAIL_EXISTS', $res['error']['code']);
        });
        $this->test('Invalid role returns 422', function () {
            $res = $this->request('POST', '/api/users', ['email' => 'x@test.local', 'password' => 'secret123', 'role' => 'root'], $this->adminToken, 422);
            $this->assertEquals('VALIDATION_ERROR', $res['error']['code']);
        });
        $this->test('List and get users', function () {
            $res = $this->request('GET', '/api/users', token: $this->adminToken, expectedStatus: 200);
            $this->assertIsArray($res['data']);
            $this->assertTrue($res['meta']['total'] >= 3);
            $id = $this->createdUsers['viewer'] ?? $this->skip('viewer not created');
            $one = $this->request('GET', "/api/users/{$id}", token: $this->adminToken, expectedStatus: 200);
            $this->assertEquals('viewer', $one['data']['role']);
        });
        $this->test('Update user (temporary user, then delete)', function () {
            $res = $this->request('POST', '/api/users', ['email' => 'temp@test.local', 'password' => 'secret123', 'role' => 'viewer'], $this->adminToken, 201);
            $id = $res['data']['id'];
            $upd = $this->request('PUT', "/api/users/{$id}", ['role' => 'editor', 'password' => ''], $this->adminToken, 200);
            $this->assertEquals('editor', $upd['data']['role']);
            $this->request('DELETE', "/api/users/{$id}", token: $this->adminToken, expectedStatus: 200);
            $this->request('GET', "/api/users/{$id}", token: $this->adminToken, expectedStatus: 404);
        });
        $this->test('Admin cannot delete own account', function () {
            $me = $this->request('GET', '/api/auth/me', token: $this->adminToken, expectedStatus: 200);
            $res = $this->request('DELETE', "/api/users/{$me['data']['id']}", token: $this->adminToken, expectedStatus: 409);
            $this->assertEquals('CANNOT_DELETE_SELF', $res['error']['code']);
        });
    }

    private function testDictionaries(): void
    {
        echo "[dictionaries]\n";
        foreach (['modules', 'operators', 'action-types', 'change-types', 'preload-sources'] as $dict) {
            $this->test("GET /api/dictionaries/{$dict}", function () use ($dict) {
                $res = $this->request('GET', "/api/dictionaries/{$dict}", token: $this->viewerToken ?? $this->adminToken, expectedStatus: 200);
                $this->assertIsArray($res['data']);
                $this->assertTrue(count($res['data']) > 0, "{$dict} is empty");
                $this->assertArrayHasKey('value', $res['data'][0]);
                $this->assertArrayHasKey('label', $res['data'][0]);
            });
        }
        $this->test('Operators contain the four comparison types', function () {
            $res = $this->request('GET', '/api/dictionaries/operators', token: $this->adminToken, expectedStatus: 200);
            $values = array_column($res['data'], 'value');
            sort($values);
            $this->assertEquals(['contains', 'equals', 'not_contains', 'not_equals'], $values);
        });
    }

    private function testProcessCrud(): void
    {
        echo "[processes]\n";
        $this->test('Create child process', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST child mark account',
                'object_type' => 'Accounts',
                'is_child' => true,
                'definition' => ['actions' => [[
                    'type' => 'modify', 'target' => 'v1.description', 'change_type' => 'calculate',
                    'value' => "concat('Deal: ', object.name)", 'save_to_crm' => true,
                ]]],
            ], $this->editorToken, 201);
            $this->assertTrue($res['data']['is_child']);
            $this->assertEquals(1, $res['data']['version']);
            $this->createdProcesses['child'] = $res['data']['id'];
        });
        $this->test('Create top-level process with lazy preload and child start', function () {
            $childId = $this->createdProcesses['child'] ?? $this->skip('child process not created');
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST big deal',
                'object_type' => 'TestDeals',
                'is_child' => false,
                'priority' => 5,
                'definition' => [
                    'conditions' => [
                        ['field' => 'object.status', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'Active'],
                        ['field' => 'v1.status', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'Active'],
                        ['field' => 'object.name', 'operator' => 'contains', 'value_type' => 'static', 'value' => 'Deal'],
                    ],
                    'preload' => [['var' => 'v1', 'source' => 'object_field', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'object.account_id']],
                    'actions' => [
                        ['type' => 'modify', 'target' => 'object.discount', 'change_type' => 'calculate', 'value' => 'object.amount * 0.9'],
                        ['type' => 'modify', 'target' => 'v1.rating', 'change_type' => 'set_value', 'value' => 'VIP', 'save_to_crm' => false],
                        ['type' => 'modify', 'target' => 'v1.phone', 'change_type' => 'set_from_variable', 'value' => 'object.phone'],
                        ['type' => 'save_object', 'target' => 'v1'],
                        ['type' => 'start_process', 'process_id' => $childId],
                    ],
                ],
            ], $this->adminToken, 201);
            $this->createdProcesses['top'] = $res['data']['id'];
            $this->assertEquals('TestDeals', $res['data']['object_type']);
        });
        $this->test('Get process with references', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $res = $this->request('GET', "/api/processes/{$id}", token: $this->viewerToken, expectedStatus: 200);
            $this->assertEquals('TEST big deal', $res['data']['name']);
            $this->assertEquals(3, count($res['data']['definition']['conditions']));
            $this->assertEquals($this->createdProcesses['child'], $res['data']['references']['children'][0]['id']);
        });
        $this->test('List processes with filters', function () {
            $res = $this->request('GET', '/api/processes?search=TEST&is_child=true', token: $this->viewerToken, expectedStatus: 200);
            $this->assertTrue($res['meta']['total'] >= 1);
            foreach ($res['data'] as $p) {
                $this->assertTrue($p['is_child']);
            }
        });
        $this->test('Update process increments version', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $current = $this->request('GET', "/api/processes/{$id}", token: $this->adminToken, expectedStatus: 200)['data'];
            $current['priority'] = 3;
            $res = $this->request('PUT', "/api/processes/{$id}", $current, $this->editorToken, 200);
            $this->assertEquals(2, $res['data']['version']);
            $this->assertEquals(3, $res['data']['priority']);
        });
        $this->test('Clone process creates an inactive copy', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $res = $this->request('POST', "/api/processes/{$id}/clone", token: $this->editorToken, expectedStatus: 201);
            $this->assertFalse($res['data']['is_active']);
            $this->assertTrue(str_contains($res['data']['name'], 'TEST big deal'));
            $this->createdProcesses['clone'] = $res['data']['id'];
        });
        $this->test('Get non-existent process returns 404', function () {
            $res = $this->request('GET', '/api/processes/999999', token: $this->adminToken, expectedStatus: 404);
            $this->assertEquals('PROCESS_NOT_FOUND', $res['error']['code']);
        });
    }

    private function testProcessValidation(): void
    {
        echo "[validation]\n";
        $this->test('Missing name/object_type returns 422 with details', function () {
            $res = $this->request('POST', '/api/processes', ['definition' => []], $this->adminToken, 422);
            $fields = array_column($res['error']['details'], 'field');
            $this->assertTrue(in_array('name', $fields, true));
            $this->assertTrue(in_array('object_type', $fields, true));
        });
        $this->test('Unknown operator, undefined variable and bad expression are rejected', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST invalid', 'object_type' => 'Accounts', 'is_child' => false,
                'definition' => [
                    'conditions' => [['field' => 'v5.x', 'operator' => 'greater', 'value_type' => 'static', 'value' => '1']],
                    'actions' => [['type' => 'modify', 'target' => 'object.a', 'change_type' => 'calculate', 'value' => '1 +']],
                ],
            ], $this->adminToken, 422);
            $fields = array_column($res['error']['details'], 'field');
            $this->assertTrue(in_array('definition.conditions[0].operator', $fields, true));
            $this->assertTrue(in_array('definition.conditions[0].field', $fields, true));
            $this->assertTrue(in_array('definition.actions[0].value', $fields, true));
        });
        $this->test('start_process must reference a child process', function () {
            $top = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST starts top', 'object_type' => 'Accounts', 'is_child' => false,
                'definition' => ['actions' => [['type' => 'start_process', 'process_id' => $top]]],
            ], $this->adminToken, 422);
            $this->assertEquals('VALIDATION_ERROR', $res['error']['code']);
        });
        $this->test('Cycle A → B → A is detected', function () {
            $a = $this->request('POST', '/api/processes', ['name' => 'TEST cycle A', 'object_type' => 'Accounts', 'is_child' => true, 'definition' => []], $this->adminToken, 201)['data'];
            $b = $this->request('POST', '/api/processes', [
                'name' => 'TEST cycle B', 'object_type' => 'Accounts', 'is_child' => true,
                'definition' => ['actions' => [['type' => 'start_process', 'process_id' => $a['id']]]],
            ], $this->adminToken, 201)['data'];
            $a['definition'] = ['actions' => [['type' => 'start_process', 'process_id' => $b['id']]]];
            $res = $this->request('PUT', "/api/processes/{$a['id']}", $a, $this->adminToken, 422);
            $this->assertEquals('CYCLE_DETECTED', $res['error']['code']);
            $this->assertTrue(count($res['error']['details']['path']) >= 3);
        });
    }

    private function testAuthMiddleware(): void
    {
        echo "[rbac]\n";
        $this->test('Access without token returns 401', function () {
            $res = $this->request('GET', '/api/processes', expectedStatus: 401);
            $this->assertEquals('UNAUTHORIZED', $res['error']['code']);
        });
        $this->test('Access with invalid token returns 401', function () {
            $this->request('GET', '/api/processes', token: 'abc.def.ghi', expectedStatus: 401);
        });
        $this->test('Viewer cannot create a process (403)', function () {
            $res = $this->request('POST', '/api/processes', ['name' => 'TEST x', 'object_type' => 'Accounts', 'is_child' => false], $this->viewerToken, 403);
            $this->assertEquals('FORBIDDEN', $res['error']['code']);
        });
        $this->test('Viewer cannot run a process (403)', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => '1']], $this->viewerToken, 403);
        });
        $this->test('Editor cannot delete a process (403, delete is admin-only)', function () {
            $id = $this->createdProcesses['clone'] ?? $this->skip('clone not created');
            $this->request('DELETE', "/api/processes/{$id}", token: $this->editorToken, expectedStatus: 403);
        });
        $this->test('Editor cannot manage users (403)', function () {
            $this->request('GET', '/api/users', token: $this->editorToken, expectedStatus: 403);
        });
        $this->test('Viewer can read executions journal', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $this->request('GET', "/api/processes/{$id}/executions", token: $this->viewerToken, expectedStatus: 200);
        });
    }

    private function testManualRun(): void
    {
        echo "[manual run]\n";
        $this->seedAccount();
        $this->test('Manual run with unmet conditions returns the condition trace', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $res = $this->request('POST', "/api/processes/{$id}/run", ['object' => [
                'id' => 'd1', 'module' => 'TestDeals', 'status' => 'Closed', 'name' => 'Deal 1', 'account_id' => $this->accountId,
            ]], $this->editorToken, 200);
            $this->assertFalse($res['data']['conditions_met']);
            $this->assertEquals(null, $res['data']['execution_id']);
            $this->assertFalse($res['data']['conditions'][0]['result']);
        });
        $this->test('Manual run with met conditions starts an execution', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $res = $this->request('POST', "/api/processes/{$id}/run", ['object' => [
                'id' => 'd2', 'module' => 'TestDeals', 'status' => 'Active', 'name' => 'Deal 2', 'amount' => '1000',
                'phone' => '+70000000001', 'account_id' => $this->accountId,
            ]], $this->editorToken, 201);
            $this->assertTrue($res['data']['conditions_met']);
            $this->assertTrue((bool)preg_match('/^[0-9a-f-]{36}$/', (string)$res['data']['execution_id']));
            $this->executionId = $res['data']['execution_id'];
        });
        $this->test('Invalid run payload returns 422', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $this->request('POST', "/api/processes/{$id}/run", ['object' => ['name' => 'no id']], $this->editorToken, 422);
        });
    }

    private function testExecutions(): void
    {
        echo "[executions]\n";
        $this->test('Execution completes with all steps and saves to CRM', function () {
            $id = $this->executionId ?? $this->skip('no execution');
            $execution = $this->waitForExecution($id);
            $this->assertEquals('completed', $execution['status'], (string)($execution['error_message'] ?? ''));
            $types = array_column($execution['actions'], 'action_type');
            $this->assertEquals(['preload', 'modify', 'modify', 'modify', 'save_object', 'start_process'], $types);
            $this->assertEquals(900, $execution['context']['variables']['object']['discount']);
            $account = $this->crmObject('Accounts', $this->accountId);
            $this->assertEquals('VIP', $account['rating']);
            $this->assertEquals('+70000000001', $account['phone']);
        });
        $this->test('Child execution is linked and updates the account', function () {
            $id = $this->executionId ?? $this->skip('no execution');
            $deadline = microtime(true) + 10;
            do {
                $execution = $this->request('GET', "/api/executions/{$id}", token: $this->viewerToken, expectedStatus: 200)['data'];
                $done = isset($execution['children'][0]) && in_array($execution['children'][0]['status'], ['completed', 'failed'], true);
                if (!$done) {
                    usleep(300000);
                }
            } while (!$done && microtime(true) < $deadline);
            $this->assertEquals('child', $execution['children'][0]['trigger'] ?? null);
            $this->assertEquals('completed', $execution['children'][0]['status'] ?? null);
            $this->assertEquals('Deal: Deal 2', $this->crmObject('Accounts', $this->accountId)['description']);
        });
        $this->test('Get execution actions', function () {
            $id = $this->executionId ?? $this->skip('no execution');
            $res = $this->request('GET', "/api/executions/{$id}/actions", token: $this->viewerToken, expectedStatus: 200);
            $this->assertEquals(6, count($res['data']));
            $this->assertEquals('success', $res['data'][0]['status']);
        });
        $this->test('Executions journal filters by status', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            $res = $this->request('GET', "/api/processes/{$id}/executions?status=completed", token: $this->viewerToken, expectedStatus: 200);
            $this->assertTrue($res['meta']['total'] >= 1);
            $none = $this->request('GET', "/api/processes/{$id}/executions?status=failed", token: $this->viewerToken, expectedStatus: 200);
            $this->assertEquals(0, $none['meta']['total']);
        });
        $this->test('Get execution actions for non-existent execution returns 404', function () {
            $this->request('GET', '/api/executions/00000000-0000-4000-8000-000000000000/actions', token: $this->adminToken, expectedStatus: 404);
            $this->request('GET', '/api/executions/not-a-uuid', token: $this->adminToken, expectedStatus: 404);
        });
        $this->test('Failing CRM call is retried and the execution fails', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST retry', 'object_type' => 'TestRetry', 'is_child' => false, 'is_active' => false,
                'definition' => [
                    'preload' => [['var' => 'v1', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'fail500']],
                    'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
                ],
            ], $this->adminToken, 201);
            $run = $this->request('POST', "/api/processes/{$res['data']['id']}/run", ['object' => ['id' => 'r1']], $this->adminToken, 201);
            $execution = $this->waitForExecution($run['data']['execution_id'], 20);
            $this->assertEquals('failed', $execution['status']);
            $this->assertEquals(3, $execution['retry_count']);
            $this->assertEquals(4, $execution['actions'][0]['attempts']);
            $this->assertEquals('skipped', $execution['actions'][1]['status']);
        });
    }

    private function testStreamFlow(): void
    {
        echo "[stream]\n";
        $this->test('Event from the Redis Stream starts the matching process', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            if (!class_exists(Redis::class)) {
                $this->skip('ext-redis not available');
            }
            $redis = new Redis();
            $redis->connect(self::env('REDIS_STREAM_HOST', '127.0.0.1'), (int)self::env('REDIS_STREAM_PORT', '6379'));
            $objectId = 'stream-' . random_int(1000, 9999);
            $redis->xAdd(self::env('REDIS_STREAM_NAME', 'crm_events'), '*', ['data' => json_encode([
                'id' => $objectId, 'module' => 'TestDeals', 'status' => 'Active', 'name' => 'Deal stream',
                'amount' => '10', 'phone' => '+70000000002', 'account_id' => $this->accountId,
            ])]);
            $deadline = microtime(true) + 15;
            $found = null;
            do {
                usleep(300000);
                $list = $this->request('GET', "/api/processes/{$id}/executions?per_page=50", token: $this->adminToken, expectedStatus: 200)['data'];
                foreach ($list as $e) {
                    if ($e['object_id'] === $objectId && $e['trigger'] === 'stream') {
                        $found = $e;
                    }
                }
            } while ($found === null && microtime(true) < $deadline);
            $this->assertNotNull($found, 'no execution for the stream event');
            $execution = $this->waitForExecution($found['id']);
            $this->assertEquals('completed', $execution['status']);
        });
        $this->test('Event that does not match the conditions creates no execution', function () {
            $id = $this->createdProcesses['top'] ?? $this->skip('top process not created');
            if (!class_exists(Redis::class)) {
                $this->skip('ext-redis not available');
            }
            $redis = new Redis();
            $redis->connect(self::env('REDIS_STREAM_HOST', '127.0.0.1'), (int)self::env('REDIS_STREAM_PORT', '6379'));
            $objectId = 'stream-skip-' . random_int(1000, 9999);
            $redis->xAdd(self::env('REDIS_STREAM_NAME', 'crm_events'), '*', ['data' => json_encode([
                'id' => $objectId, 'module' => 'TestDeals', 'status' => 'Inactive', 'name' => 'Deal', 'account_id' => $this->accountId,
            ])]);
            sleep(3);
            $list = $this->request('GET', "/api/processes/{$id}/executions?per_page=100", token: $this->adminToken, expectedStatus: 200)['data'];
            $this->assertFalse(in_array($objectId, array_column($list, 'object_id'), true));
        });
    }

    private function testDeleteRules(): void
    {
        echo "[delete]\n";
        $this->test('Child process in use cannot be deleted (409)', function () {
            $id = $this->createdProcesses['child'] ?? $this->skip('child not created');
            $res = $this->request('DELETE', "/api/processes/{$id}", token: $this->adminToken, expectedStatus: 409);
            $this->assertEquals('PROCESS_IN_USE', $res['error']['code']);
        });
        $this->test('Admin deletes a process, then GET returns 404', function () {
            $id = $this->createdProcesses['clone'] ?? $this->skip('clone not created');
            $this->request('DELETE', "/api/processes/{$id}", token: $this->adminToken, expectedStatus: 200);
            $this->request('GET', "/api/processes/{$id}", token: $this->adminToken, expectedStatus: 404);
        });
    }

    /**
     * run_once — one start of a process per CRM record (doc/idempotentie_plan.md, section 9).
     */
    private function testRunOnce(): void
    {
        echo "[run_once]\n";
        $simple = static fn (array $conditions = []) => [
            'conditions' => $conditions,
            'actions' => [['type' => 'modify', 'target' => 'object.touched', 'change_type' => 'set_value', 'value' => '1']],
        ];
        $active = [['field' => 'object.status', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'Active']];

        // 1. CRUD
        $this->test('run_once is saved, returned, copied by clone; invalid value → 422', function () use ($simple, $active) {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST once', 'object_type' => 'TestOnce', 'is_child' => false, 'is_active' => true,
                'run_once' => true, 'definition' => $simple($active),
            ], $this->adminToken, 201);
            $this->assertEquals(true, $res['data']['run_once']);
            $this->createdProcesses['once'] = $res['data']['id'];
            $get = $this->request('GET', "/api/processes/{$res['data']['id']}", token: $this->viewerToken, expectedStatus: 200);
            $this->assertEquals(true, $get['data']['run_once']);
            $clone = $this->request('POST', "/api/processes/{$res['data']['id']}/clone", token: $this->editorToken, expectedStatus: 201);
            $this->assertEquals(true, $clone['data']['run_once']);
            $this->assertEquals(0, $this->request('GET', "/api/processes/{$clone['data']['id']}/runs", token: $this->adminToken)['meta']['total']);
            $this->request('DELETE', "/api/processes/{$clone['data']['id']}", token: $this->adminToken, expectedStatus: 200);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST once bad', 'object_type' => 'TestOnce', 'is_child' => false, 'run_once' => 'abc', 'definition' => $simple(),
            ], $this->adminToken, 422);
            $this->assertTrue(in_array('run_once', array_column($bad['error']['details'], 'field'), true));
            $plain = $this->request('POST', '/api/processes', [
                'name' => 'TEST once off', 'object_type' => 'TestOnceOff', 'is_child' => false, 'definition' => $simple($active),
            ], $this->adminToken, 201);
            $this->assertEquals(false, $plain['data']['run_once']);
            $this->createdProcesses['once_off'] = $plain['data']['id'];
        });

        // 2. two events of one record → 1 execution, 1 mark with blocked_count = 1
        $this->test('Two stream events of one record start a run_once process once', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $objectId = 'once-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnce', 'status' => 'Active']);
            $this->waitForExecutions($id, $objectId, 1);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnce', 'status' => 'Active']);
            sleep(3);
            $this->assertEquals(1, count($this->executionsOf($id, $objectId)));
            $runs = $this->request('GET', "/api/processes/{$id}/runs?object_id={$objectId}", token: $this->viewerToken)['data'];
            $this->assertEquals(1, count($runs));
            $this->assertEquals(1, $runs[0]['blocked_count']);
            $this->assertEquals('stream', $runs[0]['trigger']);
            $this->assertEquals('TestOnce', $runs[0]['object_type']);
            $this->assertEquals($this->executionsOf($id, $objectId)[0]['id'], $runs[0]['execution_id']);
            $this->assertNotNull($runs[0]['last_blocked_at']);
        });

        // 3. run_once = false → every event runs
        $this->test('Without run_once two events give two executions and no marks', function () {
            $id = $this->createdProcesses['once_off'] ?? $this->skip('process not created');
            $objectId = 'once-off-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceOff', 'status' => 'Active']);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceOff', 'status' => 'Active']);
            $this->waitForExecutions($id, $objectId, 2);
            $this->assertEquals(0, $this->request('GET', "/api/processes/{$id}/runs", token: $this->adminToken)['meta']['total']);
        });

        // 4. conditions not met → no mark; the next matching event starts the process
        $this->test('Conditions not met leave no mark; a later matching event runs', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $objectId = 'once-cond-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnce', 'status' => 'Draft']);
            sleep(3);
            $this->assertEquals(0, count($this->executionsOf($id, $objectId)));
            $this->assertEquals(0, $this->request('GET', "/api/processes/{$id}/runs?object_id={$objectId}", token: $this->adminToken)['meta']['total']);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnce', 'status' => 'Active']);
            $this->waitForExecutions($id, $objectId, 1);
        });

        // 5. failed execution keeps the record blocked; DELETE of the mark allows a new run
        $this->test('A failed run blocks the record until the mark is reset', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST once fail', 'object_type' => 'TestOnceFail', 'is_child' => false, 'run_once' => true,
                'definition' => [
                    'preload' => [['var' => 'v1', 'source' => 'object_field', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'object.account_id']],
                    'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
                ],
            ], $this->adminToken, 201);
            $id = $res['data']['id'];
            $this->createdProcesses['once_fail'] = $id;
            $objectId = 'once-fail-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceFail', 'account_id' => 'fail500x']);
            $first = $this->waitForExecutions($id, $objectId, 1)[0];
            $this->assertEquals('failed', $this->waitForExecution($first['id'], 20)['status']);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceFail', 'account_id' => $this->accountId]);
            sleep(3);
            $this->assertEquals(1, count($this->executionsOf($id, $objectId)));

            $this->request('DELETE', "/api/processes/{$id}/runs/TestOnceFail/{$objectId}", token: $this->editorToken, expectedStatus: 200);
            $this->request('DELETE', "/api/processes/{$id}/runs/TestOnceFail/{$objectId}", token: $this->editorToken, expectedStatus: 404);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceFail', 'account_id' => $this->accountId]);
            $list = $this->waitForExecutions($id, $objectId, 2);
            $this->assertEquals('completed', $this->waitForExecution($list[0]['id'])['status']);
        });

        // 6. manual run (also with force) of a marked record → run_once_blocked
        $this->test('Manual run of a marked record is blocked, also with force', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $objectId = 'once-manual-' . random_int(10000, 99999);
            $first = $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => $objectId, 'status' => 'Active']], $this->editorToken, 201);
            $this->assertEquals(true, $first['data']['run_once_applied']);
            $this->assertEquals(false, $first['data']['run_once_blocked']);
            foreach ([false, true] as $force) {
                $again = $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => $objectId, 'status' => 'Active'], 'force' => $force], $this->editorToken, 200);
                $this->assertEquals(true, $again['data']['conditions_met']);
                $this->assertEquals(true, $again['data']['run_once_blocked']);
                $this->assertEquals(null, $again['data']['execution_id']);
                $this->assertEquals($first['data']['execution_id'], $again['data']['previous_execution_id']);
            }
            $this->assertEquals(1, count($this->executionsOf($id, $objectId)));
            $runs = $this->request('GET', "/api/processes/{$id}/runs?object_id={$objectId}", token: $this->adminToken)['data'];
            $this->assertEquals('manual', $runs[0]['trigger']);
            $this->assertEquals(2, $runs[0]['blocked_count']);
        });

        // 7. race: parallel starts of one record → exactly one execution
        $this->test('Parallel runs of one record start exactly one execution', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $objectId = 'once-race-' . random_int(10000, 99999);
            $statuses = $this->parallelRuns($id, ['object' => ['id' => $objectId, 'status' => 'Active']], 12);
            $this->assertEquals(1, count(array_filter($statuses, static fn ($s) => $s === 201)), 'statuses: ' . implode(',', $statuses));
            $this->assertEquals(11, count(array_filter($statuses, static fn ($s) => $s === 200)));
            for ($i = 0; $i < 10; $i++) {
                $this->xadd(['id' => $objectId, 'module' => 'TestOnce', 'status' => 'Active']);
            }
            sleep(3);
            $this->assertEquals(1, count($this->executionsOf($id, $objectId)));
            $runs = $this->request('GET', "/api/processes/{$id}/runs?object_id={$objectId}", token: $this->adminToken)['data'];
            $this->assertEquals(21, $runs[0]['blocked_count']);
        });

        // 8. child process with run_once: two parent runs → one child execution (record = parent's object)
        $this->test('Child process with run_once runs once per record of the parent event', function () use ($simple) {
            $child = $this->request('POST', '/api/processes', [
                'name' => 'TEST once child', 'object_type' => 'TestOnceParent', 'is_child' => true, 'run_once' => true, 'definition' => $simple(),
            ], $this->adminToken, 201)['data']['id'];
            $parent = $this->request('POST', '/api/processes', [
                'name' => 'TEST once parent', 'object_type' => 'TestOnceParent', 'is_child' => false,
                'definition' => ['conditions' => [], 'actions' => [['type' => 'start_process', 'process_id' => $child]]],
            ], $this->adminToken, 201)['data']['id'];
            $objectId = 'once-parent-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceParent']);
            $this->xadd(['id' => $objectId, 'module' => 'TestOnceParent']);
            $this->waitForExecutions($parent, $objectId, 2);
            sleep(2);
            $childRuns = $this->executionsOf($child, $objectId);
            $this->assertEquals(1, count($childRuns));
            $this->assertEquals('child', $childRuns[0]['trigger']);
            $runs = $this->request('GET', "/api/processes/{$child}/runs?object_id={$objectId}", token: $this->adminToken)['data'];
            $this->assertEquals('child', $runs[0]['trigger']);
            $this->assertEquals(1, $runs[0]['blocked_count']);
        });

        // 9. RBAC
        $this->test('Viewer lists run marks but cannot reset them (403)', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $res = $this->request('GET', "/api/processes/{$id}/runs?page=1&per_page=5", token: $this->viewerToken, expectedStatus: 200);
            $this->assertTrue($res['meta']['total'] >= 1);
            $this->assertEquals(5, $res['meta']['per_page']);
            $mark = $res['data'][0];
            $this->request('DELETE', "/api/processes/{$id}/runs/{$mark['object_type']}/{$mark['object_id']}", token: $this->viewerToken, expectedStatus: 403);
            $this->request('GET', '/api/processes/999999999/runs', token: $this->viewerToken, expectedStatus: 404);
        });

        // 10. PUT (new version) keeps the marks; switching the flag off ignores them, on again applies them
        $this->test('Process update keeps marks; the flag switched off ignores them', function () use ($simple, $active) {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $objectId = 'once-put-' . random_int(10000, 99999);
            $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => $objectId, 'status' => 'Active']], $this->editorToken, 201);
            $body = ['name' => 'TEST once', 'object_type' => 'TestOnce', 'is_child' => false, 'is_active' => true, 'definition' => $simple($active)];
            $updated = $this->request('PUT', "/api/processes/{$id}", $body + ['run_once' => true], $this->editorToken, 200);
            $this->assertTrue($updated['data']['version'] >= 2);
            $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => $objectId, 'status' => 'Active']], $this->editorToken, 200);
            $this->request('PUT', "/api/processes/{$id}", $body + ['run_once' => false], $this->editorToken, 200);
            $off = $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => $objectId, 'status' => 'Active']], $this->editorToken, 201);
            $this->assertEquals(false, $off['data']['run_once_applied']);
            $this->request('PUT', "/api/processes/{$id}", $body + ['run_once' => true], $this->editorToken, 200);
            $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => $objectId, 'status' => 'Active']], $this->editorToken, 200);
        });

        // 11. manual run without object.id is rejected (422) before run_once is considered
        $this->test('Manual run without object.id is still rejected with 422', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $this->request('POST', "/api/processes/{$id}/run", ['object' => ['status' => 'Active']], $this->editorToken, 422);
        });

        // 12. stream event without id → DLQ, no execution, no mark
        $this->test('Stream event without id goes to the DLQ', function () {
            $id = $this->createdProcesses['once'] ?? $this->skip('process not created');
            $marker = 'no-id-' . random_int(10000, 99999);
            $redis = $this->redis();
            $dlq = self::env('REDIS_STREAM_NAME', 'crm_events') . ':dlq';
            $this->xadd(['module' => 'TestOnce', 'status' => 'Active', 'name' => $marker]);
            sleep(3);
            $found = false;
            foreach ($redis->xRevRange($dlq, '+', '-', 20) ?: [] as $entry) {
                if (str_contains((string)($entry['fields'] ?? ''), $marker)) {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'event without id is not in the DLQ');
            $this->assertEquals(0, count($this->executionsOf($id, '')));
        });

        $this->test('Metrics expose the run_once counters', function () {
            $body = $this->raw('GET', '/metrics')['body'];
            $this->assertTrue(str_contains($body, 'wps_run_once_skipped_total'));
            $this->assertTrue(str_contains($body, 'wps_run_once_no_id_total'));
        });
    }

    // ------------------------------------------------------------------ run_once helpers

    private function redis(): Redis
    {
        if (!class_exists(Redis::class)) {
            $this->skip('ext-redis not available');
        }
        $redis = new Redis();
        $redis->connect(self::env('REDIS_STREAM_HOST', '127.0.0.1'), (int)self::env('REDIS_STREAM_PORT', '6379'));
        return $redis;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function xadd(array $event): void
    {
        $this->redis()->xAdd(self::env('REDIS_STREAM_NAME', 'crm_events'), '*', ['data' => json_encode($event)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executionsOf(int $processId, string $objectId): array
    {
        $list = $this->request('GET', "/api/processes/{$processId}/executions?per_page=200", token: $this->adminToken)['data'];
        return array_values(array_filter($list, static fn (array $e) => $e['object_id'] === $objectId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function waitForExecutions(int $processId, string $objectId, int $count, int $timeout = 15): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $list = $this->executionsOf($processId, $objectId);
            if (count($list) >= $count) {
                return $list;
            }
            usleep(300000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException("expected {$count} execution(s) of process {$processId} for {$objectId}, got " . count($list));
    }

    /**
     * Sends the same manual run N times in parallel (curl_multi) and returns the HTTP statuses.
     *
     * @param array<string, mixed> $body
     * @return list<int>
     */
    private function parallelRuns(int $processId, array $body, int $count): array
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init("{$this->baseUrl}/api/processes/{$processId}/run");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$this->editorToken}"],
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        $codes = [];
        foreach ($handles as $ch) {
            $codes[] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $codes;
    }

    // ------------------------------------------------------------------ helpers

    private function test(string $name, callable $fn): void
    {
        echo "  {$name} ... ";
        try {
            $fn();
            $this->results['passed']++;
            echo "OK\n";
        } catch (SkipTest $e) {
            $this->results['skipped']++;
            echo "SKIPPED ({$e->getMessage()})\n";
        } catch (Throwable $e) {
            $this->results['failed']++;
            $this->failures[] = "{$name}: {$e->getMessage()}";
            echo "FAILED\n      {$e->getMessage()}\n";
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?string $token = null, int $expectedStatus = 200): array
    {
        $raw = $this->raw($method, $path, $body, $token);
        $data = json_decode($raw['body'], true);
        if ($raw['status'] !== $expectedStatus) {
            throw new RuntimeException("{$method} {$path}: expected HTTP {$expectedStatus}, got {$raw['status']}: " . substr($raw['body'], 0, 400));
        }
        if (!is_array($data)) {
            throw new RuntimeException("{$method} {$path}: response is not JSON");
        }
        return $data;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: string}
     */
    private function raw(string $method, string $path, ?array $body = null, ?string $token = null, ?string $base = null): array
    {
        $ch = curl_init(($base ?? $this->baseUrl) . $path);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException("{$method} {$path}: " . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$response];
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForExecution(string $id, int $timeout = 10): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $execution = $this->request('GET', "/api/executions/{$id}", token: $this->adminToken, expectedStatus: 200)['data'];
            if (in_array($execution['status'], ['completed', 'failed'], true)) {
                return $execution;
            }
            usleep(300000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException("Execution {$id} did not finish in {$timeout}s (status {$execution['status']})");
    }

    private function seedAccount(): void
    {
        $this->test('Seed account in the mock CRM', function () {
            $raw = $this->raw('PUT', "/mock/objects/Accounts/{$this->accountId}", [
                'name' => 'Test Company', 'status' => 'Active', 'rating' => '', 'phone' => '', 'description' => '',
            ], base: $this->crmUrl);
            $this->assertEquals(200, $raw['status']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function crmObject(string $module, string $id): array
    {
        $raw = $this->raw('GET', "/mock/objects/{$module}/{$id}", base: $this->crmUrl);
        $data = json_decode($raw['body'], true);
        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    private function pdo(): PDO
    {
        $host = self::env('DB_POSTGRES_HOST', self::env('DB_HOST', '127.0.0.1'));
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, self::env('DB_POSTGRES_PORT', '5432'), self::env('DB_POSTGRES_DB', 'wps'));
        return new PDO($dsn, self::env('DB_POSTGRES_USER', 'wps'), self::env('DB_POSTGRES_PASSWORD', 'secret'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function requireValue(mixed $value): void
    {
        if ($value === null) {
            $this->skip('depends on a previous step');
        }
    }

    private function skip(string $reason): never
    {
        throw new SkipTest($reason);
    }

    private function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new RuntimeException($message ?: 'Expected true, got ' . var_export($value, true));
        }
    }

    private function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new RuntimeException($message ?: 'Expected false, got ' . var_export($value, true));
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf('Expected %s, got %s%s', var_export($expected, true), var_export($actual, true), $message !== '' ? " ({$message})" : ''));
        }
    }

    private function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new RuntimeException($message ?: 'Expected a non-null value');
        }
    }

    private function assertIsArray(mixed $value): void
    {
        if (!is_array($value)) {
            throw new RuntimeException('Expected an array, got ' . get_debug_type($value));
        }
    }

    /**
     * @param array<mixed> $array
     */
    private function assertArrayHasKey(string|int $key, array $array): void
    {
        if (!array_key_exists($key, $array)) {
            throw new RuntimeException("Missing key \"{$key}\"");
        }
    }

    /**
     * @param array<mixed> $array
     */
    private function assertArrayNotHasKey(string|int $key, array $array): void
    {
        if (array_key_exists($key, $array)) {
            throw new RuntimeException("Unexpected key \"{$key}\"");
        }
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}

final class SkipTest extends RuntimeException
{
}

exit((new IntegrationTest())->run());
