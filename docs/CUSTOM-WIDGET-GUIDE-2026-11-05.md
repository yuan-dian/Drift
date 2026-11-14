# 设计分析：自绘控件与开发者自定义控件流程

日期：2026-11-05 · 状态：**已实施**（记录见 `DEBT-2026-11-02.md` §51）· 兼容性：**可破坏**

## 1. 这份文档回答什么

两件事：① 现有自绘控件（开关 / 分段 / 滑杆 / 输入域）的形状与反馈是怎么组织的；
② 一个第三方开发者从零写一个自定义控件，要走哪几步、覆写哪些钩子、哪些坑会静默出错。

结论先行：**形状是两条独立契约**（指针反馈形状 / 键盘焦点轮廓形状），不是一条。
框架把「画法」收成 `final` 一份，把「形状」留成两个覆写点；开发者只回答形状，
不碰绝对坐标、外扩与同心。

## 2. 体系全景

三棵树：**Widget**（不可变描述）→ **Element**（身份与挂载）→ **RenderBox**（持久几何与绘制）。
`Element::syncBox()` 是全仓唯一的造盒点：Widget 配置一变就**重新造一个盒**换掉，
所以不存在「字段级半同步」；hover / press 斜坡这类运行时状态靠 `inheritRuntimeState()`
从旧盒移交（只认同类型、用 `get_class()` 而非 `$previous::class`）。

绘制层序由 `RenderBox::paint()`（final）钉死：

| 顺序 | 层 | 谁画 |
|---|---|---|
| ① | 装饰 | `mods` 的 `BoxStyle`（底 / 边 / 圆角），在裁剪之外 |
| ② | 自己的 chrome | `paintOwnChrome()`：控件自绘本体与轨道 |
| ③ | 状态层 | `paintInteraction()` → `paintStateLayer()`（final 原语） |
| ④ | 内容 | `paintContent()`，坐标系是**内容盒原点** |

★ 层序不可覆写：想让状态层压在某块图元之上，只能把那块图元挪进 `paintOwnChrome()`。
§48 的分段选中药丸、滑杆轨道就是这样各挪了一格，静止态零像素变化 ⇒ 整页 golden 不刷基线。

两相位反馈的通用部分住在 `RenderObject`：`setInteraction(?Color $hover, ?Color $pressed)`
是唯一写点，声明了才 `acceptsHover()` / `acceptsPress()` 为真；`paintStateLayer()` 负责
hover 在下、press 在上、按进度淡入、两色皆 null 时零命令早退。**状态机通用，画什么是私有的**。

## 3. 三个入口，先选最轻的

组合优先：能靠现有控件搭出来的，就不要造盒。三档从轻到重，越靠后越要自己承担契约。
Flutter 官方建议继承 `Leaf/SingleChild/MultiChildRenderObjectWidget` 而非裸写渲染对象、
React 官方强调函数组件与组合、WPF 的 `UserControl → Control → FrameworkElement` 三档，
都是同一条判据。

| | ① `StatelessWidget::build()` 组合 | ② `CustomPaint` + `CustomPainter` | ③ `RenderBoxWidget` + `RenderBox` 子类 |
|---|---|---|---|
| 定位 | 把已有控件拼成可复用控件（**默认首选**） | 19 op 逃生舱：仪表盘 / 曲线 / 示波器 | 真控件：有布局、交互、焦点、语义 |
| 要写的 | 一个 `build(BuildContext): Widget` 纯函数 | 一个 `paint($canvas, $size)` 纯函数 | 造盒 + 布局 + 绘制 + 形状声明 |
| 新增类 | 1 个 Widget | 1 个 Painter | Widget + RenderBox 两个 |
| 尺寸 | 由孩子与修饰决定 | Widget 构造给宽高；未给的轴吃满约束上限，无限则响亮失败 | `performLayout()` 自己夹约束 |
| 交互反馈 | 跟着孩子走 | **不接**：画家只画内容，状态层仍是整盒 | 可覆写 `paintInteraction()` |
| 焦点轮廓 | 跟着孩子走 | **不接**：仍是整盒矩形 | 可覆写 `focusShape()` / `focusRadius()` |
| 禁用 / 语义 | 跟着孩子走 / 走外层组合 | 走外层组合；不给语义角色 | `paintsDisabledLook()` 等声明 |
| 额外契约 | 无 | 无 | 见 §11 清单 |

