<?php
declare(strict_types=1);
require_once __DIR__ . '/vehicles.php';

function openSharedGraph(array $cars): PDO {
    $db = openDatabase(DATA_DIR . '/graph.sqlite');
    if (graphHasLocations($db)) return $db;

    // Publish the schema and copied graph in the same transaction.
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!graphHasLocations($db)) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS locations (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    name     TEXT    NOT NULL,
                    type     TEXT    NOT NULL DEFAULT 'city',
                    x        REAL    NOT NULL DEFAULT 400,
                    y        REAL    NOT NULL DEFAULT 300,
                    is_start INTEGER NOT NULL DEFAULT 0
                );

                CREATE TABLE IF NOT EXISTS edges (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    loc_a    INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
                    loc_b    INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
                    distance REAL    NOT NULL
                );
            ");
            $firstId = array_key_first($cars);
            if ($firstId !== null) {
                copyVehicleGraph($db, vehiclePath($firstId));
            }
            $db->exec("
                INSERT INTO locations (id, name, type, x, y, is_start)
                SELECT 1, 'Старт', 'city', 400, 300, 1
                WHERE NOT EXISTS (SELECT 1 FROM locations)
            ");
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
    return $db;
}

function graphHasLocations(PDO $db): bool {
    return (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'locations'")->fetchColumn();
}

function copyVehicleGraph(PDO $graph, string $path): void {
    if (!is_file($path)) {
        throw new RuntimeException('Файл журнала первого автомобиля не найден');
    }

    $source = openDatabase($path);
    if (!graphHasLocations($source)) return;

    $source->beginTransaction();
    $insert = $graph->prepare('INSERT INTO locations (id, name, type, x, y, is_start) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($source->query('SELECT id, name, type, x, y, is_start FROM locations', PDO::FETCH_NUM) as $location) {
        $insert->execute($location);
    }
    $insert = $graph->prepare('INSERT INTO edges (id, loc_a, loc_b, distance) VALUES (?, ?, ?, ?)');
    foreach ($source->query('SELECT id, loc_a, loc_b, distance FROM edges', PDO::FETCH_NUM) as $edge) {
        $insert->execute($edge);
    }
    $source->commit();
}
