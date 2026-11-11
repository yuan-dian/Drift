# Drift2 架构重审：从"补丁驱动"到"结构驱动"

> 日期：2026-10-21 · 性质：**架构评审（只读分析，不改代码）**
> 输入（全部逐份读过，不是抽查）：
> - `docs/architecture/` 14 册（当前实现写实）· `docs/design-v7/` 27 册（设计蓝图）· `docs/EVAL-design-v7-2026-10-21.md`（差距评估）
> - `D:/TypePHP/Drift3/docs/research/` **11 份成熟 GUI 调研**（Flutter…GPUI 全读）
> - `D:/TypePHP/Drift3/docs/TypePHP-docs-总览.md`（编译器能力边界）
> - `D:/TypePHP/Drift3/design/`（**v5「One」13 册**）· `docs/spec/`（v4.1 16 册）· `design-v6/` + `design-v6.0/` · 两个 v1/v2 草案
>   ⇒ 见 §2.6「四代设计草案：同一道题的四个答案」
> 立场：**允许兼容性破坏**。判据是"下一个控件会不会再打一次同样的补丁"，不是"改动大小"。
> 取数口径：每条结论都带 `file:line` 或可复跑命令，不靠记忆。

---

## 0. 一句话结论

**Drift2 的架构主干是好的（三层树 / 五阶段帧 / Canvas 接口 / 双后端），但它缺三块"结构"——
覆盖层模型、控件基座、能力降级协议。这三块缺口是过去 30 条开放项里绝大多数补丁的共同源头。
其中"覆盖层"最急：不做它，`Select` / `DatePicker` / `Tooltip` 每加一个都要再打一次补丁，
而且**现在的补丁已经打不动了**（单槽位互斥，第三个浮层无处安放）。**

**11 份调研的总账**（`D:/TypePHP/Drift3/docs/research/` 全 11 份逐份读完，不是只看 GPUI）：

| 类别 | 条数 | 含义 |
|---|---|---|
| **已兑现** | 4 | Drift2 已经做对了，别再改（Qt 的 `update()` 合并 / Flutter 的三树与脏集合 / MoUI 的 provider 抽象 / Compose 的位置记忆） |
| **该借未借** | 6 | 有明确收益、且能落到具体文件（GPUI 三条 + UDA 的"降级要可解释" + **Drift2 自己的 G1（死命令构建期校验）** + Flutter 的滚动位置进约束） |
| **明确不借** | 5 | 有充分理由不借，写下来免得再讨论（SwiftUI 自动订阅 / React 可中断渲染 / Compose 链式 Modifier / WPF 两遍布局 / Aria 原生控件） |
| **反面警示** | 4 | **比正面借鉴更值钱**——它们示范了 Drift2 正在走近的坑（Qt 双轨 / WPF 静默失败 / SwiftUI 黑盒 / React memo 地狱） |
| **跨代借鉴** | 4+3 | **Drift3 四代设计草案已经开好药方**（见 §2.6）：可直接抄的 4 条 + Drift2 没守的 3 条纪律。★ 这是本轮新增的一类——**不是"学外部平台"，是"学自己另一条线已经想清楚的结论"** |

三条建议（按性价比排序）：

| # | 建议 | 为什么现在 | 破坏性 |
|---|---|---|---|
| **A1** | **覆盖层栈** `OverlayStack` 替代单槽位 | 做 Select 的硬前置；现在 Modal 与 Menu 互斥。**依据**：GPUI 四大模块家族 + 13 条不变量第 ⑨ 条；**形态直接抄 `design/08` §4.1**（`barrier`/`scrim` 分离 + 四方向翻转 + anchor 消失自动弹） | 中（`Application` 5 字段 + `Frame::run` 签名） |
| **A2** | **有状态控件基座** `StatefulControl` | 4 组"三件套"已重复，第 5 组正在写（Slider）。**依据**：GPUI "行为归基座、表现归应用" + `design/08` §1 的"八原语" | 低（新控件用，老控件不动） |
| **A3** | **两个后端能力等价**（而非"不支持"） | 控件自绘被锁死在 Core+Extended。**依据**：UDA 的 `SupportLevel`（只借"可解释"）+ Qt 双轨的反面警示 + **`design/04` §5.1 的判据"软光栅就是产品，必须全能力"** | 中（`RasterCanvas` 补 `drawPath`） |

★ 与上一版相比，读完 11 份后有 **两处判断被修正**（见 §2.5）：
① "只有三条值得借" 太窄 —— 实际是 **4 条已兑现 + 6 条该借**，且漏掉了最有价值的一条
（不是从别人借的，是 Drift2 自己的 G1 没兑现）；
② 命令系统"15 条不值得改" **方向对但漏了一条自己的原则** —— `docs/_archive/DESIGN-v2-2026-10-14.md:79` 的 G1
原文就写着"死命令可构建期校验，GUI 却放运行期"，而 `tools/lint.php` 里零命中。

---

## 1. 现状基线（实测，不是印象）

| 项 | 值 | 命令 |
|---|---|---|
| 源码规模 | 223 个 `.php` / 26 025 行 | `find src -name '*.php' \| wc -l` |
| 最大单文件 | `Application.php` **2192 行** | `wc -l src/App/Application.php` |
| 控件文件 | 63（+ `Drag/` 2 + `Menu/` 4） | `find src/Widget -name '*.php' \| wc -l` |
| 渲染盒子类 | 22 | `grep -rl "extends RenderBox\|extends RenderProxyBox" src/Layout/ \| wc -l` |
| lint | 391 文件 · 0 error / 0 warn | `php tools/lint.php` |
| smoke | **936 ok / 0 FAIL** | `php tools/smoke.php` |
| 帧耗时 | 3.14 ms（16.6 ms 预算，5.3× 余量） | `_build/` M4 数据 |
| 开放项 | §24.2 共 30 条，**18 条已闭环** | `grep -c "✅" DESIGN.md` |
| **`★` 注释密度** | **974 处**（`坑` 28 · `踩` 7 · `潜伏` 3） | `grep -ro "★" src/ \| wc -l` |

★ 最后一行是本次评审最重要的**间接证据**：`★` 是"这里有个坑、别踩"的标记。
974 处意味着平均每 27 行代码就有一条"当年踩过的坑"。这不是坏事（知识被固化了），
但它精确地标出了**哪些地方是补丁而不是设计**。

---

## 2. 11 份调研逐份对账

`D:/TypePHP/Drift3/docs/research/` 全 11 份（Flutter / SwiftUI / Qt / React / Compose / WPF /
Vue / Aria / MoUI / UniDesktop / GPUI）逐份读完，按"对 Drift2 的决策价值"分四类。
**每类只留能落到具体文件的**，纯概念不进表。

### 2.1 已兑现（4 条）—— Drift2 做对了，别再改

这四条是"别的框架用十年证明的正确答案，Drift2 第一天就撞对了"。**改动它们要有极强的理由**。

| 判据 | 出处 | Drift2 的对应 | 备注 |
|---|---|---|---|
| **延迟合并重绘**：`update()` 只登记脏，事件循环空闲才 `paintEvent` | Qt（鼻祖） | `State::markDirty()`（`State.php:81`）+ `Element::settle()` 帧末统一（`Element.php:282`） | 同一思想，Drift2 的边界是 vsync 而非事件循环空闲 |
| **三树分离**：配置（便宜可重建）/ 身份+State（复用）/ 几何+绘制（持久） | Flutter | `Widget` / `Element` / `RenderObject` 三基类 | 见 `docs/architecture/01` |
| **渲染后端 provider + 命令流是唯一中间表示** | MoUI `Renderer` trait | `Canvas` 接口 + `DisplayList` / `RecordingCanvas` | 这正是 MoUI 说的"声明树 → 命令流 → 任意渲染器" |
| **位置记忆实现增量复用** | Compose `SlotTable` | `Element::updateChildren()` 的 `(type, key)` diff | Compose 用一张表 + 编译器插桩，Drift2 用显式 Element 层——**目的相同、手段更朴素**（PHP 没有编译器插件，这是对的选择） |

