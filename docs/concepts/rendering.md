# 渲染

> 这篇说明 RenderBox 如何单遍约束布局、Canvas op 流与三个实现如何分工、能力位与 Optional 如何显式失败，以及确定性的来源。

## RenderBox 单遍约束布局

布局是单遍的：`RenderBox::layout()` 是 `final`，子类只实现 `performLayout(BoxConstraints): Size`。约束从父往下传，尺寸从子往上回，父再给子盒定位，整个过程是纯函数。没有 `measure` / `place` 两步分离。

`layout()` 做几件固定的事：调 `performLayout()` 拿测量尺寸；做上界检查，只有**超出上界**才算违反，下界提升是父盒把尺寸定死的本职工作；把结果钳回约束内。`final` 保证这条检查只有一处，子类绕不过。

几处关键契约：

- **父管挂接，子不自定位**：`setOffset()` 只有父写；子盒的偏移住在内容盒坐标里。
- **内禀测量**：`intrinsicWidth()` / `intrinsicHeight()` 带另一轴重算，供父盒询问。
- **弹性与溢出意图**：`flex()` 与 `overflow()` 由容器读取，会超的容器必须声明自己的策略。
- **画到盒外即失败**：超出上界走响亮失败；窗口驱动的尺寸把违规降级为裁剪加一条诊断，因为客户区归用户，拖到很小或从小尺寸重启不该弹中止框；纯布局与快照仍然中止。
- **无界用 `INF`**，不拿 0 当哨兵。

命中测试 `hitTest()` 越界返回 `null`，子节点**逆序**问（后画的在上），子都不中则返回自己，于是容器吞掉事件而不穿透。绝对原点只有 `absoluteOrigin()` 一个出处，沿父链累加偏移；命中、IME 候选框、菜单锚点与语义几何都走它，自己沿 Element 链累加会重复计算。

## Canvas op 流

`Canvas` 是**接口**而不是最终类。节点在 `paint($canvas)` 里直接画，画布在画每个子盒前已经 `translate` 到盒原点，所以 `paint()` 内部以 (0,0) 为盒左上角。

op 流是扁平的：保存与恢复、变换、裁剪栈、图元绘制、层与帧末自证都在同一条流上。几个值得记住的方法：

```php
$canvas->save();
$canvas->translate($dx, $dy);
$canvas->pushClip($rect);
$canvas->drawRect($rect, $paint);
$canvas->drawParagraph($paragraph, $paint, $x, $y);
$canvas->popClip();
$canvas->restore();
$canvas->assertFrameBalanced();
```

两条纪律：

- `translate()` 只改 PHP 侧偏移，**不调用后端变换**；每个 draw 自己把偏移加进坐标。新 op 若照「几何交给画布 translate」的写法，会落在画布原点、被盒裁掉，真机完全无墨而解释器全过。
- 状态栈必须显式配对，`assertFrameBalanced()` 在帧末检查是否回到基线。接口只要求「帧末归位 + 调用方显式配对」，不规定后端内部用几条栈。

生产路径**不录制**：`Frame::run()` 把画布直接递给绘制，真机是 `SkiaCanvas`，无头预览是 `RasterCanvas`，都不经过 `DisplayList`。录制只用于重绘边界、测试夹具与 golden。

## 三个实现

| 实现 | 面向 | 特点 |
|---|---|---|
| `RasterCanvas` | 纯 PHP 软光栅，测试与 golden 基础 | 天生可移植；对多数扩展能力是降级近似，状态用多条独立栈 |
| `SkiaCanvas` | 默认真机后端 | 走真 Skia；变换与裁剪共用一条保存栈，PHP 侧镜像栈深 |
| `RecordingCanvas` | 录制 | 把 op 录进 `DisplayList`，供回放、快照与 golden |

## 能力位掩码与 Optional 显式失败

