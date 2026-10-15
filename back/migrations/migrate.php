<?php

declare(strict_types=1);

/**
 * Applies migrations/*.sql in name order, once each (tracked in schema_migrations).
 * Usage: php migrations/migrate.php
 */

use Wps\Config\AppConfig;
use Wps\Kernel\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');
$config = AppConfig::fromEnv();

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config->dbHost, $config->dbPort, $config->dbName);
$pdo = null;
$deadline = time() + (int)(Env::get('DB_WAIT_SECONDS', '60') ?? 60);
while ($pdo === null) {
    try {
        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        if (time() >= $deadline) {
            fwrite(STDERR, "[migrate] database is not reachable: {$e->getMessage()}\n");
            exit(1);
        }
        echo "[migrate] waiting for database...\n";
        sleep(2);
    }
}

$pdo->exec("SET TIME ZONE 'UTC'");
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT NOW())');
$applied = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));

$files = glob(__DIR__ . '/*.sql') ?: [];
sort($files, SORT_STRING);
$count = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($applied[$version])) {
        continue;
    }
    $sql = (string)file_get_contents($file);
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $stmt->execute([$version]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "[migrate] {$version} failed: {$e->getMessage()}\n");
        exit(1);
    }
    echo "[migrate] applied {$version}\n";
    $count++;
}
echo "[migrate] done, {$count} new migration(s)\n";
