<?php
declare(strict_types=1);
require_once __DIR__ . '/vehicles.php';

header('Cache-Control: no-store');
$cars = listVehicles(vehicleCatalog());
$requestedId = $_GET['vehicle'] ?? null;
$selectedId = is_string($requestedId) && isset($cars[$requestedId])
    ? $requestedId
    : array_key_first($cars);
if ($requestedId !== null && $requestedId !== $selectedId) {
    header('Location: ?page=logbook');
    exit;
}
$plate = $cars[$selectedId]['plate'] ?? null;
$vehicleQuery = $selectedId === null ? '' : '&amp;vehicle=' . $selectedId;

$page      = $_GET['page']      ?? 'logbook';
$waybillId = (int)($_GET['id'] ?? 0);
$allowed   = ['logbook', 'waybill', 'graph', 'settings'];
if (!in_array($page, $allowed, true)) $page = 'logbook';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Бортовой журнал</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header>
  <nav class="top-nav">
    <a href="?page=logbook<?= $vehicleQuery ?>"   class="<?= $page==='logbook'  ?'active':'' ?>">Бортовой журнал</a>
    <a href="?page=graph<?= $vehicleQuery ?>"     class="<?= $page==='graph'    ?'active':'' ?>">Граф локаций</a>
    <a href="?page=settings<?= $vehicleQuery ?>"  class="<?= $page==='settings' ?'active':'' ?>">Настройки</a>
    <details class="vehicle-menu">
      <summary aria-label="Выбор автомобиля">
        <?php if ($plate !== null): ?>
          <?php if (preg_match('/^([АВЕКМНОРСТУХ][0-9]{3}[АВЕКМНОРСТУХ]{2})([0-9]{2,3})$/uD', $plate, $parts)): ?>
            <span class="vehicle-plate">
              <span><?= htmlspecialchars($parts[1]) ?></span>
              <span class="plate-region"><?= $parts[2] ?><small>RUS <i aria-hidden="true"></i></small></span>
            </span>
          <?php else: ?>
            <span class="vehicle-plate"><?= htmlspecialchars($plate) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span><?= $selectedId === null ? 'Автомобиль' : 'Без госномера' ?></span>
        <?php endif; ?>
        <span class="vehicle-chevron" aria-hidden="true"></span>
      </summary>
      <div class="vehicle-options">
        <?php foreach ($cars as $car): ?>
          <?php $label = htmlspecialchars($car['plate'] ?? 'Журнал без госномера'); ?>
          <div class="vehicle-option">
            <a href="?page=<?= $page === 'waybill' ? 'logbook' : $page ?>&amp;vehicle=<?= $car['id'] ?>"
               <?= $car['id'] === $selectedId ? 'aria-current="true"' : '' ?>><?= $label ?></a>
            <?php if ($car['plate'] === null): ?>
              <button type="button" data-vehicle-form="assign_legacy_plate">Указать номер</button>
            <?php endif; ?>
            <button type="button" class="vehicle-delete" data-delete-vehicle="<?= $car['id'] ?>"
                    data-vehicle-label="<?= $label ?>" aria-label="Удалить: <?= $label ?>" title="Удалить">×</button>
          </div>
        <?php endforeach; ?>
        <button type="button" data-vehicle-form="add_vehicle">+ Добавить автомобиль</button>
      </div>
    </details>
  </nav>
</header>

<main id="app" data-page="<?= $selectedId === null ? '' : htmlspecialchars($page) ?>" data-vehicle="<?= $selectedId ?? '' ?>"
      <?= $waybillId ? 'data-waybill-id="'.(int)$waybillId.'"' : '' ?>>

<?php if ($selectedId === null): ?>
<div class="page-toolbar">
  <h1>Бортовой журнал</h1>
  <button type="button" data-vehicle-form="add_vehicle">Добавить автомобиль</button>
</div>
<?php elseif ($page === 'logbook'): ?>
<!-- ══════════════ LOGBOOK ══════════════ -->
<div class="page-toolbar">
  <h1>Бортовой журнал</h1>
  <div>
    <button id="btn-checkpoint">Добавить точку отсчёта</button>
    <button id="btn-waybill">Заполнить путевой</button>
  </div>
</div>

<div class="table-wrap">
<table id="logbook-table">
  <thead>
    <tr>
      <th rowspan="2">Число</th>
      <th rowspan="2">Одометр, км</th>
      <th colspan="2" style="text-align:center">Пробег</th>
      <th colspan="3" style="text-align:center">Бензин</th>
      <th rowspan="2">Путевой лист</th>
    </tr>
    <tr>
      <th>за сутки</th>
      <th>от ТО-2</th>
      <th>расход</th>
      <th>заправлено</th>
      <th>остаток</th>
    </tr>
  </thead>
  <tbody id="logbook-body"></tbody>
</table>
</div>

<!-- modal: checkpoint -->
<div id="modal-cp" class="modal-overlay" hidden>
  <div class="modal-box">
    <h2>Добавить точку отсчёта</h2>
    <label>Одометр (км)<input type="number" id="cp-odometer" min="0" step="0.1"></label>
    <label>От ТО‑2 (км)<input type="number" id="cp-to2"      min="0" step="0.1"></label>
    <label>Остаток бензина (л)<input type="number" id="cp-fuel" min="0" step="0.1"></label>
    <div class="modal-footer">
      <button id="cp-save">Сохранить</button>
      <button class="btn-cancel" data-close="modal-cp">Отмена</button>
    </div>
  </div>
