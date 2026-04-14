<?php
declare(strict_types=1);

function getDB(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $db = new PDO('sqlite:' . $dir . '/logbook.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');

    initSchema($db);
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

        CREATE TABLE IF NOT EXISTS locations (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            name     TEXT    NOT NULL,
            type     TEXT    NOT NULL DEFAULT 'city',
            x        REAL    NOT NULL DEFAULT 400,
            y        REAL    NOT NULL DEFAULT 300,
            is_start INTEGER NOT NULL DEFAULT 0
        );
        INSERT OR IGNORE INTO locations (id, name, type, x, y, is_start)
            VALUES (1, 'Старт', 'city', 400, 300, 1);

        CREATE TABLE IF NOT EXISTS edges (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            loc_a    INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
            loc_b    INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
            distance REAL    NOT NULL
        );

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

        CREATE TABLE IF NOT EXISTS route_segments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            waybill_id INTEGER NOT NULL REFERENCES waybills(id) ON DELETE CASCADE,
            seg_order  INTEGER NOT NULL,
            from_id    INTEGER NOT NULL REFERENCES locations(id),
            to_id      INTEGER NOT NULL REFERENCES locations(id),
            start_time TEXT NOT NULL,
            end_time   TEXT NOT NULL,
            distance   REAL NOT NULL
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
}
