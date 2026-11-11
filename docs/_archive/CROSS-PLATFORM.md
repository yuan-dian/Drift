# 跨平台抽象设计（首版只落 Win32）

> 状态：**评审稿**（2026-10-20）。未改一行代码。
> ★ **后续**：本册的平台结论已被 `docs/design-v7/` 吸收（见其 [08 平台与能力矩阵](design-v7/08-平台与能力矩阵.md)）。
> 本册保留作**现状盘点与实测数据**的来源；设计以 design-v7 为准。
> 前提（用户 2026-10-20 裁决）：**目标是跨平台多端；第一个版本可以只实现 Windows，但抽象必须提前设计。**
> 权威：`DESIGN.md` §5.1（端口纪律）/ §6（DPI）/ §7（渲染）/ §8（文本）。本册只补"平台矩阵"这一维，不重复它们。

---

## 0. 一句话结论

**框架侧已经平台无关，可移植资产比预想多；真正要提前定的是 7 条会渗进语义的决策，不是"接口怎么拆"。**

实测依据（2026-10-20）：

| 层 | 实物 | 平台耦合 | 判定 |
|---|---|---|---|
| 框架 PHP | `src/` **198 文件** | `Win32` 仅出现在**注释**，零类型引用 | ✅ 直接可移植 |
| 确定性光栅 | `RasterCanvas`（纯 PHP 软光栅） | 0 | ✅ 天生可移植 |
| Skia 后端 | `cpp-src/skia/drift_skia.cc` **384 行** | **1 处**：`GetModuleFileNameA`（找 exe 旁 `fonts/`） | ⚠️ 一处就能清干净 |
| 平台端口 | `src/Platform/*.php` 9 接口 + `Win32/` 10 文件（**1501 行**） | 全部收敛在 `Win32/` 子目录 | ✅ 结构已经对了 |
| OS 壳 | `cpp-src/win32/drift_win32.cc` **1858 行 / 21 个桥函数** | 100% | ❗ 唯一真耦合 |
| 构建 | `tools/drift-build` | Win32 库列表硬编码 | ❗ 需按平台分册 |

⇒ 结论：**不需要为跨平台重做架构，只需要把"唯一真耦合"切成可替换的片，并把 7 条语义决策现在钉住。**

---

## 1. 端口契约总表（21 个桥函数 → 8 个关注点）

`drift_win32.cc` 的 1858 行实际混了 8 件事。按关注点切开，每块就是新平台要写的全部内容：

| # | 关注点 | 桥函数 | 每平台难度 | 估行数 | 备注 |
|---|---|---|---|---|---|
| 1 | **窗口生命周期** | `create` `show` `close` `set_title` `set_min_size` `set_cursor` `client_size` `is_maximized` `window_position` `place_window` | 中 | 400–600 | 平台差异最大的一块（Win32 `CreateWindowEx` / Cocoa `NSWindow` / X11 `XCreateWindow`） |
| 2 | **消息泵** | `pump` | 中 | 120–180 | 阻塞语义三条（`Platform.php` 已写死）：阻塞、归一化、`false`=退出 |
| 3 | **定时器** | `set_timer` `kill_timer` | 低 | 40–60 | macOS `CVDisplayLink`/`NSTimer`、Linux `timerfd` |
| 4 | **单调时钟** | `now_ms` | 低 | 10 | `GetTickCount64` / `mach_absolute_time` / `CLOCK_MONOTONIC` |
| 5 | **屏幕与主题** | `dpi` `system_dark` | 低 | 40–60 | macOS `backingScaleFactor`（几乎恒 2）、Linux `Xft.dpi` |
| 6 | **IME 定位** | `ime_caret` | 低 | 30–50 | 只需"把插入符矩形交给 IME"，见 §3.4 |
| 7 | **呈现** | `present` | 低 | 40–70 | 只做"整帧像素上屏"，见 §3.1 |
| 8 | **失败路径** | `message_box` | 低 | 10–20 | 最差写 stderr（`Platform.php` 已允许） |
| 9 | **系统目录** | `config_dir` | 低 | 10 | `%APPDATA%` / `~/Library/Application Support` / `$XDG_CONFIG_HOME` |
| 10 | **无障碍推送** | `uia_push` | **高** | **250–400** | UIA / NSAccessibility / AT-SPI，三套模型差异最大，见 §3.5 |

**每平台合计 ≈ 1000–1500 行 C++**（不含 Skia 静态库）。其中 8 个关注点各 <200 行，只有无障碍是硬骨头。

★ **反过来看更乐观**：`drift_skia.cc`（渲染）**零改动可复用**，`RasterCanvas` 零改动，框架 198 个 PHP 文件零改动。**新平台的成本几乎全在"窗口 + 无障碍"两件事上。**

