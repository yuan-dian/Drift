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
  hover: 0,
  // 视图：`scale` 是最终像素/逻辑单位（= 适配比例 × 用户缩放），`offset` 是视口原点在画布里的位置。
  scale: 0,
  fit: 0,
  zoom: 1,
  offset: { x: 0, y: 0 },
  view: { w: 0, h: 0 },
  lastFrame: -1,
  hintBase: '',
  hidden: 0,
  // 底图对应的录制帧号（宿主拍这张时主循环跑到第几帧）。**只用于显示** ——
  // 拍照发生在 `endFrame()` 之前，这个号必然落后一帧，拿它取几何就是"新图配旧框"。
  shotFrame: null,
  // 快照序号：这是第几张画面。与 `shotFrame` 分开——序号只回答"换不换图"，
  // 帧号回答"配哪一帧的几何"。用帧号当图片版本号，静止的应用会每帧都被当成新图。
  shotSerial: null,
  shotUrl: '',
  shotSize: { w: 0, h: 0 },
  // 是否跟着应用最新帧走。★ 用显式标志而不是"帧号相等"来推断：拿两个来自不同
  // 窗口的数（录制帧号 / 几何覆盖帧号）比较，只要一开始不等就永远不跟，而页面上
  // 看不出任何异常。用户点了历史帧或地址带 frame= 才置 false。
  follow: true,
  // 用户是否动过视图。没动过时 `layout()` 每次重新居中（窗口尺寸变了也不跑偏）；
  // 一旦拖过或缩放过就尊重用户的视点，直到点"适配"。
  panned: false,
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

