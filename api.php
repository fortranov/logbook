<?php
declare(strict_types=1);
require_once __DIR__ . '/vehicles.php';

// Only run as HTTP endpoint, not when included by other scripts
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'api.php') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)($_REQUEST['action'] ?? '');
    try {
        header('Cache-Control: no-store');
        $management = in_array($action, ['add_vehicle', 'assign_legacy_plate', 'delete_vehicle'], true);
        if ($management && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || ($_SERVER['HTTP_X_LOGBOOK_REQUEST'] ?? '') !== '1')) {
            throw new RuntimeException('Недопустимый запрос на изменение списка автомобилей');
        }

        $catalog = vehicleCatalog($management);
        if ($management) {
            $out = $action === 'delete_vehicle'
                ? deleteVehicle($catalog)
                : saveVehicle($catalog, $action === 'assign_legacy_plate');
        } else {
            $cars = listVehicles($catalog);
            $id = $_GET['vehicle'] ?? array_key_first($cars);
            if (!is_string($id) || !isset($cars[$id])) {
                throw new RuntimeException('Автомобиль не найден. Откройте журнал заново.');
            }
            $path = vehiclePath($id);
            if (!is_file($path)) {
                throw new RuntimeException('Файл журнала автомобиля не найден');
            }
            $out = dispatch(openLogbook($path), $action);
        }
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
        'delete_waybill'      => actionDeleteWaybill($db),
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
        SELECT l.*, w.number AS waybill_number, w.fuel_refueled AS fuel_refueled, w.fuel_spent AS fuel_spent
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
    $number       = trim($_POST['number']        ?? '');
    $fuelRefueled = (float)($_POST['fuel_refueled'] ?? 0);
    $refuelTime   = trim($_POST['refuel_time']   ?? '');
    $date         = trim($_POST['date']          ?? '');
    if (!$date) $date = date('Y-m-d');

    if (!$number)           throw new \RuntimeException('Номер путевого листа обязателен');
    if ($fuelRefueled <= 0) throw new \RuntimeException('Количество топлива > 0');
    if (!$refuelTime)       throw new \RuntimeException('Время заправки обязательно');

    $prev          = lastLogEntry($db);
    $odomBefore    = (float)($prev['odometer']       ?? 0);
    $to2Before     = (float)($prev['since_to2']      ?? 0);
    $fuelBefore    = (float)($prev['fuel_remaining'] ?? 0);

    $options = null;
    if (isset($_POST['segment_mode']) || isset($_POST['fuel_mode'])) {
        $segmentMode = $_POST['segment_mode'] ?? 'average';
        $fuelMode = $_POST['fuel_mode'] ?? 'economy';
        if (!in_array($segmentMode, ['minimum', 'average'], true)
            || !in_array($fuelMode, ['economy', 'consumption'], true)) {
            throw new RuntimeException('Неверные настройки маршрута');
        }
        $options = ['segment_mode' => $segmentMode, 'fuel_mode' => $fuelMode];
    }
    $route = generateRoute($db, $fuelRefueled, $refuelTime, actionGetSettings($db), options: $options);
    if (!$route) throw new \RuntimeException('Не удалось построить маршрут. Добавьте точки и рёбра в «Граф локаций».');

    $dist      = $route['totalDist'];
    $fuelSpent = round($route['fuelSpent'], 1);
    $odomAfter = $odomBefore + $dist;
    $to2After  = $to2Before  + $dist;
    $fuelAfter = round($fuelBefore + $fuelRefueled - $fuelSpent, 1);
    $dailyFuel = round($fuelRefueled - $fuelSpent, 1);

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO waybills (number,date,refuel_time,odometer_before,odometer_after,daily_mileage,
                                  fuel_refueled,fuel_spent,fuel_before,fuel_after)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$number, $date, $refuelTime, $odomBefore, $odomAfter, $dist,
                     $fuelRefueled, $fuelSpent, $fuelBefore, $fuelAfter]);
        $wid = (int)$db->lastInsertId();

        if ($options !== null) {
            $db->prepare('INSERT INTO waybill_route_options (waybill_id, segment_mode, fuel_mode) VALUES (?, ?, ?)')
               ->execute([$wid, $options['segment_mode'], $options['fuel_mode']]);
        }
        saveSegments($db, $wid, $route['segments']);

        $db->prepare("
            INSERT INTO logbook (entry_type,odometer,daily_mileage,since_to2,fuel_remaining,daily_fuel,
                                 waybill_id,entry_date,entry_time)
            VALUES ('waybill',?,?,?,?,?,?,?,time('now','localtime'))
        ")->execute([$odomAfter, $dist, $to2After, $fuelAfter, $dailyFuel, $wid, $date]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }

    return ['waybill_id' => $wid];
}