★ 第 ① 档只有一条纪律：`build()` 必须是纯函数（同样入参 + 同样 `$ctx` ⇒ 同样输出），
业务数据从构造器注入，不从 `$ctx` 取。需要"记住"点什么就换 `StatefulWidget`。
★ 第 ② 档的 `CustomPainter` 是 `(canvas, size)` 纯函数：同输入必然同墨。动画相位住 `State`
（`tickFrame()` 驱动、重建换新画家），不住画家；`version()` 是内容戳，今天只有诊断 HUD 消费。
★ 需要反馈或焦点视觉时，**不能**用第 ② 档凑：那是「画出来了但键盘用户看不见焦点」的形状。
★ `keyboard()` 不要求声明者自己可聚焦：`FocusManager` 沿焦点控件的父链找最近的声明者，
包裹式控件（`ComboBox` 包 `TextField`）是合法形态。别因为"没调 `markFocusable()`"就给
父链上的包裹者补一条 —— 它本来就靠子控件进焦点链。

## 4. 第 ③ 档：真控件的五步接线

以下五步只适用于 §3 的第 ③ 档。第 ① ② 档不用走这条路，也不承担下面的契约。

1. **Widget**：继承 `RenderBoxWidget`，字段 readonly、构造器即终态。构造里必须
   `parent::__construct()`（漏了 `$mods` 未初始化 ⇒ `syncBox()` 读它时 Fatal）。
   要进 Tab 链还必须调 `markFocusable()` —— 默认 `false`，这是最容易漏的一步。
2. **造盒**：`createRenderObject(BuildContext $context)` 只读 Widget 自己的字段。
   主题 token 只在**这一层**解析成 `Color` / `BoxStyle` 传进盒；Layout 层不依赖 Theme。
   构造末尾调 `setInteraction($hover, $pressed)` 声明两相位反馈色（禁用态给两个 null ⇒ 零成本）。
3. **`Element::syncBox()`**（框架做，不用你写）：注入 `mods`、`defaultBoxStyle()`、
   光标；按 `paintsBoxChrome()` 剥掉视觉三字段、按 `paintsDisabledLook()` 去掉 dim；
   不产盒的 Widget 若套了修饰会**响亮失败**而不是静默丢弃。
4. **盒**：继承 `RenderBox`，实现 `performLayout()` / `contentIntrinsicWidth()` /
   `contentIntrinsicHeight()` / `paintContent()` / `visitChildren()`。
5. **形状与视觉**：见下一节。

坐标语义必须分清：`paintContent()` / `paintInteraction()` / `focusShape()` 都是**盒局部坐标**
（有 padding 时基类已 `translate`），而 `paintFocusOutline()` 画的是**绝对坐标**
（`absoluteOrigin()` 累加父链偏移）。新的 `focusShape()` 让开发者只写局部坐标，
绝对换算由 final 画法做。

## 5. 形状是两条契约

| 契约 | 覆写点 | 默认 | 谁触发 |
|---|---|---|---|
| 指针反馈形状 | `protected paintInteraction(?Size)` → `paintStateLayer(Rect, radius, strokeWidth)` | 整盒矩形 + `outlineRadius()` | hover / press |
| 键盘焦点轮廓 | `protected focusShape(): ?Rect` + `protected focusRadius(): float` | 整盒矩形 + `outlineRadius()` | `Application::paintFocusRing()`（`final` 画法） |
| 本体圆角 | `public outlineRadius(): float` | `mods->boxStyle()->radius` | 上面两者的默认值 |

`paintFocusOutline()` 在 §51 起是 **final public**：取形状 → 加 `absoluteOrigin()` →
外扩 1px → 半径 `r > 0 ? r + 1 : 0`（2px 内描边的同心外扩）→ 一条 `drawRect`。
画法只此一份，子类不能覆写；形状由两个钩子回答。

**为什么不合成一条形状声明**：滑杆的 hover 光晕绕**拇指**（指针指哪儿反馈在哪儿），
键盘焦点环该绕**整条轨道**（焦点在控件上，且拇指会动）。两者语义不同，
强行共用一份声明会画错。浏览器 `outline` 独立于 `:hover` 背景、WPF `FocusVisualStyle`
独立于 `ControlTemplate`、Flutter `FocusNode` 与 `WidgetState` 分离 —— 成熟框架都是两条契约。

三个内置自绘控件的落点：

| 控件 | `focusShape()` | `focusRadius()` | 理由 |
|---|---|---|---|
| `RenderToggle` | 本体（不含标签） | 方框 `0.18×boxSize`；开关/圆点 `boxSize/2` | 整行框色像列表高亮，与 §48 光晕同一裁决；浏览器 checkbox 的 focus ring 也只罩方框 |
| `RenderSegment` | 药丸（四边内缩 `inset`） | `min(w,h)/2` | 段盒比药丸大一圈，环套段盒会跑出轨道 |
| `RenderSlider` | 默认整盒 | `trackHeight/2` | 键盘焦点绕整条滑杆，但要跟随轨道圆角；形状与 hover 拇指刻意不同 |

