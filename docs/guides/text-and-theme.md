# 文本、主题与字体

> 本篇解决：样式和颜色怎样写成 token、字体与度量怎样确定性工作，以及中文点阵字模和 IME 这两个容易静默出问题的接缝。

## 文本样式

`TextStyle`（`src/Text/`）是值对象：构造参数有 `size`（默认 14.0）、`weight`、`slant`、`lineHeight`（默认 1.2）、`letterSpacing`、`align`、`maxLines`、`ellipsize`，`family` 是 readonly 的 `FontFamily`。改样式用 `withSize()` / `withWeight()` / `withFamily()` 等 `with*` 方法返回新实例，不就地改；`cacheKey()` / `equals()` 供排版缓存与门控使用。

展示用 `Text::make(string)`，语义字号用 `TextRole` 收口：`TextRole` 有 `Unset` / `Title` / `Heading` / `Body` / `Caption` / `Error`，`Unset` 是显式 case 不是哨兵数字，0 因此是合法取值。`Text::make(...)->body()` / `->caption()` 这类方法把角色写进控件；`ThemeData::textSize()` / `textColor()` 再把角色解析成实际值。

`Text` 是纯展示，不进语义树（叶子内容不给角色）；可访问名由包住它的按钮给。可编辑用 `TextField` / `TextArea`，它们有 `TextFieldState` 与 IME。

## 颜色、字号 token 与主题

颜色、字号、间距都写 token，不写死。`ColorToken`（10 个封闭枚举）有 `Pane` / `Surface` / `Chrome` / `Text` / `Muted` / `Border` / `Accent` / `Danger` / `Hover` / `Pressed`，各有 `lightValue()` / `darkValue()`；`MetricToken` 有 `Padding` / `Gap` / `Radius` / `BorderWidth` / `FontSize` / `FontSizeSmall` / `FontSizeTitle` / `FontSizeHeading` / `RowHeight` / `HeaderHeight`，与明暗无关。

`BuildContext` 是唯一被允许碰主题的入口：控件在 `createRenderObject(BuildContext)` 里经 `context->color(ColorToken::Accent)` / `context->metric(MetricToken::Gap)` / `context->textSize(TextRole::Body)` 取值，`RenderObject` 只收到解析后的值对象（`ToggleBase::createRenderObject()` 是样板）。不要在 Render 层反查主题。

应用侧能做的事：

- `ThemeData::light()` / `dark()` / `forSystem(SystemTheme)` 选基础主题；
- `withColor(ColorToken, Color)` / `withMetric(MetricToken, float)` 点覆盖，返回新实例、原主题不变，同一 token 后写覆盖先写；
- `ThemeScope::make(ThemeData, Widget)` 给一整棵子树换表，嵌套时最内层赢；
- `withHighContrast()` 用框架自带高对比配色，`withExtension(ThemeExtension)` 挂应用自定义 token，取用走 `BuildContext::extension(Foo::class)`（类名作键，编译期常量，拼错不会静默拿到 null）；应用侧包一个 `Foo::of($ctx)` 把转型收在一处，`ShowcaseBrand` 是样板。

封闭枚举不能新增成员，要自定义语义值走扩展缝。换主题的入口是 `Element::applyContext()`：无状态节点立即重建，有状态节点只标脏、帧末刷新——推迟不是丢。

系统外观跟随走事件：`SystemAppearance` 是明暗、高对比度与系统色的一次快照，`Screen::appearance()` 现读不缓存；`ThemeChangedEvent` 带着快照进 `Application::dispatchInput()`，先用 `ThemeData::forAppearance()` 换主题再 `applyNewContext()` 全树下发。高对比度是独立轴，暗色加高对比度是合法组合；高对比分支用用户选的系统色覆盖 `Pane` / `Surface` / `Text` / `Muted` / `Border` / `Chrome` / `Accent`，硬编码配色只在系统色拿不到时兜底。初值由组合根 `AppScope::setTheme()` 灌进去，`Application` 自己不持平台。

## 字体栈与度量

