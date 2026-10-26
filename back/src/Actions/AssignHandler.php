<?php

declare(strict_types=1);

namespace Wps\Actions;

use Redis;
use Wps\Actions\Support\RelatedIdsResolver;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Crm\FilterRulesValidator;
use Wps\Crm\FilterTranslator;
use Wps\Exceptions\NonRecoverableException;
use Wps\Infrastructure\RedisConnection;
use Wps\Services\Assign\AssignmentStrategies;
use Wps\Services\Assign\UserPoolResolver;
use Wps\Services\CrmObjectSaver;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Назначить ответственного" (md/tasks_list/4.9-assign.md): a user field of a record gets a user chosen by a rule —
 * round_robin, least_busy, random, manager (reports_to_id), user. The decision of a step is remembered per execution,
 * pc and iteration, so a repeated step (retry, resume) assigns the same user and does not move the queue again.
 */
final class AssignHandler implements ActionHandlerInterface
{
    public const STRATEGIES = [
        'round_robin' => 'По кругу', 'least_busy' => 'Наименее загруженный', 'random' => 'Случайно',
        'manager' => 'Руководитель', 'user' => 'Конкретный пользователь',
    ];
    private const POOL_STRATEGIES = ['round_robin', 'least_busy', 'random'];
    private const DECISION_TTL = 86400;

    public function __construct(
        private readonly UserPoolResolver $pools,
        private readonly AssignmentStrategies $strategies,
        private readonly FilterTranslator $translator,
        private readonly FilterRulesValidator $filters,
        private readonly CrmObjectSaver $saver,
        private readonly ?RedisConnection $redis,
        private readonly AppConfig $config,
        private readonly ?MetricsService $metrics = null,
    ) {
    }

    public function type(): string
    {
        return 'assign';
    }

    public function label(): string
    {
        return 'Назначить ответственного';
    }

