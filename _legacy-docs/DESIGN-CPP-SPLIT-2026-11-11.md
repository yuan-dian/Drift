# 2026-11-11 · cpp-src 第二次拆分：按链接性切 ABI 实现段 + 注释清理

## 1. 复核：日志给的「下一棒」两条都已腐烂

| 日志（2026-11-11.md:103-108） | 代码真相 |
|---|---|
| `core/` 只有 `io_pool.h`，`CreateThreadpoolIo` 要下沉 | `core/io_pool.cc` 已是 C++20 `std::thread` + condition_variable，**零 `windows.h`**；唯一平台句 `WakeFn` 已下沉到 `win32/io_wake.cc`。这条从一开始就不成立 |
| `drift_win32.cc` 按「输入路由 / IME / 模态循环」能力边界拆 | 三段彼此确无互调，但都卡在**同一处**：`W:117–1658` 匿名 namespace 内共享 5 个 TU 级全局（`g_slots`/`g_liveWindows`/`g_closeGuarded`/`g_queue`/`g_wakePosted`）+ `EventKind`/`PendingEvent` 内部类型 + 640 行 `WndProc` switch。模态循环更是 `WndProc → PHP → present → WndProc` 的跨 ABI 回环，**切不开** |

结论：日志警告的「双向调用」不在三个候选段之间，而在 **WndProc / 消息泵 / PHP 回程** 与这些段之间。按能力名切会一上来就撞上 5 个全局 + 巨型 switch，正是它自己说的「拆完更难读」。

## 2. 裁决：按链接性切，不碰内部实现

主体有一条天然分界：`W:117` 开匿名 namespace、`W:1658` 关 ⇒ **前 1540 行内部链接、共享全部状态；后 1480 行是全局作用域的 `php_drift_*` ABI 入口**。后半段里「剪贴板」(`W:2801–2893`) 与「原生对话框」(`W:2894–3102`) 完全自包含：零共享状态、零回调、零 WndProc 依赖，只借两个纯字符串换算函数。

⇒ **本棒只切这两段（302 行）**：不新增全局状态抽象、不改 WndProc、不做 dispatcher、不动模态循环。
`toWide`/`toUtf8` 抽成 `string_conv.h/.cc` 是唯一必要的新契约（它们在匿名 namespace 里，跨 TU 不可见）。

「更优」在哪：切法从「按能力名判断」变成「按链接性 + 状态依赖机械判断」——
① 风险可判定（看有没有读写 `g_*`）；② 不预支 `shell_state.h` 那次大改；
③ 为后续「先收状态 → 再切输入/IME → 最后才动 WndProc」留出正确顺序。
对照 Chromium `ui/base`：平台能力按 ABI 入口分 TU，共享状态留在聚合实现里，不在没有边界的地方硬切。

## 3. 落地形态

新增（全部平铺 `cpp-src/win32/`，因 `build/base.yml:89` 只有一个 include 根）：

| 文件 | 来源 | 说明 |
|---|---|---|
| `string_conv.h` / `.cc` | `W:294–330` | 全局外部链接；主体 `using` 不变（匿名 namespace 内未限定名会向外围查） |
| `clipboard.cc` | `W:2801–2893` | 4 个 `php_drift_win_clipboard_*`；`user32.lib` 已在 link-libs |
| `dialogs.cc` | `W:2894–3102` | 5 个 ABI + 3 个 `static` helper；**必须自带 `#pragma comment(lib,"comdlg32.lib")`**（不在 link-libs） |

主体：删上述三段、加 `#include "string_conv.h"`、删 `comdlg32.lib` pragma（拖放用的 `shell32.lib` 保留）。
`abi-check` 递归收 `.cc` / `.stub.php`（上一棒已修）、指纹 `hashTree(..., '')` 自动感知新文件 ⇒ 构建配置零改动。

## 4. 注释清理（用户要求「删无用或过期」）

三类分开处理，**只动第二类**：

