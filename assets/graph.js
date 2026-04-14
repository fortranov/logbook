/* ══════════ GRAPH PAGE ══════════
   Canvas-based interactive location graph
   Supports drag, zoom (wheel), add/edit/delete nodes and edges
═══════════════════════════════════════════════════════════════ */

(function () {

  /* ── State ── */
  let locs = [];   // [{id, name, type, x, y, is_start}]
  let edges = [];  // [{id, loc_a, loc_b, distance, loc_a_name, loc_b_name}]

  // Viewport transform
  let vx = 0, vy = 0, vscale = 1;

  // Drag state
  let dragging = null;  // {id, ox, oy}
  let panning  = null;  // {sx, sy, vx0, vy0}

  // Selection
  let selNode = null;  // location id
  let selEdge = null;  // edge id

  const canvas = document.getElementById('graph-canvas');
  const ctx    = canvas.getContext('2d');

  /* ── Resize canvas to fill wrapper ── */
  function resizeCanvas() {
    const wrap = canvas.parentElement;
    canvas.width  = wrap.clientWidth;
    canvas.height = wrap.clientHeight - 28; // minus hint bar
    draw();
  }

  /* ── Coordinate transform helpers ── */
  function worldToScreen(wx, wy) {
    return [(wx - vx) * vscale + canvas.width / 2,
            (wy - vy) * vscale + canvas.height / 2];
  }
  function screenToWorld(sx, sy) {
    return [(sx - canvas.width / 2)  / vscale + vx,
            (sy - canvas.height / 2) / vscale + vy];
  }

  /* ── Draw ── */
  const NODE_R = 18;
  const COLORS = { city: '#1565c0', countryside: '#2e7d32', start: '#b71c1c' };

  function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Grid
    ctx.strokeStyle = '#e8edf2';
    ctx.lineWidth = 1;
    const step = 50 * vscale;
    const offX = ((-vx * vscale) + canvas.width / 2) % step;
    const offY = ((-vy * vscale) + canvas.height / 2) % step;
    for (let x = offX; x < canvas.width; x += step) { ctx.beginPath(); ctx.moveTo(x,0); ctx.lineTo(x,canvas.height); ctx.stroke(); }
    for (let y = offY; y < canvas.height; y += step) { ctx.beginPath(); ctx.moveTo(0,y); ctx.lineTo(canvas.width,y); ctx.stroke(); }

    // Edges
    for (const e of edges) {
      const la = locs.find(l => l.id === e.loc_a);
      const lb = locs.find(l => l.id === e.loc_b);
      if (!la || !lb) continue;
      const [ax, ay] = worldToScreen(la.x, la.y);
      const [bx, by] = worldToScreen(lb.x, lb.y);
      const sel = e.id === selEdge;
      ctx.strokeStyle = sel ? '#e65100' : '#78909c';
      ctx.lineWidth   = sel ? 2.5 : 1.8;
      ctx.setLineDash(sel ? [6,3] : []);
      ctx.beginPath(); ctx.moveTo(ax, ay); ctx.lineTo(bx, by); ctx.stroke();
      ctx.setLineDash([]);
      // Distance label
      const mx = (ax+bx)/2, my = (ay+by)/2;
      const lbl = parseFloat(e.distance).toFixed(1) + ' км';
      ctx.font = `${Math.round(11*vscale)}px system-ui,sans-serif`;
      ctx.fillStyle = '#fff';
      const tw = ctx.measureText(lbl).width;
      ctx.fillRect(mx - tw/2 - 3, my - 8*vscale, tw + 6, 14*vscale);
      ctx.fillStyle = sel ? '#e65100' : '#455a64';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(lbl, mx, my);
    }

    // Nodes
    for (const l of locs) {
      const [sx, sy] = worldToScreen(l.x, l.y);
      const r   = NODE_R * vscale;
      const sel = l.id === selNode;
      const col = parseInt(l.is_start) ? COLORS.start
                : l.type === 'countryside' ? COLORS.countryside
                : COLORS.city;

      // Shadow
      ctx.shadowColor = sel ? '#ff9800' : 'rgba(0,0,0,.18)';
      ctx.shadowBlur  = sel ? 10 : 5;

      ctx.beginPath();
      ctx.arc(sx, sy, r, 0, 2 * Math.PI);
      ctx.fillStyle = col;
      ctx.fill();
      if (sel) { ctx.strokeStyle = '#ff9800'; ctx.lineWidth = 3; ctx.stroke(); }

      ctx.shadowBlur = 0;

      // Label
      const fontSize = Math.max(9, Math.round(12 * vscale));
      ctx.font = `600 ${fontSize}px system-ui,sans-serif`;
      ctx.fillStyle = '#fff';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      // If name fits in circle, put inside; otherwise below
      const maxW = r * 1.7;
      const tw   = ctx.measureText(l.name).width;
      if (tw <= maxW) {
        ctx.fillText(l.name, sx, sy);
      } else {
        const small = Math.max(8, Math.round(10 * vscale));
        ctx.font = `600 ${small}px system-ui,sans-serif`;
        ctx.fillText(l.name, sx, sy);
      }
    }
  }

  /* ── Hit testing ── */
  function hitNode(wx, wy) {
    for (const l of locs) {
      const dx = l.x - wx, dy = l.y - wy;
      const r  = NODE_R / vscale;
      if (Math.sqrt(dx*dx + dy*dy) <= r + 2) return l.id;
    }
    return null;
  }

  function hitEdge(wx, wy) {
    for (const e of edges) {
      const la = locs.find(l => l.id === e.loc_a);
      const lb = locs.find(l => l.id === e.loc_b);
      if (!la || !lb) continue;
      const d = pointToSegDist(wx, wy, la.x, la.y, lb.x, lb.y);
      if (d < 8 / vscale) return e.id;
    }
    return null;
  }

  function pointToSegDist(px, py, ax, ay, bx, by) {
    const dx = bx-ax, dy = by-ay;
    const len2 = dx*dx + dy*dy;
    if (!len2) return Math.hypot(px-ax, py-ay);
    const t = Math.max(0, Math.min(1, ((px-ax)*dx + (py-ay)*dy) / len2));
    return Math.hypot(px - (ax+t*dx), py - (ay+t*dy));
  }

  /* ── Mouse events ── */
  canvas.addEventListener('mousedown', e => {
    if (e.button !== 0) return;
    const [wx, wy] = screenToWorld(e.offsetX, e.offsetY);
    const nid = hitNode(wx, wy);
    if (nid !== null) {
      dragging = { id: nid, ox: wx - locs.find(l=>l.id===nid).x, oy: wy - locs.find(l=>l.id===nid).y };
      selNode = nid; selEdge = null;
      showEditNode(nid);
      draw();
      return;
    }
    const eid = hitEdge(wx, wy);
    if (eid !== null) {
      selEdge = eid; selNode = null;
      showEditEdge(eid);
      draw();
      return;
    }
    // Start pan
    selNode = null; selEdge = null;
    hideEdit();
    panning = { sx: e.clientX, sy: e.clientY, vx0: vx, vy0: vy };
    draw();
  });

  canvas.addEventListener('mousemove', e => {
    if (dragging) {
      const [wx, wy] = screenToWorld(e.offsetX, e.offsetY);
      const loc = locs.find(l => l.id === dragging.id);
      if (!loc) return;
      loc.x = wx - dragging.ox;
      loc.y = wy - dragging.oy;
      draw();
    } else if (panning) {
      const dx = (e.clientX - panning.sx) / vscale;
      const dy = (e.clientY - panning.sy) / vscale;
      vx = panning.vx0 - dx;
      vy = panning.vy0 - dy;
      draw();
    } else {
      // Change cursor on hover
      const [wx, wy] = screenToWorld(e.offsetX, e.offsetY);
      canvas.style.cursor = hitNode(wx, wy) !== null ? 'grab' : 'default';
    }
  });

  canvas.addEventListener('mouseup', async e => {
    if (dragging) {
      const loc = locs.find(l => l.id === dragging.id);
      if (loc) {
        // Save position to server
        await apiCall('update_location_pos', { id: loc.id, x: Math.round(loc.x), y: Math.round(loc.y) });
      }
      dragging = null;
    }
    panning = null;
  });

  canvas.addEventListener('mouseleave', async () => {
    if (dragging) {
      const loc = locs.find(l => l.id === dragging.id);
      if (loc) await apiCall('update_location_pos', { id: loc.id, x: Math.round(loc.x), y: Math.round(loc.y) });
      dragging = null;
    }
    panning = null;
  });

  canvas.addEventListener('wheel', e => {
    e.preventDefault();
    const factor = e.deltaY < 0 ? 1.12 : 0.89;
    const [wx, wy] = screenToWorld(e.offsetX, e.offsetY);
    vscale *= factor;
    vscale  = Math.max(0.2, Math.min(5, vscale));
    // Keep mouse world point fixed
    const [sx2, sy2] = worldToScreen(wx, wy);
    vx += (sx2 - e.offsetX) / vscale;
    vy += (sy2 - e.offsetY) / vscale;
    draw();
  }, { passive: false });

  /* ── Sidebar helpers ── */

  function gid(id) { return document.getElementById(id); }

  function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function graphMsg(msg, isErr = true) {
    const el = gid('graph-msg');
    el.style.color = isErr ? '#c0392b' : '#2e7d32';
    el.textContent = msg;
    if (!isErr) setTimeout(() => { if (el.textContent === msg) el.textContent = ''; }, 3000);
  }

  function showEditNode(id) {
    const loc = locs.find(l => l.id === id);
    if (!loc) return;
    const sec = gid('edit-section');
    sec.style.display = '';
    gid('edit-title').textContent = 'Редактировать точку';
    gid('edit-fields').innerHTML = `
      <label>Название<input type="text" id="edit-name" value="${escHtml(loc.name)}"></label>
      <label>Тип
        <select id="edit-type">
          <option value="city" ${loc.type==='city'?'selected':''}>Город</option>
          <option value="countryside" ${loc.type==='countryside'?'selected':''}>За городом</option>
        </select>
      </label>
      <div style="display:flex;gap:8px;margin-top:6px">
        <button id="edit-save-btn">Сохранить</button>
        ${!parseInt(loc.is_start) ? '<button class="danger" id="edit-del-btn">Удалить</button>' : ''}
      </div>`;
    gid('edit-save-btn').onclick = () => saveEditNode(id);
    const delBtn = gid('edit-del-btn');
    if (delBtn) delBtn.onclick = () => deleteNode(id);
  }

  function showEditEdge(id) {
    const edge = edges.find(e => e.id === id);
    if (!edge) return;
    const sec = gid('edit-section');
    sec.style.display = '';
    gid('edit-title').textContent = 'Редактировать ребро';
    gid('edit-fields').innerHTML = `
      <p style="font-size:12px;margin-bottom:8px;color:#555">
        ${escHtml(edge.loc_a_name)} ↔ ${escHtml(edge.loc_b_name)}
      </p>
      <label>Расстояние (км)<input type="number" id="edit-dist" min="0.1" step="0.1" value="${edge.distance}"></label>
      <div style="display:flex;gap:8px;margin-top:6px">
        <button id="edit-save-btn">Сохранить</button>
        <button class="danger" id="edit-del-btn">Удалить</button>
      </div>`;
    gid('edit-save-btn').onclick = () => saveEditEdge(id);
    gid('edit-del-btn').onclick  = () => deleteEdge(id);
  }

  function hideEdit() {
    gid('edit-section').style.display = 'none';
    gid('edit-fields').innerHTML = '';
  }

  /* ── API wrapper (graph page uses plain fetch to avoid importing app.js deps) ── */
  const spinner = document.getElementById('spinner');
  async function apiCall(action, body = null, method = 'POST') {
    spinner.hidden = false;
    try {
      let url = `api.php?action=${action}`;
      const opts = {};
      if (body && method === 'POST') {
        opts.method = 'POST';
        const fd = new FormData();
        for (const [k, v] of Object.entries(body)) fd.append(k, v);
        opts.body = fd;
      } else if (body && method === 'GET') {
        url += '&' + new URLSearchParams(body).toString();
      }
      const res = await fetch(url, opts);
      const j   = await res.json();
      if (!j.ok) throw new Error(j.error || 'Ошибка');
      return j.data;
    } finally {
      spinner.hidden = true;
    }
  }

  /* ── Load & refresh ── */
  async function reload() {
    const d = await apiCall('get_locations', null, 'GET');
    locs  = d.locations.map(l => ({ ...l, id: parseInt(l.id), x: parseFloat(l.x), y: parseFloat(l.y), is_start: parseInt(l.is_start) }));
    edges = d.edges.map(e  => ({ ...e, id: parseInt(e.id), loc_a: parseInt(e.loc_a), loc_b: parseInt(e.loc_b), distance: parseFloat(e.distance) }));
    populateSelects();
    renderLists();
    draw();
  }

  function populateSelects() {
    const opts = locs.map(l => `<option value="${l.id}">${escHtml(l.name)}</option>`).join('');
    const noOpt = '<option value="">— нет —</option>';
    gid('loc-from').innerHTML = noOpt + opts;
    gid('edge-a').innerHTML = opts;
    gid('edge-b').innerHTML = opts;
  }

  function renderLists() {
    gid('loc-list').innerHTML = locs.map(l => `
      <div class="item-row">
        <span title="${escHtml(l.name)}">${escHtml(l.name)} <small style="color:${l.type==='countryside'?'#2e7d32':'#1565c0'}">(${l.type==='countryside'?'за г.':'г.'})</small>${parseInt(l.is_start)?'<small> ★</small>':''}</span>
        <button class="secondary" onclick="graphEditNode(${l.id})">✏</button>
        ${!l.is_start ? `<button class="danger" onclick="graphDelNode(${l.id})">✕</button>` : ''}
      </div>`).join('') || '<p style="color:#999;font-size:12px">Нет точек</p>';

    gid('edge-list').innerHTML = edges.map(e => `
      <div class="item-row">
        <span title="${escHtml(e.loc_a_name)} ↔ ${escHtml(e.loc_b_name)}">${escHtml(e.loc_a_name)} ↔ ${escHtml(e.loc_b_name)}: ${parseFloat(e.distance).toFixed(1)} км</span>
        <button class="secondary" onclick="graphEditEdge(${e.id})">✏</button>
        <button class="danger" onclick="graphDelEdge(${e.id})">✕</button>
      </div>`).join('') || '<p style="color:#999;font-size:12px">Нет рёбер</p>';
  }

  /* ── Add location ── */
  gid('btn-add-loc').addEventListener('click', async () => {
    const name   = gid('loc-name').value.trim();
    const type   = gid('loc-type').value;
    const fromId = gid('loc-from').value;
    const dist   = gid('loc-dist').value;

    if (!name) { graphMsg('Введите название'); return; }
    if (fromId && !dist) { graphMsg('Введите расстояние'); return; }

    // Compute a position near the parent node
    let nx = 400, ny = 300;
    if (fromId) {
      const parent = locs.find(l => l.id === parseInt(fromId));
      if (parent) {
        const angle = Math.random() * 2 * Math.PI;
        nx = parent.x + Math.cos(angle) * 120;
        ny = parent.y + Math.sin(angle) * 120;
      }
    } else if (locs.length) {
      nx = locs[0].x + Math.random() * 200 - 100;
      ny = locs[0].y + Math.random() * 200 - 100;
    }

    try {
      await apiCall('add_location', { name, type, from_id: fromId, distance: dist, x: Math.round(nx), y: Math.round(ny) });
      gid('loc-name').value = '';
      gid('loc-dist').value = '';
      graphMsg('Точка добавлена', false);
      await reload();
    } catch(e) { graphMsg(e.message); }
  });

  /* ── Add edge ── */
  gid('btn-add-edge').addEventListener('click', async () => {
    const a    = gid('edge-a').value;
    const b    = gid('edge-b').value;
    const dist = gid('edge-dist').value;
    if (!a || !b) { graphMsg('Выберите обе точки'); return; }
    if (!dist)    { graphMsg('Введите расстояние'); return; }
    try {
      await apiCall('add_edge', { from_id: a, to_id: b, distance: dist });
      gid('edge-dist').value = '';
      graphMsg('Ребро добавлено', false);
      await reload();
    } catch(e) { graphMsg(e.message); }
  });

  /* ── Edit/delete node ── */
  async function saveEditNode(id) {
    const name = gid('edit-name')?.value.trim();
    const type = gid('edit-type')?.value;
    if (!name) { graphMsg('Введите название'); return; }
    try {
      await apiCall('update_location', { id, name, type });
      hideEdit(); selNode = null;
      graphMsg('Сохранено', false);
      await reload();
    } catch(e) { graphMsg(e.message); }
  }

  async function deleteNode(id) {
    if (!confirm('Удалить точку и все связанные рёбра?')) return;
    try {
      await apiCall('delete_location', { id });
      hideEdit(); selNode = null;
      await reload();
    } catch(e) { graphMsg(e.message); }
  }

  async function saveEditEdge(id) {
    const dist = gid('edit-dist')?.value;
    if (!dist) { graphMsg('Введите расстояние'); return; }
    try {
      await apiCall('update_edge', { id, distance: dist });
      hideEdit(); selEdge = null;
      graphMsg('Сохранено', false);
      await reload();
    } catch(e) { graphMsg(e.message); }
  }

  async function deleteEdge(id) {
    if (!confirm('Удалить ребро?')) return;
    try {
      await apiCall('delete_edge', { id });
      hideEdit(); selEdge = null;
      await reload();
    } catch(e) { graphMsg(e.message); }
  }

  /* ── Globals for inline onclick ── */
  window.graphEditNode = id => { selNode = id; selEdge = null; showEditNode(id); draw(); };
  window.graphDelNode  = id => deleteNode(id);
  window.graphEditEdge = id => { selEdge = id; selNode = null; showEditEdge(id); draw(); };
  window.graphDelEdge  = id => deleteEdge(id);

  /* ── Init ── */
  window.addEventListener('resize', resizeCanvas);
  resizeCanvas();
  reload();

})();
