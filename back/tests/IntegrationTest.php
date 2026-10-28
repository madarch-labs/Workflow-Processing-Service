<?php

declare(strict_types=1);

/**
 * WPS API integration tests (md/doc/concept_apitest_back.md): plain PHP, HTTP requests to a running server.
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
        $this->testTypedConditions();
        $this->testProgramBlocks();
        $this->testSecrets();
        $this->testSideEffects();
        $this->testCrmContract();
        $this->testEventFormat();
        $this->testEventTypes();
        $this->testChangeOperators();
        $this->testErrorHandling();
        $this->testSendEmail();
        $this->testHttpRequest();
        $this->testLinkRecords();
        $this->testFindRecords();
        $this->testForeach();
        $this->testUpdateRecords();
        $this->testAggregate();
        $this->testNotify();
        $this->testAssign();
        $this->testStartsEndsWith();
        $this->testBetween();
        $this->testRelativeDates();
        $this->testCaseInsensitive();
        $this->testListOperators();
        $this->testExpressionValue();
        $this->testSimpleChangeTypes();
        $this->testConditionBranches();
        $this->testStopProcess();
        $this->testSetVariable();
        $this->testWaitingExecutions();
        $this->testExpressions();
        $this->testSystemVariables();
        $this->testEditorFormContract();

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
            $pdo->exec("DELETE FROM secrets WHERE name LIKE 'test\\_%'");
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
        foreach (['modules', 'operators', 'action-types', 'change-types', 'preload-sources', 'casts', 'system-variables'] as $dict) {
            $this->test("GET /api/dictionaries/{$dict}", function () use ($dict) {
                $res = $this->request('GET', "/api/dictionaries/{$dict}", token: $this->viewerToken ?? $this->adminToken, expectedStatus: 200);
                $this->assertIsArray($res['data']);
                $this->assertTrue(count($res['data']) > 0, "{$dict} is empty");
                $this->assertArrayHasKey('value', $res['data'][0]);
                $this->assertArrayHasKey('label', $res['data'][0]);
            });
        }
        $this->test('Operators contain the comparison types', function () {
            $res = $this->request('GET', '/api/dictionaries/operators', token: $this->adminToken, expectedStatus: 200);
            $values = array_column($res['data'], 'value');
            foreach (['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal'] as $operator) {
                $this->assertTrue(in_array($operator, $values, true), "{$operator} is missing");
            }
        });
        $this->test('Operators list their casts and operand kind; casts dictionary has 7 types', function () {
            $operators = array_column($this->request('GET', '/api/dictionaries/operators', token: $this->adminToken)['data'], null, 'value');
            $this->assertEquals(['string', 'list'], $operators['contains']['casts']);
            $this->assertEquals('value', $operators['equals']['operands']);
            $casts = array_column($this->request('GET', '/api/dictionaries/casts', token: $this->viewerToken)['data'], null, 'value');
            $this->assertEquals(['string', 'int', 'decimal', 'date', 'datetime', 'bool', 'list'], array_keys($casts));
            $this->assertTrue($casts['decimal']['orderable']);
            $this->assertEquals('scale', $casts['decimal']['options'][0]['name']);
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
        $this->test('Unknown cast, operator without the cast and an uncastable value are rejected', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST invalid cast', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [
                    ['field' => 'object.a', 'operator' => 'equals', 'value' => '1', 'cast' => 'money'],
                    ['field' => 'object.a', 'operator' => 'contains', 'value' => '1', 'cast' => 'decimal'],
                    ['field' => 'object.a', 'operator' => 'equals', 'value' => 'abc', 'cast' => 'decimal'],
                    ['field' => 'object.a', 'operator' => 'equals', 'value' => '1', 'cast' => 'string', 'cast_options' => ['scale' => 2]],
                    ['field' => 'object.a', 'operator' => 'equals', 'value' => '1', 'on_cast_error' => 'stop'],
                ]],
            ], $this->adminToken, 422);
            $details = array_column($res['error']['details'], 'message', 'field');
            $this->assertTrue(str_contains($details['definition.conditions[0].cast'] ?? '', 'Unknown cast "money"'));
            $this->assertTrue(str_contains($details['definition.conditions[1].cast'] ?? '', 'does not support cast "decimal"'));
            $this->assertEquals('Value "abc" is not a valid decimal', $details['definition.conditions[2].value'] ?? null);
            $this->assertArrayHasKey('definition.conditions[3].cast_options.scale', $details);
            $this->assertArrayHasKey('definition.conditions[4].on_cast_error', $details);
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

    /**
     * Typed comparison (md/tasks_list/done/3.0-type-cast.md): cast in conditions, strict mode.
     */
    private function testTypedConditions(): void
    {
        echo "[typed conditions]\n";
        $this->test('cast decimal: "100.00" equals 100, trace has the cast values', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST cast decimal', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '100', 'cast' => 'decimal']]],
            ], $this->adminToken, 201)['data'];
            $res = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'cast-1', 'amount' => '100.00']], $this->editorToken, 201);
            $this->assertTrue($res['data']['conditions_met']);
            $this->assertEquals('100.00', $res['data']['conditions'][0]['actual']);
            $this->assertEquals('100', $res['data']['conditions'][0]['actual_cast']);
            $res = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'cast-2', 'amount' => '1 000,50']], $this->editorToken, 200);
            $this->assertFalse($res['data']['conditions_met']);
            $this->assertEquals('1000.5', $res['data']['conditions'][0]['actual_cast']);
        });
        $strictId = null;
        $this->test('greater_than: requires a cast; "1 500,00" > 1000 as decimal', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST order no cast', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.amount', 'operator' => 'greater_than', 'value' => '1000']]],
            ], $this->adminToken, 422);
            $this->assertEquals('definition.conditions[0].cast', $res['error']['details'][0]['field']);
            $this->assertEquals('Operator "greater_than" requires cast: int, decimal, date or datetime', $res['error']['details'][0]['message']);
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST order decimal', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.amount', 'operator' => 'greater_than', 'value' => '1000', 'cast' => 'decimal']]],
            ], $this->adminToken, 201)['data'];
            $met = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'ord-1', 'amount' => '1 500,00']], $this->editorToken, 201)['data'];
            $this->assertTrue($met['conditions_met']);
            $notMet = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'ord-2', 'amount' => '999']], $this->editorToken, 200)['data'];
            $this->assertFalse($notMet['conditions_met']);
            $this->assertEquals('999', $notMet['conditions'][0]['actual_cast']);
        });
        $this->test('is_empty / is_null: a missing field is not empty but has no data; value is rejected', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST presence value', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.phone', 'operator' => 'is_null', 'value' => 'x']]],
            ], $this->adminToken, 422);
            $this->assertEquals('Operator "is_null" does not take a value', $res['error']['details'][0]['message']);
            $make = fn (string $op): int => (int)$this->request('POST', '/api/processes', [
                'name' => "TEST presence {$op}", 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.phone', 'operator' => $op]]],
            ], $this->adminToken, 201)['data']['id'];
            $empty = $make('is_empty');
            $null = $make('is_null');
            $this->assertFalse($this->request('POST', "/api/processes/{$empty}/run", ['object' => ['id' => 'pr-1']], $this->editorToken, 200)['data']['conditions_met']);
            $this->assertTrue($this->request('POST', "/api/processes/{$null}/run", ['object' => ['id' => 'pr-2']], $this->editorToken, 201)['data']['conditions_met']);
            $this->assertTrue($this->request('POST', "/api/processes/{$empty}/run", ['object' => ['id' => 'pr-3', 'phone' => '']], $this->editorToken, 201)['data']['conditions_met']);
            $this->assertFalse($this->request('POST', "/api/processes/{$null}/run", ['object' => ['id' => 'pr-4', 'phone' => '']], $this->editorToken, 200)['data']['conditions_met']);
        });
        $this->test('in / not_in: list validation and evaluation', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST in invalid', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [
                    ['field' => 'object.status', 'operator' => 'in', 'value' => 'New'],
                    ['field' => 'object.status', 'operator' => 'in', 'value' => []],
                    ['field' => 'object.code', 'operator' => 'in', 'value' => [1, 'x1'], 'cast' => 'int'],
                    ['field' => 'object.status', 'operator' => 'in', 'value' => ['a'], 'value_list_options' => ['separator' => ';']],
                    ['field' => 'object.status', 'operator' => 'in', 'value' => ['a'], 'cast' => 'bool'],
                ]],
            ], $this->adminToken, 422);
            $details = array_column($res['error']['details'], 'message', 'field');
            $this->assertEquals('Operator "in" requires a list of values', $details['definition.conditions[0].value'] ?? null);
            $this->assertEquals('List must not be empty', $details['definition.conditions[1].value'] ?? null);
            $this->assertTrue(str_contains($details['definition.conditions[2].value[1]'] ?? '', 'is not an integer'));
            $this->assertEquals('Only used with value_type "from_object_field"', $details['definition.conditions[3].value_list_options'] ?? null);
            $this->assertTrue(str_contains($details['definition.conditions[4].cast'] ?? '', 'does not support cast "bool"'));
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST in status', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.status', 'operator' => 'in', 'value' => ['New', 'Assigned', 'In Progress']]]],
            ], $this->adminToken, 201)['data'];
            $this->assertTrue($this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'in-1', 'status' => 'Assigned']], $this->editorToken, 201)['data']['conditions_met']);
            $this->assertFalse($this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'in-2', 'status' => 'Closed']], $this->editorToken, 200)['data']['conditions_met']);
        });
        $this->test('Strict cast error: manual run reports CAST_ERROR', function () use (&$strictId) {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST cast strict', 'object_type' => 'TestCast', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '5', 'cast' => 'int', 'on_cast_error' => 'error']]],
            ], $this->adminToken, 201)['data'];
            $strictId = (int)$process['id'];
            $res = $this->request('POST', "/api/processes/{$strictId}/run", ['object' => ['id' => 'cast-3', 'amount' => '12.5']], $this->editorToken, 200);
            $this->assertFalse($res['data']['conditions_met']);
            $this->assertEquals('CAST_ERROR', $res['data']['error']);
            $this->assertEquals('actual', $res['data']['conditions'][0]['cast_error']['side']);
        });
        $this->test('Strict cast error in a stream event is recorded as a failed execution and not redelivered', function () use (&$strictId) {
            $strictId ?? $this->skip('strict process not created');
            $objectId = 'cast-stream-' . random_int(1000, 9999);
            $this->xadd(['id' => $objectId, 'module' => 'TestCast', 'amount' => 'abc']);
            $execution = $this->waitForExecution($this->waitForExecutions($strictId, $objectId, 1)[0]['id']);
            $this->assertEquals('failed', $execution['status']);
            $this->assertEquals('conditions', $execution['actions'][0]['action_type']);
            $this->assertTrue(str_contains((string)$execution['error_message'], 'is not an integer'));
            sleep(2);
            $this->assertEquals(1, count($this->executionsOf($strictId, $objectId)));
        });
    }

    /**
     * Flat program (md/tasks_list/done/0.1-program-compiler.md): condition blocks, audit paths, skipped steps, warnings.
     */
    private function testProgramBlocks(): void
    {
        echo "[program blocks]\n";
        $condition = static fn (array $then, ?array $else = null): array => ['type' => 'condition', 'id' => 'size',
            'branches' => [['conditions' => [['field' => 'object.amount', 'operator' => 'equals', 'value' => '1000', 'cast' => 'decimal']], 'actions' => $then]]]
            + ($else !== null ? ['else' => $else] : []);
        $set = static fn (string $target, string $value): array => ['type' => 'modify', 'target' => $target, 'change_type' => 'set_value', 'value' => $value];

        $this->test('action-types dictionary lists blocks with kind "block"', function () {
            $types = array_column($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], null, 'value');
            $this->assertEquals('block', $types['condition']['kind'] ?? null);
            $this->assertEquals('action', $types['modify']['kind'] ?? null);
        });
        $branchId = null;
        $this->test('condition: the matching branch runs, the audit has the choice and step paths', function () use ($condition, $set, &$branchId) {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST program branch', 'object_type' => 'TestProgram', 'is_child' => false,
                'definition' => ['actions' => [$condition([$set('object.priority', 'High')], [$set('object.priority', 'Normal')]), $set('object.checked', 'yes')]],
            ], $this->adminToken, 201)['data'];
            $branchId = (int)$process['id'];
            $run = $this->request('POST', "/api/processes/{$branchId}/run", ['object' => ['id' => 'prog-1', 'amount' => '1000.00']], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('completed', $execution['status']);
            $actions = $execution['actions'];
            $this->assertEquals(['condition', 'modify', 'modify'], array_column($actions, 'action_type'));
            $this->assertEquals(0, $actions[0]['result_data']['selected']);
            $this->assertEquals('size', $actions[0]['step_id']);
            $this->assertEquals('actions[0].branches[0].actions[0]', $actions[1]['step_path']);
            $this->assertEquals([0, 1, 2], array_column($actions, 'step_index'));
            $this->assertEquals([0, 1, 4], array_column($actions, 'pc'));
            $this->assertEquals('High', $execution['context']['variables']['object']['priority']);
        });
        $this->test('condition: otherwise the else branch runs', function () use (&$branchId) {
            $branchId ?? $this->skip('branch process not created');
            $run = $this->request('POST', "/api/processes/{$branchId}/run", ['object' => ['id' => 'prog-2', 'amount' => '5']], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('else', $execution['actions'][0]['result_data']['selected']);
            $this->assertEquals('actions[0].else[0]', $execution['actions'][1]['step_path']);
            $this->assertEquals('Normal', $execution['context']['variables']['object']['priority']);
        });
        $this->test('A failed step in a branch skips the rest of the branch and the following top-level steps only', function () use ($condition, $set) {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST program branch failure', 'object_type' => 'TestProgram', 'is_child' => false,
                'definition' => ['actions' => [
                    $condition(
                        [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => '1 / 0'], $set('object.after_fail', '1')],
                        [$set('object.other_branch', '1')],
                    ),
                    $set('object.top_after', '1'),
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'prog-3', 'amount' => '1000']], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('failed', $execution['status']);
            $rows = array_map(static fn (array $a) => $a['action_type'] . ':' . $a['status'] . ':' . $a['step_path'], $execution['actions']);
            $this->assertEquals([
                'condition:success:actions[0]',
                'modify:failed:actions[0].branches[0].actions[0]',
                'modify:skipped:actions[0].branches[0].actions[1]',
                'modify:skipped:actions[1]',
            ], $rows);
        });
        $this->test('A variable set only in one branch and read later gives a warning, not an error', function () use ($condition, $set) {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST program warning', 'object_type' => 'TestProgram', 'is_child' => false,
                'definition' => ['actions' => [
                    $condition([$set('task.name', 'Call')]),
                    ['type' => 'modify', 'target' => 'object.note', 'change_type' => 'set_from_variable', 'value' => 'task.name'],
                ]],
            ], $this->adminToken, 201);
            $this->assertEquals('definition.actions[1].value', $res['data']['warnings'][0]['field'] ?? null);
            $this->assertTrue(str_contains($res['data']['warnings'][0]['message'], 'may be undefined here'));
        });
        $this->test('Step ids are validated: format, uniqueness inside nested blocks; unknown nested type', function () use ($condition, $set) {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST program ids', 'object_type' => 'TestProgram', 'is_child' => false,
                'definition' => ['actions' => [
                    $condition([['id' => 'size'] + $set('object.a', '1'), ['type' => 'teleport']]),
                    ['id' => 'Bad-Id'] + $set('object.b', '1'),
                ]],
            ], $this->adminToken, 422);
            $details = array_column($res['error']['details'], 'message', 'field');
            $this->assertTrue(str_contains($details['definition.actions[0].branches[0].actions[0].id'] ?? '', 'is already used at definition.actions[0]'));
            $this->assertEquals('Unknown action type', $details['definition.actions[0].branches[0].actions[1].type'] ?? null);
            $this->assertArrayHasKey('definition.actions[1].id', $details);
        });
        $this->test('A start_process cycle through a branch is detected', function () use ($condition) {
            $a = $this->request('POST', '/api/processes', ['name' => 'TEST program cycle A', 'object_type' => 'TestProgram', 'is_child' => true, 'definition' => []], $this->adminToken, 201)['data'];
            $b = $this->request('POST', '/api/processes', [
                'name' => 'TEST program cycle B', 'object_type' => 'TestProgram', 'is_child' => true,
                'definition' => ['actions' => [$condition([['type' => 'start_process', 'process_id' => $a['id']]])]],
            ], $this->adminToken, 201)['data'];
            $a['definition'] = ['actions' => [['type' => 'start_process', 'process_id' => $b['id']]]];
            $res = $this->request('PUT', "/api/processes/{$a['id']}", $a, $this->adminToken, 422);
            $this->assertEquals('CYCLE_DETECTED', $res['error']['code']);
            // B is started from inside a branch of A's child — it cannot be deleted while referenced
            $this->request('DELETE', "/api/processes/{$a['id']}", token: $this->adminToken, expectedStatus: 409);
        });
    }

    /**
     * Secrets store (md/tasks_list/done/7.4-secrets.md): write-only values, roles, references from processes.
     */
    private function testSecrets(): void
    {
        echo "[secrets]\n";
        $value = 'tok-integration-' . random_int(100000, 999999);
        $this->test('Admin creates a secret; the response has a mask and never the value', function () use ($value) {
            $res = $this->request('POST', '/api/secrets', ['name' => 'test_erp_token', 'kind' => 'bearer', 'value' => $value, 'description' => 'ERP'], $this->adminToken, 201);
            $this->assertEquals('****' . substr($value, -4), $res['data']['mask']);
            $this->assertArrayNotHasKey('value', $res['data']);
            $this->assertFalse(str_contains((string)json_encode($res), $value));
            $this->request('POST', '/api/secrets', ['name' => 'test_erp_token', 'kind' => 'bearer', 'value' => $value], $this->adminToken, 409);
        });
        $this->test('Secret validation: name, short value, composite fields, https token URL', function () {
            $res = $this->request('POST', '/api/secrets', ['name' => 'Bad Name', 'kind' => 'bearer', 'value' => 'abc'], $this->adminToken, 422);
            $fields = array_column($res['error']['details'], 'field');
            $this->assertTrue(in_array('name', $fields, true) && in_array('value', $fields, true));
            $res = $this->request('POST', '/api/secrets', ['name' => 'test_basic', 'kind' => 'basic_auth', 'value' => ['username' => 'erp']], $this->adminToken, 422);
            $this->assertEquals('value.password', $res['error']['details'][0]['field']);
            $res = $this->request('POST', '/api/secrets', ['name' => 'test_oauth', 'kind' => 'oauth2_client',
                'value' => ['token_url' => 'http://idp/token', 'client_id' => 'wps', 'client_secret' => 'secret-1']], $this->adminToken, 422);
            $this->assertEquals('Token URL must use https', $res['error']['details'][0]['message']);
        });
        $this->test('Editor lists secrets but cannot manage them; viewer has no access', function () use ($value) {
            $list = $this->request('GET', '/api/secrets', token: $this->editorToken, expectedStatus: 200);
            $this->assertTrue(in_array('test_erp_token', array_column($list['data'], 'name'), true));
            $this->assertFalse(str_contains((string)json_encode($list), $value));
            $this->request('POST', '/api/secrets', ['name' => 'test_x', 'kind' => 'generic', 'value' => 'xxxx'], $this->editorToken, 403);
            $this->request('GET', '/api/secrets', token: $this->viewerToken, expectedStatus: 403);
            $this->request('GET', '/api/dictionaries/secret-kinds', token: $this->viewerToken, expectedStatus: 200);
        });
        $processId = null;
        $this->test('Process references: unknown secret and secret in a data field are rejected; a valid one is saved', function () use (&$processId) {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST secret refs', 'object_type' => 'TestSecret', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'save_object', 'target' => 'object', 'credentials' => 'secret://test_missing'],
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => 'secret://test_erp_token'],
                ]],
            ], $this->adminToken, 422);
            $details = array_column($res['error']['details'], 'message', 'field');
            $this->assertEquals('Secret "test_missing" does not exist', $details['definition.actions[0].credentials'] ?? null);
            $this->assertEquals('Secrets can only be used in credential fields', $details['definition.actions[1].value'] ?? null);
            $processId = $this->request('POST', '/api/processes', [
                'name' => 'TEST secret refs', 'object_type' => 'TestSecret', 'is_child' => false,
                'definition' => ['actions' => [['type' => 'save_object', 'target' => 'object', 'credentials' => 'secret://test_erp_token']]],
            ], $this->adminToken, 201)['data']['id'];
        });
        $this->test('A used secret cannot be deleted; its usages are listed', function () use (&$processId) {
            $processId ?? $this->skip('process not created');
            $secret = $this->request('GET', '/api/secrets/test_erp_token', token: $this->editorToken)['data'];
            $this->assertEquals('definition.actions[0].credentials', $secret['usages'][0]['path'] ?? null);
            $res = $this->request('DELETE', '/api/secrets/test_erp_token', token: $this->adminToken, expectedStatus: 409);
            $this->assertEquals('SECRET_IN_USE', $res['error']['code']);
        });
        $this->test('Updating the value bumps the version; rotation with the same key changes nothing; delete after use', function () use (&$processId) {
            $res = $this->request('PUT', '/api/secrets/test_erp_token', ['value' => 'tok-rotated-1234567'], $this->adminToken, 200);
            $this->assertEquals(2, $res['data']['version']);
            $this->assertEquals('****4567', $res['data']['mask']);
            $this->request('PUT', '/api/secrets/test_erp_token', ['name' => 'test_other'], $this->adminToken, 422);
            $this->assertEquals(0, $this->request('POST', '/api/secrets/rotate', [], $this->adminToken, 200)['data']['reencrypted']);
            if ($processId !== null) {
                $this->request('DELETE', "/api/processes/{$processId}", token: $this->adminToken, expectedStatus: 200);
            }
            $this->request('DELETE', '/api/secrets/test_erp_token', token: $this->adminToken, expectedStatus: 200);
            $this->request('GET', '/api/secrets/test_erp_token', token: $this->adminToken, expectedStatus: 404);
        });
    }

    /**
     * Side-effect journal (md/tasks_list/done/0.4-side-effect-ledger.md): API and validation; the journal itself is
     * exercised by the external actions (http_request, send_email).
     */
    private function testSideEffects(): void
    {
        echo "[side effects]\n";
        $this->test('delivery-policies dictionary and an empty journal of an execution without external calls', function () {
            $policies = array_column($this->request('GET', '/api/dictionaries/delivery-policies', token: $this->viewerToken)['data'], 'value');
            $this->assertEquals(['at_most_once', 'at_least_once'], $policies);
            $this->executionId ?? $this->skip('no execution');
            $this->assertEquals([], $this->request('GET', "/api/executions/{$this->executionId}/side-effects", token: $this->viewerToken)['data']);
            $this->request('GET', '/api/executions/00000000-0000-0000-0000-000000000000/side-effects', token: $this->viewerToken, expectedStatus: 404);
        });
        $this->test('"delivery" is rejected on actions without external side effects', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST delivery', 'object_type' => 'TestSide', 'is_child' => false,
                'definition' => ['actions' => [['type' => 'modify', 'target' => 'object.a', 'change_type' => 'set_value', 'value' => '1', 'delivery' => 'at_most_once']]],
            ], $this->adminToken, 422);
            $this->assertEquals('Action "modify" does not support "delivery"', $res['error']['details'][0]['message']);
        });
    }

    /**
     * CRM contract 2.0 (md/tasks_list/done/0.2-crm-contract-v9.md): capabilities, degradation to 1.0, X-WPS-* origin.
     */
    private function testCrmContract(): void
    {
        echo "[crm contract]\n";
        $mock = fn (string $method, string $path, ?array $body = null): array => (array)json_decode((string)file_get_contents($this->crmUrl . $path, false, stream_context_create(['http' => [
            'method' => $method, 'header' => 'Content-Type: application/json', 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true,
        ]])), true);
        $this->test('crm-capabilities: the mock implements contract 2.0', function () {
            $caps = $this->request('POST', '/api/dictionaries/modules/refresh', [], $this->editorToken) && true;
            $data = $this->request('GET', '/api/dictionaries/crm-capabilities', token: $this->viewerToken)['data'];
            $this->assertEquals('2.0', $data['contract_version']);
            $this->assertEquals('crm', $data['source']);
            $this->assertTrue(in_array('relationships', $data['features'], true));
        });
        $this->test('An old plugin (no /capabilities) is contract 1.0 with no features', function () use ($mock) {
            $mock('PUT', '/mock/config', ['contract' => '1.0']);
            try {
                $this->request('POST', '/api/dictionaries/modules/refresh', [], $this->editorToken);
                $data = $this->request('GET', '/api/dictionaries/crm-capabilities', token: $this->viewerToken)['data'];
                $this->assertEquals('1.0', $data['contract_version']);
                $this->assertEquals('fallback', $data['source']);
                $this->assertEquals([], $data['features']);
            } finally {
                $mock('PUT', '/mock/config', ['contract' => '2.0']);
                $this->request('POST', '/api/dictionaries/modules/refresh', [], $this->editorToken);
            }
        });
        $this->test('A CRM save made by an execution carries X-WPS-* headers: the event has wps_origin', function () use ($mock) {
            $objectId = 'origin-' . random_int(1000, 9999);
            $mock('PUT', "/mock/objects/TestOrigin/{$objectId}", ['flag' => 'go']);
            $set = static fn (string $v): array => ['type' => 'modify', 'target' => 'object.flag', 'change_type' => 'set_value', 'value' => $v, 'save_to_crm' => true];
            $writer = $this->request('POST', '/api/processes', ['name' => 'TEST origin writer', 'object_type' => 'TestOrigin', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.flag', 'operator' => 'equals', 'value' => 'go']], 'actions' => [$set('stop')]]], $this->adminToken, 201)['data'];
            $observer = $this->request('POST', '/api/processes', ['name' => 'TEST origin observer', 'object_type' => 'TestOrigin', 'is_child' => false,
                'definition' => ['conditions' => [['field' => 'object.flag', 'operator' => 'equals', 'value' => 'stop']]]], $this->adminToken, 201)['data'];
            $mock('PUT', '/mock/config', ['publish_on_write' => true]);
            try {
                $run = $this->request('POST', "/api/processes/{$writer['id']}/run", ['object' => ['id' => $objectId, 'module' => 'TestOrigin', 'flag' => 'go']], $this->editorToken, 201)['data'];
                $this->assertEquals('completed', $this->waitForExecution($run['execution_id'])['status']);
                $seen = $this->waitForExecution($this->waitForExecutions((int)$observer['id'], $objectId, 1)[0]['id']);
                // service fields of the event are parsed into event_info and object_before (6.1)
                $info = $seen['context']['event_info'] ?? [];
                $this->assertEquals('update', $info['event_type'] ?? null);
                $this->assertEquals(['flag'], $info['changed_fields'] ?? null);
                $this->assertEquals('go', $seen['context']['variables']['object_before']['flag'] ?? null);
                $this->assertEquals($run['execution_id'], $info['origin']['execution_id'] ?? null);
                $this->assertEquals((int)$writer['id'], $info['origin']['process_id'] ?? null);
                $this->assertFalse(array_key_exists('wps_origin', $seen['context']['event']));
            } finally {
                $mock('PUT', '/mock/config', ['publish_on_write' => false]);
            }
        });
    }

    /**
     * Event format 2.0: event_type, before, changed_fields (md/tasks_list/done/6.1-event-before-values.md).
     */
    private function testEventFormat(): void
    {
        echo "[event format]\n";
        $process = null;
        $this->test('object_before: a stream event of format 2.0 starts a process with a condition on the old value', function () use (&$process) {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST before', 'object_type' => 'TestBefore', 'is_child' => false, 'is_active' => true,
                'definition' => [
                    'conditions' => [['field' => 'object_before.status', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'New']],
                    'actions' => [['type' => 'modify', 'target' => 'object.note', 'change_type' => 'template', 'value' => '{{ object_before.status }} → {{ object.status }}']],
                ],
            ], $this->adminToken, 201)['data'];
            $objectId = 'before-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestBefore', 'event_type' => 'update', 'before' => ['status' => 'New'], 'status' => 'Assigned']);
            $execution = $this->waitForExecution($this->waitForExecutions((int)$process['id'], $objectId, 1)[0]['id']);
            $this->assertEquals('completed', $execution['status']);
            $context = $execution['context'];
            $this->assertEquals(['status'], $context['event_info']['changed_fields']);
            $this->assertEquals(false, $context['event_info']['legacy']);
            $this->assertEquals('New', $context['variables']['object_before']['status']);
            $this->assertEquals('New → Assigned', $context['variables']['object']['note']);
            $this->assertFalse(array_key_exists('before', $context['variables']['object']));
        });
        $this->test('An old-format event (no before) does not satisfy a condition on object_before', function () use (&$process) {
            $process ?? $this->skip('process not created');
            $objectId = 'before-legacy-' . random_int(10000, 99999);
            $this->xadd(['id' => $objectId, 'module' => 'TestBefore', 'status' => 'Assigned']);
            sleep(3);
            $this->assertEquals(0, count($this->executionsOf((int)$process['id'], $objectId)));
        });
        $this->test('Manual run accepts event_type / before / changed_fields', function () use (&$process) {
            $process ?? $this->skip('process not created');
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'before-manual', 'event_type' => 'update', 'before' => ['status' => 'New'], 'status' => 'Converted',
            ]], $this->editorToken, 201)['data'];
            $this->assertEquals(true, $run['conditions_met']);
            $execution = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('New → Converted', $execution['context']['variables']['object']['note']);
            $miss = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'before-manual-2', 'status' => 'Converted']], $this->editorToken);
            $this->assertEquals(false, $miss['data']['conditions_met']);
        });
        $this->test('object_before is read-only: modify, preload and save_object targets are rejected', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST before write', 'object_type' => 'TestBefore', 'is_child' => false,
                'definition' => [
                    'preload' => [['var' => 'object_before', 'object_type' => 'Accounts', 'by' => 'id', 'value_from' => 'object.account_id']],
                    'actions' => [
                        ['type' => 'modify', 'target' => 'object_before.status', 'change_type' => 'set_value', 'value' => 'x'],
                        ['type' => 'save_object', 'target' => 'object_before'],
                    ],
                ],
            ], $this->adminToken, 422);
            $details = array_column($res['error']['details'], 'message', 'field');
            $this->assertEquals('Name "object_before" is reserved', $details['definition.preload[0].var'] ?? null);
            $this->assertEquals('Variable "object_before" is read-only', $details['definition.actions[0].target'] ?? null);
            $this->assertEquals('Variable "object_before" is read-only', $details['definition.actions[1].target'] ?? null);
        });
        $this->test('save_object (mode full) after a format 2.0 event does not send service fields to the CRM', function () {
            $objectId = 'before-save-' . random_int(10000, 99999);
            $this->raw('PUT', "/mock/objects/TestBefore/{$objectId}", ['status' => 'Assigned'], base: $this->crmUrl);
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST before save', 'object_type' => 'TestBefore', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.touched', 'change_type' => 'set_value', 'value' => '1'],
                    ['type' => 'save_object', 'target' => 'object', 'mode' => 'full'],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => $objectId, 'module' => 'TestBefore', 'event_type' => 'update', 'before' => ['status' => 'New'], 'changed_fields' => ['status'], 'status' => 'Assigned',
            ]], $this->editorToken, 201)['data'];
            $this->assertEquals('completed', $this->waitForExecution($run['execution_id'])['status']);
            $stored = $this->crmObject('TestBefore', $objectId);
            $this->assertEquals('1', $stored['touched'] ?? null);
            foreach (['event_type', 'before', 'changed_fields', 'wps_origin'] as $key) {
                $this->assertFalse(array_key_exists($key, $stored), "{$key} must not reach the CRM");
            }
        });
    }

    /**
     * Event types and trigger_on (md/tasks_list/done/6.2-event-type.md).
     */
    private function testEventTypes(): void
    {
        echo "[event types]\n";
        $touch = ['type' => 'modify', 'target' => 'object.touched', 'change_type' => 'set_value', 'value' => '1'];
        $create = fn (array $body, int $status = 201): array => $this->request('POST', '/api/processes', $body + [
            'object_type' => 'TestTrigger', 'is_child' => false, 'is_active' => true,
        ], $this->adminToken, $status);
        $this->test('event-types dictionary; trigger_on validation (not an array, unknown type, delete-only + saving object)', function () use ($create, $touch) {
            $types = $this->request('GET', '/api/dictionaries/event-types', token: $this->viewerToken)['data'];
            $this->assertEquals(['create', 'update', 'delete'], array_column($types, 'value'));
            $bad = $create(['name' => 'TEST trigger bad', 'trigger_on' => 'create', 'definition' => ['actions' => [$touch]]], 422);
            $this->assertEquals('Must be an array of event types', array_column($bad['error']['details'], 'message', 'field')['trigger_on'] ?? null);
            $bad = $create(['name' => 'TEST trigger bad', 'trigger_on' => ['create', 'link'], 'definition' => ['actions' => [$touch]]], 422);
            $this->assertEquals('Unknown event type "link" (allowed: create, update, delete)', array_column($bad['error']['details'], 'message', 'field')['trigger_on'] ?? null);
            $bad = $create(['name' => 'TEST trigger bad', 'trigger_on' => ['delete'], 'definition' => ['actions' => [
                $touch, ['type' => 'save_object', 'target' => 'object'], $touch + ['save_to_crm' => true],
            ]]], 422);
            $details = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('The process runs only on delete: the deleted record cannot be saved', $details['definition.actions[1]'] ?? null);
            $this->assertEquals('The process runs only on delete: the deleted record cannot be saved', $details['definition.actions[2]'] ?? null);
        });
        $ids = [];
        $this->test('trigger_on: create-only process ignores updates; default process still runs on old-format events', function () use ($create, $touch, &$ids) {
            $ids['create'] = (int)$create(['name' => 'TEST trigger create', 'trigger_on' => ['create', 'create'], 'definition' => ['actions' => [$touch]]])['data']['id'];
            $default = $create(['name' => 'TEST trigger default', 'definition' => ['actions' => [$touch]]])['data'];
            $ids['default'] = (int)$default['id'];
            $this->assertEquals(['create', 'update'], $default['trigger_on']);
            $this->assertEquals(['create'], $this->request('GET', "/api/processes/{$ids['create']}", token: $this->viewerToken)['data']['trigger_on']);
            $a = 'trig-' . random_int(10000, 99999);
            $this->xadd(['id' => $a, 'module' => 'TestTrigger', 'event_type' => 'update', 'before' => ['x' => '1'], 'x' => '2']);
            $this->waitForExecutions($ids['default'], $a, 1);
            sleep(2);
            $this->assertEquals(0, count($this->executionsOf($ids['create'], $a)));
            $b = 'trig-' . random_int(10000, 99999);
            $this->xadd(['id' => $b, 'module' => 'TestTrigger', 'event_type' => 'create', 'x' => '1']);
            $this->waitForExecutions($ids['create'], $b, 1);
            $c = 'trig-' . random_int(10000, 99999);
            $this->xadd(['id' => $c, 'module' => 'TestTrigger', 'x' => '1']);
            $this->waitForExecutions($ids['default'], $c, 1);
            $d = 'trig-' . random_int(10000, 99999);
            $this->xadd(['id' => $d, 'module' => 'TestTrigger', 'event_type' => 'link', 'x' => '1']);
            sleep(2);
            $this->assertEquals(0, count($this->executionsOf($ids['default'], $d)), 'unsupported event_type starts nothing');
        });
        $this->test('delete: a delete-only process runs; saving the deleted object is skipped in a create+delete process', function () use ($create, $touch, &$ids) {
            $ids['delete'] = (int)$create(['name' => 'TEST trigger delete', 'trigger_on' => ['delete'], 'definition' => ['actions' => [
                ['type' => 'modify', 'target' => 'note.text', 'change_type' => 'template', 'value' => 'Удалена запись {{ object.name }}'],
            ]]])['data']['id'];
            $ids['both'] = (int)$create(['name' => 'TEST trigger both', 'trigger_on' => ['create', 'delete'], 'definition' => ['actions' => [
                $touch, ['type' => 'save_object', 'target' => 'object'],
            ]]])['data']['id'];
            $id = 'trig-del-' . random_int(10000, 99999);
            $this->xadd(['id' => $id, 'module' => 'TestTrigger', 'event_type' => 'delete', 'before' => [], 'changed_fields' => ['deleted'], 'name' => 'Old deal', 'deleted' => '1']);
            $deleted = $this->waitForExecution($this->waitForExecutions($ids['delete'], $id, 1)[0]['id']);
            $this->assertEquals('completed', $deleted['status']);
            $this->assertEquals('Удалена запись Old deal', $deleted['context']['variables']['note']['text']);
            $this->assertEquals('delete', $deleted['context']['event_info']['event_type']);
            $both = $this->waitForExecution($this->waitForExecutions($ids['both'], $id, 1)[0]['id']);
            $this->assertEquals('completed', $both['status']);
            $this->assertEquals('skipped', $both['actions'][1]['status']);
            $this->assertEquals('Record was deleted in the CRM — nothing to save', $both['actions'][1]['result_data']['reason'] ?? null);
            $this->assertEquals(0, count($this->executionsOf($ids['default'], $id)), 'the default process does not run on delete');
        });
        $this->test('PUT without trigger_on keeps it; clone copies it; list filter ?trigger_on=delete', function () use (&$ids, $touch) {
            $id = $ids['delete'] ?? $this->skip('process not created');
            $updated = $this->request('PUT', "/api/processes/{$id}", ['name' => 'TEST trigger delete 2', 'object_type' => 'TestTrigger', 'is_child' => false,
                'definition' => ['actions' => [$touch]]], $this->adminToken, 200)['data'];
            $this->assertEquals(['delete'], $updated['trigger_on']);
            $clone = $this->request('POST', "/api/processes/{$id}/clone", token: $this->editorToken, expectedStatus: 201)['data'];
            $this->assertEquals(['delete'], $clone['trigger_on']);
            $list = $this->request('GET', '/api/processes?trigger_on=delete&object_type=TestTrigger&per_page=100', token: $this->viewerToken)['data'];
            $names = array_column($list, 'name');
            $this->assertTrue(in_array('TEST trigger delete 2', $names, true) && in_array('TEST trigger both', $names, true));
            $this->assertFalse(in_array('TEST trigger default', $names, true));
            $this->request('DELETE', "/api/processes/{$clone['id']}", token: $this->adminToken, expectedStatus: 200);
            foreach ($ids as $pid) {
                $this->request('PUT', "/api/processes/{$pid}", ['name' => "TEST trigger off {$pid}", 'object_type' => 'TestTrigger', 'is_child' => false, 'is_active' => false,
                    'definition' => ['actions' => [$touch]]], $this->adminToken, 200);
            }
        });
    }

    /**
     * changed / not_changed / changed_to / changed_from (md/tasks_list/done/3.3-changed-operators.md).
     */
    private function testChangeOperators(): void
    {
        echo "[change operators]\n";
        $this->test('operators dictionary and validation: object fields only, no value for changed, value required for changed_to', function () {
            $ops = array_column($this->request('GET', '/api/dictionaries/operators', token: $this->viewerToken)['data'], null, 'value');
            $this->assertEquals('Изменилось на', $ops['changed_to']['label'] ?? null);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST changed bad', 'object_type' => 'TestChange', 'is_child' => false,
                'definition' => [
                    'preload' => [['var' => 'v1', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'x']],
                    'conditions' => [
                        ['field' => 'v1.name', 'operator' => 'changed'],
                        ['field' => 'object.status', 'operator' => 'changed', 'value' => 'New'],
                        ['field' => 'object.status', 'operator' => 'changed_to'],
                    ],
                ],
            ], $this->adminToken, 422);
            $details = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Operator "changed" works only with event object fields (object.<field>)', $details['definition.conditions[0].field'] ?? null);
            $this->assertEquals('Operator "changed" does not take a value', $details['definition.conditions[1].value'] ?? null);
            $this->assertEquals('Value is required', $details['definition.conditions[2].value'] ?? null);
        });
        $process = null;
        $this->test('changed_from on a manual run with event_type / before / changed_fields; without them — change_info missing', function () use (&$process) {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST changed', 'object_type' => 'TestChange', 'is_child' => false, 'is_active' => true,
                'definition' => [
                    'conditions' => [['field' => 'object.status', 'operator' => 'changed_from', 'value_type' => 'static', 'value' => 'New']],
                    'actions' => [['type' => 'modify', 'target' => 'object.note', 'change_type' => 'template', 'value' => 'from {{ object_before.status }}']],
                ],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'chg-1', 'event_type' => 'update', 'before' => ['status' => 'New'], 'changed_fields' => ['status'], 'status' => 'Assigned',
            ]], $this->editorToken, 201)['data'];
            $this->assertEquals(true, $run['conditions_met']);
            $this->assertEquals(true, $run['conditions'][0]['changed']);
            $miss = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'chg-2', 'status' => 'Assigned']], $this->editorToken)['data'];
            $this->assertEquals(false, $miss['conditions_met']);
            $this->assertEquals('missing', $miss['conditions'][0]['change_info'] ?? null);
        });
        $this->test('changed_to on stream events: phantom change (1000.00 → 1000) is ignored with cast decimal', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST changed_to', 'object_type' => 'TestChange2', 'is_child' => false, 'is_active' => true,
                'definition' => ['conditions' => [
                    ['field' => 'object.amount', 'operator' => 'changed', 'cast' => 'decimal'],
                    ['field' => 'object.stage', 'operator' => 'changed_to', 'value_type' => 'static', 'value' => 'Won'],
                ]],
            ], $this->adminToken, 201)['data'];
            $real = 'chg-real-' . random_int(10000, 99999);
            $this->xadd(['id' => $real, 'module' => 'TestChange2', 'event_type' => 'update', 'before' => ['amount' => '1000.00', 'stage' => 'Open'], 'amount' => '1200', 'stage' => 'Won']);
            $this->waitForExecutions((int)$process['id'], $real, 1);
            $phantom = 'chg-phantom-' . random_int(10000, 99999);
            $this->xadd(['id' => $phantom, 'module' => 'TestChange2', 'event_type' => 'update', 'before' => ['amount' => '1000.00', 'stage' => 'Open'], 'amount' => '1000', 'stage' => 'Won']);
            sleep(3);
            $this->assertEquals(0, count($this->executionsOf((int)$process['id'], $phantom)));
            $this->request('PUT', "/api/processes/{$process['id']}", ['name' => 'TEST changed_to off', 'object_type' => 'TestChange2', 'is_child' => false, 'is_active' => false,
                'definition' => ['conditions' => []]], $this->adminToken, 200);
        });
    }

    /**
     * on_error and retry profiles of steps (md/tasks_list/done/7.1-on-error.md).
     */
    private function testErrorHandling(): void
    {
        echo "[on_error]\n";
        $fail = static fn (array $extra = []): array => ['type' => 'preload', 'var' => 'acc', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'fail500-x'] + $extra;
        $set = static fn (string $target, string $value, array $extra = []): array => ['type' => 'modify', 'target' => $target, 'change_type' => 'set_value', 'value' => $value] + $extra;
        $create = fn (string $name, array $actions, int $status = 201): array => $this->request('POST', '/api/processes', [
            'name' => $name, 'object_type' => 'TestOnError', 'is_child' => false, 'definition' => ['actions' => $actions],
        ], $this->adminToken, $status);
        $run = function (array $process, string $id): array {
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => $id]], $this->editorToken, 201)['data'];
            return $this->waitForExecution($r['execution_id'], 20);
        };
        $this->test('dictionaries error-policies and retry-classes; validation of on_error, retry and goto targets', function () use ($create, $fail, $set) {
            $this->assertEquals(['stop', 'continue', 'goto'], array_column($this->request('GET', '/api/dictionaries/error-policies', token: $this->viewerToken)['data'], 'value'));
            $this->assertTrue(in_array('transient', array_column($this->request('GET', '/api/dictionaries/retry-classes', token: $this->viewerToken)['data'], 'value'), true));
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST on_error bad', 'object_type' => 'TestOnError', 'is_child' => false, 'definition' => [
                'preload' => [['var' => 'v1', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'x', 'on_error' => 'continue']],
                'actions' => [
                    $set('object.a', '1', ['id' => 'first']),
                    $fail(['on_error' => ['goto' => 'first']]),
                    $fail(['on_error' => ['goto' => 'nowhere']]),
                    $fail(['on_error' => 'ignore', 'retry' => ['max_attempts' => 50, 'jitter' => 'random', 'retry_on' => ['6xx']]]),
                    ['type' => 'condition', 'branches' => [['conditions' => [], 'actions' => [$set('object.b', '1', ['id' => 'inner'])]]]],
                    $fail(['on_error' => ['goto' => 'inner']]),
                ],
            ]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Not supported in the preload block', $d['definition.preload[0].on_error'] ?? null);
            $this->assertEquals('Step "first" must be a top-level step after the failing step', $d['definition.actions[1].on_error.goto'] ?? null);
            $this->assertEquals('Step "nowhere" not found', $d['definition.actions[2].on_error.goto'] ?? null);
            $this->assertEquals('Must be "stop", "continue" or {"goto": "<step id>"}', $d['definition.actions[3].on_error'] ?? null);
            $this->assertEquals('Must be an integer between 1 and 20', $d['definition.actions[3].retry.max_attempts'] ?? null);
            $this->assertEquals('Must be one of: none, full, equal', $d['definition.actions[3].retry.jitter'] ?? null);
            $this->assertEquals('Unknown retry class "6xx"', $d['definition.actions[3].retry.retry_on[0]'] ?? null);
            $this->assertEquals('Step "inner" must be a top-level step after the failing step', $d['definition.actions[5].on_error.goto'] ?? null);
        });
        $this->test('on_error continue: the failed step is handled, the next one runs, the execution completes', function () use ($create, $fail, $set, $run) {
            $process = $create('TEST on_error continue', [
                $fail(['on_error' => 'continue', 'retry' => ['max_attempts' => 1]]),
                ['type' => 'modify', 'target' => 'object.err', 'change_type' => 'calculate', 'value' => "concat(sys.last_error.code, ' / ', sys.errors_count)"],
            ])['data'];
            $execution = $run($process, 'onerr-1');
            $this->assertEquals('completed', $execution['status']);
            $this->assertEquals(1, $execution['handled_errors']);
            $this->assertEquals(['failed', 'success'], array_column($execution['actions'], 'status'));
            $this->assertEquals('continue', $execution['actions'][0]['handled']);
            $this->assertEquals('HTTP_500', $execution['actions'][0]['error_code']);
            $this->assertEquals(1, $execution['actions'][0]['attempts']);
            $this->assertEquals('HTTP_500 / 1', $execution['context']['variables']['object']['err']);
        });
        $this->test('on_error goto: steps between are not run, the target gets sys.last_error', function () use ($create, $fail, $set, $run) {
            $process = $create('TEST on_error goto', [
                $fail(['on_error' => ['goto' => 'fallback'], 'retry' => ['max_attempts' => 2, 'base_delay' => '100ms', 'jitter' => 'none']]),
                $set('object.skipped', '1'),
                ['type' => 'modify', 'id' => 'fallback', 'target' => 'object.err', 'change_type' => 'template', 'value' => '{{ sys.last_error.code }} after {{ sys.last_error.attempts }} attempts'],
            ])['data'];
            $execution = $run($process, 'onerr-2');
            $this->assertEquals('completed', $execution['status']);
            $this->assertEquals(['preload', 'modify'], array_column($execution['actions'], 'action_type'));
            $this->assertEquals('goto', $execution['actions'][0]['handled']);
            $this->assertEquals('fallback', $execution['actions'][0]['result_data']['goto'] ?? null);
            $this->assertEquals('HTTP_500 after 2 attempts', $execution['context']['variables']['object']['err']);
            $this->assertFalse(array_key_exists('skipped', $execution['context']['variables']['object']));
        });
        $this->test('stop (default): the execution fails as before; a goto from a branch leaves the branch', function () use ($create, $fail, $set, $run) {
            $stopped = $run($create('TEST on_error stop', [$fail(['retry' => ['max_attempts' => 1]]), $set('object.x', '1')])['data'], 'onerr-3');
            $this->assertEquals('failed', $stopped['status']);
            $this->assertEquals(0, $stopped['handled_errors']);
            $this->assertEquals(['failed', 'skipped'], array_column($stopped['actions'], 'status'));
            $branch = $create('TEST on_error branch', [
                ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'object.id', 'operator' => 'is_not_empty']], 'actions' => [
                    $fail(['on_error' => ['goto' => 'after'], 'retry' => ['max_attempts' => 1]]),
                    $set('object.in_branch', '1'),
                ]]]],
                $set('object.after', '1', ['id' => 'after']),
            ])['data'];
            $execution = $run($branch, 'onerr-4');
            $this->assertEquals('completed', $execution['status']);
            $object = $execution['context']['variables']['object'];
            $this->assertEquals('1', $object['after']);
            $this->assertFalse(array_key_exists('in_branch', $object));
        });
        $this->test('retry profile: a step that fails twice succeeds on the third attempt', function () use ($create, $run) {
            $key = 'fail2x500-' . random_int(100000, 999999);
            $process = $create('TEST retry profile', [
                ['type' => 'preload', 'var' => 'acc', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => $key,
                    'retry' => ['max_attempts' => 3, 'base_delay' => '200ms', 'backoff' => 1, 'jitter' => 'none', 'retry_on' => ['5xx']]],
            ])['data'];
            $execution = $run($process, 'onerr-5');
            $this->assertEquals('completed', $execution['status']);
            $this->assertEquals(3, $execution['actions'][0]['attempts']);
            $this->assertEquals(2, $execution['retry_count']);
        });
    }

    /**
     * send_email through Mailpit (md/tasks_list/done/4.1-send-email.md).
     */
    private function testSendEmail(): void
    {
        echo "[send_email]\n";
        $mailpit = self::env('MAILPIT_API_URL', '');
        $mail = function (string $path) use ($mailpit): array {
            return (array)json_decode((string)file_get_contents($mailpit . $path), true);
        };
        $find = function (string $subject) use ($mail): ?array {
            for ($i = 0; $i < 20; $i++) {
                foreach ((array)($mail('/api/v1/messages?limit=50')['messages'] ?? []) as $m) {
                    if (($m['Subject'] ?? '') === $subject) {
                        return $mail('/api/v1/message/' . $m['ID']);
                    }
                }
                usleep(300000);
            }
            return null;
        };
        $this->test('validation of send_email fields', function () {
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST mail bad', 'object_type' => 'TestMail', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'send_email', 'to' => [], 'subject' => '', 'headers' => ['From' => 'x', 'X-Ok' => '{{ object.id }}'], 'on_empty_recipients' => 'maybe', 'attachments' => []],
                ['type' => 'send_email', 'to' => ['abc', ['path' => 'v9.email']], 'subject' => 'S {{ object.name | foo }}', 'text' => 'x', 'from' => 'not-mail', 'transport' => 'smtp://x', 'delivery' => 'sometimes'],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('At least one recipient is required', $d['definition.actions[0].to'] ?? null);
            $this->assertEquals('Subject is required', $d['definition.actions[0].subject'] ?? null);
            $this->assertEquals('Email body is required (text or html)', $d['definition.actions[0]'] ?? null);
            $this->assertEquals('Header "From" cannot be set here', $d['definition.actions[0].headers.From'] ?? null);
            $this->assertEquals('Must be "skip" or "fail"', $d['definition.actions[0].on_empty_recipients'] ?? null);
            $this->assertEquals('Attachments are not supported yet', $d['definition.actions[0].attachments'] ?? null);
            $this->assertEquals('Invalid email address "abc"', $d['definition.actions[1].to[0]'] ?? null);
            $this->assertEquals('Variable "v9" is not defined at this point', $d['definition.actions[1].to[1].path'] ?? null);
            $this->assertEquals('Unknown template filter "foo"', $d['definition.actions[1].subject'] ?? null);
            $this->assertEquals('Sender must be an email or {"email": ..., "name": ...}', $d['definition.actions[1].from'] ?? null);
            $this->assertEquals('Transport must be a secret reference (secret://name)', $d['definition.actions[1].transport'] ?? null);
            $this->assertTrue(isset($d['definition.actions[1].delivery']));
        });
        $this->test('a letter reaches Mailpit: recipients, escaped HTML, Message-ID of the step, invalid address skipped, history in the CRM', function () use ($find) {
            $subject = 'WPS mail ' . random_int(100000, 999999);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST mail', 'object_type' => 'TestMail', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'send_email', 'id' => 'notify',
                    'to' => ['{{ object.email1 }}', '{{ object.bad_email }}'],
                    'cc' => [['path' => 'object.manager_email', 'name' => 'Менеджер {{ object.manager }}'], '{{ object.email1 }}'],
                    'bcc' => ['audit@company.ru'],
                    'from' => ['email' => 'crm@company.ru', 'name' => 'CRM'],
                    'subject' => $subject . ': {{ object.name }}',
                    'html' => '<p>Сделка <b>{{ object.name }}</b> на {{ object.amount | number:2:",":" " }}</p>',
                    'headers' => ['X-Deal' => '{{ object.id }}'],
                    'save_to_crm' => ['module' => 'Emails', 'parent' => 'object'],
                ],
            ]]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'mail-1', 'module' => 'TestMail', 'email1' => 'Client@Example.COM', 'bad_email' => 'broken-address', 'manager_email' => 'manager@company.ru', 'manager' => 'Олег',
                'name' => '<script>x</script>', 'amount' => '1234567.5',
            ]], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id'], 30);
            $this->assertEquals('completed', $execution['status']);
            $result = $execution['actions'][0]['result_data'];
            $this->assertEquals(true, $result['sent']);
            $this->assertEquals(3, $result['recipients']);
            $this->assertEquals('broken-address', $result['invalid_recipients'][0]['value'] ?? null);
            $this->assertEquals(['***1 hidden***'], $execution['actions'][0]['request_data']['bcc']);
            $message = $find($subject . ': <script>x</script>') ?? throw new RuntimeException('letter not found in Mailpit');
            $this->assertEquals('Client@example.com', $message['To'][0]['Address']);
            $this->assertEquals('manager@company.ru', $message['Cc'][0]['Address']);
            $this->assertEquals(1, count($message['Cc']), 'the duplicate of "to" is removed from "cc"');
            $this->assertEquals('crm@company.ru', $message['From']['Address']);
            $this->assertTrue(str_contains($message['HTML'], '<b>&lt;script&gt;x&lt;/script&gt;</b>'), $message['HTML']);
            $this->assertTrue(str_contains($message['HTML'], '1 234 567,50'));
            $this->assertTrue(str_contains($message['Text'], 'Сделка <script>x</script>'), 'text part generated from html');
            $this->assertEquals($run['execution_id'] . '.0@wps.local', $message['MessageID']);
            $historyId = $result['crm_history_id'] ?? throw new RuntimeException('no history record: ' . json_encode($result));
            $history = $this->crmObject('Emails', (string)$historyId);
            $this->assertEquals('mail-1', $history['parent_id'] ?? null);
            $this->assertEquals('TestMail', $history['parent_type'] ?? null);
            $this->assertEquals('sent', $history['status'] ?? null);
            $effects = $this->request('GET', "/api/executions/{$run['execution_id']}/side-effects", token: $this->adminToken)['data'];
            $this->assertEquals('done', $effects[0]['status']);
            $this->assertEquals('send_email', $effects[0]['action_type']);
        });
        $this->test('no valid recipients: skipped by default, failed with on_empty_recipients fail', function () {
            $make = fn (array $extra) => $this->request('POST', '/api/processes', ['name' => 'TEST mail empty', 'object_type' => 'TestMail', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'send_email', 'to' => ['{{ object.email1 }}'], 'subject' => 'x', 'text' => 'y'] + $extra,
            ]]], $this->adminToken, 201)['data'];
            $skip = $make([]);
            $r1 = $this->request('POST', "/api/processes/{$skip['id']}/run", ['object' => ['id' => 'mail-2']], $this->editorToken, 201)['data'];
            $e1 = $this->waitForExecution($r1['execution_id']);
            $this->assertEquals('skipped', $e1['actions'][0]['status']);
            $fail = $make(['on_empty_recipients' => 'fail']);
            $r2 = $this->request('POST', "/api/processes/{$fail['id']}/run", ['object' => ['id' => 'mail-3']], $this->editorToken, 201)['data'];
            $this->assertEquals('failed', $this->waitForExecution($r2['execution_id'])['status']);
        });
        $this->test('POST /api/mail/test: admin sends a test letter, editor gets 403; mail-transports dictionary', function () use ($find) {
            $res = $this->request('POST', '/api/mail/test', ['to' => 'admin-test@example.com'], $this->adminToken, 200)['data'];
            $this->assertEquals(true, $res['sent']);
            $this->request('POST', '/api/mail/test', ['to' => 'admin-test@example.com'], $this->editorToken, 403);
            $this->assertEquals(true, $this->request('GET', '/api/dictionaries/mail-transports', token: $this->editorToken)['data']['default_configured']);
            $this->request('GET', '/api/dictionaries/mail-transports', token: $this->viewerToken, expectedStatus: 403);
            $types = array_column($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Отправить письмо', $types['send_email'] ?? null);
        });
    }

    /**
     * http_request against the mock (md/tasks_list/done/4.2-http-request.md).
     */
    private function testHttpRequest(): void
    {
        echo "[http_request]\n";
        $mock = 'http://mock:9502';
        $create = fn (string $name, array $actions, int $status = 201): array => $this->request('POST', '/api/processes', [
            'name' => $name, 'object_type' => 'TestHttp', 'is_child' => false, 'definition' => ['actions' => $actions],
        ], $this->adminToken, $status);
        $run = function (array $process, array $object, int $timeout = 20): array {
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => $object], $this->editorToken, 201)['data'];
            return $this->waitForExecution($r['execution_id'], $timeout);
        };
        $last = fn (string $key): array => (array)json_decode((string)file_get_contents("{$this->crmUrl}/mock/echo-last?key={$key}"), true)['data'];
        $this->test('validation of http_request', function () use ($create) {
            $bad = $create('TEST http bad', [
                ['type' => 'http_request', 'method' => 'FETCH', 'url' => '{{ object.url }}'],
                ['type' => 'http_request', 'method' => 'GET', 'url' => 'https://{{ object.host }}/x', 'body' => ['type' => 'json', 'value' => []],
                    'auth' => ['type' => 'bearer', 'token' => 'plain-token'], 'headers' => ['Host' => 'x'],
                    'response' => ['extract' => ['status' => '$.a', 'id' => 'data.id']], 'success_codes' => ['6xx'], 'timeout' => 500, 'follow_redirects' => 9],
                ['type' => 'http_request', 'method' => 'POST', 'url' => 'https://api.example.com/{{ v9.id }}', 'body' => ['type' => 'raw', 'value' => 'x'], 'var' => 'object'],
            ], 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Method must be one of: GET, HEAD, POST, PUT, PATCH, DELETE', $d['definition.actions[0].method'] ?? null);
            $this->assertEquals('Scheme and host of the URL must be literal (https://host/...)', $d['definition.actions[0].url'] ?? null);
            $this->assertEquals('Scheme and host of the URL must be literal (https://host/...)', $d['definition.actions[1].url'] ?? null);
            $this->assertEquals('A body is not allowed for GET', $d['definition.actions[1].body'] ?? null);
            $this->assertEquals('Credentials must reference a secret (secret://name)', $d['definition.actions[1].auth.token'] ?? null);
            $this->assertEquals('Header "Host" cannot be set here', $d['definition.actions[1].headers.Host'] ?? null);
            $this->assertTrue(str_starts_with($d['definition.actions[1].response.extract.status'] ?? '', 'Invalid field name'));
            $this->assertEquals('JSONPath must start with "$"', $d['definition.actions[1].response.extract.id'] ?? null);
            $this->assertEquals('Must be "2xx" or a list of codes and ranges (200, "2xx")', $d['definition.actions[1].success_codes'] ?? null);
            $this->assertEquals('Must be an integer between 1 and 30', $d['definition.actions[1].timeout'] ?? null);
            $this->assertEquals('Must be an integer between 0 and 5', $d['definition.actions[1].follow_redirects'] ?? null);
            $this->assertEquals('Variable "v9" is not defined at this point', $d['definition.actions[2].url'] ?? null);
            $this->assertEquals('Content type is required for a raw body', $d['definition.actions[2].body.content_type'] ?? null);
            $this->assertTrue(isset($d['definition.actions[2].var']));
        });
        $this->test('POST with a typed JSON body: path encoding, query, Idempotency-Key of the step, response in a variable with JSONPath', function () use ($create, $run, $last, $mock) {
            $key = 'http-' . random_int(100000, 999999);
            $process = $create('TEST http post', [
                ['type' => 'http_request', 'var' => 'erp', 'method' => 'POST', 'url' => "{$mock}/mock/echo/orders/{{ object.id }}?fixed=1",
                    'query' => ['deal' => '{{ object.name }}', 'skip' => '{{ object.none }}'], 'headers' => ['X-Mock-Key' => $key],
                    'body' => ['type' => 'json', 'value' => ['number' => '{{ object.name }}', 'amount' => '{{= to_number(object.amount) }}', 'tags' => '{{ object.tags }}', 'note' => 'Сделка {{ object.name }}']],
                    'response' => ['extract' => ['order_id' => '$.data.order_id', 'echoed' => '$.data.json.amount']]],
                ['type' => 'modify', 'target' => 'object.erp_id', 'change_type' => 'set_from_variable', 'value' => 'erp.order_id'],
            ])['data'];
            $execution = $run($process, ['id' => 'a/b', 'name' => 'Big & deal', 'amount' => '1 500,50', 'tags' => ['x', 'y']]);
            $this->assertEquals('completed', $execution['status']);
            $erp = $execution['context']['variables']['erp'];
            $this->assertEquals(200, $erp['status']);
            $this->assertEquals('A-1001', $erp['order_id']);
            $this->assertEquals(1500.5, $erp['echoed']);
            $this->assertEquals('A-1001', $execution['context']['variables']['object']['erp_id']);
            $echo = $last($key)['last'];
            $this->assertEquals('/mock/echo/orders/a%2Fb', $echo['path']);
            $this->assertEquals(['fixed' => '1', 'deal' => 'Big & deal'], $echo['query']);
            $json = $echo['json'];
            ksort($json); // the definition is stored as jsonb: object keys come back sorted
            $this->assertEquals(['amount' => 1500.5, 'note' => 'Сделка Big & deal', 'number' => 'Big & deal', 'tags' => ['x', 'y']], $json);
            $this->assertEquals($execution['id'] . ':0', $echo['headers']['idempotency-key'] ?? null);
            $this->assertEquals('application/json', $echo['headers']['content-type'] ?? null);
        });
        $this->test('errors: 422 is not repeated; 503 with Retry-After is repeated; 500 without an idempotency key is an unknown outcome', function () use ($create, $run, $last, $mock) {
            $k422 = 'h422-' . random_int(1000, 9999);
            $e422 = $run($create('TEST http 422', [['type' => 'http_request', 'method' => 'PUT', 'url' => "{$mock}/mock/status/422?key={$k422}", 'body' => ['type' => 'json', 'value' => ['a' => 1]]]])['data'], ['id' => 'h1']);
            $this->assertEquals('failed', $e422['status']);
            $this->assertEquals('HTTP_422', $e422['actions'][0]['error_code']);
            $this->assertEquals(1, $e422['actions'][0]['attempts']);
            $k503 = 'h503-' . random_int(1000, 9999);
            $started = microtime(true);
            $e503 = $run($create('TEST http 503', [['type' => 'http_request', 'method' => 'GET', 'url' => "{$mock}/mock/status/503?retry_after=1&key={$k503}",
                'retry' => ['max_attempts' => 2, 'base_delay' => '100ms', 'jitter' => 'none']]])['data'], ['id' => 'h2']);
            $this->assertEquals('failed', $e503['status']);
            $this->assertEquals(2, $e503['actions'][0]['attempts']);
            $this->assertTrue(microtime(true) - $started >= 1.0, 'Retry-After: 1 s waited');
            $this->assertEquals(2, $last($k503)['count']);
            $k500 = 'h500-' . random_int(1000, 9999);
            $e500 = $run($create('TEST http 500', [['type' => 'http_request', 'method' => 'POST', 'url' => "{$mock}/mock/status/500?key={$k500}", 'idempotency_header' => null,
                'body' => ['type' => 'form', 'value' => ['a' => '1']]]])['data'], ['id' => 'h3']);
            $this->assertEquals('UNKNOWN_OUTCOME', $e500['actions'][0]['error_code']);
            $this->assertEquals(1, $last($k500)['count'], 'not repeated');
            $k500b = 'h500b-' . random_int(1000, 9999);
            $e500b = $run($create('TEST http 500 key', [['type' => 'http_request', 'method' => 'POST', 'url' => "{$mock}/mock/status/500?key={$k500b}",
                'body' => ['type' => 'form', 'value' => ['a' => '1']], 'retry' => ['max_attempts' => 2, 'base_delay' => '100ms']]])['data'], ['id' => 'h4']);
            $this->assertEquals('HTTP_500', $e500b['actions'][0]['error_code']);
            $this->assertEquals(2, $last($k500b)['count'], 'with an Idempotency-Key the server deduplicates: repeated');
        });
        $this->test('SSRF protection, redirects, response size, success_codes', function () use ($create, $run, $mock) {
            $meta = $run($create('TEST http ssrf', [['type' => 'http_request', 'method' => 'GET', 'url' => 'http://169.254.169.254/latest/meta-data']])['data'], ['id' => 'h5']);
            $this->assertEquals('Destination address is not allowed (private network)', $meta['actions'][0]['error_message']);
            $pg = $run($create('TEST http ssrf host', [['type' => 'http_request', 'method' => 'GET', 'url' => 'http://postgres:8080/']])['data'], ['id' => 'h6']);
            $this->assertEquals('Host "postgres" is not allowed', $pg['actions'][0]['error_message']);
            $redirect = $run($create('TEST http redirect', [
                ['type' => 'http_request', 'var' => 'r', 'method' => 'GET', 'url' => "{$mock}/mock/redirect?to=/mock/echo/after", 'follow_redirects' => 2],
                ['type' => 'http_request', 'var' => 'nr', 'method' => 'GET', 'url' => "{$mock}/mock/redirect?to=/mock/echo/after", 'success_codes' => ['2xx', 302]],
            ])['data'], ['id' => 'h7']);
            $this->assertEquals('completed', $redirect['status']);
            $this->assertEquals('/mock/echo/after', $redirect['context']['variables']['r']['body']['data']['path']);
            $this->assertEquals(302, $redirect['context']['variables']['nr']['status']);
            $big = $run($create('TEST http big', [['type' => 'http_request', 'method' => 'GET', 'url' => "{$mock}/mock/big?bytes=6000000"]])['data'], ['id' => 'h8']);
            $this->assertEquals('RESPONSE_TOO_LARGE', $big['actions'][0]['error_code']);
            $text = $run($create('TEST http text', [['type' => 'http_request', 'var' => 't', 'method' => 'GET', 'url' => "{$mock}/mock/text?charset=windows-1251"]])['data'], ['id' => 'h9']);
            $this->assertEquals('Привет', $text['context']['variables']['t']['body_text']);
        });
        $this->test('auth from secrets: a bearer secret with allowed_hosts is not sent to another host; OAuth2 token_url must be https', function () use ($create, $run, $mock) {
            $suffix = random_int(1000, 9999);
            $bad = $this->request('POST', '/api/secrets', ['name' => "test_oauth_{$suffix}", 'kind' => 'oauth2_client',
                'value' => ['token_url' => "{$mock}/mock/oauth/token", 'client_id' => 'wps', 'client_secret' => 'wps-secret']], $this->adminToken, 422);
            $this->assertEquals('Token URL must use https', $bad['error']['details'][0]['message']);
            $this->request('POST', '/api/secrets', ['name' => "test_bearer_{$suffix}", 'kind' => 'bearer', 'value' => 'tok-static-0123456789',
                'allowed_hosts' => ['api.example.com']], $this->adminToken, 201);
            $this->request('POST', '/api/secrets', ['name' => "test_mock_{$suffix}", 'kind' => 'bearer', 'value' => 'tok-mock-0123456789',
                'allowed_hosts' => ['mock']], $this->adminToken, 201);
            $denied = $run($create('TEST http bearer host', [['type' => 'http_request', 'method' => 'GET', 'url' => "{$mock}/mock/protected",
                'auth' => ['type' => 'bearer', 'token' => "secret://test_bearer_{$suffix}"]]])['data'], ['id' => 'h12']);
            $this->assertEquals("Secret \"test_bearer_{$suffix}\" is not allowed for host \"mock\"", $denied['actions'][0]['error_message']);
            $allowed = $run($create('TEST http bearer ok', [['type' => 'http_request', 'var' => 'p', 'method' => 'GET', 'url' => "{$mock}/mock/protected",
                'auth' => ['type' => 'bearer', 'token' => "secret://test_mock_{$suffix}"]]])['data'], ['id' => 'h13']);
            $this->assertEquals('completed', $allowed['status']);
            $this->assertEquals('***', $allowed['context']['variables']['p']['body']['data']['token'] ?? null, 'the token is masked wherever it is echoed back');
            foreach ($this->request('GET', '/api/processes?search=TEST%20http&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
            foreach (["test_bearer_{$suffix}", "test_mock_{$suffix}"] as $name) {
                $this->request('DELETE', "/api/secrets/{$name}", token: $this->adminToken);
            }
        });
    }

    /**
     * link_records / unlink_records against the mock of contract 2.0 (md/tasks_list/done/4.12-link-records.md).
     */
    private function testLinkRecords(): void
    {
        echo "[link_records]\n";
        $rel = fn (string $module, string $id, string $link): array => (array)(json_decode((string)file_get_contents("{$this->crmUrl}/mock/relationships/{$module}/{$id}/{$link}"), true)['data'] ?? []);
        $this->test('a meeting is created and linked to users (accept_status) and to the lead; a repeat updates without duplicates; unlink removes', function () use ($rel) {
            $meeting = [
                ['type' => 'modify', 'target' => 'meeting.name', 'change_type' => 'template', 'value' => 'Встреча: {{ object.last_name }}'],
                ['type' => 'save_object', 'target' => 'meeting', 'module' => 'Meetings'],
                ['type' => 'link_records', 'id' => 'invite', 'record' => 'meeting', 'link' => 'users', 'related_module' => 'Users',
                    'related' => [['path' => 'object.assigned_user_id'], ['path' => 'object.team'], ['value' => '1']], 'fields' => ['accept_status' => 'accept']],
                ['type' => 'link_records', 'record' => 'meeting', 'link' => 'leads', 'related' => [['path' => 'object.id']]],
                ['type' => 'link_records', 'record' => 'object', 'link' => 'calls', 'related' => [['path' => 'object.none']]],
                ['type' => 'link_records', 'record' => 'draft', 'link' => 'users', 'related' => [['value' => '1']]],
            ];
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST link', 'object_type' => 'TestLink', 'is_child' => false, 'definition' => ['actions' => array_merge(
                [['type' => 'modify', 'target' => 'draft.name', 'change_type' => 'set_value', 'value' => 'not saved']], $meeting)]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'lead-link-1', 'module' => 'TestLink', 'last_name' => 'Иванов', 'assigned_user_id' => 'u7', 'team' => ['u8', 'u7'],
            ]], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('completed', $execution['status']);
            $steps = $execution['actions'];
            $meetingId = $execution['context']['variables']['meeting']['id'];
            $this->assertEquals(['u7', 'u8', '1'], $steps[3]['result_data']['linked']);
            $this->assertEquals('skipped', $steps[5]['status']);
            $this->assertEquals('No related ids to link', $steps[5]['result_data']['reason']);
            $this->assertEquals('skipped', $steps[6]['status'], 'a record without id');
            $users = $rel('Meetings', $meetingId, 'users');
            $this->assertEquals(['1', 'u7', 'u8'], array_map('strval', array_keys($users)));
            $this->assertEquals('accept', $users['u7']['fields']['accept_status'] ?? null);
            $this->assertEquals(['lead-link-1'], array_keys($rel('Meetings', $meetingId, 'leads')));

            $again = $this->request('POST', '/api/processes', ['name' => 'TEST link again', 'object_type' => 'TestLink', 'is_child' => false, 'definition' => [
                'preload' => [['var' => 'm', 'source' => 'manual', 'module' => 'Meetings', 'by_field' => 'id', 'value' => $meetingId]],
                'actions' => [
                    ['type' => 'link_records', 'record' => 'm', 'link' => 'users', 'related' => [['value' => 'u7']], 'fields' => ['accept_status' => 'tentative']],
                    ['type' => 'unlink_records', 'record' => 'm', 'link' => 'users', 'related' => [['value' => 'u8'], ['value' => 'u99']]],
                ]]], $this->adminToken, 201)['data'];
            $r2 = $this->request('POST', "/api/processes/{$again['id']}/run", ['object' => ['id' => 'lead-link-1', 'module' => 'TestLink']], $this->editorToken, 201)['data'];
            $e2 = $this->waitForExecution($r2['execution_id']);
            $this->assertEquals('completed', $e2['status']);
            $this->assertEquals(['u7'], $e2['actions'][1]['result_data']['updated']);
            $this->assertEquals(['u8'], $e2['actions'][2]['result_data']['removed']);
            $this->assertEquals(['u99'], $e2['actions'][2]['result_data']['not_linked']);
            $users = $rel('Meetings', $meetingId, 'users');
            $this->assertEquals(['1', 'u7'], array_map('strval', array_keys($users)));
            $this->assertEquals('tentative', $users['u7']['fields']['accept_status']);
            $types = array_column($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Связать записи', $types['link_records'] ?? null);
            $this->assertEquals('Отвязать записи', $types['unlink_records'] ?? null);
        });
        $this->test('validation and an old CRM without relationships (contract 1.0)', function () {
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST link bad', 'object_type' => 'TestLink', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'link_records', 'record' => 'v9', 'link' => 'users', 'related' => []],
                ['type' => 'unlink_records', 'record' => 'object', 'link' => 'users', 'related' => [['value' => '1']], 'fields' => ['a' => 'b']],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Variable "v9" is not defined at this point', $d['definition.actions[0].record'] ?? null);
            $this->assertEquals('At least one related id source is required', $d['definition.actions[0].related'] ?? null);
            $this->assertEquals('Fields are not used when unlinking', $d['definition.actions[1].fields'] ?? null);
            $mock = fn (array $config) => file_get_contents("{$this->crmUrl}/mock/config", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($config)]]));
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST link legacy', 'object_type' => 'TestLink', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'link_records', 'record' => 'object', 'link' => 'users', 'related' => [['value' => '1']]],
            ]]], $this->adminToken, 201)['data'];
            $mock(['contract' => '1.0']);
            try {
                $this->request('POST', '/api/dictionaries/modules/refresh', [], $this->editorToken);
                $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'x1', 'module' => 'TestLink']], $this->editorToken, 201)['data'];
                $e = $this->waitForExecution($r['execution_id']);
                $this->assertEquals('CRM_CAPABILITY_MISSING', $e['actions'][0]['error_code']);
            } finally {
                $mock(['contract' => '2.0']);
                $this->request('POST', '/api/dictionaries/modules/refresh', [], $this->editorToken);
            }
            foreach ($this->request('GET', '/api/processes?search=TEST%20link&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * find_records: CRM search by a filter and related records (md/tasks_list/done/4.10-find-records.md).
     */
    private function testFindRecords(): void
    {
        echo "[find_records]\n";
        $put = fn (string $path, array $body) => file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
        $this->test('search by filter (not_in, decimal, sort, fields, limit), empty value, related records with a local filter', function () use ($put) {
            foreach ([
                ['fd1', 'Сделка A', 'Prospecting', '500'], ['fd2', 'Сделка B', 'Closed Won', '900'], ['fd3', 'Сделка C', 'Negotiation', '1500.50'],
                ['fd4', 'Сделка D', 'Closed Lost', '700'], ['fd5', 'Сделка E', 'Qualification', '50'], ['fd6', 'Сделка F', 'Proposal', '300'],
            ] as [$id, $name, $stage, $amount]) {
                $put("objects/FindDeals/{$id}", ['name' => $name, 'sales_stage' => $stage, 'amount' => $amount, 'account_id' => 'acc-find']);
            }
            $put('objects/FindDeals/fd7', ['name' => 'Чужая', 'sales_stage' => 'Prospecting', 'amount' => '9999', 'account_id' => 'acc-other']);
            $put('objects/TestFind/acc-find', ['name' => 'Компания']);
            foreach ([['fc1', 'Decision Maker'], ['fc2', 'Technical'], ['fc3', 'Decision Maker']] as [$id, $role]) {
                $put("objects/Contacts/{$id}", ['last_name' => "Контакт {$id}", 'title' => $role]);
                $put("relationships/TestFind/acc-find/contacts/{$id}", ['related_module' => 'Contacts']);
            }
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST find', 'object_type' => 'TestFind', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'id' => 'deals', 'var' => 'deals', 'module' => 'FindDeals', 'filter' => [
                    ['field' => 'account_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.id'],
                    ['field' => 'sales_stage', 'operator' => 'not_in', 'value' => ['Closed Won', 'Closed Lost']],
                    ['field' => 'amount', 'operator' => 'greater_or_equal', 'value' => '100', 'cast' => 'decimal'],
                ], 'sort' => [['field' => 'amount', 'direction' => 'desc']], 'fields' => ['name', 'amount'], 'limit' => 2],
                ['type' => 'modify', 'target' => 'object.description', 'change_type' => 'template', 'value' => '{{ deals.count }}/{{ deals.has_more }}: {{ deals.items.0.name }}'],
                ['type' => 'find_records', 'id' => 'none', 'var' => 'none', 'module' => 'FindDeals', 'filter' => [
                    ['field' => 'account_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.missing'],
                ]],
                ['type' => 'find_records', 'id' => 'contacts', 'var' => 'contacts', 'related_to' => ['record' => 'object', 'link' => 'contacts'], 'filter' => [
                    ['field' => 'title', 'operator' => 'equals', 'value' => 'Decision Maker'],
                ]],
            ]]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'acc-find', 'module' => 'TestFind']], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('completed', $execution['status'], json_encode($execution['actions'], JSON_UNESCAPED_UNICODE));
            $vars = $execution['context']['variables'];
            $this->assertEquals(['fd3', 'fd1'], $vars['deals']['ids']);
            $this->assertEquals(2, $vars['deals']['count']);
            $this->assertTrue($vars['deals']['has_more'], 'fd6 is the third');
            $this->assertEquals(['nin' => ['Closed Won', 'Closed Lost']], $execution['actions'][0]['request_data']['crm_filter']['filter']['sales_stage']);
            $this->assertEquals('2/true: Сделка C', $execution['actions'][1]['result_data']['new_value']);
            $this->assertEquals(0, $vars['none']['count']);
            $this->assertEquals('The value of filter[0] (object.missing) is empty', $execution['actions'][2]['result_data']['reason']);
            $this->assertEquals(['fc1', 'fc3'], $vars['contacts']['ids']);
            $types = array_column($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Найти записи', $types['find_records'] ?? null);
        });
        $this->test('validation: list variable is read-only, filter rules; on_limit error', function () {
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST find bad', 'object_type' => 'TestFind', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'var' => 'deals', 'module' => 'FindDeals', 'filter' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => '1']]],
                ['type' => 'modify', 'target' => 'deals.count', 'change_type' => 'set_value', 'value' => '1'],
                ['type' => 'save_object', 'target' => 'deals', 'module' => 'FindDeals'],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Operator "greater_than" requires cast: int, decimal, date or datetime', $d['definition.actions[0].filter[0].cast'] ?? null);
            $this->assertTrue(isset($d['definition.actions[1].target']), json_encode($d, JSON_UNESCAPED_UNICODE));
            $this->assertTrue(isset($d['definition.actions[2].target']), json_encode($d, JSON_UNESCAPED_UNICODE));
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST find limit', 'object_type' => 'TestFind', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'var' => 'deals', 'module' => 'FindDeals', 'filter' => [['field' => 'account_id', 'operator' => 'equals', 'value' => 'acc-find']], 'limit' => 1, 'on_limit' => 'error'],
            ]]], $this->adminToken, 201)['data'];
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'x', 'module' => 'TestFind']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($r['execution_id']);
            $this->assertEquals('failed', $e['status']);
            $this->assertEquals('find_records: more than 1 records match (on_limit=error)', $e['actions'][0]['error_message'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20find&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * foreach over found records (md/tasks_list/4.11-foreach.md).
     */
    private function testForeach(): void
    {
        echo "[foreach]\n";
        $put = fn (string $path, array $body) => file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
        $definition = fn (string $account, array $loop) => ['actions' => [
            ['type' => 'find_records', 'var' => 'contacts', 'module' => 'LoopContacts', 'filter' => [['field' => 'account_id', 'operator' => 'equals', 'value' => $account]], 'sort' => [['field' => 'last_name']]],
            ['type' => 'foreach', 'id' => 'each', 'source' => 'contacts.items', 'as' => 'contact', 'actions' => [
                ['type' => 'modify', 'target' => 'contact.assigned_user_id', 'change_type' => 'set_from_variable', 'value' => 'object.assigned_user_id'],
                ['type' => 'save_object', 'target' => 'contact', 'retry' => ['max_attempts' => 1]],
            ]] + $loop,
            ['type' => 'modify', 'target' => 'object.description', 'change_type' => 'template', 'value' => 'loop done'],
        ]];
        $runProcess = function (string $name, array $definition, array $object): array {
            $process = $this->request('POST', '/api/processes', ['name' => $name, 'object_type' => 'TestLoop', 'is_child' => false, 'definition' => $definition], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => $object + ['module' => 'TestLoop']], $this->editorToken, 201)['data'];
            return $this->waitForExecution($run['execution_id'], 30);
        };
        $this->test('the responsible user of every contact of the account is changed; audit: one foreach record and body records with iteration', function () use ($put, $definition, $runProcess) {
            foreach (['lc1' => 'Алексеев', 'lc2' => 'Борисов', 'lc3' => 'Васильев'] as $id => $name) {
                $put("objects/LoopContacts/{$id}", ['last_name' => $name, 'account_id' => 'acc-loop', 'assigned_user_id' => 'old']);
            }
            $execution = $runProcess('TEST foreach', $definition('acc-loop', []), ['id' => 'acc-loop', 'assigned_user_id' => 'u42']);
            $this->assertEquals('completed', $execution['status'], json_encode($execution['actions'], JSON_UNESCAPED_UNICODE));
            foreach (['lc1', 'lc2', 'lc3'] as $id) {
                $this->assertEquals('u42', $this->crmObject('LoopContacts', $id)['assigned_user_id'] ?? null, $id);
            }
            $steps = $execution['actions'];
            $foreach = array_values(array_filter($steps, static fn ($s) => $s['action_type'] === 'foreach'));
            $this->assertEquals(1, count($foreach));
            $this->assertEquals(['count' => 3, 'processed' => 3, 'failed_items' => 0, 'unsaved_items' => 0],
                array_intersect_key($foreach[0]['result_data'], ['count' => 1, 'processed' => 1, 'failed_items' => 1, 'unsaved_items' => 1]));
            $body = array_values(array_filter($steps, static fn ($s) => str_starts_with((string)$s['step_path'], 'actions[1].actions')));
            $this->assertEquals(['0', '0', '1', '1', '2', '2'], array_column($body, 'iteration'));
            $this->assertEquals('loop done', end($steps)['result_data']['new_value']);
            $this->assertArrayNotHasKey('contact', $execution['context']['variables']);
            $types = array_column($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Для каждого элемента', $types['foreach'] ?? null);
        });
        $this->test('on_item_error: continue goes on after a failed contact (completed, failed_items = 1); stop fails the execution', function () use ($put, $definition, $runProcess) {
            $put('objects/LoopContacts/lf1', ['last_name' => 'Аа', 'account_id' => 'acc-loop-fail', 'assigned_user_id' => 'old']);
            $put('objects/LoopContacts/fail500-loop', ['last_name' => 'Бб', 'account_id' => 'acc-loop-fail', 'assigned_user_id' => 'old']);
            $put('objects/LoopContacts/lf3', ['last_name' => 'Вв', 'account_id' => 'acc-loop-fail', 'assigned_user_id' => 'old']);
            $e = $runProcess('TEST foreach continue', $definition('acc-loop-fail', ['on_item_error' => 'continue']), ['id' => 'acc-loop-fail', 'assigned_user_id' => 'u43']);
            $this->assertEquals('completed', $e['status']);
            $foreach = array_values(array_filter($e['actions'], static fn ($s) => $s['action_type'] === 'foreach'))[0];
            $this->assertEquals([1, [1], 2], [$foreach['result_data']['failed_items'], $foreach['result_data']['failed_indexes'], $foreach['result_data']['processed']]);
            $failed = array_values(array_filter($e['actions'], static fn ($s) => $s['status'] === 'failed'));
            $this->assertEquals(['item_continue', '1'], [$failed[0]['handled'], $failed[0]['iteration']]);
            $this->assertEquals('u43', $this->crmObject('LoopContacts', 'lf3')['assigned_user_id']);

            $s = $runProcess('TEST foreach stop', $definition('acc-loop-fail', []), ['id' => 'acc-loop-fail', 'assigned_user_id' => 'u44']);
            $this->assertEquals('failed', $s['status']);
            $foreach = array_values(array_filter($s['actions'], static fn ($a) => $a['action_type'] === 'foreach'))[0];
            $this->assertEquals(['failed', 1], [$foreach['status'], $foreach['result_data']['failed_at_index']]);
            $this->assertEquals('skipped', end($s['actions'])['status']);
            $this->assertEquals('u43', $this->crmObject('LoopContacts', 'lf3')['assigned_user_id'], 'the third contact is not reached');
        });
        $this->test('a delay inside the loop parks the execution; the list snapshot survives the wait', function () use ($put, $runProcess) {
            $put('objects/LoopContacts/lw1', ['last_name' => 'Аа', 'account_id' => 'acc-loop-wait']);
            $put('objects/LoopContacts/lw2', ['last_name' => 'Бб', 'account_id' => 'acc-loop-wait']);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST foreach wait', 'object_type' => 'TestLoop', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'var' => 'contacts', 'module' => 'LoopContacts', 'filter' => [['field' => 'account_id', 'operator' => 'equals', 'value' => 'acc-loop-wait']], 'sort' => [['field' => 'last_name']]],
                ['type' => 'foreach', 'source' => 'contacts.items', 'as' => 'c', 'where' => [['field' => 'c.last_name', 'operator' => 'equals', 'value' => 'Бб']], 'actions' => [
                    ['type' => 'delay', 'mode' => 'duration', 'duration' => '6s'],
                    ['type' => 'modify', 'target' => 'c.title', 'change_type' => 'template', 'value' => 'после паузы {{ c_loop.number }}/{{ c_loop.count }}'],
                    ['type' => 'save_object', 'target' => 'c'],
                ]],
            ]]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'acc-loop-wait', 'module' => 'TestLoop']], $this->editorToken, 201)['data'];
            usleep(2_000_000);
            $this->assertEquals('waiting', $this->request('GET', "/api/executions/{$run['execution_id']}", token: $this->viewerToken)['data']['status']);
            $keys = $this->redisData()->keys("exec:{$run['execution_id']}*");
            $this->assertEquals([], $keys, 'no Redis keys while waiting');
            $e = $this->waitForExecution($run['execution_id'], 30);
            $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
            $this->assertEquals('после паузы 2/2', $this->crmObject('LoopContacts', 'lw2')['title'] ?? null);
            $this->assertEquals(null, $this->crmObject('LoopContacts', 'lw1')['title'] ?? null, 'rejected by where');
            $foreach = array_values(array_filter($e['actions'], static fn ($a) => $a['action_type'] === 'foreach'))[0];
            $this->assertEquals([1, 1], [$foreach['result_data']['processed'], $foreach['result_data']['skipped_by_where']]);
        });
        $this->test('validation of foreach', function () {
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST foreach bad', 'object_type' => 'TestLoop', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'foreach', 'as' => 'object', 'actions' => [['type' => 'modify', 'target' => 'object_loop.index', 'change_type' => 'set_value', 'value' => '1']]],
                ['type' => 'foreach', 'source' => 'v1.items', 'as' => 'x', 'max_items' => 100000, 'actions' => [['type' => 'modify', 'target' => 'x_loop.index', 'change_type' => 'set_value', 'value' => '1']]],
                ['type' => 'save_object', 'target' => 'x'],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('List source is required (e.g. deals.items)', $d['definition.actions[0].source'] ?? null);
            $this->assertEquals('Name "object" is reserved', $d['definition.actions[0].as'] ?? null);
            $this->assertEquals('Variable "v1" is not defined at this point', $d['definition.actions[1].source'] ?? null);
            $this->assertEquals('max_items must be an integer between 1 and 500', $d['definition.actions[1].max_items'] ?? null);
            $this->assertEquals('Variable "x_loop" is read-only', $d['definition.actions[1].actions[0].target'] ?? null);
            $this->assertEquals('Variable "x" is not defined at this point', $d['definition.actions[2].target'] ?? null, json_encode($d));
            foreach ($this->request('GET', '/api/processes?search=TEST%20foreach&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}?force=true", token: $this->adminToken);
            }
        });
    }

    /**
     * update_records: the same fields of every record of a list in one step (md/tasks_list/4.6-update-related.md).
     */
    private function testUpdateRecords(): void
    {
        echo "[update_records]\n";
        $put = fn (string $path, array $body) => file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
        $this->test('the responsible user goes to every contact of the account: updated 2, unchanged 1; a missing record fails the step after the others', function () use ($put) {
            $acc = 'acc-upd-' . bin2hex(random_bytes(3));
            $put("objects/TestUpd/{$acc}", ['name' => 'Компания']);
            foreach (['uc1' => 'old', 'uc2' => 'old', 'uc3' => 'u77'] as $id => $user) {
                $put("objects/UpdContacts/{$id}", ['last_name' => "Контакт {$id}", 'assigned_user_id' => $user, 'description' => 'был']);
                $put("relationships/TestUpd/{$acc}/contacts/{$id}", ['related_module' => 'UpdContacts']);
            }
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST update_records', 'object_type' => 'TestUpd', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'var' => 'contacts', 'related_to' => ['record' => 'object', 'link' => 'contacts']],
                ['type' => 'update_records', 'id' => 'reassign', 'list' => 'contacts', 'set' => [
                    ['field' => 'assigned_user_id', 'change_type' => 'set_from_variable', 'value' => 'object.assigned_user_id'],
                ]],
                ['type' => 'modify', 'target' => 'object.description', 'change_type' => 'template', 'value' => '{{ contacts.items.0.assigned_user_id }}'],
            ]]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => $acc, 'module' => 'TestUpd', 'assigned_user_id' => 'u77']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
            $step = $e['actions'][1];
            $this->assertEquals([2, 1, 0], [$step['result_data']['updated'], $step['result_data']['unchanged'], $step['result_data']['errors']]);
            $this->assertEquals(['uc1' => 'updated', 'uc2' => 'updated', 'uc3' => 'unchanged'], array_column($step['result_data']['items'], 'status', 'id'));
            foreach (['uc1', 'uc2', 'uc3'] as $id) {
                $this->assertEquals('u77', $this->crmObject('UpdContacts', $id)['assigned_user_id'] ?? null, $id);
            }
            $this->assertEquals('u77', $e['actions'][2]['result_data']['new_value'], 'the list in the context has the new values');

            $put("relationships/TestUpd/{$acc}/contacts/ghost-upd", ['related_module' => 'UpdContacts']);
            $p2 = $this->request('POST', '/api/processes', ['name' => 'TEST update_records 2', 'object_type' => 'TestUpd', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'var' => 'contacts', 'related_to' => ['record' => 'object', 'link' => 'contacts']],
                ['type' => 'update_records', 'list' => 'contacts', 'item_var' => 'c', 'only_changed' => false, 'module' => 'UpdContacts', 'set' => [
                    ['field' => 'description', 'change_type' => 'calculate', 'value' => "concat(c.last_name, ' — проверен')"],
                ], 'on_error' => 'continue'],
            ]]], $this->adminToken, 201)['data'];
            $r2 = $this->request('POST', "/api/processes/{$p2['id']}/run", ['object' => ['id' => $acc, 'module' => 'TestUpd']], $this->editorToken, 201)['data'];
            $e2 = $this->waitForExecution($r2['execution_id']);
            $this->assertEquals('completed', $e2['status']);
            $step = $e2['actions'][1];
            $this->assertEquals(['failed', 'continue'], [$step['status'], $step['handled']]);
            $this->assertEquals('update_records: 1 of 4 records failed', $step['error_message']);
            $this->assertEquals([3, 1], [$step['result_data']['details']['updated'] ?? null, $step['result_data']['details']['errors'] ?? null], json_encode($step['result_data'], JSON_UNESCAPED_UNICODE));
            $this->assertEquals('Контакт uc3 — проверен', $this->crmObject('UpdContacts', 'uc3')['description'] ?? null);
            $types = array_column($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Изменить записи списка', $types['update_records'] ?? null);
        });
        $this->test('validation of update_records; an empty list skips the step', function () {
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST update_records bad', 'object_type' => 'TestUpd', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'update_records', 'list' => 'object', 'set' => [['field' => 'id', 'change_type' => 'set_value', 'value' => '1']]],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Variable "object" is not a record list (use find_records)', $d['definition.actions[0].list'] ?? null);
            $this->assertEquals('Field "id" cannot be changed', $d['definition.actions[0].set[0].field'] ?? null);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST update_records empty', 'object_type' => 'TestUpd', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'find_records', 'var' => 'none', 'module' => 'UpdContacts', 'filter' => [['field' => 'last_name', 'operator' => 'equals', 'value' => 'никто']]],
                ['type' => 'update_records', 'list' => 'none', 'set' => [['field' => 'title', 'change_type' => 'set_value', 'value' => '1']]],
            ]]], $this->adminToken, 201)['data'];
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'x', 'module' => 'TestUpd']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($r['execution_id']);
            $this->assertEquals(['completed', 'skipped'], [$e['status'], $e['actions'][1]['status']]);
            foreach ($this->request('GET', '/api/processes?search=TEST%20update_records&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * aggregate change type over CRM records (md/tasks_list/5.1.7-aggregate.md).
     */
    private function testAggregate(): void
    {
        echo "[aggregate]\n";
        $put = fn (string $path, array $body) => file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
        $this->test('sum of won deals, count by meta.total, max date, count by a relationship, limit with partial / error', function () use ($put) {
            foreach ([['ag1', 'Closed Won', '1000.50', '2026-10-01'], ['ag2', 'Closed Won', '2499.50', '2026-11-15'], ['ag3', 'Prospecting', '7000', '2026-12-01'], ['ag4', 'Closed Won', '', '2026-09-01']] as [$id, $stage, $amount, $date]) {
                $put("objects/AggDeals/{$id}", ['account_id' => 'acc-agg', 'sales_stage' => $stage, 'amount' => $amount, 'date_closed' => $date]);
            }
            $put('objects/TestAgg/acc-agg', ['name' => 'Компания']);
            foreach (['agc1', 'agc2'] as $id) {
                $put("relationships/TestAgg/acc-agg/contacts/{$id}", ['related_module' => 'Contacts']);
            }
            $won = [['field' => 'account_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.id'], ['field' => 'sales_stage', 'operator' => 'equals', 'value' => 'Closed Won']];
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST aggregate', 'object_type' => 'TestAgg', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'set_variable', 'var' => 'agg', 'fields' => [
                    ['name' => 'won', 'change_type' => 'aggregate', 'function' => 'sum', 'field' => 'amount', 'source' => ['module' => 'AggDeals', 'filter' => $won]],
                    ['name' => 'won_count', 'change_type' => 'aggregate', 'function' => 'count', 'source' => ['module' => 'AggDeals', 'filter' => $won]],
                    ['name' => 'avg', 'change_type' => 'aggregate', 'function' => 'avg', 'field' => 'amount', 'source' => ['module' => 'AggDeals', 'filter' => $won]],
                    ['name' => 'last', 'change_type' => 'aggregate', 'function' => 'max', 'field' => 'date_closed', 'cast' => 'date', 'source' => ['module' => 'AggDeals', 'filter' => [$won[0]]]],
                    ['name' => 'contacts', 'change_type' => 'aggregate', 'function' => 'count', 'source' => ['relationship' => ['module' => 'TestAgg', 'id' => 'object.id', 'link' => 'contacts']]],
                    ['name' => 'partial', 'change_type' => 'aggregate', 'function' => 'count', 'field' => 'id', 'limit' => 2, 'on_limit' => 'partial', 'source' => ['module' => 'AggDeals', 'filter' => [$won[0]]]],
                    ['name' => 'none', 'change_type' => 'aggregate', 'function' => 'sum', 'field' => 'amount', 'source' => ['module' => 'AggDeals', 'filter' => [['field' => 'account_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.missing']]]],
                ]],
                ['type' => 'modify', 'target' => 'object.total_won_c', 'change_type' => 'aggregate', 'function' => 'sum', 'field' => 'amount', 'limit' => 2,
                    'source' => ['module' => 'AggDeals', 'filter' => [$won[0]]], 'on_error' => 'continue'],
            ]]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'acc-agg', 'module' => 'TestAgg']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
            $agg = $e['context']['variables']['agg'];
            ksort($agg);
            $this->assertEquals(['avg' => 1750, 'contacts' => 2, 'last' => '2026-12-01', 'none' => 0, 'partial' => 2, 'won' => 3500, 'won_count' => 3], $agg, json_encode($agg));
            $fields = $e['actions'][0]['result_data']['fields'];
            $this->assertEquals(3, $fields['won']['records_scanned'] ?? null);
            $this->assertEquals(3, $fields['won_count']['total'] ?? null, 'count by meta.total');
            $this->assertTrue($fields['partial']['truncated'] ?? false);
            $this->assertEquals('failed', $e['actions'][1]['status']);
            $this->assertEquals('aggregate: more than 2 records of AggDeals; narrow the filter or set "on_limit": "partial"', $e['actions'][1]['error_message']);
            $fns = array_column($this->request('GET', '/api/dictionaries/aggregate-functions', token: $this->viewerToken)['data'], 'value');
            $this->assertEquals(['count', 'count_distinct', 'sum', 'avg', 'min', 'max'], $fns);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST aggregate bad', 'object_type' => 'TestAgg', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'aggregate', 'function' => 'sum', 'source' => ['module' => 'AggDeals']],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Field is required for "sum"', $d['definition.actions[0].field'] ?? null);
            $this->assertEquals('Filter is required (aggregating a whole module is not allowed)', $d['definition.actions[0].source.filter'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20aggregate&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * notify: CRM alerts and messengers (md/tasks_list/4.8-notify.md); messengers are mock endpoints,
     * the backend runs with TELEGRAM_API_BASE=http://mock:9502/mock/telegram.
     */
    private function testNotify(): void
    {
        echo "[notify]\n";
        $run = substr(bin2hex(random_bytes(4)), 0, 8);
        $mock = fn (string $method, string $path) => json_decode((string)file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => $method]])), true)['data'] ?? [];
        $secret = function (string $name, string $kind, string $value): void {
            $this->raw('DELETE', "/api/secrets/{$name}", null, $this->adminToken);
            $this->request('POST', '/api/secrets', ['name' => $name, 'kind' => $kind, 'value' => $value], $this->adminToken, 201);
        };
        $this->test('one step: CRM alert, Telegram (HTML), Slack, Teams; tokens and webhook URLs are masked', function () use ($run, $mock, $secret) {
            $secret('test_tg_bot', 'telegram_bot', "tok-{$run}-secret");
            $secret('test_slack', 'webhook_url', "http://mock:9502/mock/slack/s-{$run}");
            $secret('test_teams', 'webhook_url', "http://mock:9502/mock/teams/t-{$run}");
            $mock('DELETE', 'notify');
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST notify', 'object_type' => 'TestNotify', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'notify', 'id' => 'notify', 'title' => 'Новый лид', 'message' => 'Вам назначен лид {{ object.last_name }}', 'link' => ['record' => 'object'], 'channels' => [
                    ['channel' => 'crm_alert', 'users' => ['{{ object.assigned_user_id }}']],
                    ['channel' => 'telegram', 'connection' => 'secret://test_tg_bot', 'chat_id' => '-100500'],
                    ['channel' => 'slack', 'connection' => 'secret://test_slack'],
                    ['channel' => 'teams', 'connection' => 'secret://test_teams'],
                ]],
            ]]], $this->adminToken, 201)['data'];
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => "n-{$run}", 'module' => 'TestNotify', 'last_name' => 'Иванов <ООО>', 'assigned_user_id' => 'u7']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($r['execution_id']);
            $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
            $channels = $e['actions'][0]['result_data']['channels'];
            $this->assertEquals(['success', 'success', 'success', 'success'], array_column($channels, 'status'));
            $alert = $this->crmObject('Alerts', (string)$channels[0]['deliveries'][0]['record']);
            $this->assertEquals(['u7', '0', "#/testnotify/record/n-{$run}", 'Новый лид', 'Вам назначен лид Иванов <ООО>', 'TestNotify'],
                [$alert['assigned_user_id'] ?? null, $alert['is_read'] ?? null, $alert['url_redirect'] ?? null, $alert['name'] ?? null, $alert['description'] ?? null, $alert['target_module'] ?? null]);
            $got = array_column($mock('GET', 'notify'), null, 'service');
            $this->assertEquals("bottok-{$run}-secret", $got['telegram']['id'], 'the token is in the path of the Bot API URL');
            $this->assertEquals(['-100500', 'HTML'], [$got['telegram']['body']['chat_id'], $got['telegram']['body']['parse_mode']]);
            $this->assertEquals("<b>Новый лид</b>\nВам назначен лид Иванов &lt;ООО&gt;\n<a href=\"http://mock:9502/#/testnotify/record/n-{$run}\">Открыть</a>", $got['telegram']['body']['text']);
            $this->assertEquals("*Новый лид*\nВам назначен лид Иванов &lt;ООО&gt;\n<http://mock:9502/#/testnotify/record/n-{$run}|Открыть>", $got['slack']['body']['blocks'][0]['text']['text']);
            $this->assertEquals('AdaptiveCard', $got['teams']['body']['attachments'][0]['content']['type']);
            $json = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertFalse(str_contains($json, "tok-{$run}"), 'the bot token is not in the audit');
            $this->assertFalse(str_contains($json, "mock/slack/s-{$run}"), 'the webhook URL is not in the audit');
        });
        $this->test('partial errors: 429 repeats the step without a duplicate; a forbidden chat fails the step (fail_on any), all keeps it', function () use ($run, $mock, $secret) {
            $secret('test_slack_busy', 'webhook_url', "http://mock:9502/mock/slack/fail429-{$run}");
            $mock('DELETE', 'notify');
            $make = fn (string $name, array $channels, string $failOn = 'any') => $this->request('POST', '/api/processes', ['name' => $name, 'object_type' => 'TestNotify', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'notify', 'message' => 'Сообщение', 'channels' => $channels, 'fail_on' => $failOn, 'retry' => ['max_attempts' => 3, 'base_delay' => '1s']],
            ]]], $this->adminToken, 201)['data'];
            $p1 = $make('TEST notify retry', [['channel' => 'slack', 'connection' => 'secret://test_slack'], ['channel' => 'slack', 'connection' => 'secret://test_slack_busy']]);
            $r1 = $this->request('POST', "/api/processes/{$p1['id']}/run", ['object' => ['id' => "nr-{$run}", 'module' => 'TestNotify']], $this->editorToken, 201)['data'];
            $e1 = $this->waitForExecution($r1['execution_id'], 20);
            $this->assertEquals('completed', $e1['status'], json_encode($e1['actions'], JSON_UNESCAPED_UNICODE));
            $this->assertEquals(2, $e1['actions'][0]['attempts']);
            $this->assertEquals(true, $e1['actions'][0]['result_data']['channels'][0]['deliveries'][0]['replayed'], 'the first Slack message came from the journal');
            $sent = array_count_values(array_column($mock('GET', 'notify'), 'id'));
            $this->assertEquals(['s-' . $run => 1, 'fail429-' . $run => 1], $sent, 'no duplicates');

            $channels = [['channel' => 'telegram', 'connection' => 'secret://test_tg_bot', 'chat_id' => 'forbidden'], ['channel' => 'slack', 'connection' => 'secret://test_slack']];
            $p2 = $make('TEST notify any', $channels);
            $e2 = $this->waitForExecution($this->request('POST', "/api/processes/{$p2['id']}/run", ['object' => ['id' => "na-{$run}", 'module' => 'TestNotify']], $this->editorToken, 201)['data']['execution_id']);
            $this->assertEquals('failed', $e2['status']);
            $this->assertEquals('notify: 1 of 2 channels failed: telegram: telegram: HTTP 403 Forbidden: bot is not a member of the channel chat', $e2['actions'][0]['error_message']);
            $p3 = $make('TEST notify all', $channels, 'all');
            $e3 = $this->waitForExecution($this->request('POST', "/api/processes/{$p3['id']}/run", ['object' => ['id' => "nl-{$run}", 'module' => 'TestNotify']], $this->editorToken, 201)['data']['execution_id']);
            $this->assertEquals('completed', $e3['status']);
            $this->assertEquals(['failed', 'success'], array_column($e3['actions'][0]['result_data']['channels'], 'status'));
        });
        $this->test('test endpoint, dictionary, validation', function () {
            $ok = $this->request('POST', '/api/notify/test', ['channel' => ['channel' => 'slack', 'connection' => 'secret://test_slack']], $this->editorToken)['data'];
            $this->assertTrue($ok['sent']);
            $bad = $this->request('POST', '/api/notify/test', ['channel' => ['channel' => 'telegram', 'connection' => 'secret://test_tg_bot', 'chat_id' => 'forbidden']], $this->editorToken)['data'];
            $this->assertFalse($bad['sent']);
            $this->assertTrue(str_contains($bad['error'], 'bot is not a member'));
            $this->assertEquals(['crm_alert', 'telegram', 'slack', 'teams'], array_column($this->request('GET', '/api/dictionaries/notify-channels', token: $this->viewerToken)['data'], 'value'));
            $v = $this->request('POST', '/api/processes', ['name' => 'TEST notify bad', 'object_type' => 'TestNotify', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'notify', 'message' => 'x', 'channels' => [['channel' => 'slack', 'connection' => 'secret://test_tg_bot'], ['channel' => 'telegram', 'connection' => 'secret://test_tg_bot'], ['channel' => 'teams', 'connection' => 'secret://test_teams', 'format' => 'html']]],
            ]]], $this->adminToken, 422);
            $d = array_column($v['error']['details'], 'message', 'field');
            $this->assertEquals('Secret "test_tg_bot" has kind "telegram_bot", expected webhook_url or generic', $d['definition.actions[0].channels[0].connection'] ?? null);
            $this->assertEquals('Chat id is required', $d['definition.actions[0].channels[1].chat_id'] ?? null);
            $this->assertEquals('Must be adaptive_card or message_card', $d['definition.actions[0].channels[2].format'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20notify&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * assign: responsible user by a rule (md/tasks_list/4.9-assign.md).
     */
    private function testAssign(): void
    {
        echo "[assign]\n";
        $put = fn (string $path, array $body) => file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
        $run = substr(bin2hex(random_bytes(4)), 0, 6);
        $u = fn (string $n) => "as{$run}-{$n}";
        $this->test('round_robin over a security group: u1, u2, u3, u1; the inactive user is skipped; a repeated step keeps the decision', function () use ($put, $run, $u) {
            foreach (['u1' => 'Active', 'u2' => 'Active', 'u3' => 'Active', 'u4' => 'Inactive'] as $n => $status) {
                $put('objects/Users/' . $u($n), ['user_name' => $n, 'status' => $status, 'employee_status' => 'Active', 'is_group' => '0', 'portal_only' => '0']);
                $put("relationships/SecurityGroups/g{$run}/users/" . $u($n), ['related_module' => 'Users']);
            }
            $put("objects/SecurityGroups/g{$run}", ['name' => 'Sales']);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST assign rr', 'object_type' => 'TestAssign', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'assign', 'id' => 'rr', 'target' => 'object', 'strategy' => 'round_robin', 'pool' => ['security_group' => "g{$run}"], 'queue_key' => "test_{$run}"],
            ]]], $this->adminToken, 201)['data'];
            $assigned = [];
            foreach (range(1, 4) as $i) {
                $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => "al{$i}-{$run}", 'module' => 'TestAssign']], $this->editorToken, 201)['data'];
                $e = $this->waitForExecution($r['execution_id']);
                $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
                $assigned[] = $e['actions'][0]['result_data']['chosen_user_id'];
            }
            $this->assertEquals([$u('u1'), $u('u2'), $u('u3'), $u('u1')], $assigned);
            $this->assertEquals([$u('u4')], $e['actions'][0]['result_data']['inactive']);
            $this->assertEquals('round_robin: next after ' . $u('u3'), $e['actions'][0]['result_data']['reason']);
            // the save fails once: the repeated step reuses the decision, the queue moves once
            $put("objects/TestAssign/fail1x500-{$run}", ['name' => 'x']);
            $p2 = $this->request('POST', '/api/processes', ['name' => 'TEST assign retry', 'object_type' => 'TestAssign', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'assign', 'target' => 'object', 'strategy' => 'round_robin', 'pool' => ['security_group' => "g{$run}"], 'queue_key' => "test_{$run}",
                    'save_to_crm' => true, 'retry' => ['max_attempts' => 2, 'base_delay' => '1s', 'retry_on' => ['5xx']]],
            ]]], $this->adminToken, 201)['data'];
            $e2 = $this->waitForExecution($this->request('POST', "/api/processes/{$p2['id']}/run", ['object' => ['id' => "fail1x500-{$run}", 'module' => 'TestAssign']], $this->editorToken, 201)['data']['execution_id'], 20);
            $this->assertEquals('completed', $e2['status'], json_encode($e2['actions'], JSON_UNESCAPED_UNICODE));
            $this->assertEquals([2, $u('u2'), true], [$e2['actions'][0]['attempts'], $e2['actions'][0]['result_data']['chosen_user_id'], $e2['actions'][0]['result_data']['reused_decision'] ?? false]);
            $r3 = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => "al5-{$run}", 'module' => 'TestAssign']], $this->editorToken, 201)['data'];
            $this->assertEquals($u('u3'), $this->waitForExecution($r3['execution_id'])['actions'][0]['result_data']['chosen_user_id'], 'u2 was taken once');
            $types = array_column($this->request('GET', '/api/dictionaries/assign-strategies', token: $this->viewerToken)['data'], 'value');
            $this->assertEquals(['round_robin', 'least_busy', 'random', 'manager', 'user'], $types);
        });
        $this->test('least_busy by open cases; manager with fallback keep; save_to_crm', function () use ($put, $run, $u) {
            foreach ([['c1', 'u1', 'Open'], ['c2', 'u1', 'Open'], ['c3', 'u2', 'Open'], ['c4', 'u3', 'Closed']] as [$id, $user, $state]) {
                $put("objects/AsCases/{$id}-{$run}", ['assigned_user_id' => $u($user), 'state' => $state]);
            }
            $put('objects/Users/' . $u('u2'), ['user_name' => 'u2', 'status' => 'Active', 'reports_to_id' => $u('u1')]);
            $put("objects/TestAssign/m-{$run}", ['name' => 'x']);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST assign lb', 'object_type' => 'TestAssign', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'assign', 'target' => 'object', 'strategy' => 'least_busy', 'pool' => ['users' => [['value' => $u('u1')], ['value' => $u('u2')], ['value' => $u('u3')]]],
                    'least_busy' => ['module' => 'AsCases', 'filter' => [['field' => 'state', 'operator' => 'equals', 'value' => 'Open']]]],
                ['type' => 'assign', 'target' => 'object', 'field' => 'manager_id', 'strategy' => 'manager', 'manager_of' => ['value' => $u('u2')], 'save_to_crm' => true],
                ['type' => 'assign', 'target' => 'object', 'field' => 'other_id', 'strategy' => 'manager', 'manager_of' => ['value' => $u('u3')]],
            ]]], $this->adminToken, 201)['data'];
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => "m-{$run}", 'module' => 'TestAssign']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($r['execution_id']);
            $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
            $lb = $e['actions'][0]['result_data'];
            $this->assertEquals([$u('u3'), [$u('u1') => 2, $u('u2') => 1, $u('u3') => 0]], [$lb['chosen_user_id'], $lb['counts']]);
            $this->assertEquals($u('u1'), $e['actions'][1]['result_data']['chosen_user_id']);
            $saved = $this->crmObject('TestAssign', "m-{$run}");
            $this->assertEquals([$u('u3'), $u('u1')], [$saved['assigned_user_id'] ?? null, $saved['manager_id'] ?? null]);
            $this->assertEquals('skipped', $e['actions'][2]['status']);
            $this->assertEquals('fallback: keep — user ' . $u('u3') . ' has no manager', $e['actions'][2]['result_data']['reason']);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST assign bad', 'object_type' => 'TestAssign', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'assign', 'target' => 'object', 'strategy' => 'least_busy', 'pool' => ['users' => [['value' => 'u1']]]],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Filter of "open" records is required for least_busy', $d['definition.actions[0].least_busy.filter'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20assign&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * starts_with / ends_with (md/tasks_list/done/3.5-starts-ends-with.md).
     */
    private function testStartsEndsWith(): void
    {
        echo "[starts_with]\n";
        $this->test('ends_with in a manual run, case-insensitive starts_with, validation', function () {
            $operators = array_column($this->request('GET', '/api/dictionaries/operators', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals(['Начинается с', 'Заканчивается на'], [$operators['starts_with'] ?? null, $operators['ends_with'] ?? null]);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST starts_with', 'object_type' => 'TestSw', 'is_child' => false, 'definition' => [
                'conditions' => [
                    ['field' => 'object.email1', 'operator' => 'ends_with', 'value' => '@gmail.com'],
                    ['field' => 'object.name', 'operator' => 'starts_with', 'value' => 'дг-', 'cast' => 'string', 'cast_options' => ['case_sensitive' => false]],
                ],
                'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
            ]], $this->adminToken, 201)['data'];
            $yes = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'sw1', 'module' => 'TestSw', 'email1' => 'a@gmail.com', 'name' => 'ДГ-17']], $this->editorToken, 201)['data'];
            $this->assertTrue($yes['conditions_met']);
            $no = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'sw2', 'module' => 'TestSw', 'email1' => 'a@gmail.com.evil.ru', 'name' => 'ДГ-17']], $this->editorToken, 200)['data'];
            $this->assertFalse($no['conditions_met']);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST starts_with bad', 'object_type' => 'TestSw', 'is_child' => false, 'definition' => ['conditions' => [
                ['field' => 'object.n', 'operator' => 'ends_with', 'value' => '1', 'cast' => 'int'],
                ['field' => 'object.n', 'operator' => 'starts_with', 'value' => '  '],
            ], 'actions' => []]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Operator "ends_with" does not support cast "int" (supported: string)', $d['definition.conditions[0].cast'] ?? null);
            $this->assertEquals('Value is required for operator "starts_with"', $d['definition.conditions[1].value'] ?? null);
            $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
        });
    }

    /**
     * between / not_between (md/tasks_list/done/3.6-between.md).
     */
    private function testBetween(): void
    {
        echo "[between]\n";
        $this->test('a range in a manual run, operand kind in the dictionary, validation of pairs', function () {
            $operators = array_column($this->request('GET', '/api/dictionaries/operators', token: $this->viewerToken)['data'], null, 'value');
            $this->assertEquals(['В диапазоне', 'range'], [$operators['between']['label'] ?? null, $operators['between']['operands'] ?? null]);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST between', 'object_type' => 'TestBt', 'is_child' => false, 'definition' => [
                'conditions' => [['field' => 'object.amount', 'operator' => 'between', 'cast' => 'decimal', 'value' => ['10000', '50000']]],
                'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
            ]], $this->adminToken, 201)['data'];
            $this->assertTrue($this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'b1', 'module' => 'TestBt', 'amount' => '50000']], $this->editorToken, 201)['data']['conditions_met']);
            $this->assertFalse($this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'b2', 'module' => 'TestBt', 'amount' => '50001']], $this->editorToken, 200)['data']['conditions_met']);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST between bad', 'object_type' => 'TestBt', 'is_child' => false, 'definition' => ['conditions' => [
                ['field' => 'object.a', 'operator' => 'between', 'value' => ['1', '2']],
                ['field' => 'object.a', 'operator' => 'between', 'cast' => 'int', 'value' => ['1']],
                ['field' => 'object.a', 'operator' => 'not_between', 'cast' => 'int', 'value' => ['9', '2']],
                ['field' => 'object.a', 'operator' => 'between', 'cast' => 'date', 'value' => ['', 'abc']],
                ['field' => 'object.a', 'operator' => 'between', 'cast' => 'int', 'value_type' => 'from_object_field', 'value' => ['v1.min', 'object.max']],
            ], 'actions' => []]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Operator "between" requires cast: int, decimal, date or datetime', $d['definition.conditions[0].cast'] ?? null);
            $this->assertEquals('Operator "between" expects a pair [from, to]', $d['definition.conditions[1].value'] ?? null);
            $this->assertEquals('Range start must not be greater than range end', $d['definition.conditions[2].value'] ?? null);
            $this->assertEquals('Range bound is required', $d['definition.conditions[3].value[0]'] ?? null);
            $this->assertTrue(isset($d['definition.conditions[3].value[1]']));
            $this->assertEquals('Variable "v1" is not defined at this point', $d['definition.conditions[4].value[0]'] ?? null);
            $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
        });
    }

    /**
     * Relative dates in conditions and filters (md/tasks_list/done/3.7-relative-dates.md).
     */
    private function testRelativeDates(): void
    {
        echo "[relative dates]\n";
        $this->test('today+30d / now-24h in a manual run with the resolved value in the trace; dictionary; validation', function () {
            $dict = $this->request('GET', '/api/dictionaries/relative-dates', token: $this->viewerToken)['data'];
            $this->assertEquals(['now', 'today'], array_slice(array_column($dict['anchors'], 'value'), 0, 2));
            $this->assertEquals(['min', 'h', 'd', 'w', 'mo', 'y'], array_column($dict['units'], 'value'));
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST relative', 'object_type' => 'TestRel', 'is_child' => false, 'definition' => [
                'conditions' => [
                    ['field' => 'object.date_end', 'operator' => 'less_than', 'cast' => 'date', 'value' => 'today+30d'],
                    ['field' => 'object.date_entered', 'operator' => 'greater_or_equal', 'cast' => 'datetime', 'value' => 'now-24h'],
                ],
                'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
            ]], $this->adminToken, 201)['data'];
            $soon = gmdate('Y-m-d', time() + 10 * 86400);
            $res = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'r1', 'module' => 'TestRel', 'date_end' => $soon, 'date_entered' => gmdate('Y-m-d H:i:s', time() - 3600)]], $this->editorToken, 201)['data'];
            $this->assertTrue($res['conditions_met']);
            $this->assertTrue(isset($res['conditions'][0]['expected_resolved']), json_encode($res['conditions']));
            $old = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'r2', 'module' => 'TestRel', 'date_end' => $soon, 'date_entered' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]], $this->editorToken, 200)['data'];
            $this->assertFalse($old['conditions_met']);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST relative bad', 'object_type' => 'TestRel', 'is_child' => false, 'definition' => ['conditions' => [
                ['field' => 'object.d', 'operator' => 'less_than', 'cast' => 'date', 'value' => 'today+7days'],
                ['field' => 'object.d', 'operator' => 'between', 'cast' => 'date', 'value' => ['start_of_decade', 'today']],
            ], 'actions' => [
                ['type' => 'find_records', 'var' => 'old', 'module' => 'Tasks', 'filter' => [['field' => 'date_due', 'operator' => 'less_than', 'cast' => 'datetime', 'value' => 'today+10wd']]],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Invalid relative date "today+7days": unknown unit "days" at position 7 (use min, h, d, w, mo, y)', $d['definition.conditions[0].value'] ?? null);
            $this->assertEquals('Unknown relative date anchor "start_of_decade"', $d['definition.conditions[1].value[0]'] ?? null);
            $this->assertTrue(str_contains((string)($d['definition.actions[0].filter[0].value'] ?? ''), 'unknown unit "wd"'));
            $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
        });
    }

    /**
     * case_sensitive / trim of the string cast (md/tasks_list/done/3.8-case-insensitive.md).
     */
    private function testCaseInsensitive(): void
    {
        echo "[case_sensitive]\n";
        $this->test('"Москва" matches "  МОСКВА " only with case_sensitive: false and trim; the casts dictionary lists the options; flat options are rejected', function () {
            $casts = array_column($this->request('GET', '/api/dictionaries/casts', token: $this->viewerToken)['data'], 'options', 'value');
            $this->assertEquals(['case_sensitive', 'trim'], array_slice(array_column($casts['string'], 'name'), 0, 2));
            $make = fn (array $extra) => $this->request('POST', '/api/processes', ['name' => 'TEST case ' . count($extra), 'object_type' => 'TestCi', 'is_child' => false, 'definition' => [
                'conditions' => [['field' => 'object.city', 'operator' => 'equals', 'value' => 'Москва'] + $extra],
                'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
            ]], $this->adminToken, 201)['data'];
            $strict = $make([]);
            $loose = $make(['cast' => 'string', 'cast_options' => ['case_sensitive' => false, 'trim' => true]]);
            $object = ['object' => ['id' => 'ci1', 'module' => 'TestCi', 'city' => '  МОСКВА ']];
            $this->assertFalse($this->request('POST', "/api/processes/{$strict['id']}/run", $object, $this->editorToken, 200)['data']['conditions_met']);
            $res = $this->request('POST', "/api/processes/{$loose['id']}/run", $object, $this->editorToken, 201)['data'];
            $this->assertTrue($res['conditions_met']);
            $this->assertEquals(['москва', ['case_sensitive' => false, 'trim' => true]], [$res['conditions'][0]['actual_cast'] ?? null, $res['conditions'][0]['cast_options'] ?? null]);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST case bad', 'object_type' => 'TestCi', 'is_child' => false, 'definition' => ['conditions' => [
                ['field' => 'object.city', 'operator' => 'equals', 'value' => 'x', 'case_sensitive' => false],
                ['field' => 'object.n', 'operator' => 'equals', 'value' => '1', 'cast' => 'int', 'cast_options' => ['trim' => true]],
            ], 'actions' => []]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Use "cast_options": {"case_sensitive": ...}', $d['definition.conditions[0].case_sensitive'] ?? null);
            $this->assertEquals('Option "trim" is not supported by cast "int"', $d['definition.conditions[1].cast_options.trim'] ?? null);
            $this->request('DELETE', "/api/processes/{$strict['id']}", token: $this->adminToken);
            $this->request('DELETE', "/api/processes/{$loose['id']}", token: $this->adminToken);
        });
    }

    /**
     * contains_any / contains_all / contains_none (md/tasks_list/done/3.9-list-operators.md).
     */
    private function testListOperators(): void
    {
        echo "[list operators]\n";
        $this->test('multienum tags: contains_any without substring matches, contains_none for no tags, validation', function () {
            $operators = array_column($this->request('GET', '/api/dictionaries/operators', token: $this->viewerToken)['data'], null, 'value');
            $this->assertEquals(['Содержит любое из', ['list'], 'list'], [$operators['contains_any']['label'] ?? null, $operators['contains_any']['casts'] ?? null, $operators['contains_any']['operands'] ?? null]);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST list ops', 'object_type' => 'TestLo', 'is_child' => false, 'definition' => [
                'conditions' => [
                    ['field' => 'object.tags_c', 'operator' => 'contains_any', 'value' => ['vip', 'partner'], 'cast' => 'list', 'cast_options' => ['wrapper' => '^', 'case_sensitive' => false]],
                    ['field' => 'object.tags_c', 'operator' => 'contains_none', 'value' => '^spam^,^test^', 'cast' => 'list', 'cast_options' => ['wrapper' => '^']],
                ],
                'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
            ]], $this->adminToken, 201)['data'];
            $run = fn (string $tags) => (array)(json_decode($this->raw('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'lo-' . md5($tags), 'module' => 'TestLo', 'tags_c' => $tags]], $this->editorToken)['body'], true)['data'] ?? []);
            $this->assertTrue($run('^VIP^,^gold^')['conditions_met']);
            $this->assertFalse($run('^vipclient^')['conditions_met']);
            $this->assertFalse($run('^vip^,^spam^')['conditions_met']);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST list bad', 'object_type' => 'TestLo', 'is_child' => false, 'definition' => ['conditions' => [
                ['field' => 'object.tags_c', 'operator' => 'contains_all', 'value' => ['a']],
                ['field' => 'object.tags_c', 'operator' => 'contains_any', 'value' => '^^', 'cast' => 'list', 'cast_options' => ['wrapper' => '^']],
            ], 'actions' => []]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Operator "contains_all" requires cast: list', $d['definition.conditions[0].cast'] ?? null);
            $this->assertEquals('List of values must not be empty', $d['definition.conditions[1].value'] ?? null);
            $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
        });
    }

    /**
     * value_type "expression" in conditions (md/tasks_list/done/3.10-expression-value.md).
     */
    private function testExpressionValue(): void
    {
        echo "[expression value]\n";
        $put = fn (string $path, array $body) => file_get_contents("{$this->crmUrl}/mock/{$path}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
        $this->test('amount > 80 % of the credit limit of a preloaded account; dictionary; validation', function () use ($put) {
            $this->assertEquals(['static', 'from_object_field', 'expression'], array_column($this->request('GET', '/api/dictionaries/value-types', token: $this->viewerToken)['data'], 'value'));
            $put('objects/ExprAccounts/ea1', ['name' => 'Компания', 'credit_limit' => '100000']);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST expression value', 'object_type' => 'TestEv', 'is_child' => false, 'definition' => [
                'preload' => [['var' => 'v1', 'source' => 'object_field', 'module' => 'ExprAccounts', 'by_field' => 'id', 'value' => 'object.account_id']],
                'conditions' => [['field' => 'object.amount', 'operator' => 'greater_than', 'cast' => 'decimal', 'value_type' => 'expression', 'value' => 'v1.credit_limit * 0.8']],
                'actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1']],
            ]], $this->adminToken, 201)['data'];
            $yes = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'ev1', 'module' => 'TestEv', 'amount' => '90000', 'account_id' => 'ea1']], $this->editorToken, 201)['data'];
            $this->assertTrue($yes['conditions_met']);
            $this->assertEquals(80000, $yes['conditions'][0]['expected'] ?? null);
            $no = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'ev2', 'module' => 'TestEv', 'amount' => '70000', 'account_id' => 'ea1']], $this->editorToken, 200)['data'];
            $this->assertFalse($no['conditions_met']);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST expression bad', 'object_type' => 'TestEv', 'is_child' => false, 'definition' => ['conditions' => [
                ['field' => 'object.a', 'operator' => 'greater_than', 'cast' => 'int', 'value_type' => 'expression', 'value' => '1 +'],
                ['field' => 'object.a', 'operator' => 'greater_than', 'cast' => 'int', 'value_type' => 'expression', 'value' => 'v9.x * 2'],
                ['field' => 'object.a', 'operator' => 'changed_to', 'value_type' => 'expression', 'value' => '1'],
                ['field' => 'object.a', 'operator' => 'equals', 'value_type' => 'formula', 'value' => '1'],
            ], 'actions' => []]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertTrue(isset($d['definition.conditions[0].value']), json_encode($d));
            $this->assertEquals('Variable "v9" is not defined at this point', $d['definition.conditions[1].value'] ?? null);
            $this->assertEquals('Operator "changed_to" does not support value_type "expression"', $d['definition.conditions[2].value_type'] ?? null);
            $this->assertEquals('Must be "static", "from_object_field" or "expression"', $d['definition.conditions[3].value_type'] ?? null);
            $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
        });
    }

    /**
     * increment / decrement, clear, append / prepend / remove, map (md/tasks_list/done/5.1.3-…5.1.6).
     */
    private function testSimpleChangeTypes(): void
    {
        echo "[change types 5.1.3-5.1.6]\n";
        $this->test('counter, cleared field, history line, multienum tag, priority by rating; dictionary; validation', function () {
            $types = array_column($this->request('GET', '/api/dictionaries/change-types', token: $this->viewerToken)['data'], 'label', 'value');
            foreach (['increment', 'decrement', 'clear', 'append', 'prepend', 'remove', 'map'] as $type) {
                $this->assertTrue(isset($types[$type]), $type);
            }
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST change types', 'object_type' => 'TestCt', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'modify', 'target' => 'object.cases_count', 'change_type' => 'increment'],
                ['type' => 'modify', 'target' => 'object.bonus', 'change_type' => 'decrement', 'value_type' => 'from_object_field', 'value' => 'object.used'],
                ['type' => 'modify', 'target' => 'object.lost_reason', 'change_type' => 'clear'],
                ['type' => 'modify', 'target' => 'object.history', 'change_type' => 'append', 'value' => 'статус {{ object.status }}', 'separator' => '; '],
                ['type' => 'modify', 'target' => 'object.tags_c', 'change_type' => 'append', 'mode' => 'list', 'value' => 'vip', 'list' => ['wrapper' => '^']],
                ['type' => 'modify', 'target' => 'object.tags_c', 'change_type' => 'remove', 'mode' => 'list', 'value' => 'cold', 'list' => ['wrapper' => '^']],
                ['type' => 'modify', 'target' => 'object.priority', 'change_type' => 'map', 'source' => 'object.rating', 'map' => ['Hot' => 'High', 'Warm' => 'Medium'], 'default' => 'Low', 'cast' => 'string', 'cast_options' => ['case_sensitive' => false]],
            ]]], $this->adminToken, 201)['data'];
            $r = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'ct1', 'module' => 'TestCt', 'cases_count' => '4', 'bonus' => '100.50', 'used' => '20.25',
                'lost_reason' => 'Цена', 'history' => 'создан', 'status' => 'New', 'tags_c' => '^cold^,^web^', 'rating' => 'hot']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($r['execution_id']);
            $this->assertEquals('completed', $e['status'], json_encode($e['actions'], JSON_UNESCAPED_UNICODE));
            $new = array_map(static fn ($a) => $a['result_data']['new_value'] ?? null, $e['actions']);
            $this->assertEquals([5, '80.25', '', 'создан; статус New', '^cold^,^web^,^vip^', '^web^,^vip^', 'High'], $new);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST change types bad', 'object_type' => 'TestCt', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'modify', 'target' => 'object.n', 'change_type' => 'increment', 'value' => 'abc'],
                ['type' => 'modify', 'target' => 'object.t', 'change_type' => 'remove', 'value' => 'x'],
                ['type' => 'modify', 'target' => 'object.p', 'change_type' => 'map', 'source' => 'object.r', 'map' => []],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Step must be a number', $d['definition.actions[0].value'] ?? null);
            $this->assertEquals('"remove" works only with mode "list"', $d['definition.actions[1].mode'] ?? null);
            $this->assertEquals('Map must be a non-empty object', $d['definition.actions[2].map'] ?? null);
            $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
        });
    }

    /**
     * condition with several branches, names, a default branch without conditions (md/tasks_list/done/4.3-condition-branch.md).
     */
    private function testConditionBranches(): void
    {
        echo "[condition branches]\n";
        $this->test('if / else-if / default branch: first true branch runs, names are traced, unreachable branches give warnings', function () {
            $set = static fn (string $v): array => ['type' => 'modify', 'target' => 'object.tier', 'change_type' => 'set_value', 'value' => $v];
            $res = $this->request('POST', '/api/processes', ['name' => 'TEST branches', 'object_type' => 'TestBranch', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'condition', 'id' => 'deal_size', 'branches' => [
                    ['name' => 'Больше 1 млн', 'conditions' => [['field' => 'object.amount', 'operator' => 'greater_than', 'cast' => 'decimal', 'value' => '1000000']], 'actions' => [$set('A')]],
                    ['name' => 'Больше 100 тыс.', 'conditions' => ['logical_operator' => 'OR', 'conditions' => [
                        ['field' => 'object.amount', 'operator' => 'greater_than', 'cast' => 'decimal', 'value' => '100000'],
                        ['field' => 'object.vip', 'operator' => 'equals', 'value' => '1'],
                    ]], 'actions' => [$set('B')]],
                    ['name' => 'Остальные', 'conditions' => [], 'actions' => [$set('C')]],
                ]],
                ['type' => 'modify', 'target' => 'object.done', 'change_type' => 'set_value', 'value' => '1'],
            ]]], $this->adminToken, 201)['data'];
            $this->assertEquals([], $res['warnings'] ?? []);
            foreach ([['2000000', '0', 'A', 0], ['5000', '1', 'B', 1], ['5000', '0', 'C', 2]] as [$amount, $vip, $tier, $selected]) {
                $r = $this->request('POST', "/api/processes/{$res['id']}/run", ['object' => ['id' => "br-{$tier}", 'amount' => $amount, 'vip' => $vip]], $this->editorToken, 201)['data'];
                $e = $this->waitForExecution($r['execution_id']);
                $this->assertEquals($tier, $e['context']['variables']['object']['tier']);
                $this->assertEquals($selected, $e['actions'][0]['result_data']['selected']);
                $this->assertEquals(array_slice(['Больше 1 млн', 'Больше 100 тыс.', 'Остальные'], 0, $selected + 1), array_column($e['actions'][0]['result_data']['branches'], 'name'));
                $this->assertEquals(['condition', 'modify', 'modify'], array_column($e['actions'], 'action_type'));
            }
            $warned = $this->request('POST', '/api/processes', ['name' => 'TEST branches warn', 'object_type' => 'TestBranch', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'condition', 'branches' => [['conditions' => [], 'actions' => [$set('X')]], ['conditions' => [['field' => 'object.a', 'operator' => 'equals', 'value' => '1']], 'actions' => []]]],
            ]]], $this->adminToken, 201)['data'];
            $this->assertEquals('Branch 0 has no conditions: the following branches are never reached', $warned['warnings'][0]['message'] ?? null);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST branches bad', 'object_type' => 'TestBranch', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'object.a', 'operator' => 'equals', 'value' => '1']], 'actions' => [
                    ['type' => 'condition', 'branches' => [['conditions' => [['field' => 'v9.x', 'operator' => 'equals', 'value' => '1']], 'actions' => []]]],
                ]]]],
            ]]], $this->adminToken, 422);
            $this->assertEquals('Variable "v9" is not defined at this point', array_column($bad['error']['details'], 'message', 'field')['definition.actions[0].branches[0].actions[0].branches[0].conditions[0].field'] ?? null);
            $blocks = array_column(array_filter($this->request('GET', '/api/dictionaries/action-types', token: $this->viewerToken)['data'], static fn ($t) => ($t['kind'] ?? '') === 'block'), 'label', 'value');
            $this->assertEquals('Условие (если / иначе)', $blocks['condition'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20branches&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * stop_process (md/tasks_list/done/4.14-stop-process.md).
     */
    private function testStopProcess(): void
    {
        echo "[stop_process]\n";
        $this->test('stop_process failed inside a branch: execution failed with [CODE] message, the rest skipped; completed: no error, stop in the snapshot', function () {
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST stop', 'object_type' => 'TestStop', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'condition', 'branches' => [
                    ['conditions' => [['field' => 'object.email', 'operator' => 'is_empty']], 'actions' => [
                        ['type' => 'stop_process', 'status' => 'failed', 'code' => 'NO_CONTACTS', 'message' => 'У лида {{ object.last_name }} нет email'],
                    ]],
                    ['conditions' => [['field' => 'object.status', 'operator' => 'equals', 'value' => 'Closed']], 'actions' => [
                        ['type' => 'stop_process', 'message' => 'Сделка уже закрыта'],
                    ]],
                ]],
                ['type' => 'modify', 'target' => 'object.done', 'change_type' => 'set_value', 'value' => '1'],
            ]]], $this->adminToken, 201)['data'];
            $r1 = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 's1', 'email' => '', 'last_name' => 'Иванов']], $this->editorToken, 201)['data'];
            $e1 = $this->waitForExecution($r1['execution_id']);
            $this->assertEquals('failed', $e1['status']);
            $this->assertEquals('[NO_CONTACTS] У лида Иванов нет email', $e1['error_message']);
            $this->assertEquals(['condition', 'stop_process', 'modify'], array_column($e1['actions'], 'action_type'));
            $this->assertEquals(['success', 'success', 'skipped'], array_column($e1['actions'], 'status'));
            $this->assertEquals('NO_CONTACTS', $e1['context']['stop']['code'] ?? null);
            $r2 = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 's2', 'email' => 'a@b.ru', 'status' => 'Closed']], $this->editorToken, 201)['data'];
            $e2 = $this->waitForExecution($r2['execution_id']);
            $this->assertEquals('completed', $e2['status']);
            $this->assertEquals(null, $e2['error_message']);
            $this->assertEquals('Сделка уже закрыта', $e2['context']['stop']['message'] ?? null);
            $r3 = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 's3', 'email' => 'a@b.ru', 'status' => 'Open']], $this->editorToken, 201)['data'];
            $this->assertEquals('1', $this->waitForExecution($r3['execution_id'])['context']['variables']['object']['done']);
            $warned = $this->request('POST', '/api/processes', ['name' => 'TEST stop warn', 'object_type' => 'TestStop', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'stop_process'], ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'set_value', 'value' => '1'],
            ]]], $this->adminToken, 201)['data'];
            $this->assertEquals('Steps after stop_process in the same list are never executed', $warned['warnings'][0]['message'] ?? null);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST stop bad', 'object_type' => 'TestStop', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'stop_process', 'status' => 'failed', 'on_error' => 'continue'],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Message is required for status "failed"', $d['definition.actions[0].message'] ?? null);
            $this->assertEquals('on_error is not allowed for stop_process', $d['definition.actions[0].on_error'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20stop&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * set_variable (md/tasks_list/done/4.13-set-variable.md).
     */
    private function testSetVariable(): void
    {
        echo "[set_variable]\n";
        $this->test('a local variable carries a computed value to a CRM record; saving the local variable is rejected', function () {
            $objectId = 'setvar-' . random_int(1000, 9999);
            $this->raw('PUT', "/mock/objects/TestVar/{$objectId}", ['name' => 'Deal'], base: $this->crmUrl);
            $process = $this->request('POST', '/api/processes', ['name' => 'TEST setvar', 'object_type' => 'TestVar', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'set_variable', 'var' => 'calc', 'fields' => [
                    'total' => ['change_type' => 'set_value', 'value' => '0'],
                    'subject' => ['change_type' => 'template', 'value' => 'Сделка {{ object.name }} на {{ object.amount }}'],
                ]],
                ['type' => 'set_variable', 'target' => 'calc.total', 'change_type' => 'calculate', 'value' => 'calc.total + object.amount * 1.2'],
                ['type' => 'modify', 'target' => 'object.description', 'change_type' => 'template', 'value' => '{{ calc.subject }}, с НДС {{ calc.total }}'],
                ['type' => 'save_object', 'target' => 'object'],
            ]]], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => $objectId, 'module' => 'TestVar', 'name' => 'Deal', 'amount' => '1000']], $this->editorToken, 201)['data'];
            $e = $this->waitForExecution($run['execution_id']);
            $this->assertEquals('completed', $e['status']);
            $this->assertEquals('local', $e['context']['meta']['calc']['source'] ?? null);
            $this->assertEquals(1200, $e['context']['variables']['calc']['total']);
            $this->assertEquals('0', $e['actions'][1]['result_data']['fields']['total']['old_value']);
            $this->assertEquals(1200, $e['actions'][1]['result_data']['fields']['total']['new_value']);
            $this->assertEquals('Сделка Deal на 1000, с НДС 1200', $this->crmObject('TestVar', $objectId)['description'] ?? null);
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST setvar bad', 'object_type' => 'TestVar', 'is_child' => false, 'definition' => [
                'preload' => [['var' => 'acc', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => 'x']],
                'actions' => [
                    ['type' => 'set_variable', 'target' => 'calc.x', 'change_type' => 'set_value', 'value' => '1'],
                    ['type' => 'save_object', 'target' => 'calc'],
                    ['type' => 'set_variable', 'target' => 'acc.x', 'change_type' => 'set_value', 'value' => '1'],
                ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Variable "calc" is local (set_variable) and cannot be saved to the CRM', $d['definition.actions[1].target'] ?? null);
            $this->assertEquals('Variable "acc" is a CRM record variable; use modify', $d['definition.actions[2].target'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20setvar&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}", token: $this->adminToken);
            }
        });
    }

    /**
     * Waiting executions and delay (md/tasks_list/done/7.2-waiting-executions.md, 4.7-delay.md).
     */
    private function testWaitingExecutions(): void
    {
        echo "[waiting]\n";
        $create = fn (string $name, array $actions): array => $this->request('POST', '/api/processes', [
            'name' => $name, 'object_type' => 'TestWait', 'is_child' => false, 'definition' => ['actions' => $actions],
        ], $this->adminToken, 201)['data'];
        $set = static fn (string $f, string $v): array => ['type' => 'modify', 'target' => "object.{$f}", 'change_type' => 'set_value', 'value' => $v];
        $this->test('delay 6 s: the execution waits without a worker, appears in GET /api/executions?status=waiting, continues and saves', function () use ($create, $set) {
            $objectId = 'wait-' . random_int(10000, 99999);
            $this->raw('PUT', "/mock/objects/TestWait/{$objectId}", ['name' => 'W'], base: $this->crmUrl);
            $process = $create('TEST wait', [$set('a', '1'), ['type' => 'delay', 'id' => 'pause', 'mode' => 'duration', 'duration' => '6s'], $set('b', '2'), ['type' => 'save_object', 'target' => 'object']]);
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => $objectId, 'module' => 'TestWait']], $this->editorToken, 201)['data'];
            usleep(1500000);
            $waiting = $this->request('GET', "/api/executions/{$run['execution_id']}", token: $this->viewerToken)['data'];
            $this->assertEquals('waiting', $waiting['status']);
            $this->assertEquals('delay', $waiting['wait_reason']);
            $this->assertEquals('delay', $waiting['wait']['reason'] ?? null);
            $this->assertEquals(1, $waiting['current_step'] ?? null);
            $this->assertEquals('1', $waiting['context']['variables']['object']['a'] ?? null);
            $this->assertEquals(['success', 'waiting'], array_column($waiting['actions'], 'status'));
            $this->assertEquals(0, (int)$this->redisData()->exists("exec:{$run['execution_id']}"), 'no Redis state while waiting');
            $list = $this->request('GET', "/api/executions?status=waiting&process_id={$process['id']}", token: $this->viewerToken);
            $this->assertEquals([$run['execution_id']], array_column($list['data'], 'id'));
            $this->request('GET', '/api/executions?status=sleeping', token: $this->viewerToken, expectedStatus: 422);
            $done = $this->waitForExecution($run['execution_id'], 20);
            $this->assertEquals('completed', $done['status']);
            $this->assertEquals(1, (int)$this->redisData()->exists("exec:{$run['execution_id']}"), 'the state is back in Redis after waking');
            $this->assertEquals(['success', 'success', 'success', 'success'], array_column($done['actions'], 'status'));
            $this->assertTrue(($done['actions'][1]['result_data']['waited_seconds'] ?? 0) >= 5);
            $this->assertEquals('2', $this->crmObject('TestWait', $objectId)['b'] ?? null);
        });
        $this->test('deleting a process with waiting executions: 409, with force=true they are cancelled', function () use ($create, $set) {
            $process = $create('TEST wait delete', [['type' => 'delay', 'mode' => 'duration', 'duration' => '1h'], $set('x', '1')]);
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'wait-del']], $this->editorToken, 201)['data'];
            usleep(1500000);
            $res = $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken, expectedStatus: 409);
            $this->assertEquals('PROCESS_HAS_WAITING_EXECUTIONS', $res['error']['code']);
            $this->assertEquals(1, $res['error']['details']['waiting'] ?? null);
            $this->request('DELETE', "/api/processes/{$process['id']}?force=true", token: $this->adminToken, expectedStatus: 200);
            $e = $this->request('GET', "/api/executions/{$run['execution_id']}", token: $this->viewerToken)['data'];
            $this->assertEquals('cancelled', $e['status']);
            $this->assertEquals('Process deleted', $e['error_message']);
        });
        $this->test('a long retry delay parks the execution (reason retry) and the step succeeds after waking', function () use ($create) {
            $key = 'fail1x500-' . random_int(100000, 999999);
            $process = $create('TEST wait retry', [
                ['type' => 'preload', 'var' => 'acc', 'source' => 'manual', 'module' => 'Accounts', 'by_field' => 'id', 'value' => $key,
                    'retry' => ['max_attempts' => 2, 'base_delay' => '6s', 'jitter' => 'none', 'retry_on' => ['5xx']]],
            ]);
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'wait-retry']], $this->editorToken, 201)['data'];
            usleep(2000000);
            $parked = $this->request('GET', "/api/executions/{$run['execution_id']}", token: $this->viewerToken)['data'];
            $this->assertEquals('waiting', $parked['status']);
            $this->assertEquals('retry', $parked['wait_reason']);
            $done = $this->waitForExecution($run['execution_id'], 20);
            $this->assertEquals('completed', $done['status']);
            $this->assertEquals(2, $done['actions'][0]['attempts']);
            $this->assertEquals('success', $done['actions'][0]['status']);
            $this->assertEquals(1, count($done['actions']), 'the repeated attempt overwrote the waiting record');
        });
        $this->test('delay validation', function () {
            $bad = $this->request('POST', '/api/processes', ['name' => 'TEST wait bad', 'object_type' => 'TestWait', 'is_child' => false, 'definition' => ['actions' => [
                ['type' => 'delay', 'mode' => 'duration', 'duration' => '3x'],
                ['type' => 'delay', 'mode' => 'until', 'until' => 'v9.date'],
                ['type' => 'delay', 'mode' => 'duration', 'duration' => 'P500D'],
            ]]], $this->adminToken, 422);
            $d = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Invalid duration "3x" (use ISO 8601 like P3D or 3d, 2h, 30m)', $d['definition.actions[0].duration'] ?? null);
            $this->assertEquals('Variable "v9" is not defined at this point', $d['definition.actions[1].until'] ?? null);
            $this->assertEquals('Delay exceeds the limit P400D', $d['definition.actions[2].duration'] ?? null);
            foreach ($this->request('GET', '/api/processes?search=TEST%20wait&per_page=100', token: $this->adminToken)['data'] as $p) {
                $this->request('DELETE', "/api/processes/{$p['id']}?force=true", token: $this->adminToken);
            }
        });
    }

    /**
     * Expression language (md/tasks_list/done/5.2.1-expr-conditionals.md and the following 5.2.x tasks).
     */
    private function testExpressions(): void
    {
        echo "[expressions]\n";
        $this->test('functions dictionary lists if / coalesce with arity, operators in meta', function () {
            $res = $this->request('GET', '/api/dictionaries/functions', token: $this->viewerToken);
            $byName = array_column($res['data'], null, 'name');
            $this->assertEquals(2, $byName['if']['min_args']);
            $this->assertEquals(3, $byName['if']['max_args']);
            $this->assertEquals('logic', $byName['coalesce']['category']);
            $this->assertTrue(count($res['meta']['operators']) >= 10);
        });
        $this->test('if(): arity is checked on save; a VIP flag is computed at run time', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr arity', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => 'if(1)']]],
            ], $this->adminToken, 422);
            $this->assertEquals('Function "if" expects 2..3 arguments, got 1', $res['error']['details'][0]['message']);
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr vip', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.segment', 'change_type' => 'calculate', 'value' => "if(object.amount > 100 and object.status == 'Active', 'VIP', 'Regular')"],
                    ['type' => 'modify', 'target' => 'object.phone_any', 'change_type' => 'calculate', 'value' => "coalesce(object.mobile, object.phone, 'нет')"],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'ex-1', 'amount' => '500', 'status' => 'Active', 'phone' => '+7']], $this->editorToken, 201)['data'];
            $object = $this->waitForExecution($run['execution_id'])['context']['variables']['object'];
            $this->assertEquals('VIP', $object['segment']);
            $this->assertEquals('+7', $object['phone_any']);
        });
        $this->test('date functions: due date and letter date from event fields; literal unit and tz checked on save', function () {
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr date bad', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "date_diff(object.a, now(), 'decades')"],
                    ['type' => 'modify', 'target' => 'object.y', 'change_type' => 'calculate', 'value' => "today('Mars/Base')"],
                    ['type' => 'modify', 'target' => 'object.z', 'change_type' => 'calculate', 'value' => 'add_workdays(today(), 1)'],
                ]],
            ], $this->adminToken, 422);
            $messages = implode(' | ', array_column($bad['error']['details'], 'message'));
            $this->assertTrue(str_contains($messages, 'unit must be one of minutes, hours, days, weeks, months, years, got "decades"'), $messages);
            $this->assertTrue(str_contains($messages, 'Unknown timezone "Mars/Base"'), $messages);
            $this->assertTrue(str_contains($messages, 'Unknown function "add_workdays"'), $messages);
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr dates', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.due', 'change_type' => 'calculate', 'value' => 'add_days(date(object.date_modified), 3)'],
                    ['type' => 'modify', 'target' => 'object.letter', 'change_type' => 'calculate', 'value' => "format_date(object.date_closed, 'd.m.Y')"],
                    ['type' => 'modify', 'target' => 'object.renewal', 'change_type' => 'calculate', 'value' => 'add_months(object.date_closed, 1)'],
                    ['type' => 'modify', 'target' => 'object.age_days', 'change_type' => 'calculate', 'value' => "date_diff(object.date_entered, object.date_modified, 'days')"],
                    ['type' => 'modify', 'target' => 'object.q_end', 'change_type' => 'calculate', 'value' => 'end_of_quarter(object.date_closed)'],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-dates', 'date_entered' => '2026-09-01 08:00:00', 'date_modified' => '2026-10-10 12:00:00', 'date_closed' => '2026-01-31',
            ]], $this->editorToken, 201)['data'];
            $object = $this->waitForExecution($run['execution_id'])['context']['variables']['object'];
            $this->assertEquals('2026-10-13', $object['due']);
            $this->assertEquals('31.01.2026', $object['letter']);
            $this->assertEquals('2026-02-28', $object['renewal']);
            $this->assertEquals(39, $object['age_days']);
            $this->assertEquals('2026-03-31', $object['q_end']);
            $dictionary = array_column($this->request('GET', '/api/dictionaries/functions', token: $this->viewerToken)['data'], null, 'name');
            $this->assertEquals('date', $dictionary['add_days']['category']);
            $this->assertEquals(false, $dictionary['now']['pure']);
        });
        $this->test('decimal arithmetic: VAT without float noise, to_number and number_format, big sums stay exact', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr numbers', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.with_vat', 'change_type' => 'calculate', 'value' => 'round(object.amount * 1.2, 2)'],
                    ['type' => 'modify', 'target' => 'object.triple', 'change_type' => 'calculate', 'value' => 'object.amount * 3'],
                    ['type' => 'modify', 'target' => 'object.parsed', 'change_type' => 'calculate', 'value' => 'to_number(object.price_text) + 0.44'],
                    ['type' => 'modify', 'target' => 'object.text', 'change_type' => 'calculate', 'value' => "number_format(object.parsed, 2, ',', ' ')"],
                    ['type' => 'modify', 'target' => 'object.big', 'change_type' => 'calculate', 'value' => 'object.big_sum + 0.01'],
                    ['type' => 'modify', 'target' => 'object.discount', 'change_type' => 'calculate', 'value' => 'object.amount * 0.9'],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-numbers', 'amount' => '19.99', 'price_text' => '1 234,56', 'big_sum' => '12345678901234567.89',
            ]], $this->editorToken, 201)['data'];
            $object = $this->waitForExecution($run['execution_id'])['context']['variables']['object'];
            $this->assertEquals(23.99, $object['with_vat']);
            $this->assertEquals(59.97, $object['triple']);
            $this->assertEquals(1235, $object['parsed']);
            $this->assertEquals('1 235,00', $object['text']);
            $this->assertEquals('12345678901234567.9', $object['big']);
            $this->assertEquals(17.991, $object['discount']);
            $this->request('POST', '/api/processes', [
                'name' => 'TEST expr numbers bad', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => 'round(object.amount, 1.5)']]],
            ], $this->adminToken, 422);
        });
        $this->test('template change type: substitutions, filters, expressions, missing paths in the audit; syntax errors → 422', function () {
            $types = array_column($this->request('GET', '/api/dictionaries/change-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Шаблон', $types['template'] ?? null);
            $filters = array_column($this->request('GET', '/api/dictionaries/template-filters', token: $this->viewerToken)['data'], null, 'value');
            $this->assertEquals(1, $filters['truncate']['min_args']);
            $this->assertArrayHasKey('raw', $filters);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST template bad', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.a', 'change_type' => 'template', 'value' => 'Привет {{ object.name'],
                    ['type' => 'modify', 'target' => 'object.b', 'change_type' => 'template', 'value' => '{{ object.name | foo }}'],
                    ['type' => 'modify', 'target' => 'object.c', 'change_type' => 'template', 'value' => '{{ v9.x }}', 'missing' => 'loud'],
                ]],
            ], $this->adminToken, 422);
            $details = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Unclosed "{{" at position 7', $details['definition.actions[0].value'] ?? null);
            $this->assertEquals('Unknown template filter "foo"', $details['definition.actions[1].value'] ?? null);
            $this->assertEquals('Variable "v9" is not defined at this point', $details['definition.actions[2].value'] ?? null);
            $this->assertEquals('Must be one of: empty, error, keep', $details['definition.actions[2].missing'] ?? null);
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST template', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.title', 'change_type' => 'template', 'value' => "Позвонить: {{ object.first_name }} {{ object.last_name | upper }}{{ object.patronymic }}"],
                    ['type' => 'modify', 'target' => 'object.summary', 'change_type' => 'template', 'value' => "Сделка {{ object.name | truncate:10 }} на {{ object.amount | number:2:',':' ' }} руб., срок {{ object.date_closed | format_date:'d.m.Y' }}, скидка {{= if(object.amount > 100000, '10%', '0%') }}"],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-template', 'first_name' => 'Иван', 'last_name' => 'Петров', 'name' => 'Поставка оборудования', 'amount' => '1234567.891', 'date_closed' => '2026-10-31',
            ]], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $object = $execution['context']['variables']['object'];
            $this->assertEquals('Позвонить: Иван ПЕТРОВ', $object['title']);
            $this->assertEquals('Сделка Поставка … на 1 234 567,89 руб., срок 31.10.2026, скидка 10%', $object['summary']);
            $first = $execution['actions'][0];
            $this->assertEquals(['object.patronymic'], $first['result_data']['missing'] ?? null);
            $this->assertEquals('template', $first['request_data']['change_type']);
        });
        $this->test('date_shift change type: field + 2 days at 18:00, month clipping, missing base; validation → 422', function () {            $units = array_column($this->request('GET', '/api/dictionaries/date-units', token: $this->viewerToken)['data'], 'value');
            $this->assertEquals(['minute', 'hour', 'day', 'week', 'month', 'year'], $units);
            $types = array_column($this->request('GET', '/api/dictionaries/change-types', token: $this->viewerToken)['data'], 'label', 'value');
            $this->assertEquals('Сдвиг даты', $types['date_shift'] ?? null);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST date_shift bad', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.a', 'change_type' => 'date_shift', 'base' => 'object.date_modified', 'shift' => '+1 workday', 'set_time' => '25:00'],
                ]],
            ], $this->adminToken, 422);
            $details = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Unit must be one of: minute, hour, day, week, month, year', $details['definition.actions[0].shift'] ?? null);
            $this->assertEquals('Time must be HH:MM', $details['definition.actions[0].set_time'] ?? null);
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST date_shift', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.due', 'change_type' => 'date_shift', 'base' => 'object.date_modified', 'shift' => [['amount' => 2, 'unit' => 'day']], 'set_time' => '18:00'],
                    ['type' => 'modify', 'target' => 'object.next_contact', 'change_type' => 'date_shift', 'base' => 'object.date_closed', 'shift' => '+1 month'],
                    ['type' => 'modify', 'target' => 'object.reminder', 'change_type' => 'date_shift', 'base' => 'object.contract_end', 'shift' => '-30 day'],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-shift', 'date_modified' => '2026-10-10 12:00:00', 'date_closed' => '2026-01-31',
            ]], $this->editorToken, 201)['data'];
            $execution = $this->waitForExecution($run['execution_id']);
            $object = $execution['context']['variables']['object'];
            // WPS_TIMEZONE of the test stand is UTC
            $this->assertEquals('2026-10-12 18:00:00', $object['due']);
            $this->assertEquals('2026-02-28', $object['next_contact']);
            $this->assertEquals(null, $object['reminder']);
            $this->assertEquals(true, $execution['actions'][2]['result_data']['missing_base']);
            $this->assertEquals(2, $execution['actions'][0]['result_data']['shifts'][0]['amount']);
            $this->assertEquals('day', $execution['actions'][0]['result_data']['shifts'][0]['unit']);
        });
        $this->test('list functions: multienum tags through to_list, count/in/unique/concat_lists/sort; sort order → 422', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr lists', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.tags_list', 'change_type' => 'calculate', 'value' => "to_list(object.tags_c, ',', '^')"],
                    ['type' => 'modify', 'target' => 'object.tag_count', 'change_type' => 'calculate', 'value' => "count(to_list(object.tags_c, ',', '^'))"],
                    ['type' => 'modify', 'target' => 'object.has_vip', 'change_type' => 'calculate', 'value' => "in('vip', to_list(object.tags_c, ',', '^'))"],
                    ['type' => 'modify', 'target' => 'object.tags_new', 'change_type' => 'calculate', 'value' => "join(unique(concat_lists(to_list(object.tags_c, ',', '^'), ['vip'])), ', ')"],
                    ['type' => 'modify', 'target' => 'object.sorted', 'change_type' => 'calculate', 'value' => "sort(concat_lists(to_list(object.tags_c, ',', '^'), ['a']), 'desc')"],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-lists', 'tags_c' => '^partner^,^vip^',
            ]], $this->editorToken, 201)['data'];
            $object = $this->waitForExecution($run['execution_id'])['context']['variables']['object'];
            $this->assertEquals(['partner', 'vip'], $object['tags_list']);
            $this->assertEquals(2, $object['tag_count']);
            $this->assertEquals(true, $object['has_vip']);
            $this->assertEquals('partner, vip', $object['tags_new']);
            $this->assertEquals(['vip', 'partner', 'a'], $object['sorted']);
            $byName = array_column($this->request('GET', '/api/dictionaries/functions', token: $this->viewerToken)['data'], null, 'name');
            $this->assertEquals('list', $byName['to_list']['category']);
            $this->assertEquals('number', $byName['count']['returns']);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr lists bad', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "sort(object.tags_list, 'up')"],
                ]],
            ], $this->adminToken, 422);
            $this->assertEquals('Function "sort": order must be "asc" or "desc"', $bad['error']['details'][0]['message']);
        });

        $this->test('string functions: search, Unicode pad, translit/slug, split/join, regex; bad regex → 422', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr strings', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.phone', 'change_type' => 'calculate', 'value' => "regex_replace(object.phone_raw, '[^0-9+]', '')"],
                    ['type' => 'modify', 'target' => 'object.phone_ok', 'change_type' => 'calculate', 'value' => "regex_match(object.phone, '^\\+?\\d{10,15}$')"],
                    ['type' => 'modify', 'target' => 'object.email_user', 'change_type' => 'calculate', 'value' => "before(object.email, '@')"],
                    ['type' => 'modify', 'target' => 'object.email_domain', 'change_type' => 'calculate', 'value' => "after(object.email, '@')"],
                    ['type' => 'modify', 'target' => 'object.email_at', 'change_type' => 'calculate', 'value' => "index_of(object.email, '@')"],
                    ['type' => 'modify', 'target' => 'object.has_at', 'change_type' => 'calculate', 'value' => "contains(object.email, '@')"],
                    ['type' => 'modify', 'target' => 'object.gmail', 'change_type' => 'calculate', 'value' => "ends_with(object.email, '@example.com')"],
                    ['type' => 'modify', 'target' => 'object.prefix_ok', 'change_type' => 'calculate', 'value' => "starts_with(object.name, 'Иван')"],
                    ['type' => 'modify', 'target' => 'object.status_norm', 'change_type' => 'calculate', 'value' => "replace_ci(object.status, 'active', 'Active')"],
                    ['type' => 'modify', 'target' => 'object.padded', 'change_type' => 'calculate', 'value' => "pad_left('42', 5, '0')"],
                    ['type' => 'modify', 'target' => 'object.title', 'change_type' => 'calculate', 'value' => "title_case('иван петров')"],
                    ['type' => 'modify', 'target' => 'object.slug', 'change_type' => 'calculate', 'value' => "slug('Заказ №42')"],
                    ['type' => 'modify', 'target' => 'object.translit', 'change_type' => 'calculate', 'value' => "translit('Щука')"],
                    ['type' => 'modify', 'target' => 'object.tags', 'change_type' => 'calculate', 'value' => "split(object.tags_c, ',', '^')"],
                    ['type' => 'modify', 'target' => 'object.tags_back', 'change_type' => 'calculate', 'value' => "join(object.tags, ',', '^')"],
                    ['type' => 'modify', 'target' => 'object.left5', 'change_type' => 'calculate', 'value' => "left(object.name, 5)"],
                    ['type' => 'modify', 'target' => 'object.right5', 'change_type' => 'calculate', 'value' => "right(object.name, 5)"],
                    ['type' => 'modify', 'target' => 'object.stripped', 'change_type' => 'calculate', 'value' => "strip_tags('<p>Hello <b>world</b></p>')"],
                    ['type' => 'modify', 'target' => 'object.dashes', 'change_type' => 'calculate', 'value' => "repeat('-', 3)"],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-strings',
                'name' => 'Иван Петров',
                'email' => 'ivan@example.com',
                'status' => 'ACTIVE',
                'phone_raw' => '+7 (999) 123-45-67',
                'tags_c' => '^vip^,^partner^',
            ]], $this->editorToken, 201)['data'];
            $object = $this->waitForExecution($run['execution_id'])['context']['variables']['object'];
            $this->assertEquals('+79991234567', $object['phone']);
            $this->assertEquals(true, $object['phone_ok']);
            $this->assertEquals('ivan', $object['email_user']);
            $this->assertEquals('example.com', $object['email_domain']);
            $this->assertEquals(4, $object['email_at']);
            $this->assertEquals(true, $object['has_at']);
            $this->assertEquals(true, $object['gmail']);
            $this->assertEquals(true, $object['prefix_ok']);
            $this->assertEquals('Active', $object['status_norm']);
            $this->assertEquals('00042', $object['padded']);
            $this->assertEquals('Иван Петров', $object['title']);
            $this->assertEquals('zakaz-42', $object['slug']);
            $this->assertEquals('Shchuka', $object['translit']);
            $this->assertEquals(['vip', 'partner'], $object['tags']);
            $this->assertEquals('^vip^,^partner^', $object['tags_back']);
            $this->assertEquals('Иван ', $object['left5']);
            $this->assertEquals('етров', $object['right5']);
            $this->assertEquals('Hello world', $object['stripped']);
            $this->assertEquals('---', $object['dashes']);
            $byName = array_column($this->request('GET', '/api/dictionaries/functions', token: $this->viewerToken)['data'], null, 'name');
            $this->assertEquals('string', $byName['regex_replace']['category']);
            $this->assertEquals('string', $byName['translit']['category']);
            $this->assertEquals('boolean', $byName['regex_match']['returns']);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr strings bad', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "regex_match(object.x, '(')"],
                ]],
            ], $this->adminToken, 422);
            $this->assertEquals('Invalid regular expression in regex_match(): missing closing parenthesis', $bad['error']['details'][0]['message']);
            $badFlags = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr strings flags', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "regex_match(object.x, 'a', 'x')"],
                ]],
            ], $this->adminToken, 422);
            $this->assertEquals('Function "regex_match": flags may contain only i, m, s, got "x"', $badFlags['error']['details'][0]['message']);
        });

        $this->test('type functions: checks, conversions, JSON from a string and an array; literal JSONPath → 422', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr types', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.empty_ok', 'change_type' => 'calculate', 'value' => 'is_empty(object.phone_mobile)'],
                    ['type' => 'modify', 'target' => 'object.blank_empty', 'change_type' => 'calculate', 'value' => 'is_empty(object.blank)'],
                    ['type' => 'modify', 'target' => 'object.not_empty', 'change_type' => 'calculate', 'value' => 'is_not_empty(object.name)'],
                    ['type' => 'modify', 'target' => 'object.num_strict', 'change_type' => 'calculate', 'value' => 'is_number(object.discount_c)'],
                    ['type' => 'modify', 'target' => 'object.num_text', 'change_type' => 'calculate', 'value' => 'is_numeric_text(object.discount_c)'],
                    ['type' => 'modify', 'target' => 'object.int_ok', 'change_type' => 'calculate', 'value' => 'is_int(object.qty)'],
                    ['type' => 'modify', 'target' => 'object.date_ok', 'change_type' => 'calculate', 'value' => 'is_date(object.date_closed)'],
                    ['type' => 'modify', 'target' => 'object.bool_ok', 'change_type' => 'calculate', 'value' => 'is_bool(object.do_not_call)'],
                    ['type' => 'modify', 'target' => 'object.flag', 'change_type' => 'calculate', 'value' => 'to_bool(object.do_not_call)'],
                    ['type' => 'modify', 'target' => 'object.as_text', 'change_type' => 'calculate', 'value' => 'to_string(object.qty)'],
                    ['type' => 'modify', 'target' => 'object.end_date', 'change_type' => 'calculate', 'value' => 'to_date(object.date_closed)'],
                    ['type' => 'modify', 'target' => 'object.end_dt', 'change_type' => 'calculate', 'value' => 'to_datetime(object.date_closed)'],
                    ['type' => 'modify', 'target' => 'object.list_ok', 'change_type' => 'calculate', 'value' => 'is_list(object.ids)'],
                    ['type' => 'modify', 'target' => 'object.object_ok', 'change_type' => 'calculate', 'value' => 'is_object(object.body)'],
                    ['type' => 'modify', 'target' => 'object.json_id', 'change_type' => 'calculate', 'value' => "json_get(object.json_text, '$.data.customer.id')"],
                    ['type' => 'modify', 'target' => 'object.json_default', 'change_type' => 'calculate', 'value' => "json_get(object.json_text, '$.missing', 'нет')"],
                    ['type' => 'modify', 'target' => 'object.json_all', 'change_type' => 'calculate', 'value' => "json_get_all(object.json_text, '$.items[*].id')"],
                    ['type' => 'modify', 'target' => 'object.json_array', 'change_type' => 'calculate', 'value' => "json_get(object.body, '$.items[0].id')"],
                    ['type' => 'modify', 'target' => 'object.encoded', 'change_type' => 'calculate', 'value' => 'json_encode(object.body)'],
                    ['type' => 'modify', 'target' => 'object.decoded', 'change_type' => 'calculate', 'value' => 'json_decode(object.json_text)'],
                ]],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'ex-types',
                'name' => 'Deal',
                'phone_mobile' => null,
                'blank' => '   ',
                'discount_c' => '1 234,56',
                'qty' => 42,
                'date_closed' => '2026-10-16',
                'do_not_call' => 'yes',
                'ids' => [1, 2],
                'body' => ['name' => 'Иван', 'items' => [['id' => 10], ['id' => 20]]],
                'json_text' => '{"data":{"customer":{"id":42}},"items":[{"id":1},{"id":2}]}',
            ]], $this->editorToken, 201)['data'];
            $object = $this->waitForExecution($run['execution_id'])['context']['variables']['object'];
            $this->assertEquals(true, $object['empty_ok']);
            $this->assertEquals(true, $object['blank_empty']);
            $this->assertEquals(true, $object['not_empty']);
            $this->assertEquals(false, $object['num_strict']);
            $this->assertEquals(true, $object['num_text']);
            $this->assertEquals(true, $object['int_ok']);
            $this->assertEquals(true, $object['date_ok']);
            $this->assertEquals(true, $object['bool_ok']);
            $this->assertEquals(true, $object['flag']);
            $this->assertEquals('42', $object['as_text']);
            $this->assertEquals('2026-10-16', $object['end_date']);
            $this->assertEquals('2026-10-16 00:00:00', $object['end_dt']);
            $this->assertEquals(true, $object['list_ok']);
            $this->assertEquals(true, $object['object_ok']);
            $this->assertEquals(42, $object['json_id']);
            $this->assertEquals('нет', $object['json_default']);
            $this->assertEquals([1, 2], $object['json_all']);
            $this->assertEquals(10, $object['json_array']);
            $this->assertEquals('{"name":"Иван","items":[{"id":10},{"id":20}]}', $object['encoded']);
            $this->assertEquals(['data' => ['customer' => ['id' => 42]], 'items' => [['id' => 1], ['id' => 2]]], $object['decoded']);
            $byName = array_column($this->request('GET', '/api/dictionaries/functions', token: $this->viewerToken)['data'], null, 'name');
            $this->assertEquals('type', $byName['is_empty']['category']);
            $this->assertEquals('boolean', $byName['is_number']['returns']);
            $this->assertEquals('type', $byName['json_get']['category']);
            $this->assertEquals('list', $byName['json_get_all']['returns']);
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr types path', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "json_get(object.x, 'a.b')"],
                ]],
            ], $this->adminToken, 422);
            $this->assertEquals('json_get(): JSONPath must start with "$"', $bad['error']['details'][0]['message']);
        });

        $this->test('type functions: a bad boolean and invalid JSON fail the step with a clear message', function () {
            $boolProcess = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr types bool', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => 'to_bool(object.flag)'],
                ]],
            ], $this->adminToken, 201)['data'];
            $boolRun = $this->request('POST', "/api/processes/{$boolProcess['id']}/run", ['object' => ['id' => 'ex-types-bool', 'flag' => 'abc']], $this->editorToken, 201)['data'];
            $boolExecution = $this->waitForExecution($boolRun['execution_id']);
            $this->assertEquals('failed', $boolExecution['status']);
            $this->assertTrue(str_contains((string)$boolExecution['error_message'], 'Cannot convert "abc" to boolean'));
            $jsonProcess = $this->request('POST', '/api/processes', [
                'name' => 'TEST expr types json', 'object_type' => 'TestExpr', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "json_get(object.raw, '$.a')"],
                ]],
            ], $this->adminToken, 201)['data'];
            $jsonRun = $this->request('POST', "/api/processes/{$jsonProcess['id']}/run", ['object' => ['id' => 'ex-types-json', 'raw' => 'not json']], $this->editorToken, 201)['data'];
            $jsonExecution = $this->waitForExecution($jsonRun['execution_id']);
            $this->assertEquals('failed', $jsonExecution['status']);
            $this->assertTrue(str_contains((string)$jsonExecution['error_message'], 'json_get(): invalid JSON'));
        });
    }

    private function testSystemVariables(): void
    {
        echo "[system variables]\n";
        $this->test('System variables dictionary lists every sys.* field', function () {
            $res = $this->request('GET', '/api/dictionaries/system-variables', token: $this->viewerToken, expectedStatus: 200);
            $values = array_column($res['data'], 'value');
            foreach (['sys.execution_id', 'sys.parent_execution_id', 'sys.process_id', 'sys.trigger', 'sys.user_id',
                         'sys.now', 'sys.changed_fields', 'sys.last_error', 'sys.errors_count'] as $field) {
                $this->assertTrue(in_array($field, $values, true), "{$field} is missing");
            }
        });
        $this->test('sys.* and config.* are available in conditions, expressions and the execution snapshot', function () {
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST system variables', 'object_type' => 'TestSys', 'is_child' => false,
                'definition' => [
                    'constants' => ['vip_threshold' => 100000, 'open_stages' => ['A', 'B']],
                    'conditions' => [
                        ['field' => 'sys.trigger', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'manual'],
                    ],
                    'actions' => [
                        ['type' => 'modify', 'target' => 'object.segment', 'change_type' => 'calculate',
                            'value' => "if(object.amount >= config.vip_threshold, 'VIP', 'Regular')"],
                        ['type' => 'modify', 'target' => 'object.execution', 'change_type' => 'calculate', 'value' => 'sys.execution_id'],
                        ['type' => 'modify', 'target' => 'object.summary', 'change_type' => 'calculate',
                            'value' => "concat(sys.trigger, ':', sys.record_id)"],
                    ],
                ],
            ], $this->adminToken, 201)['data'];
            $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => [
                'id' => 'sys-1', 'user_id' => 'u-42', 'amount' => '150000',
                'event_type' => 'update', 'changed_fields' => ['amount'],
            ]], $this->editorToken, 201)['data'];
            $this->assertTrue($run['conditions_met']);
            $executionId = (string)$run['execution_id'];
            $execution = $this->waitForExecution($executionId);
            $this->assertEquals('completed', $execution['status'], (string)($execution['error_message'] ?? ''));
            $object = $execution['context']['variables']['object'];
            $this->assertEquals('VIP', $object['segment']);
            $this->assertEquals($executionId, $object['execution']);
            $this->assertEquals('manual:sys-1', $object['summary']);

            $system = $execution['context']['system'];
            $this->assertEquals($executionId, $system['execution_id']);
            $this->assertEquals('', $system['parent_execution_id']);
            $this->assertEquals($process['id'], $system['process_id']);
            $this->assertEquals('TEST system variables', $system['process_name']);
            $this->assertEquals($process['version'], $system['process_version']);
            $this->assertEquals('manual', $system['trigger']);
            $this->assertEquals(0, $system['depth']);
            $this->assertEquals('TestSys', $system['module']);
            $this->assertEquals('sys-1', $system['record_id']);
            $this->assertEquals('update', $system['event_type']);
            $this->assertEquals(['amount'], $system['changed_fields']);
            $this->assertEquals('u-42', $system['user_id']);
            $this->assertTrue((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$system['now']));
            $this->assertTrue((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$system['started_at']));
            $this->assertEquals(0, $system['errors_count']);
            $this->assertFalse(array_key_exists('last_error', $system));
        });
        $this->test('sys.* is resolved for a stream event; changed_fields default to an empty list', function () {
            if (!class_exists(Redis::class)) {
                $this->skip('ext-redis not available');
            }
            $process = $this->request('POST', '/api/processes', [
                'name' => 'TEST system variables stream', 'object_type' => 'TestSysStream', 'is_child' => false,
                'definition' => ['actions' => [
                    ['type' => 'modify', 'target' => 'object.source', 'change_type' => 'calculate', 'value' => 'sys.trigger'],
                    ['type' => 'modify', 'target' => 'object.user', 'change_type' => 'calculate', 'value' => 'sys.user_id'],
                    ['type' => 'modify', 'target' => 'object.fields_count', 'change_type' => 'calculate',
                        'value' => 'count(sys.changed_fields)'],
                ]],
            ], $this->adminToken, 201)['data'];
            $objectId = 'sys-stream-' . random_int(1000, 9999);
            $this->xadd(['id' => $objectId, 'module' => 'TestSysStream', 'wps_user_id' => 'u-77']);
            $list = $this->waitForExecutions($process['id'], $objectId, 1);
            $execution = $this->waitForExecution($list[0]['id']);
            $this->assertEquals('completed', $execution['status'], (string)($execution['error_message'] ?? ''));
            $object = $execution['context']['variables']['object'];
            $this->assertEquals('stream', $object['source']);
            $this->assertEquals('u-77', $object['user']);
            $this->assertEquals(0, $object['fields_count']);
            $this->assertEquals([], $execution['context']['system']['changed_fields']);
        });
        $this->test('Unknown sys.*, undefined config.* and writes to reserved names are rejected on save', function () {
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST system variables bad', 'object_type' => 'TestSys', 'is_child' => false,
                'definition' => [
                    'constants' => ['known' => 1],
                    'conditions' => [
                        ['field' => 'sys.unknown', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'x'],
                        ['field' => 'config.missing', 'operator' => 'equals', 'value_type' => 'static', 'value' => 'x'],
                    ],
                    'actions' => [
                        ['type' => 'modify', 'target' => 'sys.x', 'change_type' => 'set_value', 'value' => '1'],
                        ['type' => 'set_variable', 'var' => 'config', 'fields' => [['name' => 'x', 'change_type' => 'set_value', 'value' => '1']]],
                    ],
                ],
            ], $this->adminToken, 422);
            $messages = implode(' | ', array_column($bad['error']['details'], 'message'));
            $this->assertTrue(str_contains($messages, 'Unknown system variable "sys.unknown"'), $messages);
            $this->assertTrue(str_contains($messages, 'Constant "missing" is not defined in definition.constants'), $messages);
            $this->assertTrue(str_contains($messages, 'Variable "sys" is read-only'), $messages);
            $this->assertTrue(str_contains($messages, 'Name "config" is reserved'), $messages);
        });
        $this->test('Constants are validated: name, value type and secret-like names', function () {
            $bad = $this->request('POST', '/api/processes', [
                'name' => 'TEST system variables constants', 'object_type' => 'TestSys', 'is_child' => false,
                'definition' => [
                    'constants' => [
                        'bad-name' => 1,
                        'nested' => ['a' => ['b' => 1]],
                        'api_token' => 'abc',
                    ],
                    'actions' => [],
                ],
            ], $this->adminToken, 422);
            $details = array_column($bad['error']['details'], 'message', 'field');
            $this->assertEquals('Invalid constant name (letters, digits, "_")', $details['definition.constants.bad-name'] ?? null);
            $this->assertEquals('Constant must be a scalar or a list of scalars', $details['definition.constants.nested'] ?? null);
            $this->assertEquals('Constant "api_token" looks like a secret; use a secret reference (secret://name)', $details['definition.constants.api_token'] ?? null);
            $this->request('POST', '/api/processes', [
                'name' => 'TEST system variables constants list', 'object_type' => 'TestSys', 'is_child' => false,
                'definition' => ['constants' => ['ok', 'also_ok'], 'actions' => []],
            ], $this->adminToken, 422);
        });
    }

    /**
     * The visual editor (front/src/utils/processForm.ts) offers only operators and change types it can save:
     * every row it can build must be accepted here, and unknown definition keys must not vanish silently.
     */
    private function testEditorFormContract(): void
    {
        echo "[editor form contract]\n";
        $this->test('Unknown definition keys are rejected instead of dropped', function () {
            $res = $this->request('POST', '/api/processes', [
                'name' => 'TEST form contract unknown key', 'object_type' => 'TestForm', 'is_child' => false,
                'definition' => ['actions' => [], 'constant' => ['limit' => 1]],
            ], $this->adminToken, 422);
            $details = array_column($res['error']['details'], 'message', 'field');
            $this->assertEquals(
                'Unknown key "constant" (allowed: conditions, preload, actions, constants)',
                $details['definition.constant'] ?? null,
            );
            $ok = $this->request('POST', '/api/processes', [
                'name' => 'TEST form contract known keys', 'object_type' => 'TestForm', 'is_child' => false,
                'definition' => ['conditions' => [], 'preload' => [], 'actions' => [], 'constants' => ['limit' => 1]],
            ], $this->adminToken, 201)['data'];
            $this->assertEquals(['limit' => 1], $ok['definition']['constants']);
        });
        $this->test('Every operator offered by the editor is accepted in the editor row shape', function () {
            $operators = $this->request('GET', '/api/dictionaries/operators', token: $this->adminToken)['data'];
            $conditions = [];
            foreach ($operators as $op) {
                $noValue = in_array($op['operands'], ['none', 'presence', 'change'], true);
                // same rule as isFormOperator() in front/src/utils/processForm.ts: no cast selector, one text value
                $offered = $noValue
                    || (in_array($op['operands'], ['value', 'change_value'], true) && in_array('string', $op['casts'], true));
                if (!$offered) {
                    continue;
                }
                $conditions[] = $noValue
                    ? ['field' => 'object.status', 'operator' => $op['value']]
                    : ['field' => 'object.status', 'operator' => $op['value'], 'value_type' => 'static', 'value' => 'x'];
            }
            $this->assertTrue(count($conditions) >= 14, 'expected at least 14 editor operators, got ' . count($conditions));
            $res = $this->raw('POST', '/api/processes', [
                'name' => 'TEST form contract operators', 'object_type' => 'TestForm', 'is_child' => false,
                'definition' => ['conditions' => $conditions, 'preload' => [], 'actions' => []],
            ], $this->adminToken);
            $this->assertEquals(201, $res['status'], 'rejected: ' . $res['body']);
        });
        $this->test('Every change type offered by the editor is accepted in the editor row shape', function () {
            // mirror of FORM_CHANGE_TYPES in front/src/utils/processForm.ts with a value the form can produce;
            // null: the form omits "value" (an empty increment / decrement step)
            $values = [
                'set_value' => 'x', 'set_from_variable' => 'object.name', 'calculate' => "concat('a', object.name)",
                'template' => 'Hi {{ object.name }}', 'increment' => '2', 'decrement' => null, 'clear' => '',
            ];
            $known = array_column($this->request('GET', '/api/dictionaries/change-types', token: $this->adminToken)['data'], 'value');
            $actions = [];
            foreach ($values as $type => $value) {
                $this->assertTrue(in_array($type, $known, true), "change type {$type} is not in the dictionary");
                $row = ['type' => 'modify', 'target' => "object.f_{$type}", 'change_type' => $type, 'save_to_crm' => false];
                $actions[] = $value === null ? $row : $row + ['value' => $value];
            }
            $res = $this->raw('POST', '/api/processes', [
                'name' => 'TEST form contract change types', 'object_type' => 'TestForm', 'is_child' => false,
                'definition' => ['conditions' => [], 'preload' => [], 'actions' => $actions],
            ], $this->adminToken);
            $this->assertEquals(201, $res['status'], 'rejected: ' . $res['body']);
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
     * run_once — one start of a process per CRM record (md/idempotentie_plan.md, section 9).
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

    private function redisData(): Redis
    {
        $redis = new Redis();
        $redis->connect(self::env('REDIS_DATA_HOST', '127.0.0.1'), (int)self::env('REDIS_DATA_PORT', '6380'));
        return $redis;
    }

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