function actionDeleteWaybill(PDO $db): array {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new \RuntimeException('Неверный ID');

    $stmt = $db->prepare("SELECT id FROM waybills WHERE id=?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) throw new \RuntimeException('Путевой лист не найден');

    // Delete logbook entry first to satisfy FK constraint
    $db->prepare("DELETE FROM logbook WHERE waybill_id=?")->execute([$id]);
    // route_segments CASCADE on waybill delete
    $db->prepare("DELETE FROM waybills WHERE id=?")->execute([$id]);

    return actionGetLogbook($db);
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
    $stmt = $db->prepare('SELECT segment_mode, fuel_mode FROM waybill_route_options WHERE waybill_id = ?');
    $stmt->execute([$id]);
    $options = $stmt->fetch() ?: null;
    $route = generateRoute(
        $db,
        (float)$w['fuel_refueled'],
        $w['refuel_time'],
        $settings,
        mt_rand(1, 999999),
        $options,
        $options === null ? null : (float)$w['daily_mileage']
    );
    if (!$route) throw new \RuntimeException('Не удалось построить новый маршрут');

    $dist      = $route['totalDist'];
    $fuelSpent = round($route['fuelSpent'], 1);
    $odomAfter = (float)$w['odometer_before'] + $dist;
    $fuelAfter = round((float)$w['fuel_before'] + (float)$w['fuel_refueled'] - $fuelSpent, 1);

    $db->beginTransaction();
    try {
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
            $dailyFuel = round((float)$w['fuel_refueled'] - $fuelSpent, 1);
            $db->prepare("
                UPDATE logbook SET odometer=?,daily_mileage=?,since_to2=?,fuel_remaining=?,daily_fuel=? WHERE id=?
            ")->execute([$odomAfter, $dist, $to2After, $fuelAfter, $dailyFuel, $lid]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
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

function generateRoute(
    PDO $db,
    float $fuelRefueled,
    string $refuelTime,
    array $settings,
    ?int $seed = null,
    ?array $options = null,
    ?float $targetDistance = null
): ?array {
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
        $a = (int)$e['loc_a'];
        $b = (int)$e['loc_b'];
        $dist = (float)$e['distance'];
        if ($options !== null) {
            $dist = max(0.1, round($dist, 1));
        }
        $country = $locMap[$a]['type'] === 'countryside' || $locMap[$b]['type'] === 'countryside';
        $adj[$a][] = ['to' => $b, 'dist' => $dist, 'country' => $country];
        $adj[$b][] = ['to' => $a, 'dist' => $dist, 'country' => $country];
    }
    if (empty($adj[$startId])) return null;

    $rate   = (float)($settings['season'] === 'summer' ? $settings['fuel_summer'] : $settings['fuel_winter']);
    $coeff  = (float)$settings['countryside_coeff'];

    $dStart = dijkstraDist($adj, $startId);
    if ($options === null || $targetDistance === null) {
        // Use the same baseline walks for all four settings.
        $baselineSeed = $options === null ? $seed : 0;
        if ($baselineSeed !== null) mt_srand($baselineSeed);
        $baseline = findBaselineRoute($adj, $startId, $dStart, $locMap, $fuelRefueled, $rate, $coeff);
        if ($baseline === null) return null;
        $targetDistance = $baseline['totalDist'];
    }

    if ($options === null) {
        $route = $baseline;
    } else {
        // Randomize route selection separately from the fixed mileage estimate.
        if ($seed === null) {
            mt_srand();
        } else {
            mt_srand($seed);
        }
        $route = optimizeRoute($adj, $startId, $dStart, $targetDistance, $options);
        if ($route === null) return null;
        $countryKm = $route['countryDistance'];
        $cityKm = $targetDistance - $countryKm;
        $route['fuelSpent'] = round(($cityKm + $countryKm * $coeff) * $rate / 100.0, 2);
    }

    return [
        'segments' => buildSegments($route['path'], $adj, $refuelTime, $targetDistance),
        'totalDist' => $targetDistance,
        'fuelSpent' => $route['fuelSpent'],
    ];
}

function findBaselineRoute(
    array $adj,
    int $startId,
    array $dStart,
    array $locMap,
    float $fuelRefueled,
    float $rate,
    float $coeff
): ?array {
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

    return $best;
}

// Distances are integer tenths of a kilometre, matching the graph editor.
function optimizeRoute(array $adj, int $startId, array $dStart, float $distance, array $options): ?array {
    $target = (int)round($distance * 10);
    $minimum = $options['segment_mode'] === 'minimum';
    $countrySign = $options['fuel_mode'] === 'consumption' ? -1 : 1;

    // At each (distance, location), keep the best prefix. Future choices
    // depend only on the current location and the remaining distance.
    $states = [0 => [$startId => [0, 0, 0, $startId]]];
    $levels = new SplPriorityQueue();
    $levels->insert(0, 0);
    $stateCount = 1;
    while (!$levels->isEmpty()) {
        $used = $levels->extract();
        if ($used === $target) break;
        foreach ($states[$used] as $node => [$steps, $country]) {
            foreach ($adj[$node] as $edge) {
                $next = $edge['to'];
                $length = (int)round($edge['dist'] * 10);
                $newDistance = $used + $length;
                if ($newDistance + round($dStart[$next] * 10) > $target) continue;

                $newSteps = $steps + 1;
                $newCountry = $country + ($edge['country'] ? $length : 0);
                $existing = $states[$newDistance][$next] ?? null;
                if ($existing !== null) {
                    $comparison = $minimum ? $newSteps <=> $existing[0] : 0;
                    if ($comparison === 0) {
                        $comparison = ($newCountry * $countrySign) <=> ($existing[1] * $countrySign);
                    }
                    if ($comparison > 0 || ($comparison === 0 && ($minimum || mt_rand(0, 1) === 0))) continue;
                } elseif (++$stateCount > 100000) {
                    throw new RuntimeException('Маршрут слишком сложен для подбора при этом километраже');
                }

                if (!isset($states[$newDistance])) {
                    $levels->insert($newDistance, -$newDistance);
                }
                $states[$newDistance][$next] = [$newSteps, $newCountry, $used, $node];
            }
        }
    }

    if ($target <= 0 || !isset($states[$target][$startId])) return null;
    $country = $states[$target][$startId][1];
    $path = [$startId];
    $node = $startId;
    for ($used = $target; $used > 0;) {
        [, , $used, $node] = $states[$used][$node];
        $path[] = $node;
    }
    return ['path' => array_reverse($path), 'countryDistance' => $country / 10];
}

function randomWalk(
    array $adj,
    int $startId,
    float $target,
    array $dStart
): ?array {
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

    $segs    = [];
    $cumDist = 0.0;

    for ($i = 0; $i < count($path) - 1; $i++) {
        $fid    = $path[$i];
        $tid    = $path[$i + 1];
        $d      = edgeDist($adj, $fid, $tid);
        $startM = $departMin + (int)round($cumDist / $speed * 60);
        $endM   = $departMin + (int)round(($cumDist + $d) / $speed * 60);
        $segs[] = [
            'from_id'    => $fid,
            'to_id'      => $tid,
            'start_time' => minsToTime($startM),
            'end_time'   => minsToTime($endM),
            'distance'   => $d,
        ];
        $cumDist += $d;
    }

    // Add 15 min stop to the segment containing refuel time, shift subsequent segments
    $shifted = false;
    for ($i = 0; $i < count($segs); $i++) {
        [$sh, $sm] = explode(':', $segs[$i]['start_time']);
        [$eh, $em] = explode(':', $segs[$i]['end_time']);
        $sMin = (int)$sh * 60 + (int)$sm;
        $eMin = (int)$eh * 60 + (int)$em;
        if (!$shifted && $sMin <= $refuelMin && $refuelMin < $eMin) {
            $segs[$i]['end_time'] = minsToTime($eMin + 15);
            $shifted = true;
        } elseif ($shifted) {
            $segs[$i]['start_time'] = minsToTime($sMin + 15);
            $segs[$i]['end_time']   = minsToTime($eMin + 15);
        }
    }

    return $segs;
}

function minsToTime(int $m): string {
    $m = (($m % 1440) + 1440) % 1440;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
