# 自定义控件

> 本篇解决：什么时候该组合现成控件、什么时候才自绘，以及自绘时怎样接线、声明形状、避开那些"看着正常却悄悄失效"的坑。

## 优先组合，再考虑自绘

三棵树的分工是 `Widget`（不可变描述）→ `Element`（身份与挂载）→ `RenderBox`（持久几何与绘制）。`Element::syncBox()` 是全仓唯一的造盒点：Widget 配置一变就重新造一个盒换掉，不存在"字段级半同步"。自定义控件先按下面的顺序选最轻的路径，越靠后越要自己承担契约。

| 路径 | 定位 | 要写的 | 形状 / 焦点 |
|---|---|---|---|
| `StatelessWidget::build()` 组合 | 把已有控件拼成可复用控件，**默认首选** | 一个纯 `build(BuildContext): Widget` | 跟着孩子走 |
| `CustomPaint` + `CustomPainter` | 逃生舱：曲线 / 仪表盘 / 示波器 | 一个纯 `paint($canvas, $size)` | 不接，仍是整盒矩形 |
| `RenderBoxWidget` + `RenderBox` | 真控件：有布局、交互、焦点、语义 | 造盒 + 布局 + 绘制 + 形状声明 | 可覆写 `focusShape()` / `paintInteraction()` |

能用前两条搭出来的，不要造盒。第 ② 档的画家只负责内容，**不接反馈与焦点形状**：需要键盘用户看得见焦点时，必须走第 ③ 档，不能用画家凑。

### 组合式入口

`build()` 必须是纯函数：同样的入参与 `$ctx` 给出同样的子树；业务数据从构造器注入，不从 `$ctx` 取。链式设值方法必须调 `final protected mutate()`，否则能绕过封存、在挂载后改字段；封存后写入本来会在 `mutate()` 里响亮失败。包装别人的孩子时用 `clone $kid` 再贴自己的修饰（`__clone()` 会重置封存位），不要把同一个描述实例漏给两处。

需要一个"要跨 build 活"的值时才换 `StatefulWidget`：它实现 `createState()`，值放进 `State` 字段；更新时 `Element` 复用同一个 `State`，新描述通过 `didUpdateWidget()` 送达，不是换一个 State 实例。

### 逃生舱入口

`CustomPainter` 是 `(canvas, size)` 纯函数：同输入必然同墨。动画相位住在 `State`（`tickFrame()` 驱动、重建换新画家），不住画家；`version()` 是内容戳。画家尺寸由构造给的宽高决定；未给的轴吃满约束上限，无限则响亮失败。

## 自绘式：五步接线

以下几步只适用于第 ③ 档。

1. **Widget**：继承 `RenderBoxWidget`，字段 readonly、构造器即终态。构造里必须调 `parent::__construct()`（漏了 `$mods` 未初始化，`syncBox()` 读它时 Fatal）。要进 Tab 链还必须调 `markFocusable()`——默认 `false`，这是最容易漏的一步。
2. **造盒**：`createRenderObject(BuildContext $context)` 只读 Widget 自己的字段。主题 token 只在这一层解析成 `Color` / `BoxStyle` 传进盒，Layout 层不依赖 Theme。末尾按需调 `setInteraction($hover, $pressed)` 声明两相位反馈色（不接反馈就给两个 `null`，零成本）。
3. **`Element::syncBox()`**（框架做）：注入 `mods`、`defaultBoxStyle()`、光标；按 `paintsBoxChrome()` 剥掉视觉三字段、按 `paintsDisabledLook()` 去掉 dim；不产盒的 Widget 套了修饰会响亮失败而不是静默丢弃。
4. **盒**：继承 `RenderBox`，实现 `performLayout()` / `contentIntrinsicWidth()` / `contentIntrinsicHeight()` / `paintContent()` / `visitChildren()`。
5. **形状与视觉**：回答下面两条形状契约，按需覆写钩子。

绘制层序由 `RenderBox::paint()`（`final`）钉死，不可覆写：① 装饰（`mods` 的 `BoxStyle`，在裁剪之外）→ ② `paintOwnChrome()` 自绘本体与轨道 → ③ `paintInteraction()` → `paintStateLayer()` 状态层 → ④ `paintContent()` 内容（盒局部坐标）。想让状态层压住某块图元，就把那块图元挪进 `paintOwnChrome()`；写在 `paintContent()` 里会被反馈盖住。

## 形状是两条契约

