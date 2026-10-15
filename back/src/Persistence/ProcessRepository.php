<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Infrastructure\Database;

final class ProcessRepository
{
    private const COLUMNS = 'id, name, object_type, is_child, is_active, priority, run_once, version, definition, created_at, updated_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Process
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM processes WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        return $row === null ? null : Process::fromRow($row);
    }

    /**
     * @param int[] $ids
     * @return array<int, Process> keyed by id
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll('SELECT ' . self::COLUMNS . " FROM processes WHERE deleted_at IS NULL AND id IN ({$placeholders})", $ids);
        $result = [];
        foreach ($rows as $row) {
            $process = Process::fromRow($row);
            $result[$process->id] = $process;
        }
        return $result;
    }

    /**
     * Active top-level processes for an object type, in priority order (Process Resolver, TS §5.2).
     *
     * @return list<Process>
     */
    public function findActiveTopLevel(string $objectType): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM processes
             WHERE object_type = :type AND is_child = FALSE AND is_active = TRUE AND deleted_at IS NULL
             ORDER BY priority ASC, id ASC',
            ['type' => $objectType],
        );
        return array_map(Process::fromRow(...), $rows);
    }

    /**
     * @param array{search?: string|null, object_type?: string|null, is_child?: bool|null, is_active?: bool|null} $filters
     * @return array{0: list<Process>, 1: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];
        if (($filters['search'] ?? '') !== '') {
            $where[] = 'name ILIKE :search';
            $params['search'] = '%' . addcslashes((string)$filters['search'], '%_\\') . '%';
        }
        if (($filters['object_type'] ?? '') !== '') {
            $where[] = 'object_type = :object_type';
            $params['object_type'] = $filters['object_type'];
        }
        if (isset($filters['is_child'])) {
            $where[] = 'is_child = :is_child';
            $params['is_child'] = (bool)$filters['is_child'];
        }
        if (isset($filters['is_active'])) {
            $where[] = 'is_active = :is_active';
            $params['is_active'] = (bool)$filters['is_active'];
        }
        $whereSql = implode(' AND ', $where);
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM processes WHERE {$whereSql}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM processes WHERE {$whereSql} ORDER BY updated_at DESC, id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        return [array_map(Process::fromRow(...), $rows), $total];
    }

    /**
     * @param array{name: string, object_type: string, is_child: bool, is_active: bool, priority: int, run_once: bool, definition: array<mixed>} $data
     */
    public function create(array $data): Process
    {
        $row = $this->db->fetchOne(
            'INSERT INTO processes (name, object_type, is_child, is_active, priority, run_once, version, definition)
             VALUES (:name, :object_type, :is_child, :is_active, :priority, :run_once, 1, CAST(:definition AS JSONB))
             RETURNING ' . self::COLUMNS,
            $data,
        );
        return Process::fromRow((array)$row);
    }

    /**
     * @param array{name: string, object_type: string, is_child: bool, is_active: bool, priority: int, run_once: bool, definition: array<mixed>} $data
     */
    public function update(int $id, array $data): ?Process
    {
        $row = $this->db->fetchOne(
            'UPDATE processes SET name = :name, object_type = :object_type, is_child = :is_child, is_active = :is_active,
                    priority = :priority, run_once = :run_once, definition = CAST(:definition AS JSONB), version = version + 1, updated_at = NOW()
             WHERE id = :id AND deleted_at IS NULL
             RETURNING ' . self::COLUMNS,
            $data + ['id' => $id],
        );
        return $row === null ? null : Process::fromRow($row);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->execute(
            'UPDATE processes SET deleted_at = NOW(), is_active = FALSE, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        ) > 0;
    }

    /**
     * Processes whose actions start the given process (start_process.process_id / process_ids).
     *
     * @return list<array{id: int, name: string}>
     */
    public function findParents(int $childId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name FROM processes
             WHERE deleted_at IS NULL
               AND jsonb_path_exists(
                     definition,
                     '\$.actions[*] ? (@.type == \"start_process\" && (@.process_id == \$id || @.process_ids[*] == \$id || @.process_id == \$sid || @.process_ids[*] == \$sid))',
                     jsonb_build_object('id', CAST(:id AS BIGINT), 'sid', CAST(:sid AS TEXT)))
             ORDER BY name",
            ['id' => $childId, 'sid' => (string)$childId],
        );
        return array_map(static fn (array $r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows);
    }

    /**
     * Graph of start_process edges for cycle detection: process id => list of child ids.
     *
     * @return array{edges: array<int, int[]>, names: array<int, string>}
     */
    public function startProcessGraph(): array
    {
        $rows = $this->db->fetchAll("SELECT id, name, definition->'actions' AS actions FROM processes WHERE deleted_at IS NULL");
        $edges = [];
        $names = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $names[$id] = (string)$row['name'];
            $edges[$id] = [];
            $actions = json_decode((string)$row['actions'], true);
            foreach (is_array($actions) ? $actions : [] as $action) {
                if (is_array($action) && ($action['type'] ?? null) === 'start_process') {
                    foreach (\Wps\Actions\StartProcessHandler::processIds($action) as $child) {
                        $edges[$id][] = $child;
                    }
                }
            }
        }
        return ['edges' => $edges, 'names' => $names];
    }
}