- **保留**：违反即静默出错的铁律（`W:104–110` extern 全局作用域 / LNK2019、`W:275–280` `MAKEINTRESOURCEW`、`W:637–649` ULW 四不变量、`W:176–181` 槽不 erase、`W:2415–2435` 闹钟复位点、`W:2116–2122` caret 无条件定位、DWM/ABI 字段顺序、位置纪律 C2601）。
- **删 / 改写（过期或复述）**：文件头 `W:4–17`（「只做四件事」「比 gui_win32.cc 小得多」「M1 不链 Skia」——现 3140 行、Skia 已接、能力远不止四件）、`W:9` stub 路径、`W:121` 单窗口理由、`W:112–115` 已删函数的战史、`W:871/1036` 单窗口叙述、`W:2083` **事实错误**（`单窗口进程：状态在进程级`，实际在 `slotOf(hwnd)`）、`W:2437`「IME M3 才接」、`W:2583`「3927 行」、`W:2903–2904`（`base.yml:76` 已有 `/utf-8`）、`W:1396` 外部仓行号、`W:2697–2701`/`W:3104–3118` 复述、重复 `#include <vector>`。
- **顺带修**：`win32/drift_win32.stub.php:7` 路径、`src/Platform/Win32/Win32Dialog.php:179` 复述的那条错话。

## 5. 判据（反同源 + 变异牙检）

主判据 **`abi-check` 的 `schemaHash` 逐字不变**：它只读 stub 与 `.cc` 里的 `php_drift_*` 签名，不读拆分逻辑 ⇒ 拆分不改 ABI 由外部契约证明。上一棒 `d2abi1:0765f2f7324d4dc7` 是基线。

新增 `tools/cpp-bridge-check.php`（gate `cppbridge` + `cppbridgeself`，26 → 28 项），锁三条本次暴露的结构约束：
1. `cpp-src/core/**` 零 `windows.h` / Win32 API ⇒ 平台边界；
2. 含 `GetOpenFileNameW`/`GetSaveFileNameW` 的 `.cc` 必须自带 `comdlg32.lib`、含 `DwmSetWindowAttribute` 必须自带 `dwmapi.lib` ⇒ 链接自洽（这两个库不在 `link-libs`，靠 pragma 随 TU 走；少带要到全量链接才 LNK2019）；
3. **T2 首轮实测补的**：ABI 入口 TU 必须引入 `php::*` 类型。

第 ③ 条是 T2 撞出来的真坑：`String` / `Int` / `Bool` 是 `php::*`，主体靠 `W:95` 的
`using namespace php;`，而 `string_conv.h` 在 `using` 生效之前被包含。首轮 T2 编译报
`dialogs.cc(30) C2065: filters 未声明` 等 30+ 条级联错误；`abi-check` / `docref` / lint
全绿也看不出来 —— **T1 判的是契约与文本，编译期类型可见性只有 AOT 能证**。
修法：头里用全限定 `php::String`，两个 ABI 入口 TU 各补 `using namespace php;`。

`--selftest` 合成源码变异 **6/6**（基线绿 + 4 条真红 + 注释不误报）；
真实磁盘变异 **5/5 真红**（`.workbuddy-ai/tmp/mutation-cpp-split.py`，`finally` 恢复）：
V1 对话框丢 pragma / V2 core 混入 `windows.h` / V3 主体丢 `dwmapi` pragma /
V4 `clipboard.cc` ABI 名漂移（由 `abi-check` 咬红）/ V5 ABI 入口 TU 漏 `using namespace php`。
改了没红先怀疑变异打空（§71/§73 各踩过）。

**判死条件**：`schemaHash` 变了 / 新 TU 编译或链接期报错 / 真机五判据任一 FAIL ⇒ 回滚该刀。

## 6. 验证（实测）

| 层 | 结果 |
|---|---|
| T0 | 4 个改动 PHP `php -l` 全过 |
| T1 | `abi-check` `stub=70 cc=70 fakes=51 schema=d2abi1:0765f2f7324d4dc7`（**逐字不变**）；`cpp-bridge-check` ok / selftest 6/6；docref **145 ok / 0 FAIL**；gate **28/28（149.2s）** / `--fast` **25/25（54.7s）** |
| T2 首轮 | ❌ 编译红（`php::*` 类型不可见）⇒ 修后重跑 |
| T2 二轮 | `users.exe` **13.6 MB / 223.9s / EXIT=0**；指纹 `1edd872e5d06` → `892a3a95efaa`（新 `.cc` 已入指纹） |
| 真机 | `probe-window` **0 FAIL（5 判据）**；`probe-uia --app users --title 用户管理 --dump` 语义树完整（Window/TitleBar/MenuBar/Button/Edit/List） |

**踩坑登记**：`drift-build --full` 会清空 `build/`，一次性脚本不能放那里（2026-11-11 日志已记过，本棒又踩一次）。
变异脚本改放 `.workbuddy-ai/tmp/`。`probe-uia` 默认等「Drift 示例台」标题，users 应用须显式 `--title 用户管理`。
