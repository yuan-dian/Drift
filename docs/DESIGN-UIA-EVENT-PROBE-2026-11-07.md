# §70 方案：UIA 客户端事件判据 —— 把 §66 已交付的 C++ 抬到「敢用」

> 2026-11-07 · 前置：`docs/DEBT-2026-11-02.md` §66.5 / §66.6、`docs/ANALYSIS-watermark-async3-borrow-2026-11-07.md` §10。
> 本棒不改产品语义、不加框架能力，只补**判据**与**判据可复用形状**。

## 0. 结论先行

§66 交付的 C++ 在 `driftUiaApply()`（`cpp-src/win32/uia.cc:838-891`；2026-11-11 拆分前在
`drift_win32.cc` 第 3850–3872 行）里做节点 diff，并在「表格行被选中」时
raise `UIA_SelectionItem_ElementSelectedEventId`（raise 调用在 878-890 行），但 `tools/probe-uia-state.py` ⑨–⑫ 只量
**属性**与**动作结果**：把 `UiaRaiseAutomationEvent` 注释掉，20 条判据照样 0 FAIL。
这是本仓目前唯一「代码已交付、判据为零」的欠账，也是 §69「假绿修真」主线在
C++ 侧没收的尾。

本棒用 UIA **客户端侧** `AddAutomationEventHandler` 订阅 20012，把「选中变化会通知读屏」
变成一条可证伪的真机判据；探针侧抽象成通用能力，供后续 `PropertyChanged` /
`StructureChanged` / `FocusChanged` 复用。不迁 Drift3、不动 `src/`、不加框架 API。

## 1. 优先级重排（核心能力优先）

`docs/DEBT-2026-11-02.md` §68.7 / §69.4 留了四条欠账。按本仓长期判据
「①有消费者 ②错了是静默的 ③决定能不能交付」逐条打分：

| 欠账 | ① 消费者 | ② 静默 | ③ 决定交付 | 结论 |
| --- | --- | --- | --- | --- |
| §66 `ElementSelected` 事件判据 + 两条 C++ 变异 | 读屏 / 自动化明确 | **是**（注释掉全绿） | **是**（无障碍承诺的一半） | **本棒** |
| B4 EOF 死亡双判（`src/App/ProcessTaskHost.php:592`） | worker 崩溃 | 部分（管道 EOF 已当场发现） | 否（只补空闲期回收） | 仍独立成棒、需 T2 |
| `UPLOAD_TIMEOUT_S` 口径冲突 | 串行上传 | 是 | 否（当前只有标注） | 需真机量一批才有得裁 |
| `readonly class` 的 `===` 与 `DESIGN.md:2541` 白名单矛盾 | 少 | 否 | 否 | 口径订正，单独立项 |

§65→§69 五棒都在做同一件事：**把已交付能力抬到敢用**。§69 修的是解释器侧
`SelectPurity` 漏认 `static fn` 的假绿；本棒修的是 C++ 侧「事件没人验」的假绿，
同一个病、另一半身体。

## 2. 结构上为什么只能用「客户端订阅」

事件是第三种可观察量，前两层量不到：

1. **PHP 语义树**（`tests/Unit/Widget/RowSemanticsTest.php`）——只证 `selectState` 数组填了；
2. **UIA 属性 / Pattern**（`tools/probe-uia-state.py` ⑨–⑫）——只证客户端**去问**时答得对；
3. **异步通知**（本棒）——证用户改选中那一刻，读屏**会自己响**。

Qt 的反面教训正好是这件事：`QAccessible` 只发 `StateChanged` 而不发 `Selection` 事件时，
NVDA 不播报选中变化；「属性可查」与「变化会通知」是两件事。WPF 因此把
`SelectionItemPattern.ElementSelectedEvent` 做成独立事件，由
`AutomationPeer.RaiseAutomationEvent(AutomationEvents.SelectionItemPatternOnElementSelected)`
在状态变更后主动 raise。

**判据必须独立于实现**（本仓最常见的静默缺口形状）。以下三条都判死：

- 在 C++ 里加「我 raise 过了」计数器再暴露给探针 ⇒ 计数点与 raise 点同处，注释掉
  raise 也可能仍计数，与实现同源；
- 读 `oldSelect` / `lastNodeCount` 记账 ⇒ 同上，且只证「打算报」不证「报出去了」；
- 用 `AddPropertyChangedEventHandler(IsSelected)` 代替 ⇒ 绕开 §66 新增的 C++ 代码，
  注释掉 raise 照样绿，**反而是新假绿**。

唯一独立路径：客户端订阅 → UIA 核心分发 → 回调。这也正是
WPF `Automation.AddAutomationEventHandler` / FlaUI `EventLibrary` / WinAppDriver 的形状。

## 3. 成熟 GUI 框架对照与借鉴点

