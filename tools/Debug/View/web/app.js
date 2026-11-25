'use strict';

// 纪律：这个文件只画，不算。
// 阈值来自 /api/brief 的 thresholds，几何来自 /api/boxes 的 rect，顺序来自数组顺序。
// 在这里出现任何一个字面量阈值、任何一个 sort()、任何一次父链累加，就成了第四份真相。

const state = {
  brief: null,
  frame: 0,
  boxes: [],
  paint: null,
  selected: 0,
  scale: 0,
  offset: { x: 0, y: 0 },
  view: { w: 0, h: 0 },
  lastFrame: -1,
  hintBase: '',
  hidden: 0,
};

const colorCache = new Map();

function colorOf(type) {
  const key = String(type || '?');
  let c = colorCache.get(key);
  if (c) return c;
  let h = 0;
  for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) % 360;
  c = `hsl(${h}, 62%, 62%)`;
  colorCache.set(key, c);
  return c;
}

async function get(path) {
  const res = await fetch(path, { cache: 'no-store' });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.message || `${res.status} ${path}`);
  return body;
}

async function post(path, payload) {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.message || `${res.status} ${path}`);
  return body;
}

function el(tag, cls, text) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = text;
  return node;
}

async function boot() {
  document.getElementById('refresh').onclick = () => refresh();
  document.addEventListener('keydown', (e) => {
    if (e.key === 'F5' || (e.key === 'r' && (e.ctrlKey || e.metaKey))) refresh();
  });
  window.addEventListener('resize', () => scheduleDraw());

  for (const btn of document.querySelectorAll('#ctl button')) {
    btn.onclick = () => act(btn.dataset.act, btn);
  }

  const cv = document.getElementById('canvas');
  cv.addEventListener('mousemove', (e) => hover(e));
  cv.addEventListener('mouseleave', () => restoreHint());
  cv.addEventListener('click', (e) => {
    const node = hitTest(e);
    if (node) select(node);
  });

  await Promise.all([loadScript(), loadBrief()]);

  // `?node=32&frame=1` 直接开到那个节点的卡片。发现里的地址能贴进聊天窗口，
  // 收的人点开就是同一帧同一个盒 —— 否则"你看 node:32"要靠对方自己找。
  const params = new URLSearchParams(location.search);
  await loadFrame(Number(params.get('frame') || 0));
  const node = Number(params.get('node') || 0);
  if (node) await select(node);

  document.title = `drift · 帧 #${state.frame} · ${state.boxes.length} 盒` + (state.selected ? ` · node:${state.selected}` : '');
  setInterval(poll, 1000);
}

async function loadScript() {
  try {
    const script = await get('/api/script');
    if (script && script.width > 0) state.view.w = Number(script.width);
    if (script && script.height > 0) state.view.h = Number(script.height);
  } catch (e) {
    // 离线包可能没有驱动脚本：视口尺寸退化到"几何里最靠外的边"。
  }
}

async function loadBrief() {
  const brief = await get('/api/brief');
  state.brief = brief;

  document.getElementById('label').textContent = brief.label || '?';
  const w = brief.window || {};
  document.getElementById('window').textContent =
    `#${w.from ?? 0}–#${w.to ?? 0} / ${w.count ?? 0} 帧 / 容量 ${w.capacity ?? 0}`;
  document.getElementById('digest').textContent = brief.digest ? brief.digest.slice(0, 12) : '—';

  const t = brief.thresholds || {};
  document.getElementById('thresholds').textContent =
    `慢帧 ${t.slowMs}ms · 扇出 ${t.fanoutNodes} · 重复 ${t.repeatPercent}% · 重画 ${t.repaintPercent}%`;

  renderFindings(brief.findings || []);

  const cov = brief.coverage || {};
  let covText = '';
  if (cov.seen > 0) {
    covText = cov.complete
      ? `因果树扫到第 ${cov.seen} 层（完整）`
      : `因果树扫到第 ${cov.seen} 层，${(cov.cut | 0) + (cov.nested | 0)} 处未展开`;
  }
  if (cov.broken && cov.broken.length) {
    covText += `${covText ? ' · ' : ''}帧级证据不完整（${cov.broken.join('/')}）`;
  }
  document.getElementById('coverage').textContent = covText;
}