/** 给不透明的 hsl() 加 alpha。有底图时框要退到"辅助线"，同一套颜色只改透明度。 */
function withAlpha(css, alpha) {
  if (css.startsWith('hsl(')) {
    return `${css.slice(0, -1).replace('hsl(', 'hsla(')}, ${alpha})`;
  }
  return css;
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

  // 只监听 window.resize 不够：首屏 flex 算完、右面板内容撑开、字号加载完成
  // 都会改变画布的客户区尺寸，而这些都不发 window 事件。漏掉的表现是
  // "第一屏按小尺寸定了比例，之后一直缩在角落"——在无头浏览器里尤其明显。
  if (window.ResizeObserver) {
    new ResizeObserver(() => scheduleDraw()).observe(document.getElementById('viewport'));
  }

  for (const btn of document.querySelectorAll('#ctl button')) {
    btn.onclick = () => act(btn.dataset.act, btn);
  }

  const cv = document.getElementById('canvas');

  // ── 交互：DevTools 那套手感（滚轮缩放 / 拖拽平移 / 单击选中）────────────
  // ★ 点选与拖拽共用左键：按下先记起点，移动超过阈值才算拖 —— 否则"想把图挪一下"
  //   会变成"选中了一个框"，而用户以为自己在平移。
  let drag = null;

  cv.addEventListener('mousedown', (e) => {
    if (e.button !== 0 && e.button !== 1) return;
    drag = { x: e.clientX, y: e.clientY, ox: state.offset.x, oy: state.offset.y, moved: false };
    if (e.button === 1) e.preventDefault();
  });

  window.addEventListener('mousemove', (e) => {
    if (!drag) {
      hover(e);
      return;
    }
    const dx = e.clientX - drag.x;
    const dy = e.clientY - drag.y;
    if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 4) return;
    drag.moved = true;
    state.offset = { x: drag.ox + dx, y: drag.oy + dy };
    cv.style.cursor = 'grabbing';
    scheduleDraw();
  });

  window.addEventListener('mouseup', (e) => {
    if (!drag) return;
    const wasDrag = drag.moved;
    drag = null;
    cv.style.cursor = '';
    if (wasDrag) return;
    const node = hitTest(e);
    select(node);   // 点空白 = 取消选中（DevTools 同款）
  });

  cv.addEventListener('wheel', (e) => {
    e.preventDefault();
    const rect = cv.getBoundingClientRect();
    zoomAt(e.clientX - rect.left, e.clientY - rect.top, e.deltaY < 0 ? 1.15 : 1 / 1.15);
  }, { passive: false });

  cv.addEventListener('mouseleave', () => {
    if (state.hover) {
      state.hover = 0;
      scheduleDraw();
    }
    restoreHint();
  });

  document.getElementById('fit').onclick = () => resetView();
  document.getElementById('actual').onclick = () => actualSize();
  document.getElementById('follow').onclick = () => setFollow(!state.follow);
  document.addEventListener('keydown', (e) => {
    if (e.target === document.getElementById('q')) return;
    if (e.key === '0') { resetView(); return; }
    if (e.key === '1') { actualSize(); return; }
    if (e.key === 'f' || e.key === 'F') { setFollow(!state.follow); return; }
    if (e.key === 'Escape') { select(0); return; }
  });

  const q = document.getElementById('q');
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') search(q.value);
    if (e.key === 'Escape') {
      q.value = '';
      document.getElementById('hitList').textContent = '';
    }
  });

  await Promise.all([loadScript(), loadBrief()]);

  // 第一屏：有底图就跟"最新"（服务端缺省帧 = 几何窗口最新），画面也是最新的，
  // 两者同源；没有底图（离线包 / 自己跑的会话）才退回"最值得看的一帧"。
  const ping = await get('/api/shot/ping').catch(() => null);
  const hasShot = !!(ping && ping.shot);

  // `?node=32&frame=1` 直接开到那个节点的卡片。发现里的地址能贴进聊天窗口，
  // 收的人点开就是同一帧同一个盒 —— 否则"你看 node:32"要靠对方自己找。
  const params = new URLSearchParams(location.search);
  const urlFrame = Number(params.get('frame') || 0);
  // 地址里点名了帧 = 用户要看那一帧，就别让轮询把他拽回最新。
  if (urlFrame) state.follow = false;
  await loadFrame(urlFrame || (hasShot ? 0 : ((state.brief && state.brief.worst) || 0)));
  // `?zoom=1` 直接开 1:1：贴给别人"看这个像素"时，比让他自己滚轮找更靠谱。
  state.applyActual = params.get('zoom') === '1';
  const node = Number(params.get('node') || 0);
  if (node) await select(node);

  resetView();   // 显式归一：不依赖之前任何一次 draw 留下的 zoom / offset
  setFollow(state.follow);   // 同步按钮的按下态（地址带了 frame= 时是停住的）
  // 500ms：宿主拍帧上限是 4 fps，再快也拿不到新图；而 1s 会让"跟着应用动"
  // 明显滞后 —— 用户点一下按钮，画面要等一秒才换，就像没接上。
  setInterval(poll, 500);
  pollShot();
}

/**
 * 跟着最新画面走 / 停在当前帧。
 *
 * ★ 用户一点应用窗口、画面就跟着换，这是"web 看着真应用"的核心体感；而用户
 *   点了发现里的 `frame:N` 想研究某一帧时，被后台轮询拽走同样致命。两种意图
 *   必须由用户显式切换，不能靠"帧号相不相等"去猜。
 */
function setFollow(on) {
  state.follow = !!on;
  const btn = document.getElementById('follow');
  if (btn) btn.classList.toggle('on', state.follow);
  // 恢复跟随时立刻回到"最新"（服务端缺省帧），不用快照 meta 里那个滞后的帧号。
  if (state.follow) {
    loadFrame(0, true);
  }
}