| 框架 | 做法 | 对本棒的裁决 |
| --- | --- | --- |
| WPF | `AutomationPeer.RaiseAutomationEvent(...)` 的 sender 是**控件自身的 peer**，不是窗口根 | §66 已用 `makeFragmentSimple(k)` 按节点取 fragment，形状正确；别改成从根 raise |
| FlaUI | `EventLibrary` 把 Subscribe / Unsubscribe / 释放做成生命周期 | 探针侧做成上下文管理器，自动退订，别让后来人自己记 `Remove` |
| UIA 客户端 | STA + 消息泵，回调经窗口消息投递到订阅线程 | 等待期间必须 `PeekMessageW` 泵，不能只 `time.sleep()` |
| Chromium AX | 选中变化是独立 AX 事件，不复用 property-changed | 支持 §2 否决 `PropertyChanged` 的理由 |
| Qt QAccessible | 「可查询」≠「会通知」 | 正是 §66.5 欠账的本质 |

WPF 还有一条值得抄的**反同源纪律**：事件在状态**实际变更后**才 raise，且 fragment 必须
在 UIA 树里（否则客户端收不到）。§66 的节点来自同一份 `win.uiaNodes`，⑨–⑫ 已证明
`find_visible()` 能遍历到 ⇒ sender 在树里，订阅 `hwnd` 根 + `TreeScope_Descendants` 成立。

## 4. 实现设计

### 4.1 通用能力落在 `tools/probe-uia.py`（不落在判据脚本里）

新增三个可复用符号，全部参数化，不在探针里散落 COM 细节：

- `IUIA_AddAutomationEventHandler = 32` / `IUIA_RemoveAutomationEventHandler = 33`；
- `IID_IUIAutomationEventHandler = 146c3c17-f12e-4e22-8c27-f894b9b79c69`；
- `event_handler(uia, root, event_id, scope=TreeScope_Descendants)` —— 上下文管理器，
  产出 `events` 列表（每条含 `eventId` 与尽力而为的 sender 名字），`__exit__` 必退订；
- `pump(seconds)` —— `PeekMessageW(PM_REMOVE) + TranslateMessage + DispatchMessageW` 循环，
  是 `event_handler.wait()` 与既有轮询共用的唯一消息泵。

手写 COM handler：`IUIAutomationEventHandler : IUnknown`，vtable 四槽
`QueryInterface / AddRef / Release / HandleAutomationEvent(sender, eventId)`。
`QueryInterface` 对 `IID_IUnknown` 与 handler 自身 IID 返回自身，其余 `E_NOINTERFACE`；
回调对象与 vtable 必须在 Python 侧保活到进程退出（UIA 可能有在途回调）。

### 4.2 ⑬ 判据编排（`tools/probe-uia-state.py`）

在 ⑪ 的 `call_pattern(obj, 3)` **之前**订阅，`Select()` 之后泵消息等回调：

- **⑬a 静默窗口自证**：订阅之后、`Select()` 之前泵一小段，要求 **0 条** 20012。
  事件流本身若有噪声（例如结构变化被误报成选中），这条会红 ⇒ 防「永远有事件」的假绿。
- **⑬b 事件到达**：`Select()` 后泵到出现 `eventId == 20012`，超时记 FAIL。
- **⑬c 事件与动作同帧**：`Select()` 前清空计数，只认这次动作带出的事件；
  不依赖固定 `sleep`（§66.4 已踩过「固定 sleep 在负载高时偶发红」）。
- 细节打印 sender 名字（读得到时）与事件条数；**sender 名字读不到不作为判据**，
  见 §8 的诚实边界。

### 4.3 三态与反同源

- 订阅失败（`AddAutomationEventHandler` 非 `S_OK` / 拿不到 root）⇒ `PC.skip(...)`，
  退出码 2「没测到」，**不写 FAIL**（`tools/probe_check.py:73`）。
- **显式依赖 ⑪**：`select_ok = PC.check("⑪ ...", ...)`；`select_ok` 为假 ⇒ 
  `PC.skip("⑪ 没选中，⑬ 分不开『没收到事件』与『根本没选中』")`。
  这是 `DEBT §66.6` 第 2 条的硬要求，也符合「串联判据第二条必须显式依赖第一条」。
- 收不到事件 ⇒ **FAIL**（产品不对），不是 SKIP；`tools/probe_check.py` 的失败优先语义保证
  后续 SKIP 不会把这条真发现吞掉。

### 4.4 vtable 槽位实证（不猜）

`IUIAutomation` 的槽位若数错，轻则 `E_NOINTERFACE`，重则访问冲突 ——
`tools/probe-uia.py:135-140` 的 `ElementFromPoint` 事故就是签名写错。本棒两条证据：

1. Wine `include/uiautomationclient.idl` 逐条数：IDL 含
   `CreateAndConditionFromNativeArray` / `CreateOrConditionFromNativeArray` 两个方法，
   它们把 `AddAutomationEventHandler` 推到 **32**、`Remove` 到 **33**；
   `TreeScope_Descendants = 0x04`；handler IID 见 §4.1。
2. 本仓 `tools/probe-uia.py:65` 的 `IUIA_get_ControlViewWalker = 14` 已在真机跑通
   （⑨–⑫ 全过），与同一份 IDL 的 14 号一致 ⇒ 本机 UIA 与 IDL 编号体系对齐。

⇒ `DEBT §66.6` 记的「vtable 32」正确；按记忆少数两个 NativeArray 得到的 30 是错的。

