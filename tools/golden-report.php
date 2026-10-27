<?php
declare(strict_types=1);

/**
 * `golden-report.php` —— S3：把 `--check` 的失败现场渲染成**自包含 HTML 报告**。
 *
 * 这是"检查器"的**呈现层**（S1 数据 / S2 失败定位 / S3 呈现 / S4 交互式是同一观测模型
 * 的四段演进）。测量与呈现分离：`golden-raster.php --check` 只产像素 + 快照 +
 * 一份机器可读的 `golden-manifest.json`（契约）；本脚本读它，不重复任何测量。
 *
 * ```bash
 * php tools/golden-raster.php --check --pages        # 先量（顺手写 manifest）
 * php tools/golden-report.php                        # 再出 HTML（默认 _build/golden-report.html）
 * php tools/golden-report.php --manifest=<f> --out=<f>
 * ```
 *
 * ★ 自包含：CSS/JS 全内联、PNG 转 data URI，`file://` 直接双击打开，无服务、无外部依赖。
 * ★ 节点↔像素闭环（DevTools 的本质）：点 inspect 树里的节点 ⇒ 三张图（expected/actual/diff）
 *   上同时叠加该节点的**绝对矩形**（蓝），并常驻差异包围盒（红）。"哪个节点"与"像素上哪里"
 *   这一次彻底接上 —— 关掉"开图用眼睛找"和"读文字清单对坐标"两步。
 *
 * ★ 兼容性取舍（用户授权"可适当破坏"）：manifest 是新文件，老脚本不读它，文本输出未变；
 *   故无破坏性。呈现层可独立迭代（换皮肤/加交互不碰量具）。
 *
 * ★ 诚实边界：golden 基线只存像素字节、不含"基线那版的树"，本报告不存 expected 快照树；
 *   actual 树 = 当前代码所画，配像素 diff 已足够诊断（开发者刚改的就是代码）。
 */

require __DIR__ . '/autoload.php';

/** @return array<string,string> */
function reportArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        if ($eq === false) {
            $out[$body] = '1';
        } else {
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
    }
    return $out;
}

$args = reportArgs($argv);
$manifestPath = $args['manifest'] ?? dirname(__DIR__) . '/_build/golden-manifest.json';
$outPath = $args['out'] ?? dirname(__DIR__) . '/_build/golden-report.html';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "没有 manifest：{$manifestPath}（先跑 php tools/golden-raster.php --check）\n");
    exit(2);
}

/** @var array<string,mixed>|null $meta */
$meta = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($meta) || !isset($meta['scenes']) || !is_array($meta['scenes'])) {
    fwrite(STDERR, "manifest 不是合法结构：{$manifestPath}\n");
    exit(2);
}

$goldenRoot = (string) ($meta['goldenRoot'] ?? dirname(__DIR__) . '/golden');
$W = (int) ($meta['w'] ?? 900);
$H = (int) ($meta['h'] ?? 640);

/**
 * RGBA → PNG（与 golden-raster 的 goldenPng 同口径：白底不透明丢 alpha）。
 * 只用于把 `.rgba.gz` 基线还原成图（actual/diff 直接读现成 PNG，不必走这里）。
 */
function reportPng(string $rgba, int $w, int $h): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00";
        $base = $y * $w * 4;
        for ($x = 0; $x < $w; $x++) {
            $o = $base + $x * 4;
            $raw .= $rgba[$o] . $rgba[$o + 1] . $rgba[$o + 2];
        }
    }
    $chunk = static function (string $type, string $body): string {
        return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
    };
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 6))
        . $chunk('IEND', '');
}

$uri = static function (string $bytes): string {
    return 'data:image/png;base64,' . base64_encode($bytes);
};

$pngFromGz = static function (string $gzPath) use ($uri, $W, $H): ?string {
    if (!is_file($gzPath)) {
        return null;
    }
    $rgba = gzdecode((string) file_get_contents($gzPath));
    if ($rgba === false || strlen($rgba) !== $W * $H * 4) {
        return null;
    }
    return $uri(reportPng($rgba, $W, $H));
};

$pngFromFile = static function (string $pngPath) use ($uri): ?string {
    if (!is_file($pngPath)) {
        return null;
    }
    $bytes = file_get_contents($pngPath);
    return $bytes === false ? null : $uri((string) $bytes);
};

