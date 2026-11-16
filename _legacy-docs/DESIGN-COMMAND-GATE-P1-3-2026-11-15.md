# P1-3 命令启用态：多维裁决与设计（2026-11-15）

基线：HEAD `490ef46`（P1-5 热更状态保留刚落地）。本轮**只改 PHP**，零 `cpp-src/`、零新概念名词。

---

## 1. 先订正「推荐的方向」

报告 §7 顺序图把 **P1-2 指针通道**排在 P1-3 之前；上一轮收尾又把 **P1-1 Inspector** 写成
「下一棒」。两条在当天 HEAD 重 grep 后都不成立。

### 1.1 P1-2 不能是这一棒（三条硬事实）

| 事实 | 证据 |
|---|---|
| 换 `WM_POINTER` 必须同时补 `SetCapture`，而它是**明文「要改先裁决」** | `PITFALLS.md:191`：「壳侧没有 `SetCapture` 是刻意的…`grep SetCapture cpp-src/` = 0 不是漏做，**别"顺手补上"** —— 那会把"拖出窗口就断"改成"拖出窗口继续"，属于手势语义变更。**要改先裁决**」 |
| 核心判据依赖前台窗口，当前会话给不了 | `tools/probe_win.py:459-469` 抢前台；除 `probe-uia-p02.py` 外 12 个 `probe-*.py` 都需要真窗口或交互会话。桌面锁定下 `probe-uia-state.py` 已实测 SKIP(exit 2)。**判据写出来只能永久 SKIP，不构成验收** |
| 收益在 8 个 app 里零消费者，成本却是 6 C++ + 5 PHP + 两次 AOT | `WM_POINTER*` / `GetPointerInfo` / `DoDragDrop` 源码 0 命中；recognizer 只有 tap/drag 两个；`drift_win32.cc:1108-1206` 是唯一鼠标路径 |

§4 引 WinUI「一次上 `WM_POINTER`，别逐个补 `WM_TOUCH`/`WM_GESTURE`」**是对的**，但它没有
授权改「拖出窗口即取消」这条收口语义。换通道与改语义是两个决定，必须先单独裁后者。
⇒ **§7 订正**：P1-2 前置是「指针收口语义裁决 + 可交互会话」，不能排在纯 PHP、可无窗口
验证的 P1-3 之前。本轮不动 `cpp-src/`。

### 1.2 P1-1 仍维持上一轮的否定

运行时服务（HTTP/WS/socket）全仓 0 命中，`tools/inspect.php` 已被 S4 因无人用删除，
`SurfaceProfile` 只有累计/平均/中位而无帧时间线。要建就得先定形态（外部面板 vs 应用内
浮层），不是「顺手把 Snapshot 变成服务」。上一轮已裁过，本轮不重开。

### 1.3 §5 的 P1-3 落点本身是错的

报告写的是「快捷键 **Intent/Action 层** + 焦点作用域」，三条判据：
①同键不同页不同动作 ②禁用时菜单灰且按键无效 ③跨作用域重复绑定不误报 Fatal。

| 落点 | 复核结果 |
|---|---|
| `Intent` / `Action` 类 | **撞 2026-10-14 明文判死的幽灵术语**。`docs/_archive/DESIGN-v1.0-2026-10-14.md:916-917`：「本方案只保留 `command` 这一个名字，`Intent` 从全文删除」；`DECISIONS-2026-10-14.md:77,87`：「删掉 `Intent` 之后三条 lint（dead-command / intent-unhandled / intent-enumeration）**塌缩成一条**。少一个概念 = 少两条规则」。把它请回来是净负收益 |
| 给 `AcceleratorTable` 加作用域维度 | 破坏 `PITFALLS.md:2713`「纯映射 ⇒ 可整表共享」的架构性质；且全仓**只有 1 处生产注册**（`UsersApplication::accelerators()` 4 键），跨作用域重复绑定零消费者 ⇒ 判据③过满 |
| 「同一命令在不同页面不同实现」 | `State::handleCommand()` 生产侧**只有 1 个 smoke 实现**（`tools/Smoke/Commands/CommandState.php`）；`apps/users` 9 条命令全走注册表 handler ⇒ 判据①零消费者 |
| 菜单 `chord()` ↔ 加速键表一致性 | `MenuItem.php:28-30` 自承无联动校验，但只有 2 处 `chord()` 消费者，单独做一轮价值不足 |

⇒ **三条判据里只有 ② 是真缺口**，且它跟 Intent / 作用域 / 键位表**都没关系**。

---

## 2. 真缺口：命令启用态是三份手写，快捷键路径完全不查

