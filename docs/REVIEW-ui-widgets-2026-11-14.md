# UI 控件体系 review（2026-11-14）

针对 HEAD `293aca1` 之后的 `src/Widget/`、`apps/`、`docs/` 做的一次产品视角复核。
维度：表现能力 / 真实需求 / 开发者体验与心智负担 / 架构与实现优化空间。
成熟 GUI 对照取自 Flutter `RenderObjectWidget`（官方建议继承 `Leaf/SingleChild/MultiChild`
而非裸写渲染对象）、React 官方「组合优先 + 纯函数组件」、WPF `Control Authoring Overview`
的 `UserControl → Control → FrameworkElement` 三档。

## 1. 结论

**表现能力是够的，真短板不是「控件不够」，而是「能力不可发现」。**
不加控件、不重构三棵树、不统一 `make()`/`new`。本轮把能力的真实边界钉回权威文档与
应用注释，并用一条零误报的静态判据锁住复发。

## 2. 四维度评估

| 维度 | 判断 | 实证 |
|---|---|---|
| 表现能力 | 足够，且有余量 | `src/Widget` 153 文件（顶层 143）；布局容器 25 / 基础显示 12 / 输入表单 30 / 导航外壳 8 / 数据展示 19 / 反馈浮层 9 |
| 真实需求 | 能实现 | 8 个 app / 16,928 行 / showcase 34 页全部由内置控件表达；应用侧 **0 个** `extends RenderBox`，唯一深度自绘是 `CustomPaintPage` |
| 开发者体验 / 心智负担 | **真短板** | 权威文档教已删的基类谱系；示例台注释宣称能力缺席；API 形态三套并存；自定义契约靠人肉 |
| 架构 / 实现 | 分层正确，优化空间真实但另立一轮 | 见 §5 |

架构对照结论：`Widget`（不可变描述）→ `Element`（身份 / 挂载）→ `RenderBox`（持久几何与
绘制）与 Flutter 同构；组合式 `StatelessWidget` / `StatefulWidget` 是应用侧主路径，
`RenderBoxWidget + RenderBox` 是真控件逃生舱，`CustomPaint` 是更轻的绘制逃生舱。
Drift 的「配置变了重新造盒」而不是 `updateRenderObject()`，是更简单的 AOT/PHP 侧取舍，
结构上消除了字段级半同步 —— **不为对齐 Flutter 而破坏**。

## 3. 关键发现：能力被过期快照系统性隐藏

`docs/00-README.md:44` 把 `docs/architecture/08-控件面.md` 列为「想加一个新控件」的
权威入口，而它当时：

- 基类谱系表里列 `BoxWidget`（抽象）、指向 `src/Widget/BoxWidget.php:25` —— **该类和文件都不存在**；
  真实继承是 `Button extends RenderBoxWidget`、`Card extends NullableChildRenderObjectWidget`、
  `Flex extends MultiChildRenderObjectWidget`、`SizedBox extends NullableChildRenderObjectWidget`。
- 写「控件共 131 / 顶层 121」——实测 153 / 143。
- §4.0 写「`Card` 是 `StatelessWidget`，把修饰转发给内部代理盒 `SizedBox`」——与实现相反。
- §10 的验证 grep 仍在找 `abstract class BoxWidget`。

应用侧同类污染：

- `FormPage.php` 类头宣称「通用卡片面板缺席 / 圆角卡片当前做不出」，但页末已写「①② 已补、
  仅 ③ 仍缺」，正文还引导「两处能力缺口见页末」—— 同一个文件内部自相矛盾。
- `ShowcaseRoot.php` 写「框架暂无此控件（SizedBox）」、`BoxWidget::background`、
  「`Flex extends RenderBoxWidget`，无盒样式」。
- `LayoutPage.php` 写 `BoxWidget::background/border/radius`。

而事实是：`background` / `border` / `radius` / `gradient` / `shadow` / `padding` 是
`WidgetModifiers` trait 里挂在根 `Widget` 上的 public 方法，**所有控件通用**；
`SizedBox::make()->size()->background()->radius()` 在 `LayoutPage.php:91` 真实可用；
`Card::make` 用于 34 个页面。

⇒ 开发者读权威文档会以为能力不够，于是造轮子或绕路。这才是心智负担的来源，不是缺控件。

