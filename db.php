<?php
declare(strict_types=1);

function openDatabase(string $path): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function openLogbook(string $path): PDO {
    $db = openDatabase($path);
    $db->exec('PRAGMA journal_mode = WAL');
    initSchema($db);
    migrateRouteSegments($db);
    return $db;
}

function initSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id      INTEGER PRIMARY KEY DEFAULT 1,
            fuel_summer       REAL NOT NULL DEFAULT 10.0,
            fuel_winter       REAL NOT NULL DEFAULT 12.0,
            countryside_coeff REAL NOT NULL DEFAULT 1.1,
            season            TEXT NOT NULL DEFAULT 'summer'
        );
        INSERT OR IGNORE INTO settings (id) VALUES (1);

        CREATE TABLE IF NOT EXISTS waybills (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            number         TEXT NOT NULL,
            date           TEXT NOT NULL DEFAULT (date('now','localtime')),
            refuel_time    TEXT NOT NULL,
            odometer_before REAL NOT NULL DEFAULT 0,
            odometer_after  REAL NOT NULL DEFAULT 0,
            daily_mileage   REAL NOT NULL DEFAULT 0,
            fuel_refueled   REAL NOT NULL DEFAULT 0,
            fuel_spent      REAL NOT NULL DEFAULT 0,
            fuel_before     REAL NOT NULL DEFAULT 0,
            fuel_after      REAL NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS waybill_route_options (
            waybill_id   INTEGER PRIMARY KEY REFERENCES waybills(id) ON DELETE CASCADE,
            segment_mode TEXT NOT NULL,
            fuel_mode    TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS logbook (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_date     TEXT NOT NULL DEFAULT (date('now','localtime')),
            entry_time     TEXT,
            odometer       REAL,
            daily_mileage  REAL,
            since_to2      REAL,
            fuel_remaining REAL,
            daily_fuel     REAL,
            waybill_id     INTEGER REFERENCES waybills(id),
            entry_type     TEXT NOT NULL DEFAULT 'checkpoint'
        );
    ");
    createRouteSegments($db);
}

function createRouteSegments(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS route_segments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            waybill_id INTEGER NOT NULL REFERENCES waybills(id) ON DELETE CASCADE,
            seg_order  INTEGER NOT NULL,
            from_id    INTEGER NOT NULL,
            to_id      INTEGER NOT NULL,
            from_name  TEXT    NOT NULL,
            from_type  TEXT    NOT NULL,
            to_name    TEXT    NOT NULL,
            to_type    TEXT    NOT NULL,
            start_time TEXT NOT NULL,
            end_time   TEXT NOT NULL,
            distance   REAL NOT NULL
        );
    ");
}

function migrateRouteSegments(PDO $db): void {
    if (hasRouteSnapshots($db)) return;

    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!hasRouteSnapshots($db)) {
            $db->exec('ALTER TABLE route_segments RENAME TO old_route_segments');
            createRouteSegments($db);
            $db->exec("
                INSERT INTO route_segments
                    (id, waybill_id, seg_order, from_id, to_id, from_name, from_type, to_name, to_type,
                     start_time, end_time, distance)
                SELECT rs.id, rs.waybill_id, rs.seg_order, rs.from_id, rs.to_id,
                       COALESCE(from_loc.name, 'Локация ' || rs.from_id), COALESCE(from_loc.type, 'city'),
                       COALESCE(to_loc.name, 'Локация ' || rs.to_id), COALESCE(to_loc.type, 'city'),
                       rs.start_time, rs.end_time, rs.distance
                FROM old_route_segments rs
                LEFT JOIN locations from_loc ON from_loc.id = rs.from_id
                LEFT JOIN locations to_loc ON to_loc.id = rs.to_id
            ");
            $db->exec('DROP TABLE old_route_segments');
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function hasRouteSnapshots(PDO $db): bool {
    return in_array('from_name', array_column($db->query('PRAGMA table_info(route_segments)')->fetchAll(), 'name'), true);
}