// ── 组装 payload：scenes 带上 data URI 与 inspect 树 ──
$scenes = [];
$failCount = 0;
foreach ($meta['scenes'] as $s) {
    if (!is_array($s)) {
        continue;
    }
    $pass = (bool) ($s['pass'] ?? false);
    if (!$pass) {
        $failCount++;
    }
    $entry = [
        'name' => (string) ($s['name'] ?? '?'),
        'kind' => (string) ($s['kind'] ?? 'canvas'),
        'pass' => $pass,
        'reason' => $s['reason'] ?? null,
        'diffPixels' => $s['diffPixels'] ?? null,
        'pct' => $s['pct'] ?? null,
        'bbox' => $s['bbox'] ?? null,
        'bands' => $s['bands'] ?? [],
        'images' => null,
        'tree' => null,
    ];

    if (!$pass && isset($s['artifacts']) && is_array($s['artifacts'])) {
        $a = $s['artifacts'];
        $images = [
            'expected' => $pngFromGz($goldenRoot . '/' . ($a['expectedGz'] ?? '')),
            'actual' => $pngFromFile($goldenRoot . '/' . ($a['actualPng'] ?? ''))
                ?? $pngFromGz($goldenRoot . '/' . dirname($a['actualPng'] ?? '') . '/actual.rgba.gz'),
            'diff' => $pngFromFile($goldenRoot . '/' . ($a['diffPng'] ?? ''))
                ?? $pngFromGz($goldenRoot . '/' . dirname($a['diffPng'] ?? '') . '/diff.rgba.gz'),
        ];
        $entry['images'] = $images;

        if (isset($a['inspectJson'])) {
            $ij = $goldenRoot . '/' . $a['inspectJson'];
            if (is_file($ij)) {
                $snap = json_decode((string) file_get_contents($ij), true);
                if (is_array($snap)) {
                    $entry['tree'] = [
                        'elements' => $snap['trees']['elements'] ?? [],
                        'boxes' => $snap['trees']['boxes'] ?? [],
                    ];
                }
            }
        }
    }
    $scenes[] = $entry;
}

$payload = ['w' => $W, 'h' => $H, 'scenes' => $scenes];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$json = str_replace('</', '<\\/', $json); // 防止 </script> 提前终止

$total = count($scenes);

