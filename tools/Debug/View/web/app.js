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
  // 选中的真相在宿主 `query('highlight')`：页面里的 selected 只是它的镜像。
  // 点击画布写宿主、真窗口拾取由轮询读回，两个方向都只认那一份状态。
  controllable: false,
  writePending: false,
  selectionNote: '',
  picking: false,
  pickBase: 0,
  pickUntil: 0,
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
  // 共轴时间轴：帧条是横轴，请求条按帧条的实际 DOM 位置贴上去。
  frames: [],
  network: [],
  networkCaptured: false,
  activeRequest: 0,
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
  window.addEventListener('resize', () => { scheduleDraw(); placeNetwork(); });

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
    if (e.key === 'Escape') {
      if (state.picking) stopPicking(); else select(0);
      return;
    }
  });

  const q = document.getElementById('q');
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') search(q.value);
    if (e.key === 'Escape') {
      q.value = '';
      document.getElementById('hitList').textContent = '';
    }
  });

  await Promise.all([loadScript(), loadBrief(), loadFrames(), loadNetwork()]);

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
  // 先问宿主选中与干预能力，再处理 `?node=`：否则带链接打开时 controllable 还是初值，
  // 节点卡出来了，真机上却什么都没亮。
  await pollSelection();
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
    `#${w.from ?? 0}–#${w.to ?? 0}${((w.folded ?? 0) > 0 ? ` · 折叠 ${w.folded}` : '')}`;

  // 指纹、阈值、规则选择是**复算口径**，不是当前状态：放进折叠区，首屏只留能行动的信息。
  document.getElementById('digest').textContent = brief.digest || '—';
  const t = brief.thresholds || {};
  document.getElementById('thresholds').textContent =
    `慢帧 ${t.slowMs}ms · 扇出 ${t.fanoutNodes} · 重复 ${t.repeatPercent}% · 重画 ${t.repaintPercent}%`;
  const sel = brief.selection || {};
  document.getElementById('ruleInfo').textContent =
    `${sel.rules?.length ?? 0}/${sel.total ?? 0} 条${sel.filtered ? '（已过滤，结论不是全量）' : ''}`;
  document.getElementById('windowMeta').textContent =
    `${w.count ?? 0} 帧 / 容量 ${w.capacity ?? 0}`;

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
  const covEl = document.getElementById('coverage');
  covEl.textContent = covText;
  covEl.hidden = covText === '';
}

const SEVERITY = { info: '提示', warning: '警告', error: '错误' };

function renderFindings(findings) {
  const list = document.getElementById('findingList');
  list.textContent = '';
  document.getElementById('findingCount').textContent = findings.length ? `(${findings.length})` : '';

  for (const f of findings) {
    const li = el('li', `finding sev-${f.severity || 'info'}`);
    const head = el('div', 'finding-head');
    head.appendChild(el('span', 'sev', SEVERITY[f.severity] || f.severity || '提示'));
    head.appendChild(el('span', 'title', f.title));
    li.appendChild(head);
    // 数值与判据是"为什么报它"的答案，藏在 tooltip 里等于没给。
    if (f.metric) li.appendChild(el('div', 'metric', f.metric));
    if (f.threshold) li.appendChild(el('div', 'detail', `判据 ${f.threshold}`));
    if (f.recheck) li.appendChild(el('div', 'detail small', `复算 ${f.recheck}`));
    li.appendChild(el('span', 'addr', f.address));
    li.title = `${f.rule} v${f.version}`;
    li.onclick = () => jump(f.address);
    list.appendChild(li);
  }
}

function jump(address) {
  const a = address || '';
  const f = /frame:(\d+)/.exec(a);
  const n = /node:(\d+)/.exec(a);
  const s = /stage:([^\s]+)/.exec(a);
  const req = /request:(\d+)/.exec(a);

  if (req) { showRequest(Number(req[1])); return; }
  if (f) {
    // 地址里的帧号与节点号必须一起用：只选节点会让节点卡按**当前帧**取几何，
    // 应用跑远后就是"点开发现的节点却查不到"。
    setFollow(false);
    loadFrame(Number(f[1])).then(() => {
      if (n) select(Number(n[1]));
      else if (s) {
        state.hintBase += ` · 阶段 ${s[1]}`;
        restoreHint();
      }
    });
    return;
  }
  if (n) select(Number(n[1]));
}

