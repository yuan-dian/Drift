# 设计裁决：交互反馈收口 —— 把两相位状态从 `RenderButton` 抬成框架能力

日期：2026-11-05 · 状态：**已实施**（记录见 §7）· 兼容性：**可破坏**

## 1. 为什么不是"再加一个能力"

`DEBT §26.2`（有没有）与 `GAP-ANALYSIS-2026-11-04` 五轮复核（够不够用）是两个独立
来源，结论一致：**能力面不是瓶颈**。`DEBT §40.1` 因此把「核心能力优先」定义为*把已有
能力抬到"敢用"*，三条按权重：

| 权重 | 判据 | 本轮落点 |
|---|---|---|
| 高 | 有真实消费者 | `MenuItem` —— 每个菜单都要点，继承 `Button` 全套命令/焦点语义 |
| 高 | 错了是静默的 | 点下去逐像素不变，无 warning、无异常、命令照跑 |
| 中 | 决定能不能做出真实应用 | 交互反馈决定"愿不愿意用"，不决定"能不能" |

`DEBT §43.5` 的清单**三项全部不合格**（语义树指纹是零症状量具、`pressed()` 零读者、
`IoPoller` 依赖外部 tpc 升级且已挂起十次）。这正是 `DEBT:1648-1652` 警告过的
「下一棒只增不删、20% 是过期地图」。本轮按权重重排，落点在清单外：
`DEBT §40.10` 明写留给"另一棒"的那条 —— **"`RenderButton` 之外的盒没有按下态"**。

## 2. 根因：状态机是通用的，"把状态画出来"是私有的

| 层 | 现状 | 判定 |
|---|---|---|
| `RenderObject:145-247` | `hovered`/`pressed` 两条独立斜坡、`setHovered`/`setPressed`、`hoverProgress()`/`pressProgress()` | 通用、正确 |
| `FocusManager:341-399` | 沿**盒父链**问 `acceptsHover()`/`acceptsPress()`，第一个答 true 即推状态 | 通用、零 `instanceof` |
| `ColorToken::Hover/Pressed` | 明暗两套值齐（`ColorToken.php:70-71,86-87`） | 通用 |
| `RenderObject:145,208` | 基类 `acceptsXxx()` **恒 false** | 闸门 |
| `RenderButton:115-125` | **全仓唯一**覆写 `acceptsXxx()` | 私有化 |
| `RenderButton:227-277` | **全仓唯一**读 `hoverProgress()`/`pressProgress()` 并画两层叠色 | 私有化 |

⇒ 任何新可点控件必须：写 `RenderBox` 子类 → 覆写两个 `acceptsXxx()` → 在
`paintOwnChrome()` 里抄一遍两相位换色 → 在 Widget 层解析主题色传进构造。
`MenuItem` 走了第一步（换成 `RenderMenuItem`），后三步全漏：`MenuItem.php:82-92`
的构造参数里根本没有 `resolveHoverOverlay()` / `resolvePressedOverlay()`，而
`Button.php:278,285` 那两行只对 `RenderButton` 生效。

`RenderMenuItem::paintContent():116-134` 只画 label / chord / 分隔线 ⇒ **菜单项点下去
逐像素静默**。同族 `IconButton` / `NavButton` 用 `RenderButton`，是有反馈的 —— 只有它掉队。

## 3. 多维度方案比较

| | A 逐控件补 | B 抽 trait | **C 通用状态层（裁决）** | D 声明式 `Modifier` 全量 |
|---|---|---|---|---|
| 结构 | 绘制逻辑第 3 份副本 | 绘制一份，落点仍每盒一份 | **状态机 + 声明 + 绘制各一份** | 同 C，另加声明通道 |
| 实现 | 照抄 `RenderButton` 两段 | 每个盒要记得 `use` | **`RenderBox::paint()` 是 `final`，子类漏不掉** | 需改 `setMods` / 两个 `syncBox` |
| 扩展性 | 每加一个控件抄一遍 | 要写新盒子类 | 自定义盒一行 `setInteraction()` | 任意 Widget 一行 `->feedback()` |
| DX | 差 | 中 | 中 | **最好** |
| 维护性 | 最差（§「与 XXX 同义 = 未收口」） | 中 | **好**：删 `RenderButton` 私有副本 | 好，但多一个声明源 |
| 风险 | 低 | 低 | **低**（无新类、无签名变动） | **高**：`isDefault()` 反射守卫、`ModifierTest` 表驱动、`StatefulElement::syncBox()` 分支不对称（`:60-69` 不做 `defaultBoxStyle` 合成） |
| 消费者 | 1 | 1 | **1（真）** | **0（`->feedback()` 没人用）** |