## 5. 多维评估

- **结构**：产品代码零改动；新增能力在 `tools/` 通用层，判据在 `tools/` 用例层，
  与既有 `probe-uia.py` / `probe_check.py` 分层一致。不动 `src/` ⇒ 无 AOT 语义风险。
- **实现**：只用 ctypes 手写 COM（本仓纪律：不为一个探针装包）；
  `ctypes.WINFUNCTYPE` 回调从非 Python 线程进入时由 ctypes 自行 `PyGILState_Ensure`；
  回调里**只记录** `eventId` 与字符串，不持有 COM 指针跨线程。
- **扩展性**：`event_handler(event_id)` 参数化 ⇒ 后续
  `PropertyChanged` / `StructureChanged` / `FocusChanged` 直接复用；
  `pump()` 也能替掉现有 `time.sleep(0.1)` 轮询，是真机探针的第一条通用消息泵。
- **开发者体验**：⑬ 判据本身只有 5 行（订阅 / 静默自证 / 动作 / 等待 / 断言）；
  后来人不需要懂 vtable、GUID、GIL。失败信息给 `hr`、事件条数、sender 名字，
  红了能指方向。
- **维护性**：上下文管理器强制退订；回调对象保活；判据三态齐全；
  反同源（静默窗口 + 依赖 ⑪ + 清空计数）三条都写进注释，不靠人记。

## 6. 更优方案探讨（含否决）

| 备选 | 为什么否决 |
| --- | --- |
| C++ 侧加「raise 计数」暴露给探针 | 与实现同源，注释掉 raise 也可能仍计数 ⇒ 新假绿 |
| 客户端订阅 `PropertyChanged(IsSelected)` | 绕过 §66 的 C++ 代码，等价于不验 |
| 订阅 `StructureChanged` 间接推断 | 选中变化不改节点数，本来就不该有 ⇒ 否定式弱判据 |
| 改用 pywinauto / comtypes | 本机未装，且本仓纪律是探针零第三方依赖 |
| 把事件判据做成 PHP 单测 | 解释器侧没有 UIA 核心，分发路径测不到 |

无更优替代。客户端订阅 20012 是唯一「经过 UIA 核心、独立于实现」的路径。

## 7. 判死条件

1. `AddAutomationEventHandler` 在 AOT `showcase.exe` 上返回非 `S_OK`，或订阅成功但
   `Select()` 后稳定收不到回调，排除消息泵 / 套间 / scope 后仍不成 ⇒ **判死**：
   如实记入 DEBT，不伪造判据、不把脆弱探针合入。
2. 手写 COM handler 导致探针进程崩溃或回调乱序且无法在合理成本内收敛 ⇒ 同上判死，
   回退探针改动，只保留诊断结论。
3. 静默窗口恒有 20012 噪声（⑬a 恒红）⇒ 先查是否订阅到了别的进程事件；
   若确属本仓 provider 乱报，则这是**真 bug**，转为修复而非删判据。

## 8. 变异清单（诚实边界）

| 变异 | 预期 | 本棒是否验 |
| --- | --- | --- |
| C++ 注释掉 `UiaRaiseAutomationEvent(...20012)` | ⑬b 红 | **验**（AOT 重建 + 真机） |
| 探针侧把事件 id 改成 20013 | ⑬b 红 | **验**（判据量具自证，不需重建） |
| 探针侧去掉静默窗口断言 | 需另有噪声源才红 | 不造假变异，见下 |
| C++ 改成按 index 比对（去掉 `oldKeys[k] != key` 守卫） | 需「排序 / 滚窗后同一 index 指向别的节点」场景 | **不验**：现有探针无排序 / 滚窗场景，改了也测不出，属于「变异打空」 |
| C++ 去掉「无 key 跳过」 | 需滚窗后无 key 节点占位 | **不验**：同上 |

`DEBT §66.6` 第 3 条把后两条排在本棒之后，但它们**真正缺的不是 C++ 改动而是场景**：
按 `MEMORY.md`「变异必须用真实渲染」「改了没红先怀疑变异打空」，在没有排序 / 滚窗
判据前做这两条变异只会得到无意义的全绿。⇒ 本棒把它们连同「需要新增的场景」一起
写进 DEBT，明确标注「当前无消费者，不验」，不伪造证据。重裁触发条件：
`tools/probe-uia-state.py` 增加表格排序或滚动场景后，再回来补这两条。

## 9. 验证分层

| 层 | 内容 |
| --- | --- |
| T0 | `python -m py_compile tools/probe-uia.py tools/probe-uia-state.py` |
| T1 | `php tools/gate.php`（18 项全量，探针骨架 `probe-audit` 会扫到新脚本）、`docref` |
| **T2** | `php tools/drift-build.php showcase --full` → `python tools/probe-uia-state.py` 21 判据 0 FAIL；C++ 变异后**重建真机**确认 ⑬b 报红，恢复后复验 |

> `MEMORY.md`：dev 壳侧冻结，C++ / UIA 改动只走 AOT；`--dev` 验不到事件。
> 变异必须重建 `showcase.exe`（`--full`，避免增量 stale）。