function renderFindings(findings) {
  const list = document.getElementById('findingList');
  list.textContent = '';
  document.getElementById('findingCount').textContent = findings.length ? `(${findings.length})` : '';

  for (const f of findings) {
    const li = el('li');
    li.appendChild(el('span', 'title', f.title));
    li.appendChild(el('span', 'addr', f.address));
    li.title = `${f.rule} v${f.version} · ${f.metric} · 阈值 ${f.threshold} · 复算 ${f.recheck}`;
    li.onclick = () => jump(f.address);
    list.appendChild(li);
  }
}

function jump(address) {
  const f = /frame:(\d+)/.exec(address || '');
  const n = /node:(\d+)/.exec(address || '');
  if (n) select(Number(n[1]));
  else if (f) loadFrame(Number(f[1]));
}

async function loadFrame(frame, keepSelected) {
  const target = frame || (state.brief && state.brief.worst) || 0;
  const boxes = await get(`/api/boxes?frame=${target}`);
  state.frame = boxes.frame;
  state.lastFrame = boxes.frame;
  state.boxes = boxes.rows || [];
  // 绘制是**这一帧**的事实，与几何同一次刷新取回；`captured:false` 表示预算没开，
  // 页面据此显示"未采集"而不是"没重画"——后者是一个会骗人的结论。
  state.paint = await get(`/api/paint?frame=${state.frame}`).catch(() => null);
  if (!keepSelected) state.selected = 0;

  // 轮询刷新时保住选中：否则"点一下盒子、一秒后选中没了"，而用户以为自己点错了。
  // 节点在新帧里消失才清掉 —— 消失本身要说明，不能静默跳走。
  if (keepSelected && state.selected && !state.boxes.some((r) => r.node === state.selected)) {
    const gone = state.selected;
    state.selected = 0;
    state.hintBase += ` · node:${gone} 在这一帧没有几何`;
  }

  if (!state.view.w || !state.view.h) {
    let rw = 0, rh = 0;
    for (const row of state.boxes) {
      rw = Math.max(rw, row.rect.x + row.rect.w);
      rh = Math.max(rh, row.rect.y + row.rect.h);
    }
    state.view.w = rw || 800;
    state.view.h = rh || 600;
  }

  state.hintBase = boxes.stale
    ? `几何取实际生效帧 #${boxes.effective}（目标 #${boxes.frame} 未重新采样）`
    : `帧 #${boxes.frame} · ${state.boxes.length} 个盒`;

  scheduleDraw();
  renderNode(state.selected || null);
}

// 画布尺寸取自布局结果，首次 boot 时 flex 还没算完 —— 统一挪到下一帧，
// 否则第一屏会按一个偏小的 clientHeight 定比例，看起来"图缩在一角"。
let rafId = 0;

function scheduleDraw() {
  if (rafId) return;
  rafId = requestAnimationFrame(() => {
    rafId = 0;
    draw();
  });
}

function draw() {
  const cv = document.getElementById('canvas');
  const dpr = window.devicePixelRatio || 1;
  const cw = Math.max(1, cv.clientWidth);
  const ch = Math.max(1, cv.clientHeight);
  cv.width = Math.round(cw * dpr);
  cv.height = Math.round(ch * dpr);

  const ctx = cv.getContext('2d');
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, cw, ch);

  const pad = 18;
  const scale = Math.min((cw - pad * 2) / state.view.w, (ch - pad * 2) / state.view.h);
  if (!isFinite(scale) || scale <= 0) return;
  state.scale = scale;
  state.offset = {
    x: (cw - state.view.w * scale) / 2,
    y: (ch - state.view.h * scale) / 2,
  };

  ctx.strokeStyle = '#3a3a42';
  ctx.lineWidth = 1;
  ctx.strokeRect(state.offset.x, state.offset.y, state.view.w * scale, state.view.h * scale);

  // 裁到视口里。表格页的盒会排到 600 以下，不裁就会画出视口外框之外的矩形，
  // 而读者会以为"这些东西看得见"——那正好是这一层唯一不能犯的错。
  let hidden = 0;
  ctx.save();
  ctx.beginPath();
  ctx.rect(state.offset.x, state.offset.y, state.view.w * scale, state.view.h * scale);
  ctx.clip();

  for (const row of state.boxes) {
    const b = row.rect;
    const x = state.offset.x + b.x * scale;
    const y = state.offset.y + b.y * scale;
    const w = Math.max(0.6, b.w * scale);
    const h = Math.max(0.6, b.h * scale);

    if (row.node === state.selected) {
      ctx.fillStyle = 'rgba(78,201,176,0.20)';
      ctx.fillRect(x, y, w, h);
      ctx.strokeStyle = '#4ec9b0';
      ctx.lineWidth = 2;
    } else {
      ctx.strokeStyle = colorOf(row.type);
      ctx.lineWidth = 1;
    }
    ctx.strokeRect(x, y, w, h);

    if (b.y < 0 || b.y + b.h > state.view.h || b.x < 0 || b.x + b.w > state.view.w) hidden++;
  }
  ctx.restore();

  state.hidden = hidden;
  restoreHint();
}