</div>

<!-- modal: waybill -->
<div id="modal-wb" class="modal-overlay" hidden>
  <div class="modal-box">
    <h2>Создать путевой лист</h2>
    <label>Дата<input type="date" id="wb-date"></label>
    <label>Номер путевого листа<input type="text"   id="wb-number"></label>
    <label>Заправлено бензина (л)<input type="number" id="wb-fuel" min="0.1" step="0.1"></label>
    <label>Время заправки<input type="time" id="wb-time" value="12:00"></label>
    <div class="route-preferences">
      <fieldset class="route-switch">
        <legend>Количество отрезков</legend>
        <div>
          <label><input type="radio" name="wb-segments" value="minimum"><span>Минимум</span></label>
          <label><input type="radio" name="wb-segments" value="average" checked><span>Среднее</span></label>
        </div>
      </fieldset>
      <fieldset class="route-switch">
        <legend>Топливо</legend>
        <div>
          <label><input type="radio" name="wb-fuel-mode" value="economy" checked><span>Экономия</span></label>
          <label><input type="radio" name="wb-fuel-mode" value="consumption"><span>Расход</span></label>
        </div>
      </fieldset>
    </div>
    <p id="wb-error" class="error-msg"></p>
    <div class="modal-footer">
      <button id="wb-save">Создать</button>
      <button class="btn-cancel" data-close="modal-wb">Отмена</button>
    </div>
  </div>
</div>

<?php elseif ($page === 'waybill'): ?>
<!-- ══════════════ WAYBILL DETAIL ══════════════ -->
<div id="waybill-detail">
  <p>Загрузка…</p>
</div>

<?php elseif ($page === 'graph'): ?>
<!-- ══════════════ GRAPH ══════════════ -->
<div class="graph-layout">
  <div class="graph-canvas-wrap">
    <canvas id="graph-canvas"></canvas>
    <div class="canvas-hint">Перетаскивайте точки мышью · Колёсико — масштаб</div>
  </div>
  <aside class="graph-sidebar">

    <section>
      <h3>Добавить точку</h3>
      <label>Название<input type="text" id="loc-name" placeholder="Название"></label>
      <label>Тип
        <select id="loc-type">
          <option value="city">Город</option>
          <option value="countryside">За городом</option>
        </select>
      </label>
      <label>Соединить с
        <select id="loc-from"><option value="">— нет —</option></select>
      </label>
      <label>Расстояние (км)<input type="number" id="loc-dist" min="0.1" step="0.1"></label>
      <button id="btn-add-loc">Добавить точку</button>
    </section>

    <section>
      <h3>Соединить точки</h3>
      <label>Откуда<select id="edge-a"></select></label>
      <label>Куда<select id="edge-b"></select></label>
      <label>Расстояние (км)<input type="number" id="edge-dist" min="0.1" step="0.1"></label>
      <button id="btn-add-edge">Соединить</button>
    </section>

    <section id="edit-section" style="display:none">
      <h3 id="edit-title">Редактировать</h3>
      <div id="edit-fields"></div>
    </section>

    <section>
      <h3>Точки</h3>
      <div id="loc-list" class="item-list"></div>
    </section>

    <section>
      <h3>Рёбра</h3>
      <div id="edge-list" class="item-list"></div>
    </section>

    <p id="graph-msg" class="error-msg"></p>
  </aside>
</div>

<?php elseif ($page === 'settings'): ?>
<!-- ══════════════ SETTINGS ══════════════ -->
<div class="settings-wrap">
  <h1>Настройки</h1>
  <label>Расход бензина летом (л/100 км)
    <input type="number" id="s-summer" min="1" step="0.1">
  </label>
  <label>Расход бензина зимой (л/100 км)
    <input type="number" id="s-winter" min="1" step="0.1">
  </label>
  <label>Коэффициент за городом
    <input type="number" id="s-coeff" min="1.0" step="0.01">
  </label>
  <label>Текущий сезон
    <select id="s-season">
      <option value="summer">Лето</option>
      <option value="winter">Зима</option>
    </select>
  </label>
  <button id="s-save">Сохранить</button>
  <p id="s-msg"></p>
</div>
<?php endif; ?>

</main>

<dialog id="vehicle-dialog" class="vehicle-dialog">
  <form id="vehicle-form">
    <h2 id="vehicle-dialog-title">Добавить автомобиль</h2>
    <label for="vehicle-plate-input">Госномер</label>
    <input id="vehicle-plate-input" type="text" maxlength="24" placeholder="А123ВС 77" required>
    <p id="vehicle-error" role="alert"></p>
    <div class="modal-footer">
      <button type="submit">Сохранить</button>
      <button type="button" class="secondary" id="vehicle-cancel">Отмена</button>
    </div>
  </form>
</dialog>

<div id="spinner" class="spinner" hidden></div>

<script src="assets/app.js"></script>
<?php if ($selectedId !== null && $page === 'graph'): ?>
<script src="assets/graph.js"></script>
<?php endif; ?>
</body>
</html>