---

## 2. 新平台接入清单（照单做，不靠猜）

1. **建 `src/Platform/<Os>/`**：9 个接口各写一个实现，`<Os>Platform` 实现 `Platform` 主接口。
2. **写 `cpp-src/<os>.cc` + `<os>.stub.php`**（成对同名，§5.1 纪律），覆盖上表 10 个关注点。
3. **组合根**：`apps/<app>/bin/<os>/Bootstrap.php` 里换一行平台类名 —— 这是**唯一**该出现平台名的共享位置。
4. **构建**：`build/<os>/platform.ini` 声明 libs / defines / 资源目录（见 §3.7）。
5. **字体**：`fonts/` 目录随包分发，走 `Platform::assetDir()`（见 §3.3）。
6. **验收三道闸**（顺序不能反）：
   - ① `RasterCanvas` 全量 smoke 在 `php.exe` 里过（不建窗、不碰 OS）；
   - ② 真机开窗 + 一帧像素（`present` 通）；
   - ③ 真机探针：滚动条拖动 / 拖动 resize / IME 候选框 / 无障碍树（对应 §5.2 / §8.4 / §9）。
7. **不得引入**：系统字体查询、平台原生控件、平台原生菜单（§5.1 / §8 已否）。
8. **可移植性守门**：`cpp-src/portable/*.cc` 里出现 `windows.h` / `Cocoa` / `X11` 直接判红（见 §4 留口动作 R2）。

---

## 3. 必须现在定的 7 条（后改代价高）

### D1 — 呈现路径：统一"离屏光栅 + 整帧上屏"，**不做 swapchain**

**现状**：`drift_skia.cc` 画到 `SkBitmap`（`MakeRasterDirectN32`），`read_pixels()` 取回 RGBA，`drift_win_present()` 一次贴屏。**这条路径本身就是跨平台的。**

**推荐**：**保持，并把它写成契约**——`RenderSurface` 已经是这个缝（`resize` / `beginFrame` / `endFrame`），未来要 GPU 只加新实现，不动框架。

**代价**：无 GPU 加速，大窗口每帧 CPU 读回 + 上屏（1920×1080 ≈ 8MB/帧）。**实测 4.5 vs 29.9ms/帧**（Raster vs Skia）说明当前瓶颈不在上屏。
**得失**：省掉三套 GPU 后端（Metal/D3D/Vulkan）；换来的是"每个平台都能跑"的下限。**这正是首版要的东西。**

---

### D2 — op 能力分级进 `Canvas` 契约（19 op 回植的跨平台版本）

**现状**：`docs/BACKPORT-19OP.md` 里 R2/R1/R3 三选一。跨平台要求让这个问题**从"取舍"变成"必须分级"**：任何新平台的 Raster 后端都不可能全量实现 19 op。

**推荐**：分三层，写进 `Canvas` 契约：

| 层 | 内容 | 要求 |
|---|---|---|
| **Core** | RECT / ROUND_RECT / ELLIPSE / LINE / TEXT / GLYPH_RUN / SOLID / STROKE / DRAW_CHILD / CLIP_RECT | **所有后端必须实现**，缺一个就是构建期错误 |
| **Extended** | LINEAR / RADIAL / DASH / CLIP_PATH | Skia 级后端必须；Raster 可降级为近似（渐变→分带、虚线→实线） |
| **Optional** | PATH / IMAGE / LAYER / SHADOW | 仅 Skia 级后端；Raster 报 `E-OP-UNSUPPORTED` |

**机制**：`Canvas` 加 **`capabilities(): int`**（位掩码），**构建期查一次**（`CustomPaint` 拿到画布时），不在每次绘制时查。

**代价**：多一个接口方法（所有实现要加，但可以先给默认全支持）。
**得失**：换来"新平台知道底线在哪" + "CustomPaint 能降级" + "golden 测试可按层跳过"。**不做这条，19 op 会在每个平台上重新吵一遍。**

---

### D3 — 文本度量**永不外包**给平台 shaper（现在就得锁死）

**现状**：`drift_skia.cc` 头注释已写死——**逐字形绘制**，不用 `SkParagraph`/`SkUnicode`/ICU；字体走目录字体（`cpp/fonts/NotoSansSC-*.ttf`）；`SkFontMgr_directory` 只认那一个目录。

**★ 这是跨平台确定性的唯一解**：一旦让 Skia 用系统 `SkFontMgr`，macOS 上度量换成 CoreText、Linux 上换 FontConfig，**断行/光标/命中三处同时漂移**。