`tests/Verify/VerifyPlan.php:26` 明确「文档检查已退役，V2 没有 docref / apidoc 的位置」——
没有任何判据守文档与代码一致，是这类腐烂反复出现的根因。

## 4. 否决的方案（本轮授权可破坏兼容性，但论证后不破坏）

| 候选 | 否决理由 |
|---|---|
| 统一 `make()` / `new` 形态 | 83 类有 `::make()`、53 个 public ctor、41 个双通道、42 个仅 make、12 个仅 new；统一要改 41+ 类 × 全部应用调用点 × AOT 全量重编，收益只是形态一致。双通道是 PHP 生态常态，真负担是「不知道哪套可用」而非「两套」 |
| 新增 `Page` / `Section` 收口 18 页 `Card(ScrollView(body))` 样板 | showcase 自包含重复是有意的可读性取舍；新增类在 AOT 下要确认 `.cc` 生成，收益只落应用侧 |
| 拆 `TableState`（2731 行）/ 合并滚动四份实现 | 真实欠账，但属独立一轮重构，混进来会炸验证面；见 §5 登记 |
| 对齐 Flutter `updateRenderObject()` | 引入字段级半同步，是倒退 |
| 全量 lint 强制 `markFocusable()` / `semantics()` / `setInteraction()` | 试算 4 处 `$this->keyboard(`：`Menu extends Button` 靠继承合法获得可聚焦性，包裹式控件（`ComboBox` 包 `TextField`）不声明也是合法形态；强制会逼出白名单，误报比漏报贵 |
| 维护「禁止词黑名单」防注释腐烂 | 易碎、要跟着文案改；改用「权威文档引用的 `src/*.php` 路径必须真实存在」，零误报、无需维护词表 |

★ 「授权可破坏」≠「必须破坏」。本轮问题不在 API 设计，文档与代码的一致性可以在
不改任何公开签名的前提下解决，破坏反而引入 AOT 风险。

## 5. 登记欠账（本轮不做，独立一轮）

- 巨型文件：`TableState.php` 2731、`Application.php` 1934、`RenderBox.php` 1131、
  `FocusManager.php` 995、`InputRouter.php` 873、`ComboBox.php` 646。
- 滚动近似实现：`ScrollMotion.php:15` 自述「三份实现」、`ScrollLane.php:11` 写「当前四个：
  全量 / 虚拟化 / 表格 / 多行输入」，口径不一；曲线已下沉 `AnimatedScalar`。
- `Widget` 基类合入 4 个 trait，形成 38 条概念面，其中 24 条「漏了不报错」的隐性契约
  （本轮已把第 ③ 档真控件契约做成静态对账，其余仍是文档 + 人肉）。
- 示例台 UI 文案 `FormPage` 的「两处能力缺口见页末」措辞仍偏旧；页末内容本身真实
  （①② 已补、③ 仍缺），改文案会动 golden，单独一轮处理。

## 6. 本轮落地

1. `docs/CUSTOM-WIDGET-GUIDE-2026-11-05.md`：§3 改成三档决策树（组合优先 → `CustomPaint`
   → `RenderBoxWidget`），§7 骨架删掉不可用的 `setInteraction`，§11 补契约自检清单与边界。
2. `tests/Unit/Widget/CustomWidgetContractTest.php`：对账 `RenderBox` 五件套、三个 opt-out
   钩子、§7 骨架与真实可编译夹具的方法名。
3. `tests/Unit/Widget/WidgetCapabilityDiscoveryTest.php`：两个入口文档的 `src/*.php` 路径
   全存在、幽灵基类不被引为现存类、修饰能力确实挂在所有 Widget 上，含提取器变异自检。
4. 订正 `08-控件面.md`（谱系表 / 控件数 / §4 / §4.0 / §10 grep）与三个示例台注释。
5. `src/Widget/` 13 个文件清掉裸数字 / 章节号 / 批次号 / 与实现相反的旧口径。

判据不复活已退役的 docref 通道：只覆盖 `00-README.md` 指定的「加新控件」入口与三个
示例台注释，历史设计稿（`docs/arch-v2/`、`REVIEW-2026-10-*`）是当时的事实记录，不进判据。