**裁决：方案 C + 本轮落地 `MenuItem`。不做 D。** 理由是 `DEBT §26.2` 十条老账的形状：
再开一个 `->feedback()` 声明式 API 而没有消费者，就是又一条"设计承诺未兑现"。
`D` 真正该开工的时机是**出现第二个需要它的真实控件**；届时 `setInteraction()` 已经是
现成落点，抽 API 只是加一层转发。本轮把 `setInteraction()` 定为自定义盒的公开落点即可。

> 借鉴（用户指定参考成熟框架）：`Drift3/docs/research/12-横向思路提炼.md:132-133`
> 「修饰器链四类职责正交」「**交互行为集中一次实现，视觉语言交给应用**」、
> `:129` GPUI「Base owns reusable behavior. Presentation belongs to the application」。
> 对应本方案：两相位状态机与绘制 = 行为（`RenderObject` + `RenderBox::paint()`），
> 状态色 = 表现（Widget 层按主题解析成 `Color` 传入，Layout 层不碰 Theme）。
> Flutter `WidgetState` / Compose `Indication` 同构：状态由框架持有，控件只解析
> "这个状态下画什么"。**不借鉴** ripple / splash（`DEBT §40.10` 已裁决不做：需给
> `DisplayList` 加新 op 或盒自持动画，是为观感付结构性成本）。

## 4. 实施方案

### 4.1 三个落点，各一份

1. `RenderObject`：加 `protected ?Color $hoverOverlay / $pressedOverlay` +
   `setInteraction(?Color, ?Color)`（唯一写点）；`acceptsHover()` / `acceptsPress()`
   基类改为「声明了对应状态色」。`inheritRuntimeState()` **不动**（这是描述态，不是瞬态，
   每次 `syncBox()` 由 Widget 重新落）。
2. `RenderBox::paint()`（`final`，`:569-613`）：在 `paintOwnChrome()` **之后**、裁剪**之前**
   插 `paintInteraction()`。顺序与 `RenderButton` 现状一致（底/边 → hover → press → 内容）。
   加 `interactionRadius()` 供 `RenderButton` 覆写为 `$this->box->radius`；默认从 mods 读。
3. `RenderButton`：删 `acceptsHover()` / `acceptsPress()` 覆写、删 `paintOwnChrome()`
   里两段叠色；构造末尾调 `setInteraction($hoverOverlay, $pressedOverlay)`。
   **构造签名不变**（`DEBT`: 位置参数插中间会静默错位，AOT 下编译错）。
   `hoverElevation` 的浮起影子仍留 `paintOwnChrome()`，它读 `hoverProgress()` 自己画。

### 4.2 `MenuItem` 接上

`RenderMenuItem::__construct()` **末尾**加两个 `?Color` 参数（同样避开位置错位），
非 separator 形态调 `setInteraction(...)`；`MenuItem::createRenderObject()` 直接复用
继承来的 `$this->resolveHoverOverlay($context)` / `resolvePressedOverlay($context)`，
不重解析主题。**separator 必须零反馈**（不可点、不进焦点链，`MenuItem.php:50`）。

### 4.3 为什么表格行不接（结构性排除）

`TableState:1982-1985` 行盒按**索引** `->key($i)` 复用，状态只能按数据 key 存在
`$hoverKey`（`:116,1316-1317,1363-1386`）。状态住盒会在滚动后"高亮串行"。
`TreeView` 同理（`LazyColumn`）。二者不在本轮范围，也不该用本方案改。

### 4.4 明确不做

- `->feedback()` 声明式 API（见 §3，等第二个消费者）。
- `Segment` / `ToggleBase` / `Slider` 的反馈接线（真实但各有一份自绘 chrome 与
  `activate()` 语义，单独一轮）。
- `pressed()` 零读者（`DEBT §43.5` ②）：本轮不制造新读者，仍不动。
- 改 `Button::paintsBoxChrome()`：`MenuItem` 继承它 = true，但 `RenderMenuItem`
  并不自绘 chrome，mods 的视觉三字段被 `Element.php:153` 剥掉 —— 这是既有的另
  一处不一致，改了会刷整页 golden，本轮只记录不动。新状态层字段在 `RenderObject`
  上，**不受 `withoutBoxVisual()` 影响**，因此 `MenuItem` 照样能画反馈。

