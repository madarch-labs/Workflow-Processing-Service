<?php

declare(strict_types=1);

namespace Wps\Infrastructure;

use PDO;
use PDOException;
use Swoole\ConnectionPool;
use Wps\Config\AppConfig;

/**
 * PostgreSQL access through a per-worker coroutine connection pool.
 * PDO calls are non-blocking thanks to the Swoole runtime hooks (coroutine pgsql driver).
 */
final class Database
{
    private ConnectionPool $pool;

    public function __construct(private readonly AppConfig $config)
    {
        $this->pool = new ConnectionPool(fn (): PDO => $this->connect(), $config->dbPoolSize);
    }

    public function connect(): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->config->dbHost, $this->config->dbPort, $this->config->dbName);
        $pdo = new PDO($dsn, $this->config->dbUser, $this->config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // all TIMESTAMP columns are stored in UTC
        $pdo->exec("SET TIME ZONE 'UTC'");
        return $pdo;
    }

    /**
     * Borrow a connection for the duration of $fn. Broken connections are dropped from the pool.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        /** @var PDO $pdo */
        $pdo = $this->pool->get();
        try {
            $result = $fn($pdo);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable) {
                }
            }
            $this->pool->put(self::isConnectionError($e) ? null : $pdo);
            throw $e;
        }
        $this->pool->put($pdo);
        return $result;
    }

    /**
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $this->run(static function (PDO $pdo) use ($fn) {
            $pdo->beginTransaction();
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        });
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run(static fn (PDO $pdo) => self::prepare($pdo, $sql, $params)->fetchAll());
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->run(static function (PDO $pdo) use ($sql, $params) {
            $row = self::prepare($pdo, $sql, $params)->fetch();
            return $row === false ? null : $row;
        });
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->run(static function (PDO $pdo) use ($sql, $params) {
            $value = self::prepare($pdo, $sql, $params)->fetchColumn();
            return $value === false ? null : $value;
        });
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run(static fn (PDO $pdo) => self::prepare($pdo, $sql, $params)->rowCount());
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public static function prepare(PDO $pdo, string $sql, array $params): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            if ($value === null) {
                $stmt->bindValue($name, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                // pgsql does not accept "" for false, so booleans are sent as literals
                $stmt->bindValue($name, $value ? 'true' : 'false');
            } elseif (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
            } elseif (is_array($value) || is_object($value)) {
                $stmt->bindValue($name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            } else {
                $stmt->bindValue($name, (string)$value);
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public static function isConnectionError(\Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $state = (string)($e->errorInfo[0] ?? $e->getCode());
        if (str_starts_with($state, '08') || $state === '57P01' || $state === 'HY000') {
            return true;
        }
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'server closed') || str_contains($msg, 'connection') || str_contains($msg, 'broken pipe');
    }
}