function restoreHint() {
  const hint = document.getElementById('hint');
  hint.textContent = state.hintBase + (state.hidden ? ` · ${state.hidden} 个盒在视口外` : '');
}

function hitTest(event) {
  const cv = document.getElementById('canvas');
  const rect = cv.getBoundingClientRect();
  const mx = event.clientX - rect.left;
  const my = event.clientY - rect.top;
  const s = state.scale;
  if (!s) return 0;

  let best = 0;
  let bestArea = Infinity;
  for (const row of state.boxes) {
    const b = row.rect;
    const x = state.offset.x + b.x * s;
    const y = state.offset.y + b.y * s;
    const w = b.w * s;
    const h = b.h * s;
    if (mx < x || my < y || mx > x + w || my > y + h) continue;
    // 面积最小 = 最深的那层。加 0.5 是为了让零尺寸盒（分隔线）不至于永远赢。
    const area = (w + 0.5) * (h + 0.5);
    if (area < bestArea) {
      bestArea = area;
      best = row.node;
    }
  }
  return best;
}

function hover(event) {
  const node = hitTest(event);
  const hint = document.getElementById('hint');
  if (!node) {
    hint.textContent = '';
    return;
  }
  const row = state.boxes.find((r) => r.node === node);
  if (!row) return;
  hint.textContent = `#${row.node} ${row.type || '?'} · ${row.rect.w}×${row.rect.h} @ ${row.rect.x},${row.rect.y}`;
}

async function select(node) {
  state.selected = node;
  scheduleDraw();
  renderNode(node);

  if (!node) return;
  try {
    const card = await get(`/api/node?node=${node}&frame=${state.frame}`);
    renderCard(card);
  } catch (e) {
    document.getElementById('card').textContent = e.message;
  }
}

function renderNode(node) {
  const crumbs = document.getElementById('crumbs');
  const children = document.getElementById('children');
  crumbs.textContent = '';
  children.textContent = '';
  document.getElementById('selected').textContent = node ? `#${node}` : '';
  if (!node) {
    document.getElementById('card').textContent = '点画布上的盒，或点左边一条发现';
    return;
  }

  const byNode = new Map(state.boxes.map((r) => [r.node, r]));
  const row = byNode.get(node);
  if (!row) return;

  // 面包屑：从根走到自己。父链是数据（parent 字段），走一遍是渲染不是重算。
  const chain = [];
  let cur = row;
  const seen = new Set();
  while (cur && !seen.has(cur.node)) {
    seen.add(cur.node);
    chain.unshift(cur);
    cur = cur.parent ? byNode.get(cur.parent) : null;
  }
  chain.forEach((r, i) => {
    if (i) crumbs.appendChild(document.createTextNode(' > '));
    const b = el('b', '', r.type || `#${r.node}`);
    b.onclick = () => select(r.node);
    crumbs.appendChild(b);
  });

  const kids = state.boxes.filter((r) => r.parent === node);
  if (!kids.length) {
    children.appendChild(el('div', 'dim', '没有几何子盒'));
    return;
  }
  const head = el('div', 'dim', `直接子盒 ${kids.length} 个`);
  children.appendChild(head);
  for (const kid of kids.slice(0, 40)) {
    const line = el('span', 'child', `${kid.type || '?'}  #${kid.node}  ${kid.rect.w}×${kid.rect.h}`);
    line.onclick = () => select(kid.node);
    children.appendChild(line);
  }
  if (kids.length > 40) {
    children.appendChild(el('div', 'dim', `还有 ${kids.length - 40} 个未列出`));
  }
}