★ **反向判据**：这四条共同点是"**把成本推迟到确定的边界**"（帧末 / 命令流 / diff）。
Drift2 后面的所有补丁，几乎都是在这四条**之外**的地方没守住同样的纪律。

### 2.2 该借未借（6 条）—— 有明确收益，且能落到文件

| # | 判据 | 出处 | Drift2 现状 | 落点 |
|---|---|---|---|---|
| **1** | **行为/表现分离**：`Base owns reusable behavior; presentation owns the visual language` | GPUI | **无**。控件是"盒自绘"（`RenderToggle::paint()` 行为与颜色/勾/尺寸焊死） | **A2**（行为在基座、表现走样式值对象） |
| **2** | **控件分四大模块家族**：语义元素 / 复合行为根 / 有状态系统 / 基础设施 | GPUI | **无**。63 个控件一视同仁，`Select`（复合行为根）与 `Button`（语义元素）被当成同一量级 | **A1** 的理论依据 |
| **3** | **覆盖层坐标/绘制顺序/命中盒共用一个视口模型** + **状态所有权三形态（受控值/句柄/Entity）** | GPUI 不变量 ⑨ + 机制四 | **不成立**。菜单 `placeMenu()`、模态 `placeModal()` 两套；浮层用"单槽位 + 互斥"代替句柄 | **A1 的核心** |
| **4** | **能力分级要"可解释"**：`Partial { reason }` —— 降级原因能展示给用户/日志 | UDA `SupportLevel` | **有二值无原因**。`unsupportedMessage()` 已带 tier + 两条修法（这是 reason 的雏形），但**没有"降级可用"这一档** | **A3**（但见 §2.4 的 Qt 警示：不要用"降级"掩盖"没实现"） |
| **5** | **连接的可检查性 > 连接的表达力**：字符串绑定路径写错 ⇒ 运行期静默失败（WPF 最大痛点） | WPF 反面 **+ Drift2 自己的 G1** | **构建期零校验，四处裸字符串**：注册侧 `apps/users/src/UsersApplication.php:146` 的字典键、引用侧 `Pages/UsersPage.php:75` 的 `->command('nav.signup')`、键盘绑定侧 `:167` 的 `->bind(ctrl('S'), 'form.submit')`、前缀分流 `Commands/NavigationCommands.php:23` 的 `str_starts_with($name, 'nav.')`。★ **诚实修正**：派发时**有** DIAGNOSTIC 上报（`CommandRouter.php:78`，不是完全静默），但三个问题——① 是**运行期**不是构建期，**直接违反 `docs/_archive/DESIGN-v2-2026-10-14.md:79` 的 G1「编译器优先：死命令、key 唯一性、handler 存在性都可构建期校验，GUI 却放运行期」**；② DIAGNOSTIC 级在真机上用户看不见；③ `names()` 注释写着"死命令 lint（§16.6.1 R5）的静态输入"，而 `tools/lint.php` 里 **command/dead 零命中** ⇒ **规则从未实现**（正是本项目自己的判据"未被消费的分支 = 未实现"） | **B5** |
| **6** | **滚动位置进约束**：`SliverConstraints.scrollOffset` 让"布局仍是纯函数"、滚动态可无头测试 | Flutter | **部分**。`RenderScrollView` 把 offset 写进子盒 `offsetX/offsetY`（布局期，方向对），但 offset 是**盒的私有字段**，不是约束的一部分 ⇒ 布局签名里看不见"当前滚到哪" | **B6（低成本）** |

### 2.3 明确不借（5 条）—— 写下来免得再讨论

| 判据 | 出处 | 为什么不借 |
|---|---|---|
| **属性图自动订阅（读即订阅）** | SwiftUI | 它的代价是**不可观测**——"为什么这个视图没更新"无法从工具侧回答，是该平台最高频抱怨。Drift2 的显式 `Subject::subscribe()` + `markDirty` **更笨但可诊断**，方向对。★ 判据：**自动追踪换来的是黑盒，Drift2 现在缺的是可观测性而不是省代码** |
| **Fiber 可中断渲染（时间切片）** | React | 3.14 ms / 16.6 ms 预算，没有任何一段需要被打断。Drift2 的 `SlicedJob` 走的是另一条路（后台分片，不打断渲染）——**更简单且够用** |
| **链式 `Modifier`** | Compose | PHP 无编译器插桩 ⇒ `->padding()->background()->clickable()` 在 AOT 下每节一次动态分派。★ **但借它的分类法**（布局/绘制/输入/语义四类正交）→ **B4** |
| **`Measure`/`Arrange` 两遍布局** | WPF / SwiftUI | 已判 no-op（布局位置无关 ⇒ 分离无收益）。WPF 两遍式的收益（表格/网格先知道"大家多宽"）Drift2 用 `intrinsicWidth` 已覆盖 |
| **原生控件 + 每平台接线** | Aria / Qt(QWidget) | Drift2 是自绘路线（像素一致优先），与 Aria 的"原生感优先"是两条路线。★ 代价诚实：Drift2 永远要自己做 IME/选区/无障碍（Flutter 的坑它也会踩） |

★ 另有两条属"当前不需要"而非"不该做"：**GPU 自绘**（`GpuContext` 留口，3.14 ms 有 5.3× 余量）、
**可视化设计器 / AI skills 分发**（产品决策，与架构无关）。

### 2.4 反面警示（4 条）—— 比正面借鉴更值钱

这四条是"Drift2 正在走近的坑"。它们的价值在于：**不用等踩到，现在就能改判据**。

| # | 别人的坑 | Drift2 的对应风险 | 该立的判据 |
|---|---|---|---|
| **1** | **Qt 的双轨**：QWidget（命令式）+ QML（声明式）两套渲染/事件/布局，团队技能分裂，是它被长期吐槽的第一条 | **A3 做完就会接近**：Skia 能画路径、Raster 不能 ⇒ 两个后端能力不等价 ⇒ 同一个控件在两个后端表现不同（且只有 golden 能发现） | **两个后端必须能力等价**：要么都支持，要么都响亮失败。**不允许"一个强一个弱"**（这条比"支持更多 op"更重要） |
| **2** | **WPF 的字符串绑定静默失败**：`{Binding Cont}` 拼错 ⇒ 输出窗口一行警告、界面空白，无数人坑数小时 | **同构，但比 WPF 轻一档**：派发时有 DIAGNOSTIC（`CommandRouter.php:78`），可 `tools/lint.php` 里**构建期零校验**。★ 更要紧的是：`docs/_archive/DESIGN-v2-2026-10-14.md:79` 的 G1 **原文就写着"死命令可构建期校验，GUI 却放运行期"** ⇒ 这是**自己的原则没兑现**，比"学别人"更该改 | **连接必须可检查**：见 B5（lint 规则，不是常量表）。★ 与"int vs string"无关——**string 也能检查，int 也能写错** |
| **3** | **SwiftUI / Compose 的"跳过"黑盒**：属性图不可 inspect、稳定性推断要靠编译器报告——"为什么没更新/为什么一直在重组"是两家最高频问题 | **现在还没踩**：`settle()` 是全树递归找脏节点（没有"跳过"优化）。但一旦为性能加"这个子树不用重建"的推断，就会立刻变成黑盒 | **任何"跳过"必须可解释**：跳过决策要能通过 `SurfaceProfile` / Diagnostic 回答"为什么跳过这个节点"。★ 现在不做跳过，是**优点**不是欠债 |
| **4** | **React 的 memo 地狱**：响应单位 = 组件函数 ⇒ 必须手动 `memo/useCallback/useMemo` 三层，写满屏幕还上不去 | **已经避开**：Drift2 的响应单位是 `State`（比组件函数细），`markDirty` 只重建标脏的 StatefulElement | **不要引入"手动标注"文化**：任何要求调用方写 `memo(...)` 才能不卡的机制，方向就是错的。性能要靠结构（分层/虚拟化），不靠注解 |