    public function description(): string
    {
        return 'Назначает ответственного по правилу: по кругу, наименее загруженного, случайно, руководителя';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $target = (string)$actionConfig['target'];
        $field = (string)($actionConfig['field'] ?? 'assigned_user_id');
        $strategy = (string)$actionConfig['strategy'];
        $onlyActive = (bool)($actionConfig['only_active'] ?? true);
        $queueKey = (string)($actionConfig['queue_key'] ?? ('p' . ($context->processId ?? 0) . ':' . ($actionConfig['id'] ?? "pc{$context->pc}")));
        $previous = $context->get("{$target}.{$field}");
        $request = ['target' => $target, 'field' => $field, 'strategy' => $strategy, 'only_active' => $onlyActive]
            + (in_array($strategy, ['round_robin', 'least_busy'], true) ? ['queue_key' => $queueKey] : []);

        $decisionKey = $context->executionId !== null ? "assign:decided:{$context->executionId}:{$context->pc}:{$context->iteration}" : null;
        $stored = $decisionKey !== null ? $this->redis?->run(static fn (Redis $r) => $r->get($decisionKey)) : null;
        if (is_string($stored) && ($decision = json_decode($stored, true)) !== null) {
            $result = (array)$decision + ['reused_decision' => true];
        } else {
            $result = $this->decide($context, $actionConfig, $strategy, $onlyActive, $queueKey, $previous);
            if ($decisionKey !== null) {
                $this->redis?->run(static fn (Redis $r) => $r->set($decisionKey, (string)json_encode($result), ['ex' => self::DECISION_TTL]));
            }
        }
        $chosen = $result['chosen_user_id'] ?? null;
        $this->metrics?->inc(MetricsService::ASSIGNMENTS);
        if ($chosen === null) {
            if (($result['fallback'] ?? 'keep') === 'error') {
                throw new NonRecoverableException('assign: ' . $result['reason']);
            }
            return new ActionResult(ActionResult::SKIPPED, $request, $result);
        }
        $context->set("{$target}.{$field}", $chosen);
        $result += ['previous_user_id' => $previous, 'unchanged' => (string)$previous === $chosen];
        if (!empty($actionConfig['save_to_crm'])) {
            $module = isset($actionConfig['module']) && is_string($actionConfig['module']) ? $actionConfig['module'] : null;
            $save = $this->saver->save($context, $target, $module);
            $request['save'] = $save->request;
            $result['save'] = ['status' => $save->status] + $save->result;
        }
        return ActionResult::success($request, $result);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed> chosen_user_id (null — nobody), reason, pool details
     */
    private function decide(ExecutionContext $context, array $config, string $strategy, bool $onlyActive, string $queueKey, mixed $previous): array
    {
        $result = [];
        $chosen = null;
        if (in_array($strategy, self::POOL_STRATEGIES, true)) {
            $exclude = RelatedIdsResolver::resolve(array_values((array)($config['exclude'] ?? [])), $context)['ids'];
            $pool = $this->pools->resolve((array)$config['pool'], $context, $onlyActive, $exclude);
            $result = ['pool_size' => count($pool['pool']), 'pool' => array_slice($pool['pool'], 0, 50), 'pool_source' => $pool['source']]
                + ($pool['inactive'] !== [] ? ['inactive' => $pool['inactive']] : []) + ($pool['excluded'] !== [] ? ['excluded' => $pool['excluded']] : []);
            if ($pool['pool'] === []) {
                return $this->fallback($context, $config, $onlyActive, $result, 'pool is empty');
            }
            if ($strategy === 'round_robin') {
                [$chosen, $last] = $this->strategies->roundRobin($queueKey, $pool['pool']);
                $result['reason'] = $last !== null ? "round_robin: next after {$last}" : 'round_robin: first in the pool';
            } elseif ($strategy === 'least_busy') {
                AssignmentStrategies::assertLeastBusyPool(count($pool['pool']), $this->config->assignLeastBusyMaxPool);
                $lb = (array)$config['least_busy'];
                $module = (string)$lb['module'];
                $filter = $this->translator->translate(array_values((array)$lb['filter']), $context, 'error');
                [$chosen, $counts] = $this->strategies->leastBusy($pool['pool'], $module, (string)($lb['user_field'] ?? 'assigned_user_id'), $filter, $queueKey);
                $result['counts'] = $counts;
                $result['reason'] = "least_busy: {$chosen} has {$counts[$chosen]} open {$module} (min)";
            } else {
                $chosen = $this->strategies->random($pool['pool']);
                $result['reason'] = 'random';
            }
        } elseif ($strategy === 'manager') {
            $of = isset($config['manager_of']) ? (RelatedIdsResolver::resolve([$config['manager_of']], $context)['ids'][0] ?? '') : (string)$previous;
            if ($of === '') {
                return $this->fallback($context, $config, $onlyActive, $result, 'nobody to find the manager of');
            }
            $manager = (string)($this->pools->user($of)['reports_to_id'] ?? '');
            if ($manager === '') {
                return $this->fallback($context, $config, $onlyActive, $result + ['manager_of' => $of], "user {$of} has no manager");
            }
            if ($onlyActive && !$this->active($manager)) {
                return $this->fallback($context, $config, $onlyActive, $result + ['manager_of' => $of], "manager {$manager} is not active");
            }
            $chosen = $manager;
            $result += ['manager_of' => $of, 'reason' => "manager of {$of}"];
        } else {
            $user = RelatedIdsResolver::resolve([$config['user'] ?? []], $context)['ids'][0] ?? '';
            if ($user === '') {
                return $this->fallback($context, $config, $onlyActive, $result, 'user id is empty');
            }
            if ($onlyActive && !$this->active($user)) {
                return $this->fallback($context, $config, $onlyActive, $result, "user {$user} is not active");
            }
            $chosen = $user;
            $result['reason'] = 'user';
        }
        return ['chosen_user_id' => $chosen] + $result;
    }

    private function active(string $user): bool
    {
        $record = $this->pools->user($user);
        return $record !== null && UserPoolResolver::isActive($record);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function fallback(ExecutionContext $context, array $config, bool $onlyActive, array $result, string $why): array
    {
        $mode = (string)($config['fallback']['mode'] ?? 'keep');
        if ($mode === 'user') {
            $user = RelatedIdsResolver::resolve([$config['fallback']['user'] ?? []], $context)['ids'][0] ?? '';
            if ($user !== '' && (!$onlyActive || $this->active($user))) {
                return ['chosen_user_id' => $user, 'fallback' => 'user'] + $result + ['reason' => "fallback: user — {$why}"];
            }
            $why .= '; the fallback user is empty or not active';
            $mode = 'keep';
        }
        return ['chosen_user_id' => null, 'fallback' => $mode] + $result + ['reason' => "fallback: {$mode} — {$why}"];
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $target = $actionConfig['target'] ?? null;
        if (!is_string($target) || !ExecutionContext::isValidVariableName($target)) {
            $scope->error("{$path}.target", 'Record variable is required (e.g. object)');
        } elseif (in_array($scope->kind($target), [ExecutionContext::SOURCE_LIST, ExecutionContext::SOURCE_LOCAL, ExecutionContext::SOURCE_LOOP_INFO], true) || ExecutionContext::isReadOnly($target)) {
            $scope->error("{$path}.target", "Variable \"{$target}\" is not a CRM record");
        } else {
            $scope->checkWritePath("{$target}.x", "{$path}.target");
        }
        if (isset($actionConfig['field']) && (!is_string($actionConfig['field']) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $actionConfig['field']))) {
            $scope->error("{$path}.field", 'Invalid field name');
        }
        $strategy = $actionConfig['strategy'] ?? null;
        if (!is_string($strategy) || !isset(self::STRATEGIES[$strategy])) {
            $scope->error("{$path}.strategy", 'Strategy must be one of: ' . implode(', ', array_keys(self::STRATEGIES)));
            $strategy = null;
        }
        if (in_array($strategy, self::POOL_STRATEGIES, true)) {
            $pool = $actionConfig['pool'] ?? null;
            if (!is_array($pool) || array_key_exists('users', $pool) === array_key_exists('security_group', $pool)) {
                $scope->error("{$path}.pool", 'Pool is required: {"users": [...]} or {"security_group": ...}');
            } elseif (array_key_exists('users', $pool)) {
                RelatedIdsResolver::validateList($pool['users'], $scope, "{$path}.pool.users", 'At least one user source is required');
            } elseif (is_array($pool['security_group'])) {
                RelatedIdsResolver::validateSource($pool['security_group'], $scope, "{$path}.pool.security_group");
            } elseif (!is_string($pool['security_group']) || trim($pool['security_group']) === '') {
                $scope->error("{$path}.pool.security_group", 'Security group id is required');
            }
        }
        if ($strategy === 'least_busy') {
            $lb = $actionConfig['least_busy'] ?? null;
            if (!is_array($lb) || !is_string($lb['module'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $lb['module'])) {
                $scope->error("{$path}.least_busy.module", 'CRM module of the counted records is required (e.g. Cases)');
            }
            if (!is_array($lb) || empty($lb['filter'])) {
                $scope->error("{$path}.least_busy.filter", 'Filter of "open" records is required for least_busy');
            } else {
                $this->filters->validate($lb['filter'], true, $scope, "{$path}.least_busy.filter", 'least_busy');
            }
        }
        if ($strategy === 'user') {
            RelatedIdsResolver::validateSource($actionConfig['user'] ?? null, $scope, "{$path}.user");
        }
        if (isset($actionConfig['manager_of'])) {
            RelatedIdsResolver::validateSource($actionConfig['manager_of'], $scope, "{$path}.manager_of");
        }
        if (isset($actionConfig['exclude'])) {
            RelatedIdsResolver::validateList($actionConfig['exclude'], $scope, "{$path}.exclude", 'Must be a non-empty list of id sources');
        }
        if (isset($actionConfig['queue_key']) && (!is_string($actionConfig['queue_key']) || !preg_match('/^[A-Za-z0-9_:.-]{1,100}$/', $actionConfig['queue_key']))) {
            $scope->error("{$path}.queue_key", 'Invalid queue key');
        }
        if (isset($actionConfig['fallback'])) {
            $fb = $actionConfig['fallback'];
            if (!is_array($fb) || !in_array($fb['mode'] ?? null, ['keep', 'error', 'user'], true)) {
                $scope->error("{$path}.fallback", 'Fallback must be keep, error or user');
            } elseif ($fb['mode'] === 'user') {
                RelatedIdsResolver::validateSource($fb['user'] ?? null, $scope, "{$path}.fallback.user");
            }
        }
        foreach (['only_active', 'save_to_crm'] as $flag) {
            if (array_key_exists($flag, $actionConfig) && !is_bool($actionConfig[$flag])) {
                $scope->error("{$path}.{$flag}", 'Must be true or false');
            }
        }
    }
}