`grep -i enabled src/Commands/ src/App/InputRouter.php src/Widget/Menu/` = **0 命中**。

| 入口 | 今天怎么判「能不能做」 | 后果 |
|---|---|---|
| 菜单 / 按钮 | `Button::isEnabled()` = 静态 `!mods()->disabled()`，构建时写死 | 状态变了要 `setState()` 重建并逐项重算 |
| handler 内部 | `SignupCommands::handle()` 自己 `validate()` 早退；`NavigationCommands::handle('nav.back')` 无条件 `$app->back()`，而 `NavigationStack::pop()` 在栈底返回 false | 按了没反应，且**不报错** |
| 快捷键（`InputRouter.php:121-127`） | `resolve()` 命中就 `dispatch()`，**不查任何启用态** | 菜单灰着但 `Alt+Left` 照样派发 |

这是 §5 判据②「错了是静默的」的教科书形态，也是「一个模式 N 份手写」（D4/D6 复核的
同族债务）：**同一条命令的可用性有三个判断点，互不同步。**

### 2.1 成熟框架怎么解（借鉴，不照抄名词）

| 框架 | 启用态住在哪 | 本轮借鉴 |
|---|---|---|
| Flutter（**改版后**） | `Action.isEnabled`，§4 明确警告「别照最早那版（在 Intent 上）」 | 启用态跟着**动作/handler**，不跟着键位 |
| WPF | `RoutedCommand.CanExecute` + `CommandBinding` | 判定与命令绑定同一处注册 |
| Qt | `QAction::setEnabled()` | 菜单与快捷键查同一个 `QAction`，天然一致 |

三者的共同点是：**启用态是命令的属性，不是键位表的属性**。所以不碰
`AcceleratorTable`（保持纯映射可共享）、不引入 `Intent`（沿用字符串命令名）。

---

## 3. 更优方案：一个写者、一个判定入口

### 3.1 结构

```
CommandRouter::on(name, handler, ?gate)   ← 门控与 handler 同一张表、同一个写者
        │                                   ⇒ 结构上写不出「门控了一个不存在的命令」
        └─ enabled(name, arg)  沿父链查 gate；无 gate ⇒ true（默认放行，兼容全部现有命令）
                    │
Application::dispatch(name, arg)          ← 唯一派发入口
        ├─ enabled? ── no ─→ DIAGNOSTIC + return false
        └─ yes ─→ bubble(全树) → router->dispatch()
```

`Application::dispatch()` 一处判定，自动覆盖三条路径：
- 按键：`InputRouter.php:124` `host->dispatch($accel)`
- 菜单 / 按钮：`ActivationContext.php:68-74` → `Application::dispatch()`
- 其它：`InputRouter.php:723`、`Button.php:229`

⇒ **不需要给 `InputRouter` / `MenuItem` / `Widget` 各接一遍线**，也不碰
`§4.3 禁 Widget → Commands` 的依赖方向。

### 3.2 实现维度

- `CommandGate` 沿用 `CommandHandler` 的「**具名类实例，不是闭包**」纪律
  （`CommandHandler.php:8-16`：可枚举 / 可单测 / 静态可达）。零闭包、零反射。
- `CommandRouter::on()` 第三参**可选** ⇒ `Bootstrap.php:175-177` 三条窗口命令、
  `Application::register()`、全部既有调用点零改动。
- `Application::commandGates(): iterable<string, CommandGate>` 是新的显式覆写点，
  与 `commands()` 并列；`attach()` 里同一个循环装配，孤立门控（有 gate 无命令）
  留 DIAGNOSTIC —— 不新增 `AppScope` 端口、不引入 `BuildContext::of()`。

### 3.3 扩展性 / DX / 维护性

- **DX**：开发者只写「这条命令什么时候可用」一次，按键和菜单同时生效；
  命令不可用时不再是静默无反应，而是一条可查的诊断。
- **扩展性**：`commandGates()` 与 `commands()` 同形，后续 P1-1 Inspector 要列
  「命令 + 当前可用性」时，`CommandRouter` 已是单一真相源；P0-3 的
  `RestorationBucket::isDirty()` 也可直接当 `app.quit` 的门控输入（本轮不接）。
- **维护性**：门控与 handler 同一写者，`names()` / `namesInChain()` / `hasInChain()`
  语义不变；`dead-command` lint 不受影响（它只看命令名册）。

### 3.4 分工：视觉灰掉本轮不接