### 2.5 读完 11 份后被修正的两处判断

**修正一：上一版说"只有三条值得借"，太窄。**
实际是 **4 条已兑现 + 6 条该借**。上一版只看 GPUI，漏掉了 WPF 的"连接可检查性"——
而这条恰好命中 Drift2 一个真实缺口（命令名裸字符串、零校验），比 GPUI 那三条更容易落地。

**修正二：命令系统"15 条不值得改"—— 方向对，但漏了一条自己的原则。**
既有裁决（24 §5.3）说的是"15 个命令 / 52 处出现，改 int 不值得"——**这个判断保留**。
但 `docs/_archive/DESIGN-v2-2026-10-14.md:79` 的 G1 原文写着："**死命令、key 唯一性、handler 存在性都可构建期校验，GUI 却放运行期**"。
⇒ 这不是"要不要学 WPF"，是**自己写下的判据没兑现**：
`CommandRouter::names()` 的注释都声明了它是"死命令 lint 的静态输入"，而 `tools/lint.php` 里零命中。
⇒ 结论：**不改 int（派发方式不动），补一条 lint 规则**（B5）。两件事不冲突。

---

### 2.6 四代设计草案：同一道题的四个答案

`Drift3` 里有四组设计文档，是**同一道题的四个答案**。它们的演化史本身就是最有价值的借鉴——
能看出哪些结论被反复确认（= 真判据）、哪些被推翻（= 当时的误判）、哪些**要改三遍才能定**。

| 代 | 位置 | 时间 | 册数 | 定位 |
|---|---|---|---|---|
| **v1** | `docs/Drift3-设计方案.md`（82KB） | 10-17 | 1 | 首版完整方案；把 Drift2 的"探针实证结论集"当资产 |
| **v2** | `docs/Drift-Greenfield-设计方案.md`（68KB） | 10-17 | 1 | 第一性重推；"读即订阅 + 两棵半树" |
| **v4.1** | `docs/spec/`（16 册） | 10-17 | 16 | 分册化；**没有 Overlay 模型** |
| **v6 / v6.0** | `design-v6/` `design-v6.0/` | 10-19 | 16 / 12 | 加 Overlay（节点形态）、加跨平台抽象 |
| **v5「One」** | `design/`（13 册） | 10-19 | 13 | **最新、最收敛**；六条"一" + 26 条对 v4.1 的实质差异 |

### 2.6.1 三个"演化证据"——比任何单份文档都有说服力

**证据一：Overlay 被设计了三次才定下来。**

| 代 | Overlay 的形态 | 证据 |
|---|---|---|
| v4.1（`docs/spec`） | **根本不存在**——`grep -rl "Overlay" docs/spec/` = **0 个文件** | 和 Drift2 现在的单槽位是同一阶段 |
| v6 / v6.0 | 是一个**节点**（挂在 Surface 根下），维护一个栈；"不做布局，每个浮层自己决定位置" | `design-v6/07-控件与API.md:247` |
| v5 | 是**Surface 根下的栈**，条目 `OverlayEntry` 有 9 个字段；并自认"这是 review 时发现的第 6 处概念漂移——`Overlay` 在三处指三件东西，而它从未被定义过" | `design/08-控件-主题-语义.md:426-492` |

★ **Drift2 的 P1 与 v4.1 是同一个病**（没有浮层模型）。而 Drift3 自己从"没有"到"节点"到"栈"
走了两轮返工。**Drift2 可以直接抄第三轮的形态，跳过这两轮。**

**证据二：布局从"每控件一个 performLayout"收敛到"10 个算法 + 参数"。**

v5 的 `README` 第 5 条差异原文：
> 每控件一个 `performLayout` → **10 个布局算法 + 参数** —— 控件数量增长时，布局代码不增长

这正是 Drift2 的 P4（22 个 RenderBox 子类、每个都要手写 `performLayout` + `intrinsicWidth`）。
v5 的答案是**把布局从控件里抽出来**：`Box` / `Flex` / `Stack` / `Scroll` / `List` / `Text` / `Image` / `Icon`
**八种原语**，其余 30+ 控件全是组合（`Button` = `Box + Text + 修饰链`）。
⇒ **控件不写布局代码，P4 的"两套实现必须手工一致"就不存在了。**

**证据三（最重要）：后端默认的正面冲突——Drift2 选 Skia，v5 选软光栅。**

| | Drift2（§24.2 #19，2026-10-17） | v5（`design/04` §5.1） |
|---|---|---|
| 默认后端 | **Skia**（真 TTF + 抗锯齿） | **纯 C++ 软光栅 + FreeType/HarfBuzz**，Skia 降级为可选质量后端 |
| 理由 | 软光栅字模是二值点阵（CJK 16×16 / 拉丁 5×8），真机"界面完全没法看" | ① `icudtl.dat` 缺失是 **abort 不是降级**（违反自包含）② 21 个库的重构建链 ③ 跨平台字体差异破坏过真机验证 |

★ **这条给 P3 补上了更深一层的根因**：Drift2 的能力剪刀差（Skia 能画路径、Raster 不能）
不只是"分级设计错了"，而是**"软光栅被降格成测试夹具"的必然结果**——
一旦它只是测试工具，"少支持几个 op"就成了合理的借口。
v5 的判据相反：**软光栅就是产品，所以它必须全能力**。

★ 但**对 Drift2 的建议不是"翻回软光栅"**：那要自己实现 9 类光栅（贝塞尔自适应细分、
8 种混合、模糊降采样），是重写量级；而 Drift2 的 Skia 路径已真机验证、3.14 ms 有余量。
⇒ 现实做法：**保留 Skia 默认，但把 Raster 从"测试夹具"升格为"第二实现"**——
判据是能力等价（A3），不是"谁当默认"。

### 2.6.2 v5 已经开好的药方（可直接抄的四条）

这四条 Drift2 不用重新设计，照抄即可（都带章节号可查）：

| Drift2 的问题 | v5 的答案 | 出处 | 抄什么 |
|---|---|---|---|
| **P1 浮层单槽位** | `Overlay` 栈 + `OverlayEntry` | `design/08` §4.1 | ① `barrier` 与 `scrim` **是两个参数**（合成一个 `modal: bool` ⇒ 要么菜单背后变暗、要么 Toast 挡点击）② 定位用"锚 + 首选方向"，**四方向翻转 + 夹取**，不做通用避让（否则同屏不同机弹出方向不同，golden 无法比）③ 关闭有**四个来源**（点外面 / Esc / **anchor 消失** / 显式）④ 窗口失焦**不关**浮层 ⑤ 挂 Surface 根下、不挂 Navigator |
| **P2 控件三件套** | 八种原语 + 30 组件 | `design/08` §1 | 控件 = 组合，不新增布局代码也不新增绘制原语；内核表面积压在 8 个类 |
| **P3 能力剪刀差** | 软光栅全能力（见上） | `design/04` §5.1 | 至少抄判据："**第二实现必须能力等价**" |
| **P4 内禀两套实现** | **布局自校验** `verifyLayout()` | `design/03` §6 | 调试构建/CI 里：快照几何 → **忽略所有缓存强制全量重排** → 逐节点比对，不一致即抛。抓的是 `equals` 漏字段、`classify` 漏判、算法藏状态、脏传播断链。**"lint 只校验公式被调用，校验不了公式内容对不对"** |

