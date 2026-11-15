# P1-5 设计裁决：热更状态保留 —— 优雅重启而非同进程保活

日期：2026-11-15 ｜ 前置：`docs/REVIEW-CAPABILITY-2026-11-15.md` §5/§6/§7、
P0-3 已落地（`842a2d6`）、P0-1（`ad554bf`）、P0-2（`0d7bada`）

## 1. 先量当天 HEAD，不照旧清单

| 事实 | 落点 |
|---|---|
| §7 顺序图 P0-3 之后第一条分叉 | `P0-3 ─┬─→ P1-5 热更状态保留 / └─→ P1-1 Inspector` |
| §7 理由里另写「P1-6 成本最低、最该早做」 | 但顺序图把 P1-6 排在 P1-3/P1-4 之后 ⇒ **报告内部矛盾**，见 §2 |
| `bin/drift doctor` 仍 exit 2 | `bin/drift:47-74` 路由表无 `doctor`；`tools/doctor.php` 功能完整（有 `--json/--strict/--quiet/--scope`），只是没接线 |
| `Future::timeout()` 不存在 | `src/Async/Future.php` 无该方法；P1-6 的 timeout 是真缺口 |
| `tools/drift-dev.php:39-40` | 仍写「本框架无 Store/Cell，状态不应跨热更保留」⇒ **P0-3 后这句已失效，是当前 HEAD 上的自相矛盾** |
| dev 重启是强杀 | `drift-dev.php:216-220` `taskkill /IM dev.exe /F` |
| 业务状态唯一写盘点 | `WindowSet::run()` 的 `finally` → `RestorationStore::persist()`（`WindowSet.php:130-139`） |
| 窗口表空即收工 | `EventLoop.php:124-127` `$apps === [] ⇒ break` ⇒ `EventLoop::run()` 返回 ⇒ `finally` 必然执行 |
| dev-entry 走真组合根 | `apps/dev/dev-entry.php:18-24` require `apps/<app>/bin/win32/Bootstrap.php`；users 的 Bootstrap 已注入 `RestorationStore`（`:102-107`），路径 `Paths::userConfig($platform->configDir(), 'users.state')` |

**关键推论**：强杀 ⇒ `finally` 不跑 ⇒ dirty 状态（半截输入）没落盘；
但**只要让进程优雅退出**，`finally` 就会写盘，新进程构造 `RestorationStore` 即读回。
⇒ P1-5 可以**一行框架代码都不改**地闭合，只动 dev watcher 的进程生命周期。

## 2. 三条候选的多维裁决

| 维度 | P1-1 运行时 Inspector | P1-5 热更状态保留 | P1-6 CLI 收口 |
|---|---|---|---|
| **结构** | 要新建「运行中进程 ↔ 外部工具」通道；仓库里 HTTP/WS/socket 零痕迹，`tools/inspect.php` 已被 S4 因无人用删除。另有 `Snapshot::ofElement()/toTree()/diff()` 三个零消费者 API 与 `Application` 四个 devtools 预留 getter，说明「先造 API 后找消费者」在本仓已失败过 | 复用 P0-3 的 `RestorationStore` / `RestorationBucket`，不新增层、不新增协议、不新增写盘点 | 只在 `bin/drift` 路由表加表项；`Future::timeout()` 会碰 src 核心并触发 AOT 重编 |
| **实现** | 需同时做：服务层、constraints 导出、≥60 帧阶段时间线（现在只有累计/平均）、节点↔像素联动、选中高亮；新建多个类 ⇒ AOT 新类有 `0xC0000005` 历史风险 | 改 `stop()` 一处：优雅关闭优先 + 超时兜底；零 src 改动 ⇒ **零 AOT 重编** | 5 条路由 + 一个 `timeout()`；范围小但 `Future` 在核心路径 |
| **扩展性** | 做出来是通用观测底座，但形态未定（外部面板 vs 应用内浮层），先做哪边都要赌 | 与 P0-3 同一套 bucket；之后任何「重启后恢复」场景（崩溃重启、升级）都复用同一路径 | 路由表天然可扩展 |
| **DX** | 高，但 §4 明写「别再造第二个 `tools/inspect.php`：S4 已因无人用而删掉」 | 高：改一行 PHP 不再丢掉正在编辑的半截文字；`drift dev` 是每天用的工具 | 中：新人第一条命令不撞墙，但 **exit 2 是响亮的，不是「错了是静默的」** |
| **维护性** | 跨进程协议 + 前端长期维护面大 | 一个纯函数 + 一处接线；可用跨进程判据盯住 | 低 |

