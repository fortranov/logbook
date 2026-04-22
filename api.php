<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Only run as HTTP endpoint, not when included by other scripts
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'api.php') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)($_REQUEST['action'] ?? '');
    try {
        $db  = getDB();
        $out = dispatch($db, $action);
        echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ─── Router ────────────────────────────────────────────────────────────────

function dispatch(PDO $db, string $action): mixed {
    return match ($action) {
        'get_settings'        => actionGetSettings($db),
        'save_settings'       => actionSaveSettings($db),
        'get_logbook'         => actionGetLogbook($db),
        'add_checkpoint'      => actionAddCheckpoint($db),
        'create_waybill'      => actionCreateWaybill($db),
        'get_waybill'         => actionGetWaybill($db),
        'regen_route'         => actionRegenRoute($db),
        'get_locations'       => actionGetLocations($db),
        'add_location'        => actionAddLocation($db),
        'update_location'     => actionUpdateLocation($db),
        'delete_location'     => actionDeleteLocation($db),
        'update_location_pos' => actionUpdateLocationPos($db),
        'add_edge'            => actionAddEdge($db),
        'update_edge'         => actionUpdateEdge($db),
        'delete_edge'         => actionDeleteEdge($db),
        default               => throw new \RuntimeException("Unknown action: $action"),
    };
}

// ─── Settings ──────────────────────────────────────────────────────────────

function actionGetSettings(PDO $db): array {
    return (array)$db->query("SELECT * FROM settings WHERE id=1")->fetch();
}

function actionSaveSettings(PDO $db): bool {
    $s  = (float)($_POST['fuel_summer']       ?? 10.0);
    $w  = (float)($_POST['fuel_winter']        ?? 12.0);
    $c  = (float)($_POST['countryside_coeff']  ?? 1.1);
    $ss = in_array($_POST['season'] ?? '', ['summer','winter']) ? $_POST['season'] : 'summer';
    $db->prepare("UPDATE settings SET fuel_summer=?,fuel_winter=?,countryside_coeff=?,season=? WHERE id=1")
       ->execute([$s, $w, $c, $ss]);
    return true;
}

// ─── Logbook ───────────────────────────────────────────────────────────────

function actionGetLogbook(PDO $db): array {
    return $db->query("
        SELECT l.*, w.number AS waybill_number, w.fuel_refueled AS fuel_refueled
        FROM logbook l
        LEFT JOIN waybills w ON l.waybill_id = w.id
        ORDER BY l.id ASC
    ")->fetchAll();
}

function lastLogEntry(PDO $db): ?array {
    $r = $db->query("SELECT * FROM logbook ORDER BY id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

function actionAddCheckpoint(PDO $db): array {
    $odo  = pp('odometer');
    $to2  = pp('since_to2');
    $fuel = pp('fuel_remaining');

    $db->prepare("
        INSERT INTO logbook (entry_type, odometer, since_to2, fuel_remaining, entry_date, entry_time)
        VALUES ('checkpoint', ?, ?, ?, date('now','localtime'), time('now','localtime'))
    ")->execute([$odo, $to2, $fuel]);

    return ['id' => (int)$db->lastInsertId()];
}

/** nullable float helper */
function pp(string $key): ?float {
    if (!isset($_POST[$key]) || $_POST[$key] === '') return null;
    return (float)$_POST[$key];
}

function actionCreateWaybill(PDO $db): array {
    $number      = trim($_POST['number']       ?? '');
    $fuelRefueled = (float)($_POST['fuel_refueled'] ?? 0);
    $refuelTime  = trim($_POST['refuel_time']  ?? '');

    if (!$number)        throw new \RuntimeException('Номер путевого листа обязателен');
    if ($fuelRefueled <= 0) throw new \RuntimeException('Количество топлива > 0');
    if (!$refuelTime)    throw new \RuntimeException('Время заправки обязательно');

    $prev          = lastLogEntry($db);
    $odomBefore    = (float)($prev['odometer']       ?? 0);
    $to2Before     = (float)($prev['since_to2']      ?? 0);
    $fuelBefore    = (float)($prev['fuel_remaining'] ?? 0);

    $settings = actionGetSettings($db);
    $route    = generateRoute($db, $fuelRefueled, $refuelTime, $settings);
    if (!$route) throw new \RuntimeException('Не удалось построить маршрут. Добавьте точки и рёбра в «Граф локаций».');

    $dist      = $route['totalDist'];
    $fuelSpent = $route['fuelSpent'];
    $odomAfter = $odomBefore + $dist;
    $to2After  = $to2Before  + $dist;
    $fuelAfter = $fuelBefore + $fuelRefueled - $fuelSpent;

    $db->prepare("
        INSERT INTO waybills (number,date,refuel_time,odometer_before,odometer_after,daily_mileage,
                              fuel_refueled,fuel_spent,fuel_before,fuel_after)
        VALUES (?,date('now','localtime'),?,?,?,?,?,?,?,?)
    ")->execute([$number, $refuelTime, $odomBefore, $odomAfter, $dist,
                 $fuelRefueled, $fuelSpent, $fuelBefore, $fuelAfter]);
    $wid = (int)$db->lastInsertId();

    saveSegments($db, $wid, $route['segments']);

    $db->prepare("
        INSERT INTO logbook (entry_type,odometer,daily_mileage,since_to2,fuel_remaining,daily_fuel,
                             waybill_id,entry_date,entry_time)
        VALUES ('waybill',?,?,?,?,?,?,date('now','localtime'),time('now','localtime'))
    ")->execute([$odomAfter, $dist, $to2After, $fuelAfter, $fuelRefueled - $fuelSpent, $wid]);

    return ['waybill_id' => $wid];
}

// ─── Waybill ───────────────────────────────────────────────────────────────

function actionGetWaybill(PDO $db): array {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new \RuntimeException('Неверный ID');
    $stmt = $db->prepare("SELECT * FROM waybills WHERE id=?");
    $stmt->execute([$id]);
    $w = $stmt->fetch();
    if (!$w) throw new \RuntimeException('Путевой лист не найден');

    $stmt = $db->prepare("
        SELECT rs.*, fl.name AS from_name, fl.type AS from_type,
                     tl.name AS to_name,   tl.type AS to_type
        FROM route_segments rs
        JOIN locations fl ON rs.from_id = fl.id
        JOIN locations tl ON rs.to_id   = tl.id
        WHERE rs.waybill_id = ?
        ORDER BY rs.seg_order
    ");
    $stmt->execute([$id]);
    return ['waybill' => $w, 'segments' => $stmt->fetchAll()];
}

function actionRegenRoute(PDO $db): array {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new \RuntimeException('Неверный ID');
    $stmt = $db->prepare("SELECT * FROM waybills WHERE id=?");
    $stmt->execute([$id]);
    $w = $stmt->fetch();
    if (!$w) throw new \RuntimeException('Путевой лист не найден');

    $settings = actionGetSettings($db);
    $route = generateRoute($db, (float)$w['fuel_refueled'], $w['refuel_time'], $settings, mt_rand(1, 999999));
    if (!$route) throw new \RuntimeException('Не удалось построить новый маршрут');

    $dist      = $route['totalDist'];
    $fuelSpent = $route['fuelSpent'];
    $odomAfter = (float)$w['odometer_before'] + $dist;
    $fuelAfter = (float)$w['fuel_before'] + (float)$w['fuel_refueled'] - $fuelSpent;

    $db->prepare("DELETE FROM route_segments WHERE waybill_id=?")->execute([$id]);
    $db->prepare("
        UPDATE waybills SET odometer_after=?,daily_mileage=?,fuel_spent=?,fuel_after=? WHERE id=?
    ")->execute([$odomAfter, $dist, $fuelSpent, $fuelAfter, $id]);
    saveSegments($db, $id, $route['segments']);

    // Update logbook row
    $stmt = $db->prepare("SELECT id FROM logbook WHERE waybill_id=?");
    $stmt->execute([$id]);
    $logRow = $stmt->fetch();
    if ($logRow) {
        $lid = (int)$logRow['id'];
        $prev = $db->prepare("SELECT since_to2 FROM logbook WHERE id < ? ORDER BY id DESC LIMIT 1");
        $prev->execute([$lid]);
        $pRow     = $prev->fetch();
        $to2After = ((float)($pRow['since_to2'] ?? 0)) + $dist;
        $dailyFuel = (float)$w['fuel_refueled'] - $fuelSpent;
        $db->prepare("
            UPDATE logbook SET odometer=?,daily_mileage=?,since_to2=?,fuel_remaining=?,daily_fuel=? WHERE id=?
        ")->execute([$odomAfter, $dist, $to2After, $fuelAfter, $dailyFuel, $lid]);
    }

    // Re-fetch GET param spoof
    $_GET['id'] = $id;
    return actionGetWaybill($db);
}

function saveSegments(PDO $db, int $wid, array $segs): void {
    $st = $db->prepare("
        INSERT INTO route_segments (waybill_id,seg_order,from_id,to_id,start_time,end_time,distance)
        VALUES (?,?,?,?,?,?,?)
    ");
    foreach ($segs as $i => $s) {
        $st->execute([$wid, $i, $s['from_id'], $s['to_id'], $s['start_time'], $s['end_time'], $s['distance']]);
    }
}

// ─── Locations ─────────────────────────────────────────────────────────────

function actionGetLocations(PDO $db): array {
    $locs  = $db->query("SELECT * FROM locations ORDER BY id")->fetchAll();
    $edges = $db->query("
        SELECT e.*, la.name AS loc_a_name, lb.name AS loc_b_name
        FROM edges e
        JOIN locations la ON e.loc_a = la.id
        JOIN locations lb ON e.loc_b = lb.id
    ")->fetchAll();
    return ['locations' => $locs, 'edges' => $edges];
}

function actionAddLocation(PDO $db): array {
    $name   = trim($_POST['name']   ?? '');
    $type   = in_array($_POST['type'] ?? '', ['city','countryside']) ? $_POST['type'] : 'city';
    $fromId = (int)($_POST['from_id']  ?? 0);
    $dist   = (float)($_POST['distance'] ?? 0);
    $x      = (float)($_POST['x'] ?? 400);
    $y      = (float)($_POST['y'] ?? 300);

    if (!$name) throw new \RuntimeException('Название обязательно');
    if ($fromId && $dist <= 0) throw new \RuntimeException('Расстояние > 0');

    $db->prepare("INSERT INTO locations (name,type,x,y) VALUES (?,?,?,?)")->execute([$name,$type,$x,$y]);
    $newId = (int)$db->lastInsertId();

    if ($fromId) {
        $a = min($fromId, $newId); $b = max($fromId, $newId);
        $db->prepare("INSERT INTO edges (loc_a,loc_b,distance) VALUES (?,?,?)")->execute([$a,$b,$dist]);
    }
    return actionGetLocations($db);
}

function actionUpdateLocation(PDO $db): array {
    $id   = (int)($_POST['id']   ?? 0);
    $name = trim($_POST['name']  ?? '');
    $type = in_array($_POST['type'] ?? '', ['city','countryside']) ? $_POST['type'] : 'city';
    if (!$id || !$name) throw new \RuntimeException('Неверные данные');
    $db->prepare("UPDATE locations SET name=?,type=? WHERE id=?")->execute([$name,$type,$id]);
    return actionGetLocations($db);
}

function actionDeleteLocation(PDO $db): array {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new \RuntimeException('Неверный ID');
    $stmt = $db->prepare("SELECT is_start FROM locations WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['is_start']) throw new \RuntimeException('Нельзя удалить точку «Старт»');
    $db->prepare("DELETE FROM locations WHERE id=?")->execute([$id]);
    return actionGetLocations($db);
}

function actionUpdateLocationPos(PDO $db): array {
    $id = (int)($_POST['id'] ?? 0);
    $x  = (float)($_POST['x'] ?? 0);
    $y  = (float)($_POST['y'] ?? 0);
    $db->prepare("UPDATE locations SET x=?,y=? WHERE id=?")->execute([$x,$y,$id]);
    return ['ok' => true];
}

function actionAddEdge(PDO $db): array {
    $a    = (int)($_POST['from_id']  ?? 0);
    $b    = (int)($_POST['to_id']    ?? 0);
    $dist = (float)($_POST['distance'] ?? 0);
    if (!$a || !$b)   throw new \RuntimeException('Укажите обе точки');
    if ($a === $b)    throw new \RuntimeException('Точки должны различаться');
    if ($dist <= 0)   throw new \RuntimeException('Расстояние > 0');
    $la = min($a,$b); $lb = max($a,$b);
    $st = $db->prepare("SELECT id FROM edges WHERE loc_a=? AND loc_b=?");
    $st->execute([$la,$lb]);
    if ($st->fetch()) throw new \RuntimeException('Такое ребро уже существует');
    $db->prepare("INSERT INTO edges (loc_a,loc_b,distance) VALUES (?,?,?)")->execute([$la,$lb,$dist]);
    return actionGetLocations($db);
}

function actionUpdateEdge(PDO $db): array {
    $id   = (int)($_POST['id']   ?? 0);
    $dist = (float)($_POST['distance'] ?? 0);
    if (!$id || $dist <= 0) throw new \RuntimeException('Неверные данные');
    $db->prepare("UPDATE edges SET distance=? WHERE id=?")->execute([$dist,$id]);
    return actionGetLocations($db);
}

function actionDeleteEdge(PDO $db): array {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new \RuntimeException('Неверный ID');
    $db->prepare("DELETE FROM edges WHERE id=?")->execute([$id]);
    return actionGetLocations($db);
}

// ─── Route Generation ──────────────────────────────────────────────────────

function generateRoute(PDO $db, float $fuelRefueled, string $refuelTime, array $settings, ?int $seed = null): ?array {
    $data  = actionGetLocations($db);
    $locs  = $data['locations'];
    $edges = $data['edges'];

    if (empty($edges)) return null;

    $locMap  = [];
    $startId = null;
    foreach ($locs as $l) {
        $locMap[(int)$l['id']] = $l;
        if ($l['is_start']) $startId = (int)$l['id'];
    }
    if (!$startId) return null;

    // Adjacency list
    $adj = [];
    foreach ($locs as $l) $adj[(int)$l['id']] = [];
    foreach ($edges as $e) {
        $adj[(int)$e['loc_a']][] = ['to' => (int)$e['loc_b'], 'dist' => (float)$e['distance']];
        $adj[(int)$e['loc_b']][] = ['to' => (int)$e['loc_a'], 'dist' => (float)$e['distance']];
    }
    if (empty($adj[$startId])) return null;

    $rate   = (float)($settings['season'] === 'summer' ? $settings['fuel_summer'] : $settings['fuel_winter']);
    $coeff  = (float)$settings['countryside_coeff'];

    if ($seed !== null) mt_srand($seed);

    // Dijkstra distances FROM each node back to start (= from start, symmetric)
    $dStart = dijkstraDist($adj, $startId);

    $best     = null;
    $bestDiff = PHP_FLOAT_MAX;

    for ($att = 0; $att < 400; $att++) {
        // Alternate target: city-rate vs countryside-rate
        $targetRate = ($att % 3 === 0) ? $rate : $rate * $coeff;
        $target     = $fuelRefueled * 100.0 / $targetRate;

        $res = randomWalk($adj, $startId, $target, $dStart);
        if (!$res) continue;
        [$path, $totalDist] = $res;

        // Determine effective rate
        $hasCountry = false;
        foreach ($path as $nid) {
            if (($locMap[$nid]['type'] ?? 'city') === 'countryside') { $hasCountry = true; break; }
        }
        $effRate   = $hasCountry ? $rate * $coeff : $rate;
        $fuelSpent = round($totalDist * $effRate / 100.0, 2);
        $diff      = abs($fuelSpent - $fuelRefueled);

        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $best = ['path' => $path, 'totalDist' => round($totalDist, 1), 'fuelSpent' => $fuelSpent];
        }
        if ($diff < 0.3) break;
    }

    if (!$best) return null;

    $segs = buildSegments($best['path'], $adj, $refuelTime, $best['totalDist']);
    return ['segments' => $segs, 'totalDist' => $best['totalDist'], 'fuelSpent' => $best['fuelSpent']];
}

function randomWalk(array $adj, int $startId, float $target, array $dStart): ?array {
    $path  = [$startId];
    $dist  = 0.0;
    $cur   = $startId;
    $steps = 0;

    while ($steps < 300) {
        $neighbors = $adj[$cur] ?? [];
        if (empty($neighbors)) break;

        $dHome     = $dStart[$cur] ?? PHP_FLOAT_MAX;
        $remaining = $target - $dist;

        // If we're close enough to home, take shortest path back
        if ($remaining <= $dHome + 0.5) {
            $home = dijkstraPath($adj, $cur, $startId);
            if ($home) {
                $prev = $cur;
                foreach ($home as $n) {
                    if ($n === $cur) continue;
                    $dist += edgeDist($adj, $prev, $n);
                    $path[] = $n;
                    $prev   = $n;
                }
            }
            break;
        }

        // Filter valid moves (won't overshoot by more than 30 km)
        shuffle($neighbors);
        $valid = array_filter($neighbors, function($nb) use ($dist, $target, $dStart) {
            $nd = $dist + $nb['dist'] + ($dStart[$nb['to']] ?? PHP_FLOAT_MAX);
            return $nd <= $target + 30;
        });

        if (empty($valid)) {
            // No valid move – head home directly
            $home = dijkstraPath($adj, $cur, $startId);
            if ($home) {
                $prev = $cur;
                foreach ($home as $n) {
                    if ($n === $cur) continue;
                    $dist += edgeDist($adj, $prev, $n);
                    $path[] = $n;
                    $prev   = $n;
                }
            }
            break;
        }

        $nb   = array_values($valid)[0];
        $path[] = $nb['to'];
        $dist  += $nb['dist'];
        $cur    = $nb['to'];
        $steps++;
    }

    // Ensure we end at start
    if (end($path) !== $startId) {
        $home = dijkstraPath($adj, $cur, $startId);
        if (!$home) return null;
        $prev = $cur;
        foreach ($home as $n) {
            if ($n === $cur) continue;
            $dist += edgeDist($adj, $prev, $n);
            $path[] = $n;
            $prev   = $n;
        }
    }

    if (count($path) < 2) return null;
    return [$path, $dist];
}

function edgeDist(array $adj, int $from, int $to): float {
    foreach ($adj[$from] ?? [] as $nb) {
        if ($nb['to'] === $to) return $nb['dist'];
    }
    return 0.0;
}

function dijkstraDist(array $adj, int $src): array {
    $dist = array_fill_keys(array_keys($adj), PHP_FLOAT_MAX);
    $dist[$src] = 0.0;
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $pq->insert($src, 0);
    $vis = [];

    while (!$pq->isEmpty()) {
        ['data' => $u, 'priority' => $p] = $pq->extract();
        if (isset($vis[$u])) continue;
        $vis[$u] = true;
        foreach ($adj[$u] ?? [] as $nb) {
            $nd = $dist[$u] + $nb['dist'];
            if ($nd < $dist[$nb['to']]) {
                $dist[$nb['to']] = $nd;
                $pq->insert($nb['to'], -$nd);
            }
        }
    }
    return $dist;
}

function dijkstraPath(array $adj, int $src, int $dst): ?array {
    $dist = array_fill_keys(array_keys($adj), PHP_FLOAT_MAX);
    $prev = array_fill_keys(array_keys($adj), null);
    $dist[$src] = 0.0;
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $pq->insert($src, 0);
    $vis = [];

    while (!$pq->isEmpty()) {
        ['data' => $u] = $pq->extract();
        if (isset($vis[$u])) continue;
        $vis[$u] = true;
        if ($u === $dst) break;
        foreach ($adj[$u] ?? [] as $nb) {
            $nd = $dist[$u] + $nb['dist'];
            if ($nd < $dist[$nb['to']]) {
                $dist[$nb['to']] = $nd;
                $prev[$nb['to']] = $u;
                $pq->insert($nb['to'], -$nd);
            }
        }
    }

    if ($dist[$dst] === PHP_FLOAT_MAX) return null;
    $path = [];
    $cur  = $dst;
    while ($cur !== null) { array_unshift($path, $cur); $cur = $prev[$cur]; }
    return $path;
}

function buildSegments(array $path, array $adj, string $refuelTime, float $totalDist): array {
    $speed       = 60.0;
    $totalMinutes = (int)round($totalDist / $speed * 60);

    [$rh, $rm]      = explode(':', $refuelTime);
    $refuelMin       = (int)$rh * 60 + (int)$rm;

    // Departure: refueling happens at 30–70% of trip
    $fraction       = 0.30 + (mt_rand(0, 400) / 1000.0);
    $departMin      = $refuelMin - (int)round($fraction * $totalMinutes);
    if ($departMin < 5 * 60) $departMin = 5 * 60;

    $segs = [];
    $cumDist = 0.0;

    for ($i = 0; $i < count($path) - 1; $i++) {
        $fid     = $path[$i];
        $tid     = $path[$i + 1];
        $d       = edgeDist($adj, $fid, $tid);
        $startM  = $departMin + (int)round($cumDist / $speed * 60);
        $endM    = $departMin + (int)round(($cumDist + $d) / $speed * 60);
        $segs[]  = [
            'from_id'    => $fid,
            'to_id'      => $tid,
            'start_time' => minsToTime($startM),
            'end_time'   => minsToTime($endM),
            'distance'   => $d,
        ];
        $cumDist += $d;
    }
    return $segs;
}

function minsToTime(int $m): string {
    $m = (($m % 1440) + 1440) % 1440;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
