<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const DATA_DIR = __DIR__ . '/data';
const LEGACY_VEHICLE = 'legacy';

// Hold the lock until request shutdown so deletion cannot race an open journal.
function vehicleCatalog(bool $write = false): PDO {
    static $lock;
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('Не удалось создать каталог журналов');
    }

    $lock = @fopen(DATA_DIR . '/vehicles.lock', 'c');
    if (!$lock || !flock($lock, $write ? LOCK_EX : LOCK_SH)) {
        throw new RuntimeException('Не удалось заблокировать каталог журналов');
    }

    $db = openDatabase(DATA_DIR . '/vehicles.sqlite');
    $db->exec('CREATE TABLE IF NOT EXISTS vehicles (
        id TEXT PRIMARY KEY,
        plate TEXT UNIQUE,
        deleted INTEGER NOT NULL DEFAULT 0
    )');
    if (is_file(vehiclePath(LEGACY_VEHICLE))) {
        $legacy = $db->prepare('SELECT 1 FROM vehicles WHERE id = ?');
        $legacy->execute([LEGACY_VEHICLE]);
        if (!$legacy->fetchColumn()) {
            $db->prepare('INSERT OR IGNORE INTO vehicles (id) VALUES (?)')->execute([LEGACY_VEHICLE]);
        }
    }
    return $db;
}

function vehiclePath(string $id): string {
    return DATA_DIR . '/' . ($id === LEGACY_VEHICLE ? 'logbook' : 'vehicle-' . $id) . '.sqlite';
}

function listVehicles(PDO $catalog): array {
    $cars = $catalog->query('SELECT id, plate FROM vehicles WHERE deleted = 0 ORDER BY rowid')->fetchAll();
    return array_column($cars, null, 'id');
}

function normalizePlate(mixed $input): string {
    $input = is_string($input) ? $input : '';
    $plate = preg_replace('/[\s-]+/u', '', strtoupper($input));
    if ($plate === null || !preg_match('/^[0-9A-ZА-Яа-яЁё]{2,16}$/uD', $plate)) {
        throw new RuntimeException('Укажите госномер из 2–16 букв или цифр');
    }
    $plate = strtr($plate, array_combine(
        preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY),
        preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY)
    ));
    if (preg_match('/^[ABEKMHOPCTYXАВЕКМНОРСТУХ]\d{3}[ABEKMHOPCTYXАВЕКМНОРСТУХ]{2}\d{2,3}$/uD', $plate)) {
        $plate = strtr($plate, [
            'A'=>'А', 'B'=>'В', 'E'=>'Е', 'K'=>'К', 'M'=>'М', 'H'=>'Н',
            'O'=>'О', 'P'=>'Р', 'C'=>'С', 'T'=>'Т', 'Y'=>'У', 'X'=>'Х',
        ]);
    }
    return $plate;
}

function saveVehicle(PDO $catalog, bool $assignLegacyPlate): array {
    $plate = normalizePlate($_POST['plate'] ?? '');
    $duplicate = $catalog->prepare('SELECT 1 FROM vehicles WHERE plate = ?');
    $duplicate->execute([$plate]);
    if ($duplicate->fetchColumn()) {
        throw new RuntimeException('Автомобиль с таким госномером уже есть');
    }

    if ($assignLegacyPlate) {
        $stmt = $catalog->prepare('UPDATE vehicles SET plate = ? WHERE id = ? AND plate IS NULL AND deleted = 0');
        $stmt->execute([$plate, LEGACY_VEHICLE]);
        if (!$stmt->rowCount()) {
            throw new RuntimeException('Журнал без госномера не найден');
        }
        return ['id' => LEGACY_VEHICLE];
    }

    $id = bin2hex(random_bytes(16));
    $path = vehiclePath($id);
    try {
        openLogbook($path);
        $catalog->prepare('INSERT INTO vehicles (id, plate) VALUES (?, ?)')->execute([$id, $plate]);
    } catch (Throwable $error) {
        removeVehicleFiles($path);
        throw $error;
    }
    return ['id' => $id];
}

function deleteVehicle(PDO $catalog): array {
    $id = $_POST['id'] ?? '';
    $cars = listVehicles($catalog);
    if (!is_string($id) || !isset($cars[$id])) {
        throw new RuntimeException('Автомобиль не найден');
    }

    $stmt = $catalog->prepare('UPDATE vehicles SET deleted = 1, plate = NULL WHERE id = ?');
    $stmt->execute([$id]);
    unset($cars[$id]);
    return [
        'id' => array_key_first($cars),
        'files_removed' => removeVehicleFiles(vehiclePath($id)),
    ];
}

function removeVehicleFiles(string $path): bool {
    $removed = true;
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        $file = $path . $suffix;
        if (is_file($file) && !@unlink($file)) {
            $removed = false;
            error_log('Could not remove vehicle database file: ' . $file);
        }
    }
    return $removed;
}