★ 第 4 条是 **B1 的更好答案**：我原来写"让 `intrinsic*` 默认由 `performLayout` 推导"，
那是**消除两套实现**；v5 的路子是**不消除，但让它可被证明**——用全量重算当 oracle。
两者可以并存，但**自校验更便宜且能抓更多**（推导只能抓内禀尺寸，自校验连 equals/脏传播一起抓）。

### 2.6.3 `spec/13` 的 44 条纪律里，Drift2 没守的 3 条

`docs/spec/13-避坑纪律.md` 是一份 44 条纪律表（C 编译器 9 / B 桥接 10 / P 平台 9 / F 框架 10 / PF 性能 6），
每条带"违反形态（实证）"。Drift2 大部分已守，**这三条没有**：

| # | 纪律 | Drift2 现状 |
|---|---|---|
| **D-F1** | **守卫/lint 规则必须带反例自检**（`lint --self-check`） | Drift2 的反例自检是**手工的**——每次改完临时改坏代码看红不红，跑完还原。⇒ 规则本身坏了没人知道（v4.1 记："两个 lint 空转"） |
| **D-F12** | **每个 Modifier 子类有 fingerprint fixture**（逐字段 ±ε 必变） | Drift2 没有 Modifier，但等价物是 4 个样式值对象（`BoxStyle`/`ToggleStyle`/`ProgressStyle`/`DividerStyle`）——**它们没有"逐字段必变"测试**，漏字段就是静默画旧色 |
| **D-W8** | **控件变体 × 状态矩阵 golden**（每控件 × 每变体 × 每状态 = 一张基准图，新增控件必须带） | Drift2 是零散像素断言（"58% 条填充到 57.9%"这种）。矩阵化能把"禁用态颜色不对"从上线发现变成提交时红 |

### 2.6.4 两个 v1/v2 草案的价值：问题清单，不是答案

`docs/Drift3-设计方案.md` 与 `Drift-Greenfield-设计方案.md` 的**结论大部分被 v5 推翻了**
（v5 README 有 26 条"为什么改"）。它们的价值不在方案，在两条**问题清单**：

1. **§0.2 的 Drift2 资产盘点**（v1）——把 Drift2 当时的状态列成表：
   无头核心 ✅ / PHP 框架层 M3 一半 / **平台层 0 文件** / 线程池判死 / Android·macOS·GPU·IME·无障碍未开始。
   ⇒ **这张表是"哪些是真资产、哪些是纸面承诺"的最好对照**，现在读仍有效。
2. **N1–N4 四条新决策的理由**（v1 §0.3）——尤其 N1 列出了 Drift2 选 Skia 的**具体部署代价**：
   `icudtl.dat` 运行期硬依赖 · 5 个私有头 · 20+ `link-libs` · CRT 矩阵。
   ⇒ 这是 §2.6.1 证据三的另一半，**Drift2 要自包含分发时必须回来看这张账单**。

★ 判据：**读 v1/v2 是为了知道"我们试过什么、为什么不行"，不是为了找答案**。
它们的方案层已被 v5 取代，但问题层（15 点需求、Drift2 资产盘点、部署代价账单）仍然有效。

---

## 3. 补丁痕迹：六个结构性补丁

判定标准：**"如果重来一次，这个结构会不会长得不一样？"** 会 ⇒ 是补丁。

### P1 · 浮层单槽位（最严重 · 做 Select 的硬前置）

**证据链**：

```php
// src/App/Application.php:173-190
protected ?Widget  $menu = null;
protected ?Element $menuElement = null;
protected float    $menuX = 0.0;
protected float    $menuY = 0.0;
protected ?Modal   $modal = null;      // ← 注释："O-20「一槽位两用」的判别位"

// src/App/Application.php:627
public function showModal(Modal $modal): void
{
    $this->closeMenu(); // 互斥：先清槽位（`closeMenu()` 幂等）
    ...
}

// src/App/Frame.php:52-59
public function run(
    Element $root, BoxConstraints $constraints, float $timestampMs,
    ?Element $popup = null, float $popupX = 0.0, float $popupY = 0.0,
): void
```

**这是什么**：O-20 设计时只有一个浮层槽位（菜单）。`Modal` 后来要加，就把同一个槽位
"一槽两用"——用 `$modal` 字段区分两种布局/关闭语义。`Frame::run()` 的签名也只能收
**一个** `?Element $popup`。

**后果（现在就挡路）**：

| 场景 | 现在能不能做 |
|---|---|
| 打开模态时保留菜单 | ❌ `showModal()` 强制 `closeMenu()` |
| `Select` 下拉 + 外层 `Modal` | ❌ 两个浮层要同时活 |
| `Tooltip` 悬停 + 任意浮层 | ❌ 第三个没地方放 |
| 右键菜单 + 菜单栏菜单 | ⚠️ 能，但靠"打开新的就关旧的"——不是叠加，是替换 |
| `DatePicker` 弹层 + `Select` 弹层 | ❌ 同上 |

**为什么这是最严重的**：EVAL §7.3 排期的第 1、2 项（`Select` / `DatePicker` / `Tooltip`）
**全部**是浮层消费者。不先修，做这三个控件 = 再打三次补丁，而且第三次一定打不动
（没有第三个槽位可复用）。

---

### P2 · 有状态控件"三件套"重复

**证据**：四组完全同构的三件套已经存在，第五组正在写（`Slider` 要 `SliderState`）。

| 控件 | Widget | 盒 | State | 行数合计 |
|---|---|---|---|---|
| `TextField` | `TextField.php` (142) | `TextFieldBox.php` (128) | `TextFieldState.php` (649) | **919** |
| `ScrollView` | `ScrollView.php` (67) | `ScrollViewBox.php` (76) | `ScrollViewState.php` (70) | **213** |
| `Spinner` | `Spinner.php` (55) | `SpinnerBox.php` (45) | `SpinnerState.php` (64) | **164** |
| `LazyColumn` | `LazyColumn.php` (164) | （复用 `RenderScrollView`） | `LazyColumnState.php` (123) | **287** |

★ 注意 `ScrollView` / `Spinner` 这两组：三件套合计只有 **213 / 164 行**，其中
`Box` 两个文件加起来 121 行、做的事只有"造 State + 造盒 + 转发光标"。**这是纯样板**，
是 P2 最直接的证据——不是"分层成本高"，是"样板占比过半"。

**结构长什么样**（`TextField` 为例）：

```
TextField (Widget, 不可变描述)
  └─ createElement() → TextFieldBox (Element, 身份)
       └─ onMount() → new TextFieldState()  +  syncBox() → RenderTextField
```

**问题不是"三个类"本身**（Flutter 也是三个），而是：

1. **没有类型保证**。三者的连接全靠"约定"——`TextFieldBox` 要记得 `onMount()` 里
   造 State、`Element::syncBox()` 要记得认这个 Widget 类型。漏一步就静默失效。
2. **每个新控件都要重抄一遍**。`Element::syncBox()` 里有 `instanceof` 分支表
   （`src/Widget/Element.php:106`），每加一个"自产盒"控件就要加一条。
3. **`cursor()` 声明位置是个陷阱**：`StatefulWidget` 调 `cursor()` 静默无效——
   `Element::syncBox()` 只把 `cursorShape()` 落在**自己产盒**的 Widget 上
   （`$this->box?->setCursor(...)`，box 为 null 就悄没声）。这条已经咬过一次
   （IME 的 IBeam 光标），记在 `MEMORY.md` 里。

---

### P3 · 能力分级把控件绑死在最弱后端

**证据**：

