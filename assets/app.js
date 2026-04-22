/* ── Utility ── */

const API = 'api.php';

function $(id) { return document.getElementById(id); }

function spinner(on) {
  $('spinner').hidden = !on;
}

async function api(action, body = null, method = 'GET') {
  spinner(true);
  try {
    let url = `${API}?action=${action}`;
    const opts = { headers: {} };

    if (body && method === 'POST') {
      opts.method = 'POST';
      const fd = new FormData();
      for (const [k, v] of Object.entries(body)) fd.append(k, v);
      opts.body = fd;
    } else if (body && method === 'GET') {
      const p = new URLSearchParams(body);
      url += '&' + p.toString();
    }

    const res = await fetch(url, opts);
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Ошибка сервера');
    return json.data;
  } finally {
    spinner(false);
  }
}

function fmt(v, dec = 1) {
  if (v === null || v === undefined || v === '') return '<span class="dash">—</span>';
  return parseFloat(v).toFixed(dec);
}

function fmtFuel(v) {
  if (v === null || v === undefined || v === '') return '<span class="dash">—</span>';
  const n = parseFloat(v);
  if (isNaN(n)) return '<span class="dash">—</span>';
  return Math.trunc(n).toString();
}

function fmtDate(d) {
  if (!d) return '—';
  // d is YYYY-MM-DD
  const [y, m, day] = d.split('-');
  return `${day}.${m}.${y}`;
}

/* ── Modal helpers ── */

function openModal(id) { $(id).hidden = false; }
function closeModal(id) { $(id).hidden = true; }

document.addEventListener('click', e => {
  const t = e.target.closest('[data-close]');
  if (t) closeModal(t.dataset.close);
  if (e.target.classList.contains('modal-overlay')) closeModal(e.target.id);
});

/* ══════════ LOGBOOK PAGE ══════════ */

function initLogbook() {
  loadLogbook();
  $('btn-checkpoint').addEventListener('click', () => openModal('modal-cp'));
  $('btn-waybill').addEventListener('click', () => openModal('modal-wb'));
  $('cp-save').addEventListener('click', saveCheckpoint);
  $('wb-save').addEventListener('click', saveWaybill);
}

async function loadLogbook() {
  const rows = await api('get_logbook');
  const tbody = $('logbook-body');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#999;padding:24px">Записей нет</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const wbCell = r.waybill_id
      ? `<a href="?page=waybill&id=${r.waybill_id}">${escHtml(r.waybill_number || r.waybill_id)}</a>`
      : '<span class="dash">—</span>';
    return `<tr>
      <td>${fmtDate(r.entry_date)}</td>
      <td>${fmt(r.odometer)}</td>
      <td>${fmt(r.daily_mileage)}</td>
      <td>${fmt(r.since_to2)}</td>
      <td>${r.fuel_spent != null ? fmtFuel(r.fuel_spent) : '<span class="dash">—</span>'}</td>
      <td>${r.fuel_refueled != null ? fmtFuel(r.fuel_refueled) : '<span class="dash">—</span>'}</td>
      <td>${fmtFuel(r.fuel_remaining)}</td>
      <td>${wbCell}</td>
    </tr>`;
  }).join('');
}

async function saveCheckpoint() {
  try {
    await api('add_checkpoint', {
      odometer:       $('cp-odometer').value,
      since_to2:      $('cp-to2').value,
      fuel_remaining: $('cp-fuel').value,
    }, 'POST');
    closeModal('modal-cp');
    ['cp-odometer','cp-to2','cp-fuel'].forEach(id => $(id).value = '');
    await loadLogbook();
  } catch(e) { alert(e.message); }
}

async function saveWaybill() {
  const errEl = $('wb-error');
  errEl.textContent = '';
  try {
    const d = await api('create_waybill', {
      number:        $('wb-number').value,
      fuel_refueled: $('wb-fuel').value,
      refuel_time:   $('wb-time').value,
    }, 'POST');
    closeModal('modal-wb');
    ['wb-number','wb-fuel'].forEach(id => $(id).value = '');
    $('wb-time').value = '12:00';
    await loadLogbook();
    // Navigate to new waybill
    window.location.href = `?page=waybill&id=${d.waybill_id}`;
  } catch(e) { errEl.textContent = e.message; }
}

/* ══════════ WAYBILL DETAIL PAGE ══════════ */

async function initWaybill() {
  const wid = parseInt(document.getElementById('app').dataset.waybillId || '0');
  if (!wid) { $('waybill-detail').innerHTML = '<p class="error-msg">Путевой лист не найден</p>'; return; }

  await loadWaybill(wid);
}