`CanvasCapabilities::CAP_*` 是能力位的唯一事实源，`capabilities()` 返回掩码，调用方查一次即可，不在每次绘制时查。`RECT` / `TEXT` / `SAVE` / `CLIP_RECT` 等原语无条件支持，不进掩码。

位只回答「能不能发起这个 op」，不回答「画得像不像」。三种语义要分开读：

- **exact**：两后端同义，如椭圆与直线。
- **approx**：Raster 置位但产出是降级近似——虚线退实线、层用 alpha 乘数、背景模糊为空实现、图像最近邻 blit。把近似当支持读，会以为两后端等价，这正是 golden 只跑 Raster 时验不出画质差异的根因。
- **optional**：`CAP_SHADOW` 只有 Skia 支持；Raster 遇到 `drawShadow` 会响亮失败。

缺位怎么处置由调用方决定：有的降级形状，有的跳过这一笔，只有不查位直接发 Optional op 才走 `unsupportedOp` 响亮失败。这三条不是同一条规则。掩码相关的逐位语义登记在能力契约里，新增位不登记会被判据咬红。

## DisplayList 可重放与 golden

`DisplayList` 用连续的 `OP_*` 常量记录绘制命令。`record()` 拒绝未知 op；`replay()` 对未处理的分支响亮失败，新增 op 漏补分支不会静默丢像素；`assertBalanced()` 检查保存、裁剪、层三种栈深。

录制层的能力位必须等于回放端：`RecordingCanvas` 默认取 Raster 的能力集，要求「全录」的调用方显式传 `ALL_MASK`；显式全录记下的阴影 op 若回放端是 Raster，就会在回放时响亮失败。这避免「录的时候有、放的时候静默没有」。

因为命令流可重放，同一份 op 可以打到不同后端做逐字节比对。golden 分原语与整页两套基线：原语基细粒度定位 op 差异，整页基覆盖组合后的画面。真窗口受 DPI 与时序影响，不能当基线。

## 文本度量与字体栈

文本栈三拆：度量、整形、编辑各管各的。度量归框架自己，用确定性实现，不外包给系统文本 API——系统 API 在无窗与跨平台下不可复现，而 golden 要求同输入同像素。

- `TextMetricsProvider` / `DeterministicMetricsProvider` 负责度量，`TextShaper` / `DeterministicShaper` 负责字形归属与定位。
- `FontCollection` / `DeterministicFontCollection` 管字体回退链，`FontManager` / `FontLocator` 定位与加载。
- `FontFace` 有三种实现：`TtfFace` 解析真 TTF 做步进度量，`BitmapFontFace` 走位图路径，`NotdefFontFace` 是链尾兜底——缺字画占位而不崩。不做 GPOS kerning、bidi 与断行禁则。
- `TextLayout` 把文本、样式、最大宽与缩放排成 `Paragraph`；字形位图在**回放时**才产生，录制代价与字形数无关。IME 走 `TextInputClient`，候选框锚点靠盒的绝对原点并穿过包装层找真正的文本盒。

## 确定性来源

可重放不是凭空成立的，它来自几处主动注入：

- **字体度量**：确定性字体集合与预置字形数据，不依赖机器上装了什么；Skia 光栅化的字面来源则是「可选包内字体 → 系统字体」，只有跨机像素一致才需要随包附带 `fonts/`。
- **DPI 与视口**：由平台注入约束，视口为 0 有专门处理，不让布局读到含糊尺寸。
- **主题**：注入的 token，控件不读系统外观的当次结果。
- **时间**：帧时间戳由 `FrameClock` 注入，布局与渲染不读环境时钟；动画推进也走注入的时间戳。

加上布局是纯函数、`DisplayList` 可重放、能力位显式登记，于是同一输入可以在无窗环境里稳定产出可比对的像素。

## 相关

- [心智模型](./model.md)
- [帧管线](./frame-pipeline.md)
- [布局](../guides/layout.md)
- [文本与主题](../guides/text-and-theme.md)