`UsersRoot` 是 `StatelessWidget`、不订阅 `AppStore`，动态灰掉需要新增订阅点或给
`ActivationContext` 加启用态查询出口 —— 后者会撞 `smoke.php:3187`「BuildContext
无业务数据出口」判据和 §9.1 的服务定位否决。
⇒ 本轮框架只保证「所有派发入口一致 + 被拦不静默」，菜单视觉列为独立裁决。
真消费者只接门控，**不改 `UsersRoot`、不动顶栏焦点链**。

---

## 4. 真消费者

`apps/users` 的 `nav.back`：栈底时 `NavigationStack::pop()` 返回 false，
`NavigationCommands::handle()` 仍返回 true ⇒ `Alt+Left` 与「文件」菜单里的「返回」
都静默无反应。挂门控 `canPop()` 后：被拦时 `Errors::recentDiagnostics()` 多一条
（响亮）；有历史时照常放行。

`smoke.php` 无 `nav.back` / `Alt+Left` 判据（已 grep），顶栏焦点链不动 ⇒ 不重演
P0-3 那次「加按钮挪动 Tab 4 次」的翻车。

---

## 5. 判据（不与实现同源）

1. 按键判据直接调 `$app->dispatchInput(KeyEvent::down($chord))`（`smoke.php:4059`
   同款），走完 `dispatchInput → InputRouter → dispatch` 全链，**不新增 `TestApp`
   动词**：`TestApp.php:43-46` 明文「只实现被场景走通过的动词」，为一个 PHPUnit
   用例加一个不被声明式场景走通的分支是倒退。
2. 行为判据读**记录型具名类**的公开数组（handler 收到什么 / gate 被问了几次）与
   `Errors::recentDiagnostics()` 环形缓冲，不读任何私有字段、不读配置。
   - 按键路径被拦：handler 未收到、诊断 +1；
   - 点击路径被拦：同上（证明两条入口走同一个判定）；
   - 未声明 gate 的命令默认放行（兼容既有 9 条命令）；
   - gate 恰好被问 1 次（证明一处判定，不是 handler 早退）；
   - 真消费者栈底 `nav.back` 被拦、有历史时放行。
3. 静态结构对账：`Application::dispatch()` 里 `enabled()` 判定在 `bubble()` 之前、
   `on()` 第三参接线、所有 `->dispatch(` 调用点都经 `Application`。
4. 变异自检 ≥6 组：删判定 / 判定只在按键路径接 / 默认改成禁用 / 返回值取反 /
   gate 不沿父链 / 孤立门控不诊断 —— 逐组真红并恢复复绿。

---

## 6. 边界与不做项

- **不做** `Intent` / `Action` 类（判死）、**不做** `AcceleratorTable` 作用域维度
  （零消费者 + 破坏整表共享）、**不做** `Menu::chord()` ↔ 键表对账（2 个消费者）。
- **不做** `BuildContext` 启用态出口、**不做** `AppScope` 新端口。
- 门控**不替代** handler 内部校验：业务规则（表单字段合法性）仍属业务状态，
  门控只管「这条命令整体可不可用」，避免把校验搬进框架。
- 菜单视觉灰掉是应用职责；框架不替应用重建树。
- P1-2 / P1-1 / P1-4 / P1-6 / P1-7 仍未动；`Future::timeout` 与 CLI 收口留到
  §7 的下一棒（`DEBT:9723-9725` 已裁「本轮不动 CLI」）。
- `src:` 指纹会变 ⇒ 需重编 users / showcase AOT；零 `cpp:` 改动。

---

## 7. 同族静默错：`MenuItem::chord()` 与加速键表漂移

`MenuItem.php:28-30` 自承「`chord` 只是**声明 + 显示**，不等于注册」，全仓无一致性
校验。重 grep 立刻抓到实证：

| 菜单声明 | `UsersApplication::accelerators()` | 结果 |
|---|---|---|
| `UsersRoot.php:119` `Ctrl+R` | ✅ `users.refresh` | 一致 |
| `UsersRoot.php:120` `Ctrl+N` | ❌ **未绑定** | **菜单显示 Ctrl+N，按了什么也不发生** |
| `UsersRoot.php:121` `Alt+Left` | ✅ `nav.back` | 一致 |

这是与启用态同族的「一件事两处手写 ⇒ 静默无反应」，且就在本轮真消费者里。
⇒ 补一条 `Ctrl+N → nav.signup`，并加**静态对账**：逐个读 `MenuItem::chord()` 声明与
`AcceleratorTable::bind()` 键，缺绑定即红。判据读源码声明文本（不与实现同源），
不作为独立一轮 —— 它就是「命令契约单一真相源」的另一面。