/** 请求详情：骨架 + 落地节点 + 结算帧的重建与绘制。四段都来自现成查询。 */
async function showRequest(id) {
  state.activeRequest = Number(id);
  markActiveRequest();
  document.getElementById('selected').textContent = `#req ${id}`;
  document.getElementById('crumbs').textContent = '';
  document.getElementById('children').textContent = '请求不属于控件树';
  const host = document.getElementById('card');
  host.textContent = `请求 #${id} …`;
  try {
    const answer = await get(`/api/request?request=${id}`);
    const rows = answer.rows || [];
    host.textContent = '';
    if (!rows.length) {
      host.appendChild(el('div', 'none', answer.miss || '这段证据里没有这个请求'));
      return;
    }
    const r = rows[0];
    const table = el('table');
    for (const [k, v] of [
      ['方法', r.method || '?'],
      ['URL', r.url || '?'],
      ['状态', r.status ?? '—'],
      ['耗时', `${r.elapsedMs ?? '?'} ms`],
      ['字节', r.bytes ?? '?'],
      ['帧', `#${r.startedAtFrame ?? '?'} → #${r.settledAtFrame ?? '?'}${r.pendingFrames ? `（等 ${r.pendingFrames} 帧）` : ''}`],
      ['来源', r.initiator || '—'],
    ]) {
      const tr = el('tr');
      tr.appendChild(el('th', '', k));
      tr.appendChild(el('td', '', String(v)));
      table.appendChild(tr);
    }
    host.appendChild(table);

    const landed = el('div', 'step');
    landed.appendChild(el('b', '', '它落到哪个节点'));
    if (Array.isArray(r.landed) && r.landed.length) {
      for (const node of r.landed) {
        const link = el('span', 'jump', `node:${node}`);
        link.onclick = () => {
          setFollow(false);
          loadFrame(Number(r.settledAtFrame)).then(() => select(Number(node)));
        };
        landed.appendChild(link);
        landed.appendChild(document.createTextNode(' '));
      }
    } else {
      landed.appendChild(el('span', 'none', '同帧没有状态写入'));
    }
    host.appendChild(landed);

    const frame = Number(r.settledAtFrame || 0);
    const cause = el('div', 'step');
    cause.appendChild(el('b', '', '结算那一帧'));
    const summary = state.frames.find((f) => Number(f.id) === frame);
    if (summary) {
      cause.appendChild(el('span', '', `#${frame} · 重建 ${summary.rebuilt ?? 0} · 因果 ${summary.causes ?? 0} · ${Number(summary.cost || 0).toFixed(1)}ms`));
      const link = el('span', 'jump', ' 跳到这帧');
      link.onclick = () => { setFollow(false); loadFrame(frame); };
      cause.appendChild(link);
    } else {
      cause.appendChild(el('span', 'none', frame ? `#${frame} 不在帧窗口里（滚出或未采）` : '请求还在飞，没有结算帧'));
    }
    host.appendChild(cause);

    const painted = el('div', 'step');
    painted.appendChild(el('b', '', '那一帧画了什么'));
    if (frame) {
      try {
        const p = await get(`/api/paint?frame=${frame}`);
        if (p.found) {
          const misses = Object.keys(p.misses || {}).length;
          painted.appendChild(el('span', '', `重画 ${p.paintedNodes ?? 0} 盒 · 层命中 ${p.hits ?? 0} · 未命中 ${misses}`));
        } else {
          const why = p.reason === 'paint-not-captured'
            ? '这份证据没有开绘制采集'
            : `绘制记录不可用（${p.reason || '未知'}）`;
          painted.appendChild(el('span', 'none', why));
        }
      } catch (e) {
        painted.appendChild(el('span', 'none', e.message));
      }
    } else {
      painted.appendChild(el('span', 'none', '请求未结算'));
    }
    host.appendChild(painted);
  } catch (e) {
    host.textContent = e.message;
  }
}