**推荐**：把三条写成不可协商的纪律：
1. 禁止任何 `SkFontMgr::create()`（系统字体管理器），只准 `SkFontMgr_New_Custom_Directory`；
2. 禁止 `SkParagraph`（那是 HarfBuzz + ICU 的另一套度量）；
3. 字体的"找哪"由 `Platform::assetDir()` 回答，**不靠 cwd、不靠环境变量**。

**代价**：多语言支持靠自己实现回退链（§8 已知缺口）。
**得失**：换来三平台像素级一致（golden 可以跨平台跑）；代价是"字体覆盖"要自己管。

---

### D4 — `Platform::assetDir()` 补进主接口（清掉 Skia 后端的唯一耦合）

**现状**：`drift_skia.cc` 的 `GetModuleFileNameA` 只为找 exe 旁的 `fonts/`。

**推荐**：加 `Platform::assetDir(): string`（exe/包内资源目录），由壳回答。与 `configDir()` 同类——"每个平台必然有"，"不知道"用空串。

**代价**：主接口 +1 方法，9 个实现各加一行。
**得失**：`drift_skia.cc` 变成**真正的零平台代码**（可 grep 断言守住）；否则它永远带着一处 Win32。

---

### D5 — 无障碍：语义树是唯一可移植中间层，**平台侧只做"吃同一个扁平数组"**

**现状**：`WindowHost::pushSemantics(array $flatNodes)` 已是缝；UIA 的 300 行在 `drift_win32.cc` 里。

**★ 关键洞察（决定抽象形状）**：
- **Win32 UIA 是"推"**（我们把树推过去，OS 回调来问）；
- **macOS NSAccessibility 是"拉"**（OS 主动问"X 的子节点是什么"）；
- **Linux AT-SPI 也是"拉"**，且走 D-Bus、通常要独立线程。

⇒ **扁平快照数组同时满足两者**：推的用 push 触发失效，拉的按索引回答查询。**现有形状已经对了，不要改。**

**推荐**：不加新端口（`pushSemantics` 留在 `WindowHost`，语义天然是每窗口的），只把契约写清：
1. 数组是**前序扁平**，含 `parent` 索引与几何（现状已是）；
2. 平台侧**必须保留快照**（拉模型要它，推模型也要它算 diff）；
3. 平台侧**不得**回调进 PHP（现状纪律，保住）；
4. 事件三件套按平台能力降级：结构变化 / 焦点变化 / 属性变化（§15 三档）。

**代价**：Linux 那条（AT-SPI）是唯一可能需要额外线程的地方，**明确排在最后**。
**得失**：不新增接口；代价是每个平台写 250–400 行。

---

### D6 — 指针事件：现在加 `device` 字段（为触摸/笔留口，零破坏）

**现状**：`PointerEvent` 有 `x/y/button/phase`，**无设备类型**。桌面鼠标够用；触摸端会把 `hover`/`right button` 语义搞错。

**推荐**：加 `int $device = 0`（0=鼠标 1=触摸 2=笔），**带默认值 ⇒ 现有构造点零改动**；同时写一条纪律：**框架内不得出现"这是不是鼠标"的判断**（要用就问 `device`）。

**代价**：一个 readonly 参数。
**得失**：现在加几乎免费；以后加要动所有构造点 + 所有夹具。**触摸端一旦上线，"hover 在触摸上不该存在"这类 bug 会成片出现。**

---

### D7 — 构建：`build/<os>/platform.ini`，ZTS 留参数位

**现状**：`tools/drift-build` 里 Win32 库列表硬编码（`comctl32` / `uiautomationcore` / `oleaut32` …）。

**推荐**：
1. `build/<os>/platform.ini` 声明 `libs` / `defines` / `subsystem` / `assetDir`，构建脚本按平台读；
2. **留 `--zts` 参数位**（现在不实现）：上游 `13cf7175` 让 `Swoole\Thread` 可用，但**仅 ZTS**，而 dist 的 `php.exe` 是 ZTS、我们的 `tpc` 构建链需确认（V-7 待实测）。留位是为了将来 `Async::thread` 不需要重写构建。

**代价**：构建脚本改结构（一次）。
**得失**：不做的话，第二个平台就要复制粘贴整个构建脚本 —— 那是最容易腐烂的代码。

---

## 4. 首版 Win32 的"留口"动作（不改行为）

按风险从低到高。**前三条建议随 P0 一起做**，后两条等第二个平台真开工再动。