按报告 §5 自己沿用的 `DEBT §40.1` 三条判据（①有消费者 ②错了是静默的 ③决定能不能交付）：

- P1-5：① `drift dev` 每天用 ② 半截输入丢了不报错（静默）③ 否 ⇒ **两条**
- P1-1：① 写 UI 的开发者 ② 布局错了只能靠猜（静默）③ 否 ⇒ **两条**
- P1-6：① 新人 ② **否**（exit 2 立刻可见）③ 否 ⇒ **一条**

P1-1 与 P1-5 判据强度相同，按「消费者 × 依赖 × 成本」排序 ⇒ **P1-5 优先**：
它是 P0-3 的直接延伸、零新架构、零新建类，且先闭合一个框架内部矛盾。

### 2.1 订正上一轮的推荐

上一轮收尾时我推荐「下一棒从 P1-1 Inspector 开始」，**那是没量清楚就推荐的**：
当时没有核 `drift-dev.php` 的强杀路径与 `EventLoop` 的退出条件，也没核 §7 顺序图里
P1-5 画在 P1-1 之前。本轮重 grep 后改为 P1-5。这条订正写进本节，避免下一棒照旧话做。

### 2.2 顺带订正报告 §7 的内部矛盾

§7 理由写「P1-6 成本最低、最该早做」，顺序图却把 P1-6 排在 P1-3/P1-4 之后。
按 §5 的判据三条件，P1-6 只满足一条（错了不静默），**顺序图是对的，理由那句过满**。
本轮不改 §7 图，只在报告头部补一条订正说明；P1-6 留到 P1-3/P1-4 之后。

## 3. 成熟框架对照：借鉴什么，排什么坑

| 框架 | 可借鉴 | 别踩的坑 |
|---|---|---|
| Flutter hot reload | 保留 `State`、不重跑 `initState`，编辑中的输入与滚动位置不丢 | ⚠️ **Drift dev 是跨进程重启**（`drift-dev.php:39-40`：内嵌 ZendVM 对同路径 require 缓存 opcode，同进程重刷读不到新代码）⇒ 不存在可保活的 `State`，照抄会造出一个永远不生效的假机制 |
| Flutter `RestorationMixin` / Android 进程恢复 | 官方路径就是「进程被杀 → 从 bucket 重建」，而非同进程保活；`restorablePush` / `ScrollableState::restoreState` 都注册进同一 bucket | 别为热更单造第二套存档。P0-3 的 `RestorationBucket` 是业务状态内部唯一真相源，热更只是**多一个触发写盘的时机** |
| Vite HMR / webpack `hot` | full reload 前先 flush 客户端状态；「优雅关闭 → 再拉起」是标准动作 | 别用「每次 write 立刻落盘」换可靠性：那会造第二份写盘点，破坏 P0-3「`WindowSet::run()` 的 `finally` 是唯一写盘点」的裁决 |
| .NET / Electron 的 `before-quit` | 关窗信号先给应用，超时才 `kill -9`，保证清理钩子有机会跑 | `taskkill /F` 之类强杀在 Windows 上**不投递 WM_CLOSE**，`finally` / `register_shutdown_function` 全都不跑 ⇒ 这是本项目最容易出现的「看起来实现了、其实从没生效」 |

**借鉴定稿**：做「重启后恢复」而非「同进程保活」（§6 早已裁决），
用「优雅关闭 + 超时兜底」给 `finally` 一个执行机会，**不动数据面、不加写盘点、不加 IPC**。

## 4. 本轮落地范围

1. `tools/drift-dev.php::stop()` 改为「优雅关闭优先 → 轮询等待 → 超时才强杀」：
   - 生产接线保留既有的「按映像名 `dev.exe`」纪律（`:212-215` 实测 pid/running 不可靠，
     不改成按 pid）；只去掉 `/F`，并在超时后补一次 `/F`。
   - 抽出纯函数 `gracefulStop(callable $requestClose, callable $alive, callable $forceKill, int $timeoutMs): bool`，
     生产只在一处接线；判据用注入的假回调，**不与实现同源**。
   - `running()` 保持原语义（管道 EOF 兜底），只用于轮询。