文本栈三拆，各管各的：

| 层 | 接口 | 确定性实现 |
|---|---|---|
| 度量 | `TextMetricsProvider` | `DeterministicMetricsProvider` |
| 整形 | `TextShaper` | `DeterministicShaper` |
| 字体集合 | `FontCollection` | `DeterministicFontCollection` |
| 字体管理 | `FontManager` / `FontLocator` | `DeterministicFontManager` |
| 字体面 | `FontFace` | `TtfFace` / `BitmapFontFace` / `NotdefFontFace` |

度量归 Drift 自己，不外包给系统 API：系统文本 API 在无窗、跨平台下不可复现，而确定性实现保证同输入同像素。`TextLayout::layout()` 把"文本 + 样式 + 最大宽 + 缩放"排成 `Paragraph`；字形位图在回放时才产生（`Canvas::drawParagraph()` 存 `Paragraph` 引用），录制代价与字形数无关。

`FontFamily` 声明字体族，`DeterministicFontCollection` 按 `FontFace` 顺序回退。需要新增字体能力时接到这条链上，不要在绘制点临时判断。

## 中文点阵字模是生成物

`CjkGlyphData` 是**生成物，不要手改**。它由 `tools/font-gen-glyphs.py` 从 Noto Sans SC 在 64px 上渲染、按面积覆盖度降到 16×16 二值位图写出；覆盖范围由 `tools/font-scan-chars.php` 扫描全仓字符串字面量得到，收集所有非 ASCII 字符（汉字、中文标点、箭头等）。手写那部分字模在 `DeterministicFontData` 里优先，本表只补缺。

重生成命令：

```bash
php tools/font-scan-chars.php && python tools/font-gen-glyphs.py
```

新增中文字面量后必须重跑，否则新字**静默无墨**——运行时只读这份固定数据，没扫到的字落到 `NotdefFontFace`，它给确定性步进让布局不塌，但画不出墨，也不报错。图标不要走字体私有区码点：字模脚本扫的是源码里的中文字面量，扫不到图标码点，同样会零宽隐形。图标用矢量 `Path`（`IconSet::path(IconName, float): Path`）。

## 缺字与回退

`DeterministicFontCollection` 按链回退：`TtfFace` 有真实字形时用真字形；没有时用位图面；链尾是 `NotdefFontFace`，缺字不崩、画占位。`BitmapFontFace` 的契约是"没有这个字形就交回缺字"，而不是画一个自己编的方框。`TtfFace::rasterize()` 委托给构造时给的字形源，没给就返回 null——不静默画方框。

所以"页面上有字没显示出来"先查两件事：字面量有没有被扫进字模生成物；字体链里有没有能 rasterize 该码位的面。`Notdef` 不报错正是它的设计，别指望异常帮你发现。

## IME 接入点

IME 协议是 `TextInputClient`（`src/Text/`），编辑器实现、平台侧桥接：

- `setEditingState(string $text, int $selectionStart, int $selectionEnd, int $composingStart, int $composingEnd)`：上报文本、选区与组合区间，组合区间为空表示不在组字中；
- `caretRect(): Rect`：插入符矩形，**窗口坐标**，平台 IME 靠它定位候选窗；
- `commit(string $text)` / `cancelComposition()`：确认输入与取消组合；
- `restore(string $text, int $selectionStart, int $selectionEnd)`：撤销回写。

第三方输入法靠取插入符矩形定位候选窗，自绘窗口不登记候选框就永远不出现，所以 `caretRect()` 不是可选项。锚点用光标行几何，焦点判据用 `focusedElementId`，不要用过期副本。取矩形时用 `RenderBox::absoluteOrigin()` 拿绝对原点、用 `Element::findBox()` 穿过包装层拿 `RenderTextField` 本身；只看 `effectiveBox()` 会在字段带 label 时把候选框贴到左上角。

## 相关

- [约束布局实战](./layout.md)
- [语义与无障碍](./semantics.md)
- [用 Widget 组合界面](./widgets.md)
- [渲染模型](../concepts/rendering.md)