async function loadWaybill(wid) {
  const d = await api('get_waybill', { id: wid });
  const w = d.waybill;
  const segs = d.segments;

  const totalDist = segs.reduce((s, r) => s + parseFloat(r.distance), 0);
  const startTime = segs.length ? segs[0].start_time : '—';
  const endTime   = segs.length ? segs[segs.length-1].end_time : '—';

  const segRows = segs.map((s, i) => `
    <tr>
      <td>${i+1}</td>
      <td>${escHtml(s.from_name)} <span class="${s.from_type==='countryside'?'tag-countryside':'tag-city'}">(${typeLabel(s.from_type)})</span></td>
      <td>${escHtml(s.to_name)} <span class="${s.to_type==='countryside'?'tag-countryside':'tag-city'}">(${typeLabel(s.to_type)})</span></td>
      <td>${s.start_time}</td>
      <td>${s.end_time}</td>
      <td>${parseFloat(s.distance).toFixed(1)}</td>
    </tr>`).join('');

  $('waybill-detail').innerHTML = `
    <a class="back-link" href="?page=logbook">&#8592; Бортовой журнал</a>
    <h1>Путевой лист № ${escHtml(w.number)}</h1>
    <p class="wb-meta">Дата: ${fmtDate(w.date)} &nbsp;|&nbsp; Время заправки: ${w.refuel_time}</p>

    <div class="wb-section">
      <h2>Пробег</h2>
      <div class="table-wrap">
      <table>
        <thead><tr><th>Одометр до (км)</th><th>Одометр после (км)</th><th>Пробег за сутки (км)</th></tr></thead>
        <tbody><tr>
          <td>${fmt(w.odometer_before)}</td>
          <td>${fmt(w.odometer_after)}</td>
          <td>${fmt(w.daily_mileage)}</td>
        </tr></tbody>
      </table>
      </div>
    </div>

    <div class="wb-section">
      <h2>Бензин</h2>
      <div class="table-wrap">
      <table>
        <thead><tr><th>Остаток до (л)</th><th>Заправлено (л)</th><th>Израсходовано (л)</th><th>Остаток после (л)</th></tr></thead>
        <tbody><tr>
          <td>${fmtFuel(w.fuel_before)}</td>
          <td>${fmtFuel(w.fuel_refueled)}</td>
          <td>${fmtFuel(w.fuel_spent)}</td>
          <td>${fmtFuel(w.fuel_after)}</td>
        </tr></tbody>
      </table>
      </div>
    </div>

    <div class="wb-section">
      <h2>Маршрут &nbsp;<small style="font-weight:400;color:#666">Итого: ${totalDist.toFixed(1)} км, ${startTime} – ${endTime}</small></h2>
      <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Откуда</th><th>Куда</th><th>Выезд</th><th>Прибытие</th><th>Км</th></tr></thead>
        <tbody>${segRows || '<tr><td colspan="6" style="text-align:center;color:#999">—</td></tr>'}</tbody>
      </table>
      </div>
    </div>

    <div class="wb-actions">
      <button id="btn-regen">Перегенерировать маршрут</button>
    </div>
    <p id="regen-msg" class="error-msg"></p>
  `;

  $('btn-regen').addEventListener('click', () => regenRoute(wid));
}

async function regenRoute(wid) {
  const msgEl = $('regen-msg');
  msgEl.textContent = '';
  try {
    await api('regen_route', { id: wid }, 'POST');
    await loadWaybill(wid);
  } catch(e) { msgEl.textContent = e.message; }
}

/* ══════════ SETTINGS PAGE ══════════ */

async function initSettings() {
  const s = await api('get_settings');
  $('s-summer').value = s.fuel_summer;
  $('s-winter').value = s.fuel_winter;
  $('s-coeff').value  = s.countryside_coeff;
  $('s-season').value = s.season;

  $('s-save').addEventListener('click', async () => {
    try {
      await api('save_settings', {
        fuel_summer:      $('s-summer').value,
        fuel_winter:      $('s-winter').value,
        countryside_coeff: $('s-coeff').value,
        season:           $('s-season').value,
      }, 'POST');
      $('s-msg').textContent = 'Настройки сохранены ✓';
      setTimeout(() => { $('s-msg').textContent = ''; }, 3000);
    } catch(e) { $('s-msg').textContent = e.message; $('s-msg').style.color='#c0392b'; }
  });
}

/* ── Helpers ── */

function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function typeLabel(t) { return t === 'countryside' ? 'за городом' : 'город'; }

/* ── Boot ── */

(function boot() {
  const page = document.getElementById('app')?.dataset?.page;
  if (page === 'logbook')  initLogbook();
  if (page === 'waybill')  initWaybill();
  if (page === 'settings') initSettings();
})();