```php
// src/Rendering/RasterCanvas.php:422-445 —— 六个 Optional 方法全部
public function drawPath(Path $path, Paint $paint): void
{
    $this->unsupportedOp('drawPath', 'Optional');
}
```

```php
// src/Layout/RenderToggle.php:26（注释原文）
// `drawPath` 是 Optional 层、`RasterCanvas` 显式报 `E-OP-UNSUPPORTED` ⇒ 一旦
// 用路径，preview / golden / smoke 全走软光栅，当场红
```

**这是什么**：`CanvasCapabilities` 把 19 op 分三层，Optional 层只有 Skia 能画。
而 **preview / golden / smoke 全走 `RasterCanvas`** ⇒ 任何控件只要用了 `drawPath`，
无头测试立刻失败。

**后果**：控件自绘被锁死在 `drawRect` / `drawEllipse` / `drawLine` + 渐变/虚线。
实测代价：

- `Toggle` 的勾 = **两条线段**（`RenderToggle`）；
- `Progress` = 圆角矩形套矩形；
- 即将做的 `Slider` 滑块、`Select` 的下拉箭头（▾）、`DatePicker` 的日历网格
  ——**全部只能用线段拼**。箭头用两条线段还行，日历的"选中圈"用椭圆也行，
  但再往上（图标、曲线、复合形状）就没路了。

**这不是"测试策略问题"，是架构反噬**：为了让 golden 逐字节可比，选了一个能力更弱的
后端做基准，然后让**产品表现力**去迁就它。GPUI/UDA 的做法相反——能力分级是给
**平台适配**用的，不是给**产品降级**用的。

---

### P4 · 内禀尺寸是"第二条布局路径"

**证据**：

```php
// src/Layout/RenderBox.php:128-130
abstract public function intrinsicWidth(float $height): float;
abstract public function intrinsicHeight(float $width): float;
```

- `RenderProxyBox` 给的是**转发**（`src/Layout/RenderProxyBox.php:33`，问孩子）；
- 叶子盒（`RenderText` / `RenderCard` / `RenderSizedBox` …）给的是**手算**；
- 22 个 `RenderBox` 子类（含传递继承）里 **14 个自己实现、8 个靠继承**
  （`RenderAppBar` / `RenderButton` / `RenderCenter` / `RenderErrorBoundary` /
  `RenderFlexible` / `RenderModalHost` / `RenderPopupPanel` / `RenderScrollView`）。

**问题**：`performLayout()` 与 `intrinsic*()` 是**两套独立实现，必须保持一致**，
编译器不检查。实测踩过：`SizedBox` 不覆写 `intrinsicWidth` ⇒ 在 `Row` 里"量"这一路
完全看不见定尺寸 ⇒ 静默失效（`docs/architecture/08-控件面.md:29`）。

**同族的第二颗雷**：`SizedBox::height()` 小于子内容 ⇒ C6 Fatal 掉整帧
（真机现象是"点进页面界面一动不动、也没报错"）。这说明"定尺寸"与"内容自然尺寸"
两个概念的优先级没有在契约里说清。

---

### P5 · `frame()` 是 8 个泵串成的巨型方法

**证据**（`src/App/Application.php:1001-1090`，实测 9 段）：

```
assertUiThread
  → pumpIo          ① 非阻塞 IO
  → pumpTasks       ② 后台结果
  → pumpSliced      ③ 分片任务
  → tickAnimations  ④ 动画
  → placeMenu       ⑤ 浮层定位
  → Frame::run      ⑥ settle → layout → paint → present
  → tickAnimations  ⑦ ★ 迟到挂载补 tick（注释原文："2026-10-20 修框架洞"）
  → focus.collect   ⑧ 重建焦点链
  → pumpSemantics   ⑨ 推语义树
```

**第 ⑦ 段就是补丁的化石**：④ 在 settle 之前跑，而页面挂载发生在 `Frame::run()` 的
settle 段 ⇒ 这一帧刚挂上的页的 State 从未被 tick ⇒ `wantsFrame()` 返回 false ⇒
帧时钟不重新上膛 ⇒ **之后没鼠标事件就永远静止**。修法是"再 tick 一遍"。

**为什么这是结构问题而不是实现问题**：如果帧管线是**显式阶段表**（MoUI 那种），
"tick 必须在 settle 之后对新增节点补一次"就会表现为"阶段 ④ 依赖阶段 ⑥ 的输出"——
这是个**可以画出来的依赖**，而不是一个 2192 行方法里第 7 段的一句注释。

---

### P6 · 受控值是"事后统一"的

**证据**：`docs/architecture/00-README.md:31` 自己写了：

> ★ 与 design-v7 的"六条不变式"相比：I1/I2/I3/I4/I5 在代码里成立；**I6 是 Drift2 自己的补强**
> （design-v7 没显式写"受控"）。

**含义**：设计之初没有"状态所有权"这个概念。`Checkbox`/`Radio`/`Toggle` 的 `ToggleBase`
是**绕开 tpc 去虚化**（C6）的产物，不是设计推导的结果——三个控件共用基座是因为
"虚方法覆写会被静默忽略"，不是因为"它们该共用行为"。

**后果**：`TextField` / `ScrollView` / `Modal` 各有各的状态所有权写法，没有统一模型。
GPUI 的"受控值 / 句柄 / Entity 三形态"正好是这块的完整答案。

---

## 4. 根因：三条"设计之初没想清楚"

六个补丁收敛到三条根因。**修根因，补丁自然不再长**。

| # | 根因 | 表现 | 为什么当初想不到 |
|---|---|---|---|
| **R1** | **没有覆盖层模型** | P1（单槽位）、`Menu`/`Modal`/`PopupPanel` 各一套定位与命中 | 第一个浮层是菜单，只需要一个槽位就够；"浮层"作为**一类**结构，要等到第二个消费者出现才看得见 |
| **R2** | **没有控件基座（行为/表现不分）** | P2（三件套）、P6（状态所有权）、P3（表现力被后端绑死） | 早期控件是"盒自绘"最省事；等到控件多了，"每个都是一次性实现"才成为成本 |
| **R3** | **没有能力降级模型** | P3（Optional 层要么全有要么失败）、P4（内禀两套实现） | 只有两个后端时，"支持/不支持"是二值；等第三个后端（平台原生）出现，"打折"才成为必需 |

★ 三条根因的共同形状：**"第二个消费者出现时才发现第一个是特例"**。
这恰好是 GPUI 那句 `Base owns reusable behavior` 要解决的事——它把
"第二个消费者"提前到架构层去想。

---

## 5. 改进方案

### A 级：破坏性，建议现在做

#### A1 · 覆盖层栈（`OverlayStack`）

**目标**：把"浮层"从"一个槽位"变成"一个栈"，且三种形态（菜单/模态/提示）共用
**一套视口模型**（坐标 / 绘制顺序 / 命中顺序）。

**形态**：

```php
final class OverlayStack
{
    /** @var list<OverlayEntry> */
    private array $entries = [];

    public function push(OverlayEntry $entry): void;   // 后进在上
    public function pop(): ?OverlayEntry;
    public function popTo(OverlayEntry $entry): void;  // 关掉它之上的全部
    public function top(): ?OverlayEntry;
    public function each(callable $visitor): void;     // 绘制顺序 = 数组顺序
}
```

`OverlayEntry` 的字段**直接抄 `design/08` §4.1**（它比"三件事"完整得多，且每条都有理由）：

