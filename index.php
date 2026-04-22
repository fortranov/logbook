<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
getDB(); // initialise database on first run

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
    <a href="?page=logbook"   class="<?= $page==='logbook'  ?'active':'' ?>">Бортовой журнал</a>
    <a href="?page=graph"     class="<?= $page==='graph'    ?'active':'' ?>">Граф локаций</a>
    <a href="?page=settings"  class="<?= $page==='settings' ?'active':'' ?>">Настройки</a>
  </nav>
</header>

<main id="app" data-page="<?= htmlspecialchars($page) ?>"
      <?= $waybillId ? 'data-waybill-id="'.(int)$waybillId.'"' : '' ?>>

<?php if ($page === 'logbook'): ?>
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
      <th rowspan="2">Дата</th>
      <th rowspan="2">Одометр, км</th>
      <th colspan="2">Пробег</th>
      <th rowspan="2">Остаток бензина, л</th>
      <th rowspan="2">Бензин за сутки, л</th>
      <th rowspan="2">Путевой лист</th>
    </tr>
    <tr>
      <th>за сутки</th>
      <th>от ТО-2</th>
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
    <label>Номер путевого листа<input type="text"   id="wb-number"></label>
    <label>Заправлено бензина (л)<input type="number" id="wb-fuel" min="0.1" step="0.1"></label>
    <label>Время заправки<input type="time" id="wb-time" value="12:00"></label>
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

<div id="spinner" class="spinner" hidden></div>

<script src="assets/app.js"></script>
<?php if ($page === 'graph'): ?>
<script src="assets/graph.js"></script>
<?php endif; ?>
</body>
</html>