// 一个节点这一帧的绘制状态。三态必须分开报：**没采**（预算没开）/ **没画** /
// **画了**（其中"边界重录"还带原因）。混成两句会让"没采"看起来像"没重画"。
function paintFact(node) {
  const p = state.paint;
  if (!p || p.frame !== state.frame) return '—';
  if (p.found === false) {
    return p.reason === 'paint-not-captured' ? '未采集' : `查不到（${p.reason || '?'}）`;
  }
  const reason = (p.misses || {})[node];
  if (reason) return `边界重录（${reason}）`;
  return (p.painted || []).includes(node) ? '重画' : '未重画';
}

function renderCard(card) {
  const host = document.getElementById('card');
  host.textContent = '';
  const bm = card.boxModel || {};

  if (bm.error) {
    host.appendChild(el('div', 'dim', bm.error));
    return;
  }
  if (bm.found === false) {
    host.appendChild(el('div', 'dim', `几何查不到（${bm.reason || '?'}）`));
    return;
  }

  const table = el('table');
  // 类型不在 `boxModel` 里（它是单节点几何），在 `boxes` 的行上 —— 两处都指向同一个节点，
  // 页面上读一次已经有的那份，不为一个字段再发一个请求。
  const row = state.boxes.find((r) => r.node === card.node);
  const rows = [
    ['类型', (row && row.type) || bm.type || '?'],
    ['尺寸', `${bm.size?.w ?? '?'} × ${bm.size?.h ?? '?'}`],
    ['偏移', `${bm.offset?.x ?? '?'}, ${bm.offset?.y ?? '?'}（相对父内容盒）`],
    ['父', bm.parent || '—'],
    ['本帧绘制', paintFact(card.node)],
    ['内容盒约束', `${bm.inner?.minW ?? '?'}…${bm.inner?.maxW ?? '?'}`],
    ['收到约束', `${bm.applied?.minW ?? '?'}…${bm.applied?.maxW ?? '?'}`],
  ];
  for (const [k, v] of rows) {
    const tr = el('tr');
    tr.appendChild(el('th', '', k));
    tr.appendChild(el('td', '', String(v)));
    table.appendChild(tr);
  }
  host.appendChild(table);

  const props = card.props || {};
  if (props.found === false) {
    // `props` 是 dev 反射，按需读活树；节点这一帧没挂上就读不到。说清是"读不到"
    // 而不是"没有属性"——两者对一个 inspector 是不同的结论。
    host.appendChild(el('div', 'dim small', `属性读不到（${props.reason || '?'}）`));
  } else if (Object.keys(props).length) {
    const pre = el('div', 'box');
    pre.textContent = JSON.stringify(props, null, 2);
    host.appendChild(pre);
  }
}

async function act(action, btn) {
  const payload = action === 'step' ? { action: 'resume', frames: 1 } : { action };
  btn.disabled = true;
  try {
    await post('/api/ctl', payload);
  } catch (e) {
    alert(e.message);
  } finally {
    btn.disabled = false;
    await refresh();
  }
}

async function poll() {
  try {
    const st = await get('/api/state');
    const chip = document.getElementById('state');
    chip.textContent = st.state + (st.reason ? ` (${st.reason})` : '');
    chip.hidden = false;
    document.getElementById('ctl').hidden = st.driven !== true;
    if (st.lastFrame !== state.lastFrame) {
      state.lastFrame = st.lastFrame;
      if (state.frame) await loadFrame(state.frame, true);
    }
  } catch (e) {
    document.getElementById('state').textContent = 'offline';
  }
}

async function refresh() {
  await Promise.all([loadScript(), loadBrief()]);
  await loadFrame(state.frame);
  await poll();
}

boot().catch((e) => {
  document.getElementById('label').textContent = `出错：${e.message}`;
});