async function loadScript() {
  try {
    const script = await get('/api/script');
    // 视口是 `viewport: [w, h]`（与 `Script` 的 `Script::width/height` 同名但**不扁平**）。
    // 早先这里读 `script.width`，字段根本不存在 ⇒ 视口退化成"几何里最靠外的边"，
    // 而那个数比真实窗口窄 ⇒ 底图被横向压扁，谁看都觉得"和真机不像"。
    const vp = script && script.viewport;
    if (Array.isArray(vp) && vp.length >= 2) {
      if (Number(vp[0]) > 0) state.view.w = Number(vp[0]);
      if (Number(vp[1]) > 0) state.view.h = Number(vp[1]);
    }
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
  // 跳到历史帧就不再跟着最新跑 —— 否则一秒后画面自己跳走，用户以为链接坏了。
  if (f) setFollow(false);
}

// 搜索：结果一律是地址，点一条就落到那个节点上（与发现列表同一条路 `jump()`）。
// 页面只传关键词、只画返回的数组：匹配规则、截断、扫过多少候选全由 `find()` 给 ——
// 前端再 filter 一遍就成了第二份实现，"同一次搜索三种结果"就是这么来的。
async function search(text) {
  const list = document.getElementById('hitList');
  list.textContent = '';

  const q = (text || '').trim();
  if (!q) return;

  let answer;
  try {
    answer = await get(`/api/find?domain=node&limit=20&pattern=${encodeURIComponent(q)}`);
  } catch (e) {
    list.appendChild(el('li', 'none', e.message));
    return;
  }

  const hits = answer.matches || [];
  if (hits.length === 0) {
    // "命中 0" 与 "压根没有候选" 是两件事：后者说明这段证据没采到结构。
    const scanned = (answer.domains && answer.domains.node ? answer.domains.node.scanned : 0) || 0;
    list.appendChild(el('li', 'none', scanned > 0 ? '没有命中的类型' : '节点表是空的（这段证据没采到结构）'));
    return;
  }

  for (const hit of hits) {
    const li = el('li');
    li.appendChild(el('span', 'addr', hit.address));
    li.appendChild(el('span', null, hit.text));
    li.title = hit.text;
    li.onclick = () => jump(hit.address);
    list.appendChild(li);
  }

  if (answer.truncated) {
    list.appendChild(el('li', 'none', `命中 ${answer.total}，只列了 ${answer.returned}`));
  }
}

async function loadFrame(frame, keepSelected) {
  // `0` = 服务端缺省（几何窗口最新）。
  //
  // ★ 这里**不能**退化成 `brief.worst`：worst 是"最值得看的一帧"，是第一屏的
  //   入口，不是"现在"。拿它当跟随目标，画面一变就得到"新图配旧框"——而错位
  //   看起来像布局错，用户只会说"你的线框叠在一起了"。
  const boxes = await get(frame > 0 ? `/api/boxes?frame=${frame}` : '/api/boxes');
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

  // 标题栏跟着走：用户切到别的标签页时也能看出"页面还在跟"还是"停住了"。
  // 只在 boot 设一次的话，标签页会永远停在打开时那一个帧号。
  document.title = `drift · 帧 #${state.frame} · ${state.boxes.length} 盒`
    + (state.follow ? '' : ' · 已停住')
    + (state.selected ? ` · node:${state.selected}` : '');

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

/**
 * 以画布内 (mx,my) 为锚缩放：锚点处的**逻辑坐标**在缩放前后不动。
 * 不做这一步的话，滚轮会永远把图往左上拽，放大十次后目标就找不回来了。
 */
function zoomAt(mx, my, factor) {
  const next = Math.min(8, Math.max(0.2, state.zoom * factor));
  if (next === state.zoom || !state.scale) return;
  const k = next / state.zoom;
  state.offset = {
    x: mx - (mx - state.offset.x) * k,
    y: my - (my - state.offset.y) * k,
  };
  state.zoom = next;
  state.panned = true;
  scheduleDraw();
}

function resetView() {
  state.zoom = 1;
  state.panned = false;
  scheduleDraw();
}

/**
 * 1:1 真机像素：一个逻辑点占一个 CSS 像素。
 *
 * 1920 宽的窗口塞进 560 宽的面板，`fit` 只有 0.28 —— 整体看得见，字全糊。
 * 想看"这个像素到底是什么颜色"就必须回到 1:1，代价是要拖着看（DevTools 同款取舍）。
 */
function actualSize() {
  if (!state.fit) return;
  state.zoom = 1 / state.fit;
  state.panned = false;   // 先居中，用户再拖
  scheduleDraw();
}

/** 算 scale 与 offset。`panned=false` 时居中（首次 / 适配 / 窗口尺寸变了）。 */
function layout(cw, ch) {
  const pad = 16;
  const fit = Math.min((cw - pad * 2) / state.view.w, (ch - pad * 2) / state.view.h);
  if (!isFinite(fit) || fit <= 0) return false;

  state.fit = fit;
  state.scale = fit * state.zoom;
  if (!state.panned) {
    state.offset = {
      x: (cw - state.view.w * state.scale) / 2,
      y: (ch - state.view.h * state.scale) / 2,
    };
  }

  return true;
}

function draw() {
  const cv = document.getElementById('canvas');
  const dpr = window.devicePixelRatio || 1;
  const cw = Math.max(1, cv.clientWidth);
  const ch = Math.max(1, cv.clientHeight);
  if (cv.width !== Math.round(cw * dpr) || cv.height !== Math.round(ch * dpr)) {
    cv.width = Math.round(cw * dpr);
    cv.height = Math.round(ch * dpr);
  }

  const ctx = cv.getContext('2d');
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, cw, ch);

  if (!layout(cw, ch)) return;

  if (state.applyActual) {
    state.applyActual = false;
    actualSize();

    return;
  }

  const { scale, offset, view } = state;
  const vx = offset.x;
  const vy = offset.y;
  const vw = view.w * scale;
  const vh = view.h * scale;

  // 视口外的底：比面板深一档，图与面板之间才有"这是应用窗口"的边界感。
  // ★ 视口**内**必须留空（clear 掉）：底图是 canvas 下层的一张 `<img>`，
  //   这里填满整片就等于把图盖死 —— 而盖住之后图上只剩几何框，看起来像"渲染没生效"。
  ctx.fillStyle = '#141416';
  ctx.fillRect(0, 0, cw, ch);
  ctx.clearRect(vx, vy, vw, vh);

  placeShot(vx, vy, vw, vh);

  // 视口边框：1 物理像素（不要跟着 dpr 变粗）。
  ctx.strokeStyle = '#4a4a52';
  ctx.lineWidth = 1;
  ctx.strokeRect(Math.round(vx) + 0.5, Math.round(vy) + 0.5, Math.round(vw), Math.round(vh));

  // 裁到视口里。表格页的盒会排到 600 以下，不裁就会画出视口外框之外的矩形，
  // 而读者会以为"这些东西看得见"——那正好是这一层唯一不能犯的错。
  let hidden = 0;
  ctx.save();
  ctx.beginPath();
  ctx.rect(vx, vy, vw, vh);
  ctx.clip();

  const hit = state.hover;
  const sel = state.selected;

  for (const row of state.boxes) {
    const b = row.rect;
    const isSel = row.node === sel;
    const isHover = row.node === hit && !isSel;
    const x = vx + b.x * scale;
    const y = vy + b.y * scale;
    const w = Math.max(0.75, b.w * scale);
    const h = Math.max(0.75, b.h * scale);

    if (isSel) {
      ctx.fillStyle = 'rgba(78,201,176,0.16)';
      ctx.fillRect(x, y, w, h);
      ctx.strokeStyle = '#4ec9b0';
      ctx.lineWidth = 2;
    } else if (isHover) {
      ctx.fillStyle = 'rgba(120,170,255,0.14)';
      ctx.fillRect(x, y, w, h);
      ctx.strokeStyle = '#7aa7ff';
      ctx.lineWidth = 1.5;
    } else {
      // 有底图时线框退到"辅助线"的位置：图是主角，框只回答"这是哪块"。
      // 缩得太小时（整窗塞进面板）再淡一档：否则 100+ 条彩线会把内容盖成一张网。
      const base = state.shotUrl ? 0.34 : 0.75;
      ctx.strokeStyle = withAlpha(colorOf(row.type), scale < 0.5 ? base * 0.55 : base);
      ctx.lineWidth = 1;
    }
    ctx.strokeRect(x, y, w, h);

    if (b.y < 0 || b.y + b.h > view.h || b.x < 0 || b.x + b.w > view.w) hidden++;
  }

  if (sel) annotate(ctx, sel, vx, vy, scale, vw, vh);
  ctx.restore();

  state.hidden = hidden;
  updateZoom();
  restoreHint();
}

/** 底图是 DOM `<img>`：跟着同一个矩形走，不重绘进 canvas（更快也更清晰）。 */
function placeShot(x, y, w, h) {
  const img = document.getElementById('shot');
  const none = document.getElementById('noshot');

  if (!state.shotUrl) {
    img.style.display = 'none';
    none.hidden = false;
    none.style.left = `${x}px`;
    none.style.top = `${y}px`;
    none.style.width = `${w}px`;
    none.style.height = `${h}px`;
    return;
  }

  none.hidden = true;
  img.style.display = 'block';
  img.style.left = `${x}px`;
  img.style.top = `${y}px`;
  img.style.width = `${w}px`;
  img.style.height = `${h}px`;
  // 放大到能看见单个像素时用最近邻 —— 调试时要看的是"这一像素是什么颜色"，
  // 不是"它插值之后好看"。缩小时用平滑，否则文字会碎。
  img.style.imageRendering = state.scale >= 1.5 ? 'pixelated' : 'auto';
}

/** 选中框的尺寸标注：DevTools 式的黑底小白字，贴在上边框外侧。 */
function annotate(ctx, node, vx, vy, scale, vw, vh) {
  const row = state.boxes.find((r) => r.node === node);
  if (!row) return;

  const b = row.rect;
  const x = vx + b.x * scale;
  const y = vy + b.y * scale;
  const w = Math.max(0.75, b.w * scale);
  const h = Math.max(0.75, b.h * scale);

  // 四角标记：框压在图上时，纯描边容易和界面元素自身的边混在一起。
  const c = 4;
  ctx.strokeStyle = '#4ec9b0';
  ctx.lineWidth = 2;
  for (const [px, py, sx, sy] of [
    [x, y, 1, 1], [x + w, y, -1, 1], [x, y + h, 1, -1], [x + w, y + h, -1, -1],
  ]) {
    ctx.beginPath();
    ctx.moveTo(px, py + sy * c);
    ctx.lineTo(px, py);
    ctx.lineTo(px + sx * c, py);
    ctx.stroke();
  }

  const label = `${Math.round(b.w)}×${Math.round(b.h)}`;
  ctx.font = '11px ui-monospace, Consolas, monospace';
  const tw = ctx.measureText(label).width;
  let lx = x;
  let ly = y - 18;
  if (ly < vy) ly = y + h + 4;                    // 顶到视口上沿就落到框下方
  lx = Math.min(Math.max(lx, vx), vx + vw - tw - 10);

  ctx.fillStyle = 'rgba(20,20,22,0.9)';
  ctx.fillRect(lx, ly, tw + 10, 16);
  ctx.fillStyle = '#4ec9b0';
  ctx.fillText(label, lx + 5, ly + 12);
}

function updateZoom() {
  // 显示**相对真机像素**的比例。显示"相对适应"的倍数会在 28% 适应时说 100%，
  // 而用户看到的是缩小的图 —— 那个数字就成了假的。
  document.getElementById('zoom').textContent = `${Math.round(state.scale * 100)}%`;
  // 视口尺寸常显：底图被压扁时第一眼要看的就是这两个数对不对得上真机窗口。
  document.getElementById('viewsize').textContent =
    `${Math.round(state.view.w)}×${Math.round(state.view.h)}`;
}

function restoreHint() {
  const hint = document.getElementById('hint');
  hint.textContent = state.hintBase + (state.hidden ? ` · ${state.hidden} 个盒在视口外` : '');

  const info = document.getElementById('shotinfo');
  if (!state.shotUrl) {
    info.textContent = '';
  } else if (state.follow) {
    // 跟随态：图与几何都是"最新"，不存在错位。显示快照序号 —— 帧号是主循环的
    // 计数器（空闲的桥让它一直涨），拿它当"画面版本"读者只会觉得数字乱跳。
    info.textContent = `跟随中 · 图 #${state.shotSerial ?? '?'}`;
  } else {
    // 停住了：图仍是最新画面，几何是用户点的那一帧。**不藏起来** ——
    // 藏了就会有人拿着错位图去论证布局。
    info.textContent = `已停住 · 图 #${state.shotSerial ?? '?'} · 几何 #${state.frame}`;
  }
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
  const cv = document.getElementById('canvas');
  const rect = cv.getBoundingClientRect();
  if (event.clientX < rect.left || event.clientX > rect.right
    || event.clientY < rect.top || event.clientY > rect.bottom) {
    return;
  }

  const node = hitTest(event);
  if (node !== state.hover) {
    state.hover = node;
    scheduleDraw();   // hover 也要重绘：高亮就画在这层画布上，不重绘等于没高亮
  }

  const hint = document.getElementById('hint');
  if (!node) {
    restoreHint();
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

    // ★ 这里**不**按 `lastFrame` 刷几何。帧号是"主循环跑过几轮"，空闲的应用
    //   被调试桥叫醒也会涨（~80fps）—— 按它刷新等于每 500ms 全量重取一遍几何，
    //   而真的换了画面时 `pollShot()` 自然会带着新帧号来。几何跟着**画面**走。
    state.lastFrame = st.lastFrame;
  } catch (e) {
    document.getElementById('state').textContent = 'offline';
  }

  await pollShot();
}

/**
 * 底图的一次轮询：先报心跳（宿主据此决定要不要拍），再问最新那张是哪一帧。
 *
 * ★ 只在**快照序号**变了才换 `src`：静止的应用每秒都换一次 URL 会让浏览器反复
 *   解码同一张图，看起来像"画面在闪"。序号来自宿主"真的重画了一帧"，不是帧号。
 */
async function pollShot() {
  let ping;
  try {
    ping = await get('/api/shot/ping');
  } catch (e) {
    return;
  }

  if (!ping || !ping.channel || !ping.shot) {
    if (state.shotUrl) {
      state.shotUrl = '';
      state.shotSerial = null;
      state.shotFrame = null;
      scheduleDraw();
    }
    return;
  }

  const serial = Number(ping.shot);
  if (serial === state.shotSerial && state.shotUrl) return;

  const frame = Number(ping.frame || 0);

  const img = document.getElementById('shot');
  img.onload = () => scheduleDraw();
  img.onerror = () => {
    state.shotUrl = '';
    state.shotSerial = null;
    state.shotFrame = null;
    scheduleDraw();
  };
  state.shotSerial = serial;
  state.shotFrame = frame;
  state.shotUrl = `/api/shot?serial=${serial}&t=${Date.now()}`;
  img.src = state.shotUrl;
  scheduleDraw();

  // 跟随：几何也跟到"最新"。★ 不按快照 meta 里的 frame 取 —— 那张图是在
  // `endFrame()` 之前拍的，它记的几何号必然落后一帧，按它取就是"新图配旧框"。
  // 几何的"最新"由服务端缺省保证（几何窗口的最新一帧），与画面同源。
  if (state.follow) {
    await loadFrame(0, true);
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