| 契约 | 覆写点 | 默认 | 触发方 |
|---|---|---|---|
| 指针反馈形状 | `protected paintInteraction(?Size)` → `paintStateLayer(Rect, radius, strokeWidth)` | 整盒矩形 + `outlineRadius()` | hover / press |
| 键盘焦点轮廓 | `protected focusShape(): ?Rect` + `protected focusRadius(): float` | 整盒矩形 + `outlineRadius()` | `AppShell::paintFocusRing()`（`final` 画法） |

不合成一条声明是有原因的：滑杆的 hover 光晕绕拇指（指针指哪儿反馈在哪儿），键盘焦点环绕整条轨道（焦点在控件上，拇指会动），两者语义不同。内置控件里 `RenderToggle` 的焦点只罩方框、`RenderSegment` 的药丸由 `pill()` / `pillRadius()` 四处共用一份算式、`RenderSlider` 绕整条轨道并跟随轨道圆角。

`paintFocusOutline()` 是 `final public`：取形状 → 加 `absoluteOrigin()` → 外扩 1px → 半径按同心放大 → 一条 `drawRect`。**坐标语义必须分清**：`paintContent()` / `paintInteraction()` / `focusShape()` 都是盒局部坐标（有 padding 时基类已 `translate`），`paintFocusOutline()` 画的是绝对坐标。开发者只回答局部形状，绝对换算由 final 画法做。

## 最小示例

下面的骨架就是第 ③ 档的全部必做项；三个可选钩子按需加，不要塞回骨架。

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
        $this->markFocusable();   // 漏了就不进 Tab 链
    }
    public function createRenderObject(BuildContext $ctx): RenderBox
    {
        return new MyBox();
    }
}
```

需要反馈时在 `createRenderObject()` 里对盒调 `setInteraction($ctx->color(ColorToken::Hover), $ctx->color(ColorToken::Pressed))`；需要焦点环跟随本体形状时覆写 `focusShape()` / `focusRadius()`；需要指针反馈区与本体不同时覆写 `paintInteraction()`，给 `paintStateLayer()` 传一个 `Rect`（描边型反馈再给 `strokeWidth > 0`）。

## 常见静默坑

**属性可见性。** 会被继承的类，字段不能是 `private`：tpc 按运行时类型查属性表，跨实例读 `private` 字段会静默取错；用 `protected readonly`。可空对象字段上直接 `?->` 会按运行时类名查表读，读成 `null` 也是静默的——字段层继续用"非空 + `bool` / 哨兵"的形状（例如 `RenderBox` 的 `child` + `hasChild`）。属性读**直接当实参**会撞 AOT 的 `C2440`，先把它落进局部标量再传。`paintsBoxChrome()` 返 `true` 时 `syncBox()` 会剥掉 mods 视觉三字段，控件有义务在 `createRenderObject()` 里把用户样式合并进自持 `BoxStyle`，否则 `->background()` 变死字段。

**闭包 arity。** 解释器会静默放行多传的参数，AOT 直接编译错（`expects exactly N arguments, M given`）。所有回调签名要逐字对齐：`AsyncValue::fold()` 四支必须同为 `callable(AsyncValue): mixed`，少一支当场抛；`then()` / `catch()` / `onProgress()` / `JobProgress` 的上报回调按各自声明的参数个数写。给盒构造加参数一律**追加在末尾**，插中间会静默错位。

**受控语义。** 语义角色是**赋值**，不是覆写：角色由控件在自己的构造 / 设值处给 `Widget::semantics()` 字段赋值，绝不提供同名的虚方法让子类覆写——tpc 的去虚化会静默忽略覆写，症状是"所有节点角色为空"且不报错。同时，纯布局 / 装饰 / 动画包装（`Padding` / `Row` / `Stack` / `Card` 等）一律不给角色，叶子内容（`Text` / `Icon` / `Image`）也不给：Drift 没有语义合并，`hitTest()` 取最深节点，给 `Text` 角色会让光标移到按钮上时选中里面的文字。需要"文本可被找到"就用外层控件的 `semName`。

其它容易踩的：`markFocusable()` 漏调（Tab 无任何现象）；`createState()` 不返回 `State`（AOT 签名错）；`?:` 两分支类型不同撞 `C2678`，按标志选已解析值统一写 `if/else`；`radius(0)` 不表达"我要直角"（会被回落到主题值）；测试只断"有像素变化"——形状退回整盒矩形时照样全绿，要成对断言（直角顶点不吃色 + 弧线吃色、盒心不是强调色、上边不是满格）。

## 相关

- [用 Widget 组合界面](./widgets.md)
- [语义与无障碍](./semantics.md)
- [AOT 约束与代码纪律](../reference/aot-constraints.md)
- [诊断与错误码](../reference/diagnostics.md)