// ── 自包含 HTML（静态骨架 + 内联 JS 从 DATA 渲染）──
$html = <<<HTML
<!doctype html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Golden 报告</title>
<style>
:root { --bg:#fafafa; --panel:#fff; --line:#e3e3e6; --ink:#1c1c1e; --mut:#6b6b70;
        --ok:#1a7f37; --bad:#cf222e; --accent:#0969da; --node:#0969da; --diff:#cf222e; }
* { box-sizing:border-box; }
body { margin:0; font:14px/1.5 -apple-system,"Segoe UI",system-ui,"PingFang SC","Microsoft YaHei",sans-serif;
       background:var(--bg); color:var(--ink); }
header { padding:18px 24px; background:var(--panel); border-bottom:1px solid var(--line); }
header h1 { margin:0 0 4px; font-size:18px; }
.sub { color:var(--mut); font-size:13px; }
table.summ { border-collapse:collapse; margin-top:12px; font-size:13px; }
table.summ th, table.summ td { border:1px solid var(--line); padding:4px 10px; text-align:left; }
.badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:12px; font-weight:600; }
.badge.ok { background:#e6f4ea; color:var(--ok); }
.badge.bad { background:#fce8e8; color:var(--bad); }
.badge.can { background:#eef0f2; color:var(--mut); }
main { padding:20px 24px; }
section.fail { background:var(--panel); border:1px solid var(--line); border-radius:10px;
               padding:16px 18px; margin-bottom:22px; }
section.fail h2 { margin:0 0 6px; font-size:15px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
.stats { color:var(--mut); font-size:13px; margin-bottom:12px; }
.figs { display:flex; gap:14px; flex-wrap:wrap; }
.fig { flex:1 1 280px; max-width:340px; }
.fig figcaption { font-size:12px; color:var(--mut); margin-bottom:4px; }
.fig .stage { position:relative; width:100%; aspect-ratio:{$W}/{$H}; border:1px solid var(--line);
              border-radius:6px; overflow:hidden; background:#fff; }
.fig .stage svg { position:absolute; inset:0; width:100%; height:100%; }
.layout { display:flex; gap:18px; margin-top:14px; flex-wrap:wrap; }
.tree { flex:1 1 320px; min-width:280px; border:1px solid var(--line); border-radius:8px;
        background:#fff; max-height:420px; overflow:auto; padding:6px 0; }
.tree .row { padding:1px 10px; white-space:nowrap; cursor:pointer; font-family:ui-monospace,Menlo,monospace;
             font-size:12px; border-left:2px solid transparent; }
.tree .row:hover { background:#f0f4ff; }
.tree .row.sel { background:#e6f0ff; border-left-color:var(--node); }
.tree .row .cls { color:var(--accent); }
.tree .row .geo { color:var(--mut); }
.tree .row.hidden { display:none; }
.props { flex:1 1 280px; min-width:240px; border:1px solid var(--line); border-radius:8px;
         background:#fff; padding:10px 14px; font-size:13px; }
.props h3 { margin:0 0 8px; font-size:13px; color:var(--mut); font-weight:600; }
.props dl { display:grid; grid-template-columns:auto 1fr; gap:4px 12px; margin:0; }
.props dt { color:var(--mut); }
.props dd { margin:0; font-family:ui-monospace,Menlo,monospace; }
.empty { color:var(--mut); padding:40px; text-align:center; }
.legend { font-size:12px; color:var(--mut); margin-top:8px; }
.legend b.d { color:var(--diff); } .legend b.n { color:var(--node); }
</style>
</head>
<body>
<header>
  <h1>Golden 回归报告</h1>
  <div class="sub" id="sub"></div>
  <table class="summ" id="summ"></table>
</header>
<main id="main"></main>
<script>
const DATA = {$json};
const W = DATA.w, H = DATA.h;
const SVGNS = "http://www.w3.org/2000/svg";

function fmtRect(r){
  if(!r || r[0]===null) return "(无盒)";
  return "("+r[0].toFixed(0)+","+r[1].toFixed(0)+" "+r[2].toFixed(0)+"×"+r[3].toFixed(0)+")";
}

function buildSummary(){
  const total = DATA.scenes.length;
  const fail = DATA.scenes.filter(s=>!s.pass).length;
  document.getElementById("sub").textContent =
    total+" 个场景 · "+fail+" 失败 · "+(fail===0?"全部通过 ✅":"见下方失败现场");
  const rows = DATA.scenes.map(s=>{
    let b = s.pass ? '<span class="badge ok">OK</span>'
                    : (s.reason ? '<span class="badge can">'+s.reason+'</span>'
                                : '<span class="badge bad">FAIL</span>');
    return "<tr><td>"+b+"</td><td><code>"+s.name+"</code></td><td>"+s.kind+"</td></tr>";
  }).join("");
  document.getElementById("summ").innerHTML =
    "<tr><th>状态</th><th>场景</th><th>类型</th></tr>"+rows;
}

function buildFigure(label, uri){
  const stage = document.createElement("div");
  stage.className = "stage";
  const img = document.createElementNS(SVGNS,"svg");
  img.setAttribute("viewBox","0 0 "+W+" "+H);
  img.setAttribute("preserveAspectRatio","none");
  const image = document.createElementNS(SVGNS,"image");
  image.setAttribute("x",0); image.setAttribute("y",0);
  image.setAttribute("width",W); image.setAttribute("height",H);
  if(uri) image.setAttribute("href",uri);
  img.appendChild(image);
  const ov = document.createElementNS(SVGNS,"svg");
  ov.setAttribute("viewBox","0 0 "+W+" "+H);
  ov.setAttribute("preserveAspectRatio","none");
  ov.classList.add("ov");
  stage.appendChild(img); stage.appendChild(ov);
  const fig = document.createElement("figure");
  fig.style.margin = "0";
  const cap = document.createElement("figcaption");
  cap.textContent = label;
  fig.appendChild(cap); fig.appendChild(stage);
  return {fig, ov};
}

function drawRect(ov, x, y, w, h, color, fill){
  const r = document.createElementNS(SVGNS,"rect");
  r.setAttribute("x",x); r.setAttribute("y",y);
  r.setAttribute("width",w); r.setAttribute("height",h);
  r.setAttribute("fill", fill || "none");
  r.setAttribute("stroke", color);
  r.setAttribute("stroke-width", 2);
  ov.appendChild(r);
}

function highlight(section, node){
  const ovs = section._ovs;
  ovs.forEach(ov=>{ ov.innerHTML=""; if(section.bbox) drawRect(ov,section.bbox[0],section.bbox[1],
      section.bbox[2]-section.bbox[0], section.bbox[3]-section.bbox[1], "#cf222e", "rgba(207,34,46,0.06)"); });
  if(node && node.r && node.r[0]!==null){
    const r = node.r;
    ovs.forEach(ov=> drawRect(ov, r[0], r[1], r[2], r[3], "#0969da", "rgba(9,105,218,0.10)"));
  }
}

function buildTree(section){
  const wrap = document.createElement("div");
  wrap.className = "tree";
  const els = (section.tree && section.tree.elements) || [];
  els.forEach(n=>{
    const row = document.createElement("div");
    row.className = "row";
    row.dataset.path = n.p;
    row.dataset.depth = n.d;
    const pad = (parseInt(n.d,10)||0)*14;
    row.style.paddingLeft = (10+pad)+"px";
    const box = n.b && n.b!==n.w ? ' → '+n.b : '';
    row.innerHTML = '<span class="cls">'+n.w+'</span>'+box+' <span class="geo">'+fmtRect(n.r)+'</span>';
    row.addEventListener("click", ()=>{
      document.querySelectorAll(".tree .row.sel").forEach(r=>r.classList.remove("sel"));
      row.classList.add("sel");
      highlight(section, n);
      showProps(section, n);
    });
    row.addEventListener("dblclick", ()=> toggleCollapse(wrap, n.p, parseInt(n.d,10)));
    wrap.appendChild(row);
  });
  if(els.length===0){
    const p = document.createElement("div");
    p.className="row"; p.style.color="#6b6b70";
    p.textContent="（该场景无元素树）";
    wrap.appendChild(p);
  }
  return wrap;
}

function toggleCollapse(wrap, path, depth){
  let hide = null;
  [...wrap.children].forEach(row=>{
    if(row.dataset.path===path){ hide = !row._collapsed; row._collapsed = hide;
      row.style.fontWeight = hide ? "700" : "400"; return; }
    if(hide && row.dataset.path && row.dataset.path.startsWith(path+".")){
      row.classList.toggle("hidden", hide);
    }
  });
}

function showProps(section, n){
  const p = section._props;
  p.innerHTML = "<h3>节点属性</h3><dl>"
    + "<dt>路径</dt><dd>"+n.p+"</dd>"
    + "<dt>控件</dt><dd>"+n.w+"</dd>"
    + "<dt>盒</dt><dd>"+(n.b||"(无)")+"</dd>"
    + "<dt>绝对矩形</dt><dd>"+fmtRect(n.r)+"</dd></dl>";
}

function buildFailSection(s, idx){
  const sec = document.createElement("section");
  sec.className = "fail";
  sec._ovs = [];
  const h = document.createElement("h2");
  h.textContent = s.name;
  sec.appendChild(h);
  const st = document.createElement("div");
  st.className = "stats";
  const bands = (s.bands||[]).map(b=>"y="+b[0]+".."+b[1]+"("+b[2]+"px)").join(" ");
  st.textContent = (s.diffPixels!=null ? s.diffPixels+" 像素差（占 "+(s.pct*100).toFixed(2)+"%）；" : "")
    + "差异框 x="+s.bbox[0]+".."+s.bbox[2]+" y="+s.bbox[1]+".."+s.bbox[3]
    + (bands? "；条带 "+bands : "");
  sec.appendChild(st);

  const figs = document.createElement("div");
  figs.className = "figs";
  ["expected","actual","diff"].forEach(k=>{
    const f = buildFigure(k, s.images ? s.images[k] : null);
    sec._ovs.push(f.ov);
    figs.appendChild(f.fig);
  });
  sec.appendChild(figs);

  const leg = document.createElement("div");
  leg.className = "legend";
  leg.innerHTML = "图例：<b class='d'>红框</b>=像素差异区域（常驻） · <b class='n'>蓝框</b>=选中节点 · 单击节点看属性，双击折叠子树";
  sec.appendChild(leg);

  const layout = document.createElement("div");
  layout.className = "layout";
  const tree = buildTree(sec);
  const props = document.createElement("div");
  props.className = "props";
  props.innerHTML = "<h3>节点属性</h3><div style='color:#6b6b70'>点左侧树的节点查看</div>";
  sec._props = props;
  layout.appendChild(tree);
  layout.appendChild(props);
  sec.appendChild(layout);

  // 初始：画出差异框（无节点选中）
  highlight(sec, null);
  return sec;
}

function build(){
  buildSummary();
  const main = document.getElementById("main");
  const fails = DATA.scenes.filter(s=>!s.pass);
  if(fails.length===0){
    const e = document.createElement("div");
    e.className = "empty";
    e.textContent = "✅ 全部场景通过，无失败现场。";
    main.appendChild(e);
    return;
  }
  fails.forEach((s,i)=> main.appendChild(buildFailSection(s,i)));
}
build();
</script>
</body>
</html>
HTML;

$outDir = dirname($outPath);
if ($outDir !== '' && !is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "建不了目录：{$outDir}\n");
    exit(2);
}
if (file_put_contents($outPath, $html) === false) {
    fwrite(STDERR, "写不了：{$outPath}\n");
    exit(2);
}
echo "report {$outPath}（{$total} 场景，{$failCount} 失败）\n";
exit(0);