`RenderSegment` 把药丸几何抽成 `pill()` + `pillRadius()`，选中块、状态层、两个焦点钩子
四处共用一份算式（圆角夹到半短边：软光栅会夹、Skia 桥不夹）。

## 6. 多维度方案比较与裁决

| | A 只补文档 | B 各控件覆写 `paintFocusOutline()` | **C final 画法 + 形状查询（裁决）** | D `Shape` 值对象统一三类形状 |
|---|---|---|---|---|
| 结构 | 形状仍散三处 | 画法抄 3 份 | 画法一份、形状各一处，与 §48「原语 + 形状」同构 | 一份声明，但语义错配 |
| 实现 | 0 | 中 | 小（零新增类、零数组） | 大（新类 + API 面 + AOT） |
| 扩展性 | 差 | 差 | 好：覆写局部 `Rect` + `radius` | 好 |
| DX | 差 | 中 | 好：不碰绝对坐标与画笔 | 中 |
| 维护性 | 差 | 最差 | 好：几何算式收成钩子 | 中 |
| 风险 | 低 | 中（抄错静默） | 低：该方法全仓零覆写者、默认逐像素等价 | 高 |
| 真实消费者 | 0 | 3 | 3（Toggle / Segment / Slider） | 0 |

**借鉴边界**：Drift3 的六份资料（blueprint 三册 + 补册 + 审查意见 + ArkUI 研读）
对「形状 / 轮廓 / 焦点契约」**完全未涉及**，可借的只有「扩展点收敛为三个、编译期可查、
不开放运行时插件」与「`Style` 是 `RenderBox` 字段集合、不是运行时链」两条架构原则。
据此否决 D 那种新声明通道，也否决 `->feedback()` 式的零消费者 API。

## 7. 最小可运行示例（第 ③ 档）

`tests/Feature/App/SelfDrawnFocusRingTest.php` 末尾的 `ShapeProbeBox` / `ShapeProbeWidget`
是一份可编译、可跑的骨架。下面是它的最小形态，逐方法对应；`CustomWidgetContractTest`
会对账两边的方法名，改一边要改另一边。

```php
final class MyBox extends RenderBox
{
    protected function performLayout(BoxConstraints $c): Size
    {
        return $c->constrain(new Size(80.0, 40.0));
    }

    protected function contentIntrinsicWidth(float $h): float { return 80.0; }
    protected function contentIntrinsicHeight(float $w): float { return 40.0; }
    protected function paintContent(Canvas $canvas): void { /* 画本体 */ }
    public function visitChildren(callable $visitor): void { }
}

final class MyWidget extends RenderBoxWidget
{
    public function __construct()
    {
        parent::__construct();
        $this->markFocusable();              // 漏了就不进 Tab 链
    }

    public function createRenderObject(BuildContext $ctx): RenderBox
    {
        return new MyBox();
    }
}
```

**上面就是全部必做项**。`ShapeProbeBox` 多覆写的 `focusShape()` 是为了测形状，
不是最小骨架的一部分。下面三条是可选的，按需加，不要塞回骨架：

- 需要两相位反馈 ⇒ 在 `createRenderObject()` 里对盒调
  `setInteraction($ctx->color(ColorToken::Hover), $ctx->color(ColorToken::Pressed))`；
  不接反馈时两个状态层零命令早退，没有成本。
- 需要焦点环跟随本体形状 ⇒ 覆写 `focusShape()` / `focusRadius()`（§5）；
  默认是整盒矩形 + `outlineRadius()`。
- 需要指针反馈区域与本体不同 ⇒ 覆写 `paintInteraction()` 把 `Rect` 传给
  `paintStateLayer()`；描边型反馈（输入框那种底与 Hover 太近）给它 `strokeWidth > 0`。

## 8. 坑清单（都曾在本仓真实发生过）

- **AOT 校验实参个数**：给盒构造加参数一律追加在**末尾**，插中间会静默错位、AOT 下编译错。
- **`?:` 分支类型不同**会撞 tpc 的 C2678；按标志选已解析值统一写 `if/else`。
- **`paintsBoxChrome()` 的描边不幂等**：`true` 时 `syncBox()` 剥掉 mods 视觉三字段，
  控件有义务在 `createRenderObject()` 里把用户样式合并进自持 `BoxStyle`，否则 `->background()` 变死字段。
- **`->radius(0)` 不表达「我要直角」**：`Button::resolveBoxStyle()` 用 `radius > 0` 判断「设过没有」，
  0 会被回落到主题 6.0。要方角得用 `BoxStyle::none()` 一类真正 radius=0 的路径。
