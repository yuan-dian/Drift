# 约束布局实战

> 本篇解决：怎样用一套单向约束把界面排好，以及滚动、溢出、自定义盒这些地方怎么做才不静默出错。

## 约束下行、尺寸上行

布局是单遍的。`RenderBox::layout(BoxConstraints)` 是 `final`，子类只实现 `performLayout(BoxConstraints): Size`：父把约束递下来，子在这一遍里算出自己的尺寸并给自己的子定位，尺寸再往上回。没有 `measure` / `place` 两步分离。

`layout()` 做三件事：调 `performLayout()` 拿测量尺寸；检查 C6——只有**超过上界**才算违规，下界提升不算（父把尺寸定死是本职工作）；把结果用 `constraints->constrain()` 钳回约束内。所以在 `performLayout()` 里拿到 `Size` 后，真正被采用的是钳过的尺寸，算子节点位置要基于它。

方向是单向的：父给子 `layout()` 和 `setOffset()`，子永远不自己定位。`Element::adoptBoxes()` 后序把子盒收进父盒；绝对原点只有 `RenderBox::absoluteOrigin()` 一处沿父链累加，命中测试、IME 候选框、菜单锚点、语义几何都走它——自己沿 Element 链累加会重复算。

布局是纯函数：同样的约束必须给出同样的尺寸与子偏移，不要在 `performLayout()` 里读时钟、写字段或发事件。重排时机由调用方决定。

## 常用容器与对齐

组合优先，别先写盒：

- `Row` / `Column` / `Each` 继承 `Flex`，用 `children(array)` 给子，`spacing(float)` 给间距，`paddingAll(float)` 给内边距，`cross(FlexAlign)` 定交叉轴对齐（`FlexAlign` 有 `Start` / `Center` / `End` / `Stretch`），`overflow()` 定策略。
- `Padding::make($kid)->all()` / `symmetric()` / `only()`：只加内边距，尺寸由子决定。
- `Center::make($kid, $alignment?)`：居中或按 `Alignment` 放。
- `SizedBox::make($kid)->size($w, $h)`：定尺寸；`Expanded::make($kid)->flex($share)`：在主轴上吃弹性份额，`RenderFlex` 读 `flex()`。
- `Offstage`：跳过 layout，尺寸为 null，命中测试自然为 null——切页保活就是靠它，不靠销毁。

修饰是盒的属性，`background` / `border` / `radius` / `padding` 一类方法写进 `Modifier`，`Element::syncBox()` 拿到盒后再 `setMods()`。不产盒的控件（`StatelessWidget`、没覆写 `createRenderObjectFromState()` 的 `StatefulWidget`、`ScrollView`、`ThemeScope`、`Offstage`）上直接套修饰不会生效，运行期撞守卫才 Fatal。要加边距就套 `Padding::make($x)`，别在组合控件上写 `->paddingAll(24)`。

`SizedBox` 只给宽度、让高度交给内容更稳：高度小于内容会触发 C6，把整帧拖垮。

## 无界用 INF，不用 0 哨兵

`BoxConstraints`（在 `src/Geometry/`）用 `INFINITY = INF` 表示无界：`__construct()` 默认两轴 `min = 0.0`、`max = INF`。`unbounded()` 给两轴都无界，`capWidth($maxWidth)` 给"只限宽"的松约束，`tight(Size)` / `loose(Size)` 给紧/松约束，`hasBoundedWidth()` / `hasInfiniteHeight()` 等回答查询。

绝不拿 `0` 当"不限"的哨兵：0 是合法的上界，把它和无界混用会让"这段内容有多长"变成"这段内容不占地方"。也不要在调用点手写 `BoxConstraints::INFINITY`——只限宽走 `capWidth()`，让 `INF` 与数字的约定集中在一处。`constrain()` / `tighten()` 是纯函数，布局是纯函数的前提也靠它们。

`tight()` 把尺寸定死不是违反 C6；`maxWidth` 超了才是。窗口帧会把违规降级为裁剪加诊断，纯布局（测试、快照）仍然中止。

## 溢出策略必须显式

`Overflow`（`src/Layout/`）是**舍弃方式**：`Clip`（默认）、`Scroll`、`Report`。判据一句话——这个容器在约束不足时是否必须选择舍弃方式？是就必须显式声明，默认值不许替会超的容器做决定。可能超的是 `Row`、`AppBar`、`Dock` 槽、自绘容器；`Text` / `Icon` / `Padding` 这类尺寸由内容或子决定的用默认值即可。`Report` 不是"不处理"，它响亮失败。

`Flex::overflow()` 接收策略。`RenderFlex` / `RenderDock` 不支持 `Scroll`（会报错并指路 `ScrollView`）：容器自己滚与 `ScrollView` 职责重叠。`Scroll` 是行为声明，不是裁剪开关——真正裁剪的是 `RenderScrollView` 自己在 `paintContent()` 里做的 `pushClip()`，与 `clipsOverflow()` 是两件事。