2. `--hard-restart`：**跳过优雅退出**，语义是「丢弃本次会话的 dirty 状态」，
   不是删除磁盘上的旧状态（路径由组合根决定，watcher 再推一次就是第二份真相）。
   报告 §5 判据里「`--hard-restart` 才清空」这句过满，本轮按可实现语义订正。
3. 订正 `usage()` 与文件 docblock 里「无 Store/Cell，状态不应跨热更保留」的失效陈述，
   改成「跨进程重启 + 优雅退出时写回 bucket」。
4. 判据：跨进程行为判据 + 结构对账 + 变异自检，见 §5。

**零改动**：`src/`、组合根、`RestorationStore` / `RestorationBucket` / `WindowSet`、
`bin/drift`、所有 AOT 产物。⇒ 本轮不需要重编 users / showcase。

## 5. 判据设计（不与实现同源）

- **跨进程行为判据**：`proc_open` 起独立 PHP 子进程，子进程在
  `try { 循环等 stdin } finally { 写哨兵文件 }` 里。父进程调 `gracefulStop()`，
  注入 `requestClose` = 往子进程 stdin 写 `'q'`、`alive` = 查进程状态、
  `forceKill` = `proc_terminate`。断言：优雅路径哨兵文件存在（证明 `finally` 真跑了），
  直接强杀路径哨兵文件不存在。这证明的是「优雅退出让清理钩子有机会执行」，
  不是读 `stop()` 的配置字段，也不是同进程变量。
- **决策判据**：优雅成功 ⇒ `forceKill` 零调用；`alive` 恒 true ⇒ 超时后 `forceKill` 恰好一次；
  `requestClose` 必须被调用；超时必须有界。
- **结构对账**：`stop()` 生产接线里，优雅请求在前、强杀只在兜底分支、优雅请求不带 `/F`、
  且 `--hard-restart` 能跳过优雅分支。
- **变异自检**：至少 5 组 —— 改回直接 `/F`、去掉等待、超时无边、`requestClose` 不调用、
  `--hard-restart` 不生效；逐组真红并恢复复绿。
- **真机接缝**（`tools/probe-hotreload-p15.php`，只读、不抢前台）：
  `WindowStateStore::persist()` 每次关窗无条件写 `users.window`，
  所以「发 WM_CLOSE ⇒ 文件里有 `main.x=`」「发 `/F` ⇒ 文件为空」这一对判据
  直接证明 `EventLoop` 表空 break → `WindowSet::run()` 的 `finally` 真的跑了，
  而不只是在 PHP 子进程里模拟。每轮先清场，避免历史 dev.exe 替我们写文件。

## 6. 剩余边界（诚实记录）

- **不修 close guard 冲突**：`apps/showcase` 的 `onCloseRequested()` 在 `confirmClose`
  打开时会弹模态确认（`:177-187`）。优雅关闭会卡住 ⇒ 超时后回退强杀 ⇒ 该会话状态不保留。
  这是可接受的降级，写进使用说明；不为了它去改框架关窗语义。
- **不接滚动偏移 / 选中态**：P0-3 的边界已写明这两项只在 fixture 里有，真机接的是
  导航页 / 表单 / 搜索词三项。报告 §5 P1-5 判据写「滚动位置还在」比实际落地更满，
  本轮按真消费者口径收窄为「表单半截文字 + 导航页 + 搜索词」。
- **`--hard-restart` 不删除磁盘状态**：跨进程架构下「全量清空」需要框架配合识别
  一次性标志，属于零消费者的过度设计；本轮只做「不写回本次会话」。
- **P1-1 / P1-6 / 其余五条 P1 本轮未做**。P1-1 的前置条件见 §2：先定形态
  （外部面板 vs 应用内浮层）再动数据面，别照 §5 的 L 成本判据一次铺开。
- 交互真机探针 `probe-uia-state.py` 因桌面锁定 SKIP（码 2），按纪律不写成通过；
  接缝改用 `tools/probe-hotreload-p15.php` 的只读通道实测（优雅写盘 / 强杀不写盘
  2/2），不需要前台窗口。本轮零 `src/` 改动 ⇒ AOT 产物不受影响，未重编。