| # | 动作 | 风险 | 说明 |
|---|---|---|---|
| **R1** | 文档：本册 + `DESIGN.md` §5.1 补一张"平台矩阵"表 | 零 | 只加引用 |
| **R2** | `cpp-src/portable/` 目录 + **grep 断言**（不得出现 `windows.h`/`Cocoa`/`X11`） | 低 | 把 `drift_skia.cc` 挪进去，加一条构建期检查 |
| **R3** | `Canvas::capabilities()` 加默认实现（返回全支持） | 低 | 不动任何调用点，先把方法立起来 |
| **R4** | `PointerEvent` 加 `device = 0` | 低 | 默认值保证零破坏；改完跑 smoke |
| **R5** | `Platform::assetDir()` + `drift_skia.cc` 去 `GetModuleFileNameA` | **中** | 要改 9 个实现 + 一处 C++；**必须 T2 真机构建验证**（字体路径一错就是"界面全白、布局全对"） |
| **R6** | `drift_win32.cc` 按 8 个关注点**物理拆分**（同文件多 `#include`） | **中** | 1858 行拆成 8 个文件；纯搬运但**必须 T2**（`WM_SIZE` 钩子/`extern` 作用域极易在拆分时踩坏） |

★ **R6 可以不做**：拆文件是"给人看的"，不是"给编译器看的"。若第二个平台短期不开工，**先不拆**——1858 行虽长，但注释密度高、分区清晰，拆分风险大于收益。

---

## 5. 明确"不做"（划掉，防止反复讨论）

| 项 | 理由 |
|---|---|
| **GPU swapchain / Metal / D3D / Vulkan** | D1：离屏光栅 + 上屏已够首版；接口留了口 |
| **平台原生控件 / 原生菜单栏** | §5.1 / §18 已否（风格割裂 + 违反"不外包渲染"） |
| **HarfBuzz / ICU / SkParagraph** | D3：度量必须归 Drift |
| **系统字体查询** | D3：确定性来自"不查系统字体" |
| **多窗口**（一个进程多窗口） | 当前 `Win32WindowHost` 是单窗口模型；多窗口要 `Application` 实例化，另立项 |
| **移动端** | 见 §6：不是"再实现一次 Platform"，而是换实现 + 接受空桩 |
| **Linux AT-SPI** | 排最后：唯一需要独立线程/事件循环的平台适配 |

---

## 6. "多端"里的移动端：一句话说清

移动端**不需要新抽象**，只需要一个新 `Platform` 实现，因为框架只通过端口问 OS：

- `window()->createWindow()` 在移动端返回固定句柄 `1`，`show/close/setTitle/setMinSize` 空操作；
- `clientSize` 从 `Screen::viewport()` 推；
- `pump()` 变成"等一帧 vsync"（不阻塞到有消息）；
- 指针事件 `device = 1`（D6 现在就留口）。

★ **唯一真正的语义缺口是"安全区"**（刘海/圆角/手势条）：`Screen` 需要 `safeInsets()`。**这条建议等真要上移动端再加**——现在加会逼所有实现返回 0，属于"未被消费的分支"（记忆纪律：未被消费的分支等价于未实现）。

---

## 7. 工作量与顺序（估算，±50%）

| 阶段 | 内容 | 估时 |
|---|---|---|
| **P0** | R1 + R2 + R3 + R4（留口，不改行为） | 0.5–1 天 |
| **P1** | 19 op Core 层（双后端）+ CustomPaint | 3–5 天 |
| **P2** | 19 op Extended 层（渐变/虚线） | 3–5 天 |
| **P3** | 19 op Optional 层（仅 Skia：路径/图像/图层/阴影） | 1–2 周 |
| **V2** | macOS 端口（窗口+泵+呈现+IME+NSAccessibility） | 1.5–2.5 周 |
| **V3** | Linux 端口（X11 优先；AT-SPI 单独排） | 2–3 周 |
| **V4** | 移动端桩 | 1–2 周（前提：先做 D6 + 安全区） |

★ **建议顺序**：P0 → P1 → **V2（macOS）** → P2 → V3。理由：**第二个平台越早接入，抽象漏洞越早暴露**；等 P3 做完再移植，等于拿 19 op 全量去撞一个没验证过的抽象。

---

## 8. 待裁决

1. **D1–D7 是否全部采纳**？特别是 **D2（op 三层分级）** 和 **D6（`PointerEvent` 加 `device`）** —— 这两条会改公开 API 形状。
2. **R1–R4 是否现在做**（0.5–1 天，不改行为）？R5/R6 是否推迟？
3. **19 op 走 R2（核心双后端 + 高级仅 Skia）确认**？跨平台要求下 R1（Raster 全量）成本从 4–8 周涨到 **×平台数**，不建议。
4. **V2（macOS）排在 P2 之前**是否同意？这是"早暴露抽象漏洞"与"先把表达力做全"的取舍。