## 滚动与可见窗口切片

普通滚动用 `ScrollView`，横向滚动传 `vertical: false`；虚拟化列表用 `LazyColumn` / `LazyRow`，二者共用 `LazyListState`，轴只是一个参数。表用 `TableState`。

滚动条偏移是"就地更新"，不走重建也不走重排：`ScrollBarHost::setScrollOffset(Axis, float)` 就地改偏移并返回**夹过的**实际值，`applyScrollOffsets()` 把偏移写到子盒的 `offset` 上。只改 State 字段不落子盒 offset，画面会停在旧位置甚至整段 paint 被跳过；借 `markNeedsLayout()` 让它生效则会重排整棵树。State 持有偏移作为权威，推给盒之后要回写盒夹过的值，否则增量长期大于上限，真机表现是"滚轮转了画面不动"。

内容长度变化必然伴随 State 标脏重建，走完整 layout；只有滚动不必重建。虚拟化窗口就是在这种形状下守住的：`LazyListState` 记下上一帧窗口的内容坐标范围 `[windowStart, windowEnd)`，滚动时判"窗还盖不盖得住视口"，盖不住才 `markDirty()`。`overscan()` / `cacheExtent()` 是两端缓冲带，不是可选优化——没有它，偏移动一像素窗口就变，重建收益当场归零。列宽可变的表用纯函数 `ExtentWindow::visible()` 求可见窗口。

滚轮三条入口按语义分：`scrollBy(Δ)` 立即改，`scrollByAxis(轴, Δ)` 立即且带轴判定（thumb 拖动要 1:1 跟手），`glideByAxis(轴, Δ)` 把一次增量摊成若干帧。动画 = 把大增量拆成小增量再交给现成的 `scrollBy()`，夹取、换窗、锚点都只写一份。

## 布局是纯函数

把 `performLayout()` 当纯函数写，有几条直接后果：

- 不要在里面读系统时间、读 Store、发命令或改 State。给定同一组约束，它必须给出同一组尺寸与偏移；否则快照、golden 与真机三者会各说各话。
- 布局是单遍、全树重排，没有脏边界。改一个字段让整棵树重新 layout 是正常的，代价靠"别在布局期做无关工作"来控制，而不是靠在盒里缓存一个跨帧的脏标记。
- 偏移这种"只改位置不改尺寸"的变化不该走重排：`applyScrollOffsets()` 直接写子盒 `offset`，并推 `paintEpoch` 保证那一帧必画；纯合成动画（如 `AnimatedOpacity::tickFrame()`）只写盒的合成槽，连 layout 都不用跑。
- 量具是 `Pipeline::layoutCount()`。"重排了"与"没重排"画出来可能逐像素相同，只有计数器分得出来；判断优化是否生效要看它，不要只看截图。

盒换代序号 `layoutEpoch` 住在 `Pipeline`，`State::boxEpoch()` 只是转发到这个全局序号；帧用它判断"换过盒，需要重排"。它不参与常规脏标记。

## 自定义 RenderBox 只实现 performLayout

继承 `RenderBox` 或 `RenderProxyBox`，只实现 `abstract protected function performLayout(BoxConstraints): Size`。在里面：

1. 按约束算本盒尺寸（`constrain()` 后再用）；
2. 对每个子调 `child->layout($childConstraints)`，再用 `setOffset()` 给它相对位置；
3. 返回尺寸；超上界交给 `layout()` 的 C6 检查，不要自己吞。

需要时再覆写 `intrinsicWidth($height)` / `intrinsicHeight($width)`（父盒问固有高度的另一条路径）、`flex()`（默认 0）、`overflow()`（默认 `Clip`）。`RenderText::performLayout()` 按 `maxWidth` 只度量一次并留下 paragraph，是"度量一次、多处复用"的样板。

`Canvas` 没有 `scale`，只有 `save` / `translate` / `restore`，所以缩放要在几何层做：`IconSet::path()` 直接生成乘过 `size` 的 `Path`。`RenderBox` 已有非 readonly 的 `$size`，自定义盒的同名字段要另起名（如 `RenderIcon::$iconSize`），否则 Fatal。

想用组合解决问题的，先看 `TabView`（`Offstage` 保活）、`TreeView`（`StatelessWidget` + 现有布局原语）、`LazyGrid`（复用 `LazyColumn` 虚拟化），它们都不新增 Element 与 RenderBox。

## 相关

- [用 Widget 组合界面](./widgets.md)
- [自定义控件](./custom-widgets.md)
- [渲染模型](../concepts/rendering.md)
- [调试与量具](./debugging.md)