## 5. 判据设计（必须能证伪）

1. **状态成对**：`RenderMenuItem` 的 `acceptsHover()`/`acceptsPress()` === true；
   **separator 形态 === false**；祖先 `RenderPopupPanel` 容器 === false。
   （仿 `HoverTest.php:110-158` 的"成对"断言，避免"全 true 也绿"）
2. **像素三帧**：base / hover / press 逐像素比较，断言 `diff(base,hover) > 0`、
   `diff(hover,press) > 0`、`diff(base,press) > 0`。三对全要，否则只画一层也能绿。
3. **方向判据**：亮色主题 `Hover = 0xECF0F5` → `Pressed = 0xDFE6EF`（更暗），
   断言 press 帧在采样点亮度 **小于** hover 帧。防止两色写反/写反序。
4. **静止零命令**：静止时 `hoverProgress()`/`pressProgress()` 为 0 ⇒ 状态层一条
   `drawRect` 都不发。27 页整页 golden **不刷基线仍逐像素全绿** 是正向证据。
5. **零成本早退**：未声明状态色的盒走 `if (两者皆 null) return`，不加常驻分支开销。
6. **变异保护**（至少 4 组，须各自精确命中）：撤 `setInteraction()` 调用 / 撤基类
   `acceptsXxx()` 判据 / 撤 `paintInteraction()` 的 press 层 / 两色传反。
   变异前跑基线绿，产物先 `php -l`。

## 6. 风险与对策

| 风险 | 对策 |
|---|---|
| AOT 校验实参个数、解释器不校验 | `RenderMenuItem` 新参数只追加在**末尾**；全仓 `new RenderMenuItem(` 仅 1 处，已核 |
| 新增类 ⇒ AOT `0xC0000005` | 本轮**零新增类**，只改现有类；仍跑 `drift-build --full` 与真机 |
| `RenderButton` 删 `acceptsHover()` 后丢失"只浮起不变色" | `resolveHoverOverlay()` 对四个 variant **全返回非 null**（`Button.php:367-373`），不存在该组合 |
| 换盒后状态分叉（`DEBT §42`） | 状态色是描述态，每次 `syncBox()` 重落；斜坡仍由 `inheritRuntimeState()` 移交，归零再置位的修法不变 |
| 圆角处叠色盖出直角 | `interactionRadius()` 覆写；`RenderMenuItem` 默认 0（菜单行本就直角） |
| 行尾 / 编码 | PHP 文件 LF，`Edit` 后核 `\r` 计数 |

## 7. 实施记录（2026-11-05）

**与设计的偏差（3 处，都已解决）**

1. **字段重名**：`RenderObject` 的 `$hoverOverlay/$pressedOverlay` 与
   `RenderButton` 构造里 promoted 的 `$hoverOverlay/$pressedOverlay` 撞名 ⇒
   `Fatal: Cannot redeclare non-readonly property ... as readonly`。
   改法：基类字段改名 `$interactionHover/$interactionPress`，并把 `RenderButton`
   的两个参数从 promoted **降级为普通参数** —— 顺带满足"描述态只留一份"。
2. **`Edit` 把两个文件转成 CRLF**（`RenderObject` 313 个、`RenderButton` 274 个）。
   按纪律用 PHP 刷回 LF 并复核 `git ls-files --eol` ⇒ `w/lf`。
3. **测试基类纪律**：`SmokeTestCase::assertPostConditions()` 会兜住"一条断言都没跑"，
   首版用 PHPUnit 原生 `assert*` ⇒ 5 个方法全报 0 断言。改用项目惯例
   `check()` + 每条方法末尾 `flushChecks()`。

**一处判据陷阱**（已修）：方向判据原本写 `$darker === $sampled`，当 `$sampled = 0`
时 `0 === 0` 恒真 ⇒ 加了 `$sampled > 0 &&` 前置，并单独出一条"真的采到了"的正向证据。

**验证**：`gate 17/17（145.2s）`（含 `golden 原语` + `golden 整页` 全绿 ⇒ 零基线刷新）、
新测试 `OK (5 tests, 28 assertions)`、变异 `5/5 全红`。`DEBT §44` 记完整裁决与边界。