```php
final class OverlayEntry
{
    public \Closure $builder;              // 返回一棵子树（不是实例：要能重建）
    public ?int     $anchorNode;           // 锚点节点 id；该节点 unmount ⇒ 自动弹出
    public ?Rect    $anchorRect;           // 或直接给矩形（右键菜单 = 指针点）
    public int      $prefer = DOWN;        // 首选方向 Down/Up/Left/Right
    public bool     $barrier = true;       // 是否拦截背后指针（= 模态）
    public float    $scrim = 0.0;          // 背后变暗程度（与 barrier 独立）
    public bool     $dismissOnTapOutside = true;
    public bool     $dismissOnEsc = true;
    public bool     $focusTrap = true;     // 是否成为一个 FocusScope
}
```

★ **没有 `kind` 枚举——这是刻意的**：v5 用 `barrier` / `scrim` / `focusTrap` 三个正交参数表达形态，
而不是 `MENU`/`MODAL`/`TOOLTIP` 三个值。理由很硬：

| 场景 | barrier | scrim |
|---|---|---|
| 模态对话框 | true | 0.4 |
| 右键菜单 / 下拉 | true（点外面关） | 0 |
| Toast / Tooltip | **false**（不挡点击） | 0 |

"把两者合成一个 `modal: bool` ⇒ 要么菜单背后变暗（丑），要么 Toast 挡住点击（错）。"
⇒ **形态是参数组合，不是枚举**——加第四种浮层不用改类型。

**四条判断**（都写进 v5 原文，抄过来即可）：

1. **挂 Surface 根下，不挂 Navigator**：挂 Navigator ⇒ 切页会把菜单一起带走；正确行为是
   "切页时关掉菜单（anchor 没了）"，不是"菜单跟着新页面出现"。
2. **定位用"锚 + 首选方向"，四方向翻转 + 夹取**，**不做通用避让算法**——
   通用算法要么要全量候选位置测量（贵），要么结果不可预测（同屏不同机弹出方向不同 ⇒ golden 没法比）。
3. **关闭有四个来源，缺一个就是"关不掉的菜单"**：点外面（`barrier` 吞掉 ≠ 穿透）/
   Esc（走焦点路径，靠 `focusTrap`）/ **anchor 消失**（最常漏：列表滚动把触发按钮虚拟化掉，
   菜单留在原地）/ 显式 pop。
4. **窗口失焦不关浮层**：点另一个应用不算"点了外面"；只有 Surface 关闭才清空整个栈。

**行为收敛**（三条，现在分散在 `Application` 各分支）：

1. **绘制顺序** = 栈顺序（`Frame::run()` 从"收一个 popup"改成"收一个栈"）；
2. **命中顺序** = 栈顶往下（命中第一个不透明的就停；`modal` 的层之下全不穿透）；
3. **关闭顺序** = `popTo()` 单一入口（现在 `closeMenu()` / `closeModal()` 各自一套）。

**改动面**（诚实估算）：

| 文件 | 改什么 | 风险 |
|---|---|---|
| `src/App/OverlayStack.php` | **新增** ~150 行 | 低 |
| `src/App/OverlayEntry.php` | **新增** ~80 行 | 低 |
| `src/App/Application.php` | 删 5 个字段，`showMenu`/`showModal`/`closeMenu`/`closeModal`/`placeMenu`/`placeModal` 改写成薄壳；`dispatchInput()` 的浮层分支从 3 处收敛到 1 处 | **中**——这是全仓最密的输入路由 |
| `src/App/Frame.php` | `run()` 签名：`?Element $popup, float, float` → `OverlayStack $overlays` | 中 |
| `src/Widget/Modal.php` / `Menu/` | `Modal` 从"特殊形态"变成"`kind=MODAL` 的 entry" | 中 |
| `tools/smoke.php` | 浮层相关夹具（22 条 Modal + 菜单）要跟着改 | 中 |

**判据（做完怎么验）**：

- ① 同时开模态 + 菜单 ⇒ 两者都在屏上，菜单在上，点菜单不关模态；
- ② 同时开两个菜单 ⇒ 后开的在上，关掉上面那个下面那个还在；
- ③ `modal=true` 的层之下的点击不穿透（现有 22 条 Modal 夹具必须全绿）；
- ④ `popTo()` 关掉中间层 ⇒ 它之上的全部关闭（现在做不到）。

**为什么不选"再加一个槽位"**：那是同一形状的第二次补丁。第三个消费者（Tooltip）
来了还要加第三个槽位——而 Tooltip 的位置是"跟随指针"的，第四个字段放不下。

---

#### A2 · 有状态控件基座（`StatefulControl`）

**目标**：把"三件套"从"每个控件抄一遍"变成"继承一次"。

**形态**（不是新机制，是把现有约定类型化）：

```php
abstract class StatefulControl extends Widget
{
    /** 子类实现：造自己的 State（@return State） */
    abstract protected function createState(): State;

    /** 子类实现：造自己的渲染盒（唯一造盒点） */
    abstract protected function createBox(State $state): RenderBox;

    final public function createElement(): Element
    {
        return new ControlElement($this);   // 唯一的 Element 实现，全控件共用
    }
}
```

`ControlElement` 一个类替掉 `TextFieldBox` / `ScrollViewBox` / `SpinnerBox`：

```
onMount():   $this->state = $widget->createState()
             $this->box   = $widget->createBox($this->state)   ← 光标在这里落，不再丢
rebuild():   $this->state->setState(...) → 标脏
```

**收益（三条，都可验证）**：

1. **光标陷阱消失**：`setCursor()` 只有 `ControlElement` 一个写点，不存在"调了但 box 为 null"；
2. **`Element::syncBox()` 的 `instanceof` 分支表缩短**：所有 `StatefulControl` 走同一条；
3. **新控件少写两个类**：`Slider` 只写 `Slider`（Widget）+ `SliderState`（State）+ `RenderSlider`（盒），不用再写 `SliderBox`。

**改动面**：

| 文件 | 改什么 |
|---|---|
| `src/Widget/StatefulControl.php` + `ControlElement.php` | **新增** ~120 行 |
| `src/Widget/TextField.php` / `ScrollView.php` / `Spinner.php` / `LazyColumn.php` | 改成 `extends StatefulControl`，删对应的 `*Box.php`（4 个文件） |
| `src/Widget/Element.php` | `syncBox()` 的 `instanceof` 分支表删 4 条 |

**判据**：现有 smoke 936 条**一条不改**全绿（这是纯等价重构）。反例自检：把
`ControlElement::onMount()` 里的 `createBox()` 去掉 ⇒ 应有 ≥4 条夹具红（光标 + 盒存在性）。

**风险**：`TextFieldBox` 里可能有 `TextField` 特有的逻辑（IME 相关）。要先读，
不能盲删。**这是 A2 唯一的未知量**。

---

#### A3 · 两个后端能力等价（不是"加降级档"）

**目标**：消除"Skia 能画、Raster 不能"的能力剪刀差。

★ **读完 UDA 后的判断修正**：上一版把这条写成"引入 `SupportLevel` 的 `Partial` 档"，
方向不对。UDA 的 `Partial` 是给**平台差异**用的（macOS 有托盘、Linux 的 DE 各有差异）——
那是"同一个能力在不同平台成熟度不同"。Drift2 的情况完全不同：
**Raster 不是"打折的平台"，它是我们自己的测试/预览后端**。给它一个 `Degraded` 档，
等于承认"产品表现力要迁就测试工具"，这正是 Qt 双轨（§2.4 #1）的起点。

UDA 真正该借的是**"降级要可解释"**——而这一点 Drift2 已经有了
（`unsupportedMessage()` 带 tier + 两条修法）。

**两条路，选 ②**：

