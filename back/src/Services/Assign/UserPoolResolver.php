<?php

declare(strict_types=1);

namespace Wps\Services\Assign;

use Redis;
use Wps\Actions\Support\RelatedIdsResolver;
use Wps\Context\ExecutionContext;
use Wps\Crm\CrmFilter;
use Wps\Exceptions\NonRecoverableException;
use Wps\Infrastructure\RedisConnection;
use Wps\Services\ExternalApiAdapterService;

/**
 * Users an assignment chooses from (md/tasks_list/4.9-assign.md §4.2): a list of id sources or the members of a
 * security group (SecurityGroups/{id}/relationships/users, cached ASSIGN_POOL_CACHE_TTL seconds), only active users,
 * without the excluded ones, sorted by id — the order is deterministic.
 */
class UserPoolResolver
{
    public const USER_FIELDS = ['status', 'employee_status', 'is_group', 'portal_only', 'reports_to_id'];
    public const MAX_POOL = 200;

    public function __construct(
        private readonly ExternalApiAdapterService $crm,
        private readonly ?RedisConnection $redis,
        private readonly int $cacheTtl = 60,
    ) {
    }

    /**
     * Active user: status Active, employee_status Active (or empty), not a group user, not a portal user.
     *
     * @param array<string, mixed> $user
     */
    public static function isActive(array $user): bool
    {
        return in_array((string)($user['status'] ?? 'Active'), ['Active', ''], true)
            && in_array((string)($user['employee_status'] ?? ''), ['Active', ''], true)
            && (string)($user['is_group'] ?? '0') !== '1'
            && (string)($user['portal_only'] ?? '0') !== '1';
    }

    /**
     * @param array<string, mixed> $pool {"users": [...]} | {"security_group": "<id>" | {"path": ...}}
     * @param list<string> $exclude
     * @return array{pool: list<string>, inactive: list<string>, excluded: list<string>, source: string}
     */
    public function resolve(array $pool, ExecutionContext $context, bool $onlyActive, array $exclude): array
    {
        if (array_key_exists('security_group', $pool)) {
            $group = $pool['security_group'];
            $groupId = is_array($group) ? RelatedIdsResolver::resolve([$group], $context)['ids'][0] ?? '' : trim((string)$group);
            if ($groupId === '') {
                throw new NonRecoverableException('assign: security group id is empty');
            }
            $users = $this->groupMembers($groupId);
            $source = "security_group {$groupId}";
        } else {
            $ids = RelatedIdsResolver::resolve(array_values((array)($pool['users'] ?? [])), $context)['ids'];
            $users = $onlyActive ? $this->users($ids) : array_map(static fn (string $id) => ['id' => $id], $ids);
            $source = 'users';
        }
        $result = [];
        $inactive = [];
        $excluded = [];
        foreach ($users as $user) {
            $id = (string)($user['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (in_array($id, $exclude, true)) {
                $excluded[] = $id;
            } elseif ($onlyActive && !self::isActive($user)) {
                $inactive[] = $id;
            } else {
                $result[] = $id;
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return ['pool' => $result, 'inactive' => $inactive, 'excluded' => $excluded, 'source' => $source];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(string $id): ?array
    {
        return $this->crm->getById('Users', $id);
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>> records of the users that exist (missing ids are dropped)
     */
    private function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::MAX_POOL) {
            throw new NonRecoverableException(sprintf('assign: pool is too large (%d > %d)', count($ids), self::MAX_POOL));
        }
        return $this->crm->findMany('Users', (new CrmFilter())->add('id', 'in', $ids), [], array_merge(['id'], self::USER_FIELDS), count($ids))['items'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupMembers(string $groupId): array
    {
        $key = 'assign:pool:' . sha1("security_group:{$groupId}");
        $cached = $this->redis?->run(static fn (Redis $r) => $r->get($key));
        if (is_string($cached)) {
            return (array)json_decode($cached, true);
        }
        $members = [];
        $cursor = null;
        try {
            do {
                $page = $this->crm->listRelated('SecurityGroups', $groupId, 'users', array_merge(['id'], self::USER_FIELDS), 100, $cursor);
                array_push($members, ...array_map(static fn (array $u) => array_intersect_key($u, array_flip(array_merge(['id'], self::USER_FIELDS))), $page->items));
                $cursor = $page->nextCursor;
            } while ($cursor !== null && count($members) < self::MAX_POOL);
        } catch (NonRecoverableException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw new NonRecoverableException("Security group \"{$groupId}\" not found in CRM");
            }
            throw $e;
        }
        if ($this->cacheTtl > 0) {
            $ttl = $this->cacheTtl;
            $this->redis?->run(static fn (Redis $r) => $r->set($key, (string)json_encode($members), ['ex' => $ttl]));
        }
        return $members;
    }
}