// 搜索：结果一律是地址，点一条就按地址落到节点 / 请求 / 帧上（与发现共用 `jump()`）。
// `domain=all` 把请求也纳入 —— 后端 find 本来就支持 request 域，前端写死 node 等于
// 让"这次请求到底发没发"在页面上永远搜不到。
async function search(text) {
  const list = document.getElementById('hitList');
  list.textContent = '';

  const q = (text || '').trim();
  if (!q) return;

  let answer;
  try {
    answer = await get(`/api/find?domain=all&limit=20&pattern=${encodeURIComponent(q)}`);
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

/** 帧时间轴：帧号、耗时、重建数都取自 /api/frames，页面只画不判。 */
async function loadFrames() {
  try {
    const page = await get('/api/frames?limit=120');
    state.frames = page.frames || [];
    renderFrames(state.frames);
  } catch (e) {
    document.getElementById('frameList').textContent = e.message;
  }
}

function renderFrames(rows) {
  const host = document.getElementById('frameList');
  host.textContent = '';
  document.getElementById('frameCount').textContent = rows.length ? `(${rows.length})` : '';
  const slow = Number(state.brief?.thresholds?.slowMs ?? 0);
  for (const r of rows) {
    const b = el('div', `frame${slow > 0 && Number(r.cost || 0) > slow ? ' slow' : ''}`);
    b.dataset.frame = String(r.id);
    b.appendChild(el('div', '', `#${r.id}`));
    b.appendChild(el('div', 'cost', `${Number(r.cost || 0).toFixed(1)}ms · ${r.rebuilt ?? 0} 重建`));
    b.title = `帧 #${r.id} · ${r.cost}ms · 重建 ${r.rebuilt} · 因果 ${r.causes}`;
    b.onclick = () => { setFollow(false); loadFrame(Number(r.id)); };
    host.appendChild(b);
  }
  markCurrentFrame();
  // 帧条重建后 DOM 位置全变了，网络条要跟着重贴。
  placeNetwork();
}

function markCurrentFrame() {
  for (const b of document.querySelectorAll('#frameList .frame')) {
    b.classList.toggle('current', b.dataset.frame === String(state.frame));
  }
}

/** 网络泳道：列表原样取自 /api/network，只把每帧的骨架画成横条。 */
async function loadNetwork() {
  try {
    const page = await get('/api/network?limit=100');
    state.network = page.rows || [];
    state.networkCaptured = page.window?.captured === true;
  } catch (e) {
    state.network = [];
    state.networkCaptured = false;
  }
  renderNetwork();
}

function renderNetwork() {
  const host = document.getElementById('netlane');
  if (!host) return;
  host.textContent = '';
  if (state.network.length === 0) {
    // "没开采集"与"开了但没请求"是两件事：前者不能让读者以为应用没发过请求。
    host.appendChild(el('div', 'empty',
      state.networkCaptured ? '这段证据没有请求' : '这份证据没有开网络采集'));
    return;
  }

  for (const r of state.network) {
    const failed = r.ok === false || Number(r.status || 0) >= 400;
    const bar = el('div', `req${failed ? ' failed' : ''}`);
    bar.dataset.request = String(r.request);
    bar.textContent = `${r.method || '?'} ${r.path || r.url || '?'}`;
    bar.title = `#${r.request} ${r.method || ''} ${r.url || ''} · ${r.status ?? '?'} · ${r.elapsedMs ?? '?'}ms`
      + ` · 帧 #${r.startedAtFrame ?? '?'}→#${r.settledAtFrame || '在飞'}`
      + (r.pendingFrames ? ` · 等 ${r.pendingFrames} 帧` : '');
    bar.onclick = () => showRequest(Number(r.request));
    host.appendChild(bar);
  }
  placeNetwork();
}

/**
 * 把请求条贴到帧条上。★ 用 DOM 实际位置而不是"每格 71px"：帧条宽度随帧号位数
 * 和内容变，写死格宽会让条尾和结算帧错开一格，看着像请求在别的帧落地。
 */
function placeNetwork() {
  const host = document.getElementById('netlane');
  if (!host) return;
  const cells = new Map();
  for (const b of document.querySelectorAll('#frameList .frame')) {
    cells.set(Number(b.dataset.frame), b);
  }
  for (const bar of host.querySelectorAll('.req')) {
    const r = state.network.find((one) => Number(one.request) === Number(bar.dataset.request));
    if (!r) continue;
    const a = cells.get(Number(r.startedAtFrame));
    const z = cells.get(Number(r.settledAtFrame));
    const left = a ? a.offsetLeft : (z ? z.offsetLeft : 0);
    const right = z ? z.offsetLeft + z.offsetWidth : (a ? a.offsetLeft + a.offsetWidth : 0);
    bar.style.left = `${left}px`;
    bar.style.width = `${Math.max(8, right - left)}px`;
    bar.classList.toggle('outside', !a || !z);
    if (z) z.classList.add('settled');
  }
  markActiveRequest();
}

function markActiveRequest() {
  for (const bar of document.querySelectorAll('#netlane .req')) {
    bar.classList.toggle('active', Number(bar.dataset.request) === state.activeRequest);
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
  markCurrentFrame();

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
  // 选中/悬停的祖先链要清晰：容器框平时退成辅助线，但点到某个后代时，
  // 它上面那串框是"这个控件从哪来"的答案，淡掉就看不出因果了。
  const selRow = state.boxes.find((r) => r.node === sel);
  const hoverRow = state.boxes.find((r) => r.node === hit);
  const keepChain = new Set([...(selRow?.chain || []), ...(hoverRow?.chain || [])]);

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
      // 容器框再淡一档 —— 一组全屏 Column/Card 叠起来会横贯整屏盖住文字。
      // 选中/悬停的祖先链不淡：那时候它们正好是要读的因果。
      const base = state.shotUrl ? 0.34 : 0.75;
      const containerAlpha = row.container && !keepChain.has(row.node) ? base * 0.35 : base;
      ctx.strokeStyle = withAlpha(colorOf(row.type), scale < 0.5 ? containerAlpha * 0.55 : containerAlpha);
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

function updateSelectionSource() {
  document.getElementById('selSource').textContent =
    state.selectionNote ? `（${state.selectionNote}）` : '';
}

/** 只改页面镜像。轮询从宿主同步时走这里 —— 回写宿主会形成两个方向互相追。 */
function showSelection(node, note) {
  state.selected = node;
  state.selectionNote = note || '';
  scheduleDraw();
  renderNode(node);
  updateSelectionSource();
}

async function loadCard(node) {
  if (!node) return;   // renderNode(0) 已经写了空态文案
  try {
    const card = await get(`/api/node?node=${node}&frame=${state.frame}`);
    renderCard(card);
  } catch (e) {
    document.getElementById('card').textContent = e.message;
  }
}

/** 页面点选落到真机高亮；失败时图上照选，但把原因写在节点标题旁。 */
async function writeHighlight(node) {
  if (!state.controllable) {
    state.selectionNote = '本地选中';
    updateSelectionSource();
    return;
  }
  state.writePending = true;
  try {
    const answer = await post('/api/ctl', { action: 'highlight', node });
    const h = answer.highlight || {};
    state.selectionNote = Number(h.node || 0) !== node
      ? '真机没能高亮'
      : (h.onScreen === true ? '真机已高亮' : '真机待上屏');
  } catch (e) {
    // 历史帧的节点可能已经从活树上卸掉：图上看得到，真机高亮不了。
    state.selectionNote = '真机不高亮（' + String(e.message).slice(0, 40) + '）';
  } finally {
    state.writePending = false;
  }
  updateSelectionSource();
}

async function select(node) {
  showSelection(node, '');
  await loadCard(node);
  await writeHighlight(node);
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

  const cons = card.constraints || {};
  host.appendChild(el('h3', '', '约束链'));
  if (cons.error || cons.found === false) {
    host.appendChild(el('div', 'none', cons.error || `查不到（${cons.reason || '?'}）`));
  } else if (!Array.isArray(cons.chain) || cons.chain.length === 0) {
    host.appendChild(el('div', 'none', '没有父级约束跳'));
  } else {
    for (const hop of cons.chain) {
      const line = el('div', 'hop', `#${hop.from} → #${hop.to} · ${hop.source || '?'}`);
      if (!hop.verified) line.appendChild(el('span', 'bad', ' · 未核对'));
      host.appendChild(line);
    }
    if (cons.cut) host.appendChild(el('div', 'none', `还有 ${cons.omitted ?? 0} 跳未展开`));
  }

  const why = card.why || {};
  const KIND = { state: '状态写入', inherited: '随父重建', none: '未记录' };
  host.appendChild(el('h3', '', '重建因果'));
  if (why.error) {
    host.appendChild(el('div', 'none', why.error));
  } else if (!why.rebuilt) {
    host.appendChild(el('div', 'none', `帧 #${why.frame ?? '?'} 没有重建它`));
  } else {
    for (const t of why.trail || []) {
      const cause = `${KIND[t.kind] || t.kind || '未记录'}${t.site ? ` @ ${t.site}` : ''}`;
      host.appendChild(el('div', 'cause', `${t.path || '?'} · ${cause}`));
    }
  }
}

function updatePickButton() {
  const btn = document.getElementById('pickbtn');
  btn.textContent = state.picking ? '拾取中…' : '拾取';
  btn.classList.toggle('on', state.picking);
}

async function stopPicking() {
  if (!state.picking) return;
  state.picking = false;
  updatePickButton();
  try {
    await post('/api/ctl', { action: 'pick', enabled: false });
  } catch (e) {
    // 宿主点完会自动撤防；再撤一次是幂等的，失败也不该让按钮卡在"拾取中"。
  }
}

async function act(action, btn) {
  if (action === 'pick') {
    if (state.picking) {
      await stopPicking();
      return;
    }
    btn.disabled = true;
    try {
      const answer = await post('/api/ctl', { action: 'pick', enabled: true });
      if (answer.armed !== true) {
        alert(answer.reason === 'paused' ? '先继续运行，再拾取' : '这个宿主没有可拾取的活窗口');
        return;
      }
      state.picking = true;
      state.pickBase = state.selected;
      state.pickUntil = Date.now() + 30000;
      updatePickButton();
    } catch (e) {
      alert(e.message);
    } finally {
      btn.disabled = false;
    }
    return;
  }

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

/** 宿主选中的轮询校正。真窗口拾取与别的出口改的高亮都从这里进页面。 */
async function pollSelection() {
  let data;
  try {
    data = await get('/api/highlight');
  } catch (e) {
    return;
  }

  if (typeof data.controllable === 'boolean') state.controllable = data.controllable;
  const node = Number((data.highlight || {}).node || 0);

  // 正在写高亮时宿主还来得及不更新，轮询不能把刚点中的选中又抹成旧的。
  if (!state.writePending && node !== state.selected) {
    showSelection(node, node ? '真机选中' : '');
    await loadCard(node);
  }
  if (state.picking && (node !== state.pickBase || Date.now() > state.pickUntil)) {
    state.picking = false;
    updatePickButton();
  }
}

let pollTick = 0;

/** 没有底图时说清是"这份证据本来就没有"还是"宿主还没拍到"，别让人以为坏了。 */
function updateNoShot(driven) {
  const why = document.getElementById('noshotWhy');
  const how = document.getElementById('noshotHow');
  if (!why || !how) return;
  why.textContent = driven ? '这一帧还没有画面' : '这份证据没有画面通道';
  how.textContent = driven
    ? '宿主未拍到新帧（画面通道未开，或应用静止）'
    : '离线包 / 只读会话只带几何与发现，节点与因果仍可查';
}

async function poll() {
  let driven = false;
  try {
    const st = await get('/api/state');
    const chip = document.getElementById('state');
    chip.textContent = st.state + (st.reason ? ` (${st.reason})` : '');
    chip.hidden = false;
    driven = st.driven === true;
    document.getElementById('ctl').hidden = !driven;

    // ★ 这里**不**按 `lastFrame` 刷几何。帧号是"主循环跑过几轮"，空闲的应用
    //   被调试桥叫醒也会涨（~80fps）—— 按它刷新等于每 500ms 全量重取一遍几何，
    //   而真的换了画面时 `pollShot()` 自然会带着新帧号来。几何跟着**画面**走。
    state.lastFrame = st.lastFrame;
  } catch (e) {
    document.getElementById('state').textContent = 'offline';
  }

  await pollShot();
  await pollSelection();
  updateNoShot(driven);
  // 帧时间轴增长慢，没必要跟着 500ms 的选中/画面轮询一起取；3 秒一次足够。
  if (++pollTick % 6 === 0) {
    await loadFrames();
    await loadNetwork();
  }
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
  await Promise.all([loadScript(), loadBrief(), loadFrames(), loadNetwork()]);
  await loadFrame(state.frame);
  await poll();
}

boot().catch((e) => {
  document.getElementById('label').textContent = `出错：${e.message}`;
});