| 路 | 做法 | 代价 | 评价 |
|---|---|---|---|
| ① 加 `Degraded` 档 | `CanvasCapabilities` 加 `LEVEL_FULL/DEGRADED/NONE`；Raster 的 `drawPath` 返回 `DEGRADED`（画成外接矩形近似） | 小（只改位语义） | **治标且危险**——控件能画了，但画出来是错的（矩形当路径），golden 抓不到；且立刻形成"两个后端两套能力"= **双轨雏形** |
| ② **给 Raster 补 `drawPath`** | 扫描线多边形填充（`Path` 已有 verbs + coords 的字节序列化，`RasterCanvas` 已有逐行跨度填充的基础设施） | 中（~200 行 + 曲线离散化） | **治本**——两个后端真正等价，控件表现力解锁，且**守住"双轨"那条判据** |

**判据（这条比"支持更多 op"更重要）**：
**两个后端要么都支持，要么都响亮失败；不允许"一个强一个弱"。**
一旦出现"Skia 画得出来、Raster 画不出来但降级成近似"，同一个控件在两个后端就有两种像素——
这正是 Qt 双轨（QWidget + QML 两套渲染）被吐槽三十年的形状。

**为什么选 ②**：`RasterCanvas` 的 `drawEllipse` 已经是"逐行谓词跨度"（`rasterizeEllipse`），
多边形填充是同一个形状的推广（谓词从"椭圆方程"换成"奇偶规则 / 非零环绕"）。
这是**既有基础设施的直接复用**，不是新机制。而 ① 会引入"画出来是错的但不报错"——
正是这个仓最忌讳的形状（G2 静默）。

**改动面**：

| 文件 | 改什么 |
|---|---|
| `src/Rendering/RasterCanvas.php` | `drawPath()` 从 `unsupportedOp` 改成真实现（~200 行）；曲线先离散成折线（`quadTo` → 8 段） |
| `src/Rendering/CanvasCapabilities.php` | `CAP_PATH` 从 Optional 降到 Core；`pushClipPath` 同步 |
| `tools/golden-raster.php` | 新增 `path` 场景基线 |
| `src/Widget/CustomPaint.php` 文档 | "只画 Core"的限制解除 |

**判据**：① golden 新增 `path` 场景（三角 + 二次曲线），双后端逐字节一致；
② `RenderToggle` 的勾从"两条线段"改成"一条 `drawPath`"，像素断言不变（说明路径真的对）；
③ 现有 936 条全绿。

---

### B 级：增量，可穿插做

| # | 改什么 | 收益 | 代价 |
|---|---|---|---|
| **B1** | **布局自校验**（抄 `design/03` §6 `verifyLayout`）：smoke / golden 加一个 `--verify-layout` 模式——快照几何 → **忽略缓存强制全量重排** → 逐节点比对，不一致即抛 | 抓的是**整类**"两套实现不一致"：`intrinsic*` vs `performLayout`、`equals` 漏字段、算法藏状态、脏传播断链。★ 比"统一 intrinsic 推导"更便宜且覆盖更广（"lint 只校验公式被调用，校验不了公式内容对不对"） | **低**——纯测试侧，不动 `src/`；`Frame::run()` 已能无头跑，再跑一次重排即可 |
| **B2** | **`frame()` 阶段表**：把 9 段提成 `const STAGES = [...]` + 每段一个私有方法，`frame()` 只做循环 | 消灭 P5 的"第 7 段补丁"；阶段依赖可画出来 | 低——纯重构，`SurfaceProfile` 段键正好复用 |
| **B3** | **`BoxStyle` 显式标志**：`null/0 = 未设` 改成 `hasBorder` / `hasRadius` 布尔 | `->border($c, 0.0)` 能表达"我要 0"（现在表达不出，§24.2 #26 的遗留） | 低——值对象加两个字段，合并逻辑改一处 |
| **B4** | **修饰需求按四类正交切分**（借 Compose 的分类法，不借链式 API）：把"盒样式/输入声明/语义/布局约束"四类各自的入口写成一张对照表，进 `docs/architecture/08` | 新控件不会漏一类（现在 `cursor()` 漏在 `StatefulWidget` 上就是这么来的） | 低——**文档 + lint 规则**，不改代码 |
| **B5** | **死命令构建期校验**（兑现 `docs/_archive/DESIGN-v2-2026-10-14.md:79` 的 G1，不是新发明）：`tools/lint.php` 加一条 `dead-command` 规则——扫 `->command('x')` / `->bind(_, 'x')` 的字符串字面量，与 `commands()` 返回的键集合求差；**引用了不存在的命令 ⇒ lint 报错**（构建期，零运行时成本） | ★ 这不是"借鉴 WPF"，是**补上自己写了没做的规则**：`CommandRouter::names()` 的注释已声明它是"死命令 lint 的静态输入"，但 lint 里零命中。收益：把"点了没反应"从运行期 DIAGNOSTIC 提前到构建期 error | **极低**——`names()` 已存在，只需扫两个模式；`Button::command()` / `MenuItem::command()` / `KeyChord::bind()` 都是字符串字面量（实测 8 个命令名全部是字面量，无拼接） |
| **B6** | **滚动位置进约束**（Flutter `SliverConstraints`）：把 `RenderScrollView` 的 `appliedOffset()` 提升为 `ScrollConstraints` 的一部分，`layout()` 签名里能看见"滚到哪" | 布局仍是纯函数这件事变得**可验证**（现在靠"偏移在布局期写进子盒"的纪律，纪律不是契约）；滚动态的无头夹具更直接 | 中——`RenderBox::layout()` 签名要改（或加一个 `ScrollBox extends RenderBox` 分支），影响所有容器；**建议等 A1/A2 稳定后再做** |
| **B7** | **lint 规则自带反例自检**（`spec/13` 的 D-F1）：每条规则配一个"故意违反"的样例，`php tools/lint.php --self-check` 跑一遍，**规则没红就报"这条规则空转"** | Drift2 的反例自检是**手工的**（改坏代码 → 看红 → 还原），规则本身坏了没人知道。v4.1 记过"两个 lint 空转"的实例 | 低——每条规则加一个坏样例（可放 `_build/lint-fixtures/`），`--self-check` 跑两遍 |
| **B8** | **变体 × 状态矩阵 golden**（`spec/13` 的 D-W8）：每个控件的 (变体 × 状态) 出一张基准图，新增控件必须带 | 把"禁用态颜色不对"从上线发现变成提交时红。★ Drift2 现在只有零散像素断言，没有矩阵 | 中——场景数会涨（Button 4 变体 × 4 状态 = 16 张）；**先只做 `Button` + `Toggle` 两个**，验证成本可接受再推广 |

### C 级：记录，不排期

- **双轴虚拟化**（GPUI `DataTable` 的列虚滚）：`LazyColumn` 只有纵轴。等有宽表需求。
- **int 命令回放通道**：§24.2 已判"15 命令 / 52 处不值得"（24 §5.3 的 30 以内判据）。**不改判**。
- **GPU**：`GpuContext` 留口，3.14 ms 有 5.3× 余量。**不碰**。

### 明确否决（写下来免得再讨论）

| 提议 | 否决理由 |
|---|---|
| 引入 `measure` / `place` 分离 | 已判 no-op（布局位置无关 ⇒ 分离无收益；要收益必须有尺寸缓存，缓存层判不做）。`docs/EVAL-design-v7-2026-10-21.md` §7.1 已写死。 |
| 完全照搬 GPUI 的 base/component 两层库 | Drift2 是自绘单层，没有"表现层可替换"的产品需求（0 个第三方应用）。分层只到"行为基座 + 样式值对象"为止。 |
| Compose 式 `Modifier` 链 | PHP 无编译器插桩，AOT 下链式修饰器会退化成动态分派（每次 `->padding()` 一次方法调用）。用 `BoxStyle` 值对象够了。 |
| 把 `switch` 加回来 | AOT 硬坑（C2440），不是口味。`===` 链是纪律。 |

---

## 6. 建议的执行顺序