- **`markFocusable()`**：不调就不进焦点链，Tab 无任何现象。
- **圆角谓词反直觉**：radius>0 时直角顶点**不吃色**、弧线吃色；radius=0 恰好反过来。
  判据必须成对，只写「顶点不变」会把「整个角都不画」判绿。
- **状态层与 chrome 的层序**：状态层固定压在 `paintOwnChrome()` 之后；把轨道/选中块
  写在 `paintContent()` 里会被反馈盖住，写在 `paintOwnChrome()` 里才反过来盖住反馈。
- **`CustomPaint` 不接形状契约**：画家只画内容，别指望它有跟随形状的焦点环。
- **判据别只断「变了没有」**：形状退回整盒矩形时，只量「有像素变化」的测试照样全绿。

## 9. 验证口径

- 像素判据成对：直角顶点不吃色 + 弧线/上边吃色；再加「形状内缩」「盒心不是 Accent」
  「上边不是整行满格」等，分别钉住形状查询、描边笔、圆角。
- 牙齿检查 8 组变异全红且各自精确命中：忽略 `focusShape()` / 漏 `absoluteOrigin` /
  去掉同心 `+1` / 描边换填充 / 三个控件分别撤掉形状或半径覆写。
- 焦点环只在键盘模态画（`keyboardFocusVisible` 初值 false），首帧不画 ⇒ 两套 golden 零基线刷新。
- `paintFocusOutline()` 默认实现与改动前**逐像素等价**（`focusShape()` 默认整盒、
  `focusRadius()` 默认 `outlineRadius()`），§50 的既有判据一字不改仍全绿，只有「拿 Toggle
  当方角控件」那条因 Toggle 现在自绘本体形状而改为 Flat 变体。

## 10. 明确没做

- `CustomPaint` 逃生舱的形状契约：画家协议刻意保持纯函数，形状诉求应走完整控件路径。
- 自绘控件的焦点轮廓**形状**与状态层形状仍各自覆写（§5 已论证这是对的），没有强行统一。
- 焦点环不跟随合成变换（`setCompose()` 的平移/透明度）—— 现状如此，单独一轮。

## 11. 自定义控件契约自检清单

只列第 ③ 档的履约项。「漏了不报错」的那几条才有判据；PHP / lint 已经强制的不重复造闸门。

| 义务 | 漏了的现象 | 谁守 |
|---|---|---|
| `RenderBox` 五个 abstract 方法全部实现 | PHP 当场 Fatal（不是静默） | PHP 自身；`CustomWidgetContractTest` 对账方法集合 |
| Widget 构造器调 `parent::__construct()` | AOT 报 `The parameter object must be object, got null`，栈顶看不出是哪层 | lint `app-subclass-ctor-parent` |
| 要进 Tab 链就调 `markFocusable()` | Tab 无任何现象，键盘回调永不触发 | §4 第 1 步；无静态判据（见下方边界） |
| `createState()` 一律返回 `State` | AOT 签名错 | `CreateStateSignatureTest` |
| `createRenderObject()` 只读自己的字段 | 主题 token 漏进 Layout 层 | §4 第 2 步；lint `dependency-direction` |
| `paintsBoxChrome()` 返 `true` ⇒ 合并用户 `BoxStyle` | `->background()` 变死字段（静默） | `RenderBoxWidget` docblock + §8 |
| `paintsDisabledLook()` 返 `true` ⇒ 真的按禁用换色 | 点不动但看起来完全正常 | `RenderBoxWidget` docblock |
| 三个可选钩子保持 opt-out 默认值 | 声明与 `syncBox()` 消费不一致 | `CustomWidgetContractTest` 对账默认值 |
| 盒构造追加参数一律放末尾 | AOT 下实参静默错位、编译错 | §8 坑清单 |
| 文档 §7 骨架与实际一致 | 开发者照抄踩坑 | `CustomWidgetContractTest` 对账方法名 |

★ **边界：没有一条规则要求所有控件都调 `markFocusable()` / `semantics()` / `setInteraction()`。**
这三条是按能力可选的；`keyboard()` 更特殊 —— 它沿焦点控件父链找最近的声明者，
包裹式控件（§3 末条）不调 `markFocusable()` 是合法的。试算过全仓 4 处
`$this->keyboard(`：`Menu` 靠继承 `Button` 获得可聚焦性，其余 3 处自带
`markFocusable()`。把它们一律强制会逼出白名单，反而废掉闸门。
判据只守「声明了要就得履行」，不守「没声明就是错」。
