# 外部调研对账：13 家 GUI 框架 → Drift2 该借什么、该躲什么、别抄什么

> 输入：`D:\TypePHP\Drift3\docs\research\` 全 15 篇（00 索引 / 01–11 十一家平台 /
> 12 横向提炼 / 13 nanoui / 14 Raven）。
> 方法：**先对账，再排优先级**。调研是 2026-10-17 → 11-03 分批写的，它引用的
> "Drift 现状"是 Drift2 更早的 backlog ⇒ 逐条拿 `src/` + 量具核一遍，过期项就地
> 标注，不照抄。
> 日期：2026-11-03 · 基线：gate 15 项 117.9s · smoke 471 ok / 0 FAIL。

---

## 0. 一页结论

| # | 结论 | 性质 |
|---|---|---|
| 1 | 调研的 12 条"值得借鉴"里 **5 条已落地、3 条半落地、1 条与既有裁决冲突**，照抄会重复建设 | 记账纠错 |
| 2 | 真正被**本仓自己的量具**点名的第一优化不是增量布局，是**全树 `tickAnimations` 吃掉稳态帧 98%** | **P0** |
| 3 | Raven 的"增量布局是生死线"与 §15.1 的"M5 不做"用的是**两个分母**（12k widget vs 500 行），所以两条都不算错 —— 缺的是**同一把尺子** | **P1** |
| 4 | 两条"只差最后一步"的欠账：交互脚本截图 / CLI `--json`。★ 调研最想推的「双后端逐像素一致」**不该抄** —— 与 2026-11-01 的裁决直接冲突，且跨后端**几何**同构测试早就在跑（§4.1） | **P1** |
| 5 | GPUI 的 base/component 分层，Drift2 **已经用另一种形态实现**（行为在 `Input`+`App`，表现在 `Layout`+`Widget`，方向由 `DependencyDirection` lint 强制） | 已有，不必新建 |
| 6 | immediate mode / 体积目标 / 可视化设计器 / XML 绑定 / DP 值解析链 —— 与该命题冲突，明确否掉（§6 附理由，防重开） | 排坑 |

---

## 1. 对账：调研的"现状"有一半已经过期

调研的价值在**机制**（伪代码级、设计取舍的"为什么"），不在它对 Drift2 现状的描述。
后者必须重核 —— 这正是本仓已立的判据「待办会腐烂」的外部版本。

| 调研的说法 | 实测（2026-11-03，`src/` 坐标） | 判定 |
|---|---|---|
| 「IME 组合期零实现、无剪贴板、无撤销」（13 §3.3 / 14 §2） | `App/TextEditingBridge.php` 已有 IME 光标矩形、指针框选、编辑命令、剪贴板读写；`App/EventLoop.php:185` 有 IME 时序双保险 | **2/3 过期**，只剩撤销栈 |
| 「`drift-doctor` 未建」（13 §3.1） | `tools/doctor.php`：`Environment::probe()` 一套判据，**已带 `--json` / `--strict` / `--quiet`** | **过期** |
| 「drift CLI 缺 `--json`」（13 §3.2） | 3/40 个工具支持（doctor / inspect / font-scan-chars） | 成立，但范围要改小 |
| 「golden 只覆盖同平台同后端 ⇒ 应强制 CPU==GPU 逐像素」（13 §3.6） | 只跑 `RasterCanvas` 属实，但**这是裁决不是欠账**（2026-11-01：「像素不能跨后端比」，AA 范围/描边实现有意不同）；跨后端改比**设备几何**：`tests/Feature/Rendering/BackendDeviceSpaceTest.php` + `tools/smoke.php:1063` 起 17+ 条 Skia conformance 已在跑 | **不该抄**（与既有裁决冲突） |
| 「每帧三遍全树遍历」（14 §2） | `Frame.php:270` 已有整树跳过（`$didRebuild \|\| boxEpoch \|\| constraintsChanged`）；缺的是**脏子树**而不是"跳过" | **半过期** |
| 「tickAnimations 全树遍历，应改登记表」（13 §3.4 / 14 §1.6） | `Application::tickSubtree()` 全树递归，实测占稳态帧 **98%** | **成立，且是本轮头号** |
| 「没有 12k widget 量级对标」（14 §1.11） | `frame-tax-probe` 最大 500 行（≈2000 节点）；`apps/bench` 是 5000 节点的**微**基准，测的是遍历/建值成本，不是端到端帧 | **成立** |
| 「字形逐字 `rasterize()` 到画布，缺图集」（13 §3.5） | `Text/BitmapFontFace.php` 有按字形的位图缓存；`TtfFace` 把光栅**委托**给字形源 | **半过期**：有缓存、无图集 |
| 「缺交互序列视觉回归」（13 §3.3 / 14 §3-M） | `tools/TestApp.php` 是场景宿主但**没有脚本入口**；golden 只能比静态帧 | **成立** |
| 「行为/表现应分层，GPUI base 不得 import component」（12 §8） | 行为层已在 `Input/`（GestureArena / AcceleratorTable / FocusNode）+ `App/`（FocusManager / OverlayHost / TextEditingBridge）；表现在 `Layout/` + `Widget/`；`tools/Lint/Rules/DependencyDirection.php` 把 13 个层的允许边**逐层写死** | **已实现**（形态不同） |
| 「错误走回调而非崩溃」（14 §3-L） | `Errors::report` 三级 + 标准中止序列（日志 + 原生错误框 + exit 3），`G2 绝不静默` | **已实现** |
| 「能力分级协商」（12 §6，UDA SupportLevel） | `CanvasCapabilities` 位掩码三层 + `Platform::clipboard(): ?Clipboard` 返回 null | **已实现**（画布侧） |

**这张表本身给出第一条判据**：

> 外部调研必须带**核账日期 + 代码坐标**才能进待办。
> 只有机制结论可以跨版本复用，"某框架的现状"必须重核。
> 反例就在上面：如果照 13 号文档去补剪贴板，会去重建一个 2026-10 就已存在的桥。

---

## 2. P0：稳态帧 98% 花在"问全树有没有动画"（✅ 已落地 2026-11-03，见 `DEBT` §16）

### 2.1 证据

```bash
php tools/frame-tax-probe.php --check
# rows  perFrame(us)  layout(us)  paint  skip
#  500        1055.7      10498.6      0    30
# 分段: pumpIo=890ns pumpTasks=877ns tickAnimations=1.04ms placeOverlays=670ns
#       settle=877ns layout=533ns focusCollect=1.62µs
# ② 分段自洽：最大段 tickAnimations=1.04ms ≤ 整帧 1.06ms（98%）
```

树没变、没有脏、paint 全跳过、layout 只花 533ns —— **整帧 1.06ms 里 1.04ms 是
`tickAnimations`**。

### 2.2 根因

`Application::tickSubtree()` 每帧递归整棵树，每个节点做四件事：

```php
States::of($element)                       // 查一次
$state?->tickFrameStamped($ts, $serial)    // 帧戳判重
$element->renderObject()?->tickAnimations($ts)   // 虚调用
$element->visitChildren(...)               // 闭包 + 递归
```

而 `RenderObject::tickAnimations()` 的唯一消费者是 `hoverAnim`（全仓**只有一处**定义、
零覆盖），State 侧声明动画的也只有 `AnimationController` / `AnimatedScalar` /
`AnimatedOpacity` 一族。⇒ **为了找这几个人，每帧把两千个节点全问一遍。**

### 2.3 为什么它是 P0 而不是"顺手"

1. **它发生在每一个出帧的帧**。只要有一个 Spinner 在转、有一个 hover 过渡没收口，
   `wantsFrame()` 就为真 ⇒ 每帧固定税。
2. **它随树规模线性长，且是纯浪费**。2000 节点 → 1.04ms ⇒ 外推到 12k 节点是
   **~6ms**，也就是 60FPS 预算的 36%。而它换来的信息量是"有几个人在动"。
3. **两个成熟框架独立收敛到同一答案**：nanoui 的 active set、Raven 的「有东西在动时
   窗口自己持续出帧，全部静止后 sleep」。两家语言不同、模式不同（immediate vs
   retained），答案却一样 ⇒ 这不是风格问题。
4. **修复面小**：不碰布局、不碰绘制、不碰公开 API。换的只是"谁被 tick"的发现方式。
5. **本仓已有同款先例**：`Pipeline::takeMounted()` 就是"登记表 + `isMounted()` 过滤"，
   §15 刚把"两个账本手写"收成一处 —— 同一个病根的第三次出现。

### 2.4 设计要点（坑在这里，不在代码量）

| # | 决定 | 不这么做的后果 |
|---|---|---|
| ① | 登记时机：State 首次 `tickFrameStamped()` 返回 `true` 时登记；`AnimatedValue::toward()` 时登记 | 漏登记 ⇒ 动画不动、**不报错**（静默） |
| ② | 出表时机：**值变了但 `tick()` 返回 false 的那一帧**才出表，不是"返回 false 就出" | 收口帧的值丢掉，画面停在 0.93 而不是 1.0（`RenderObject` 的注释已踩过） |
| ③ | 卸载：遍历登记表时过 `isMounted()`，同 `takeMounted()` | 给离开树的 State 发帧，僵尸 tick |
| ④ | 对账判据：一条"全树结果 == 登记表结果"的测试 | 漏登记时两边分叉，但没有红 |

★ ④ 与 §15 的 `FrameStageAccountingTest` 同构：**名单/行为双向对账**才是判据，
"我加了个登记表"本身不是。

### 2.5 验收判据

- `frame-tax-probe --check` 的 `tickAnimations` 从 **1.04ms → < 0.05ms**（无动画时）；
- 有一个 `AnimatedValue` 在跑时，只访问 1 个节点（用计数器断言，不看时间）；
- 动画结束那一帧的**终值被画出来**（接 ②）；
- 全树 vs 登记表对账测试（接 ④）。

**实测（2026-11-03）**：四条全中 —— `tickAnimations 934.95µs → 333ns`；一个 Spinner 时
一帧只访问 1 个节点；收口帧终值 `=== 1.0`；对账测试（表路径 vs 全扫路径逐帧一致）
落在 `tests/Feature/App/AnimationActiveSetTest.php`。设计 / 三个静默坑 / 变异表见
`DEBT` §16 —— ★ 尤其 §16.3 那张表：**起飞点不全的三种形态都不会报错**。

---

## 3. P1：增量布局 —— 两个分母，缺一把尺子

### 3.1 冲突在哪

| 出处 | 说法 |
|---|---|
| 14-Raven §3-B | 「增量布局是保留模式框架的生死线，Drift **必须补**」（Raven：12k widget 改一处 **0.03–0.05ms**） |
| `DEBT` §15.1（上一轮） | 「M5 脏矩形**继续驻留**，本轮不动」（最重真实页全量重排 **1,531.7µs** vs 触发线 8,000µs，余量 5.2×） |

两条都不算错，因为它们测的**不是同一棵树**：

- Raven：12 000 widget / 27 800 图元；
- Drift2：`frame-tax-probe` 的 500 行 × (1 Row + 3 Text) ≈ **2000 节点**；页面档最重的
  `TablePage` 也就百来行数据。

⇒ 把 1,531.7µs 直接对比 8,000µs 得出"余量 5.2×"，在**当前页面规模**下成立；但拿它
否掉"树大时要不要增量"，等于用 2000 节点的数据回答 12k 节点的问题。

### 3.2 该怎么裁

沿用 §15.1 那套（先跑量具、看触发线、触线才立项），只是**换分母**：

1. 把 `frame-tax-probe` 的合成档从 500 行加到 **12 000 节点**（约 3000 行），
   量三件事：首布局 / 稳态帧 / **改一个 label 后的全量重排**。
2. 判定线沿用 `DESIGN` §13.5：**全量重排 > 8,000µs 或稳态帧 > 8ms** ⇒ 立项。
3. 立项时**必须与绘制脏矩形成对**（`EVAL-design-v7` §261）：单独做任一半，瓶颈只是
   搬到对面 —— 这一点 §15 已经引过，不重复论证。
4. 真做时，Drift2 的入口已经收好了：绘制侧是 `RenderBox::paintChildAt()`（§13），
   布局侧缺的是"脏路径上传到边界"（`markNeedsLayout` 沿父链上传 + 按深度排序），
   而不是再造一套。

★ **建这把尺子本身就是交付物**：调研 14 号说"Drift 只有 3.14ms/帧的内部数，
没有外部校准线"。有了 12k 档，以后每次争"要不要做增量"都不用重新吵。

### 3.3 附带发现：绘制侧有边界，布局侧没有

`Rendering/LayerCache.php` 已经是 `RepaintBoundary` 的等价物（录 `DisplayList`，没变就
只重放，跳过整棵子树的 `paint()`）。而 `Frame.php:270` 的跳过是**整棵树**的，粒度是
"这一帧有没有任何节点重建"。

⇒ **不对称**：绘制可以跳过一棵子树，布局不能。树一大，会先在布局上痛（`layout` 是纯
PHP 递归，`paint` 反而有层缓存兜底）。这条不对称是 §3.2 那笔账的另一半，不用新立项，
但要写进判定依据。

---

## 4. P1：两条"只差最后一步" + 一条"别抄"

前两条有**现成的前半截**，缺的都是最后一环 —— 性价比最高的一档。
4.1 是调研最想推、但**与既有裁决直接冲突**的一条，单独立在这里当反例。

### 4.1 反例：「双后端逐像素一致」不该抄

调研 13 §3.6 / 14 §3-M 都把 nanoui 的「CPU == Metal 逐像素一致」列为 Drift 该补的欠账。
**这条在 Drift2 的语境下是错的**，而且它已经裁过了：

- 2026-11-01 原话：「**像素级 golden 不能跨后端比**，只能各自与自己的基线比」。
  理由是**有意保留的两处后端差异**：抗锯齿范围（软光栅只对圆角/字形做覆盖度，
  Skia 对所有边）、描边实现（外圈减内圈 vs 原生 `kStroke_Style`）。
- 横向旁证：Chromium pixel test 带 `fuzzy`、Servo 有 YAML `fuzzy`、Qt `lancelot`、
  Flutter 分平台 golden —— **没有一个**跨后端比像素。共性：**像素是后端私有的**。
- Drift2 已有的正确形态：跨后端比**设备几何**。落点两处 ——
  `tests/Feature/Rendering/BackendDeviceSpaceTest.php`（用定义重算，不调 `Scale::deviceRect()`）
  与 `tools/smoke.php:1063` 起 17+ 条 Skia conformance（单字形、dpr=2、平移、clip 配对）。
  再加上 `CanvasCapabilities` 的 Core/Extended/Optional 三层：
  **"哪些 op 必须等价"由契约说，"等价到什么程度"由几何断言证**。

★ 所以 `golden-raster.php` 零处 `SkiaCanvas` **不是缺口**：Skia 在解释器下无法无头渲染，
它的回归手段是「几何同构 smoke + 真机看图」。这条要如实写进文档，避免下次有人
拿着 nanoui 的清单来"补一个跨后端像素测试"，白花一周并得到一条永远在容差里翻红的规则。

### 4.2 交互脚本 → 截图

- 已有：`tools/TestApp.php` 场景宿主（模态 / 虚拟列表 / Spinner / 几何四类场景）、
  `golden-raster.php` 的录制与逐字节比对、`golden-report.php` 的 HTML 失败现场。
- 缺：`--script "click:X;scroll:down;key:enter"` 这种序列驱动。
- 为什么值：本仓日常是 **agent 改 UI**，"改完长什么样"目前只能靠静态帧 golden 或人工
  看窗口。交互后的视觉回归是空白（调研 13 / 14 各自点了一次）。
- 成本低：脚本解析器 + 复用场景宿主 + 复用录制。一次投入同时解决 CI 视觉回归与
  文档配图。

### 4.3 CLI `--json` 补齐

- 已有：3 个工具（doctor / inspect / font-scan-chars）。
- 缺：gate / lint / smoke / golden / bench / probe-* 的机器可读输出。
- 为什么值：agent 驱动"构建 → 测试 → 快照"的前提是**不用正则刮 stdout**。这条不改
  任何产品行为，纯工程收益。
- 做法：先做 `gate` 与 `golden --check` 两个（它们最常被 agent 读），不要一次铺 40 个。

---

## 5. 布局纪律：GPUI 那条"硬约束"Drift2 已经用另一种形态达成

调研 12 §8 的核心建议是「行为/表现分层 + 依赖方向必须可 enforce」。逐条核：

| GPUI 的约束 | Drift2 的等价物 |
|---|---|
| base 无样式行为：焦点 / 键盘导航 / 覆盖层 / 虚拟化 / a11y / 文本引擎 | `Input/`（GestureArena、AcceleratorTable、FocusNode、SemanticsTree）+ `App/`（FocusManager、OverlayHost、TextEditingBridge） |
| component 只管表现：品牌色 / 字体 / 密度 / 圆角 | `Layout/`（RenderBox 族）+ `Widget/`（控件与链式 API）+ `Theme/` |
| 「base 不得 import component 的主题」 | `tools/Lint/Rules/DependencyDirection.php` 把 13 个层的允许边逐层写死，**并带两处对 §4.3 的补正说明** |
| 四大控件家族（语义元素 / 复合行为根 / 有状态系统 / 基础设施） | 未显式分类，但物理上是分开的：`Widget/`（语义元素）、`Widget/Drag`+Overlay（复合行为根）、`*State.php`（有状态系统）、`Layout/`+`Rendering/`（基础设施） |

⇒ **不需要新建 `drift-base` / `drift-component` 两个包**。分层已经存在，只是按
"机制"分目录而不是按"层"分库；方向由 lint 强制，比文档纪律更硬。

★ 唯一值得从 gpui-kit 补的是**"跨缝类型字段全私有 + builder + reader"**这条：
新增字段不算破坏性变更。本仓 §10 已有 `@api` 标注 + `internal-leak` 双向检查，
但**没有"公开类型的字段可见性"规则**。这条属于将来真要做 SDK 分发（`-m lib`）时才付的账，
现在记着，不立项。

---

## 6. 明确否掉（附理由，防重开）

| 候选 | 否掉的理由 |
|---|---|
| immediate mode 主体（nanoui） | 会丢三样：reconcile 复用、Element 生命周期承载的焦点/滚动/动画状态、471 条 smoke 的断言面。nanoui 用 `Context.state` 补救，本质是在 immediate 上重建一个迷你 retained |
| 0.7MB 单文件体积 | 产物带 `libphp.so` + `libphpx.so`，下界由 PHP 运行时定。当目标会逼出"砍功能换体积" |
| 可视化 designer / theme editor（Raven 卖点） | 与 gpui 同判：读者是 AI 不是人。PHP 声明即 DSL + 代码生成即文档 |
| XML 声明 + 9 语言 FFI 绑定 | Drift 的卖点是"语言即 PHP"、单语言单产物；链式 PHP API 对 IDE 更友好 |
| WPF 依赖属性值解析链 | 四来源合并的推理成本与样板量超标；换肤用显式查找链即可 |
| base/component 分**库** | 见 §5：已用目录分层 + lint 达成等价，分库只是换包装 |
| GPU 为主路（Raven / nanoui） | 软光栅是 golden 的**参考实现**，必须自持；GPU 是加速档不是真相档。Raven 的 glass 在 SwiftShader 上 250ms/帧也印证"没有真 GPU 时自绘材质很贵" |
| TEA 全量样板（MoUI） | 小界面也被迫定义 Msg + update 分支。取"纯数据 Model 可被程序化操作"之神，弃税 |
| React Fiber 时间切片 | 单线程无抢占需求；"commit 原子"的合理内核本仓已有（帧末批量生效，不做撕裂中间态） |
| Qt 式"事件循环空闲才刷新" | 声明式应对齐固定帧节拍；本仓 `wantsFrame()` 五判据已经是这个方向 |

---

## 7. 调研真正值得留下的三条（跨语言、跨模式都成立）

去掉所有"某框架现状"的部分，剩下的是可以写进公理层的东西：

1. **能力等价不能靠自觉，只能靠测试 —— 但"等价"的粒度必须先选对。**（nanoui 选像素；
   Drift2 选几何：`CanvasCapabilities` 定"哪些 op 必须等价"，smoke 的 Skia conformance
   定"等价到什么程度"。选像素在这里会与 AA 差异打一辈子架 —— 见 §4.1）
2. **"谁在工作"要显式登记，不要每帧问全树。**（nanoui active set / Raven active 集合 /
   Drift2 的 `takeMounted()` 先例 —— 见 §2）
3. **声明式连接失败必须响亮。**（WPF 字符串绑定静默失败、Qt 漏连接静默不响应；
   Drift2 的 `G2 绝不静默` + `Errors` 三级 + `Fatal` 中止序列已覆盖；剩下的死角是
   "声明了但没人验证"——那种要靠闸门，不靠自觉）

---

## 8. 下一棒（按上面排的序）

| 优先级 | 事项 | 出口判据 | 预估面 |
|---|---|---|---|
| **P0** | 动效 active set（替换全树 `tickSubtree`） | `tickAnimations` 1.04ms → <0.05ms；全树/登记表对账测试；终值帧不丢 | ✅ **已完成 2026-11-03**：934.95µs → 333ns，见 `DEBT` §16 |
| **P1** | 12k 节点 benchmark 档 | 出三数（首布局 / 稳态帧 / 全量重排），按 §13.5 判定增量布局立项与否 | `frame-tax-probe` 加档 |
| **P1** | `--json`：先 gate + golden | 两条命令输出可被 `json_decode` 直接消费 | ✅ **已完成 2026-11-03（换了形态）**：不是"加 15 个 `json_encode` 分支"，而是先把 6 份判据模板收成 `CheckReport` 一份账本，`--json` 是它的第二个渲染。覆盖 `frame-tax` / `rva2sym` / `gate` / `golden` 四个，见 `DEBT` §17 |
| **P1** | `M2Conformance` 进 `gate`（§17.8 第 1 条） | 先判 3 条既有红是真死字段还是规则误报 | ✅ **已完成 2026-11-03**：3 条**全是规则误报**（读点只认本类体、漏了 `use` 进来的特征与子类、只认一条造盒路径）。真问题不是那 3 条，是 W3 保护的 22 个字段（交互/无障碍能力的全部声明面）对尺子是瞎的。修准后进 `gate`，见 `DEBT` §18 |
| **P2** | TestApp `--script` 交互截图 | `--script "click:X;scroll:down"` 出 PNG，进 golden 流程 | `TestApp` + 脚本解析 |
| **P2** | 撤销栈（文本编辑最后一件） | 撤销/重做/合并连续输入；与 IME 组合期正确交互 | ✅ **已完成 2026-11-03（落点换了）**：不在 `TextEditingBridge`（`App` 层，拿不到每个草稿，且表格单元格根本不经过它），而在 `TextDraft` 本身 —— 它是所有改文本路径的唯一收口点，一处收口三处受益（输入框 / 表格单元格 / `ComboBox` 搜索框）。栈是**快照式**；合并按"同类且连续"而**不按时间窗**（§14 不读环境时钟），顺带解决了 IME 组合期（连续 `insert` 自动并成一步）。见 `DEBT` §19 |
| **P2** | 字形图集 | 先量"文本密集页 paint 里字形光栅占比"，占比高才做 | 量具先行 |
| **—** | 跨缝公开类型字段可见性规则 | 等真做 `-m lib` 分发时再付 | 记着不立项 |

★ 老规矩：开工前把这一节和 `DEBT` §15.8 一起核一遍（§13.1 的教训：待办会腐烂）。