```
第零步（半天）  B5 命令名可检查        ← 独立、零冲突、收益立刻可见（WPF 教训）
第零步（1 天）  B7 lint 反例自检        ← 给已有规则装"规则自己坏了会红"；B5 的新规则直接受益
第一步（1 周）   A1 覆盖层栈            ← 做 Select 的硬前置，不然后面全是补丁
第二步（1 周）   A3 Raster 补 drawPath  ← 解锁表现力；守"两后端能力等价"（Qt 双轨警示）
第三步（2 天）   A2 控件基座            ← 纯等价重构，936 条夹具当护栏
第四步（穿插）   B2 阶段表 / B3 BoxStyle / B4 四类正交对照表 / B8 变体×状态矩阵 golden
第五步           然后才做 Select / DatePicker / Tooltip
最后（可选）     B6 滚动位置进约束       ← 动 layout 签名，等前面稳定
```

★ 顺序理由：
- **B5 排最前**：不依赖任何其他改动，半天就能做完，而且**立刻**消灭一类静默失败。
  它是这份报告里性价比最高的一条（WPF 用十年证明"连接不可检查"是最贵的债）。
- **B7 紧跟 B5**：`spec/13` D-F1 的教训是"**规则本身坏了没人知道**"（v4.1 记过"两个 lint 空转"）。
  Drift2 现在的反例自检是手工的——改坏代码看红不红，跑完还原 ⇒ 漏一次就永久失效。
  顺序上放 B5 之后，因为 B5 会新加规则，正好第一批进自检。
- **A1 必须在 Select 之前**：否则 Select 的下拉会把单槽位彻底撑爆（第三次补丁打不动）。
- **A3 在 A1 之后**：新浮层正好需要一个下拉箭头，而箭头现在只能两条线段拼。
- **A2 随时可做但别和 A1 同时**：两个都动 `Element` 与 `Application`，冲突面重叠。
- **B8 放穿插**：变体 × 状态矩阵 golden 是纯增量的测试资产，不阻塞任何改造；
  但越早建越好——A1/A3 落地时正好给新浮层补上第一组矩阵。
- **B6 放最后**：它要改 `layout()` 签名（影响所有容器），风险与收益不匹配当前阶段；
  现在的"偏移在布局期写进子盒"虽然靠纪律，但没出过问题（Flutter 那条是锦上添花）。

---

## 7. 这份评审不推翻什么

明确保留的既有裁决（避免下一个人重新讨论）：

| 已判 | 结论 | 出处 |
|---|---|---|
| `layout()` 单遍 | **保留** | `RenderBox.php:88` · EVAL §7.1 |
| 命令字符串名 | **保留**（15 命令不值得改 int）—— **但补可检查性（B5）**：裁决的是"派发方式"，不是"能不能校验" | `CommandRouter.php` · 24 §5.3 · 本报告 §2.5 |
| 控制流 `switch` 零处 | **保留**（AOT 硬坑） | `docs/architecture/11` A1 |
| `int $kind` + `===` 链 | **保留**（去虚化） | `ToggleBase.php:141` |
| 性能线关闭 | **保留**（3.14 ms / 5.3× 余量） | EVAL §7.1 |
| 双后端 + golden 逐字节 | **保留**（A3 是补强不是推翻） | `CanvasCapabilities.php` |
| 63 个控件一视同仁 | **部分推翻** —— 引入"复合行为根"家族（A1 的理论依据） | 本报告 §2 #2 |

---

## 8. 取数口径（怎么复核这份报告）

```bash
# 现状基线
find src -name '*.php' | wc -l                        # 223
wc -l src/App/Application.php                         # 2192
grep -ro "★" src/ | wc -l                             # 974

# P1 浮层单槽位
grep -n "互斥：先清槽位" src/App/Application.php
grep -n -A7 "public function run" src/App/Frame.php    # 只收一个 ?Element $popup

# P2 三件套
ls src/Widget/*State.php src/Widget/*Box.php

# P3 能力分级
grep -n "unsupportedOp" src/Rendering/RasterCanvas.php # 6 个方法 + 1 处定义
sed -n '16,32p' src/Rendering/CanvasCapabilities.php   # 三层表

# P4 内禀两套
grep -n "function intrinsicWidth" src/Layout/RenderBox.php   # abstract
grep -rn "extends RenderProxyBox" src/Layout/               # 靠转发的那批

# P5 frame 九段
sed -n '1001,1090p' src/App/Application.php

# P6 受控是补强
sed -n '31,33p' docs/architecture/00-README.md

# §2.2 #5 命令名零校验（WPF 教训的落点，四处裸字符串）
grep -n "'nav.users' =>" apps/users/src/UsersApplication.php      # 注册侧（字典键，:146）
grep -n -e "->command('" apps/users/src/Pages/UsersPage.php       # 引用侧（:71 :75 :89）
grep -n -e "->bind(" apps/users/src/UsersApplication.php           # 键盘绑定侧（:166-168）
grep -n "str_starts_with(\$name" apps/users/src/Commands/NavigationCommands.php  # 前缀分流（:23）
# ⇒ 四处之间没有任何交叉检查（写错不报错）
# ★ 诚实标注：当前 8 个命令名实测**全部有注册**（无死命令）⇒ 这是"没有护栏"而不是"已经掉下去"，B5 是提前装护栏。

# §2.5 修正二：G1 自己写了、lint 没做
sed -n '79p' DESIGN.md                    # "死命令…可构建期校验，GUI 却放运行期"
grep -c -i "command" tools/lint.php       # 0 —— 规则从未实现
grep -n "死命令 lint" src/Commands/CommandRouter.php   # names() 的注释声明了用途

# §2.2 #6 滚动位置是盒的私有字段、不在约束里
grep -n "appliedOffset" src/Layout/RenderScrollView.php
grep -n "class BoxConstraints" src/Geometry/BoxConstraints.php   # 里面没有 scrollOffset

# §2.4 #1 双轨风险：两个后端的能力差
grep -c "unsupportedOp" src/Rendering/RasterCanvas.php   # 7 = 6 个方法调用 + 1 处定义（这六个 op 只有 Skia 有）
```

### 跨代证据（`D:/TypePHP/Drift3/`）

```bash
D=/d/TypePHP/Drift3

# §2.6.1 证据一：v4.1 根本没有浮层模型
grep -rl "Overlay" $D/docs/spec/ | wc -l          # 0
grep -rl "Overlay" $D/design/ | wc -l             # 8 —— v5 已经定义清楚了

# v5 的 OverlayEntry 九字段 + 四条判断
sed -n '426,492p' $D/design/08-控件-主题-语义.md

# §2.6.1 证据二：布局从"每控件 performLayout"收敛到"算法 + 参数"
grep -n "10 个布局算法" $D/design/README.md
sed -n '139,147p' $D/design/03-布局.md           # 所有算法共同遵守的 5 条契约

# §2.6.1 证据三：后端默认的正面冲突
sed -n '369,376p' $D/design/04-绘制与呈现.md     # 为什么不用 Skia 做默认（三条理由）
grep -n "N1" $D/docs/Drift3-设计方案.md           # Drift2 选 Skia 的部署账单

# §2.6.2 布局自校验
sed -n '379,415p' $D/design/03-布局.md           # verifyLayout 全文

# §2.6.3 未守的三条纪律
grep -n "D-F1\|D-F12\|D-W8" $D/docs/spec/13-避坑纪律.md
```

---

> **一句话收尾**：Drift2 不缺能力，缺的是"**把第二个消费者提前到架构层去想**"的习惯。
> 六个补丁里有五个都是"第一个消费者能跑就收工"的后果。A1/A2/A3 三条的共同点，
> 都是把"第二次出现"变成"结构里本来就有"。
