# 心智模型

> 这篇建立总纲：三棵树如何协作、身份如何确定、状态何时重建、依赖方向如何约束，以及框架有意不提供哪些机制。

## 一帧总览

先把整体形状记住，再拆细节：

```text
平台泵 pumpEvents() → tick（动画 / IO / 任务 / 定时器）
  → settle（脏 Element 重建）→ layout（单遍约束）
  → paint（Canvas op 流）→ present()
```

UI 线程亲和：后台结果不作为回调直接改树，而是以事件形式**回贴**，在帧内 `settle` 之前落地，让同一帧的重建消费掉。不在事件中途重排，是后面所有确定性结论的前提。

更细的段顺序、跳帧条件与时钟策略见[帧管线](./frame-pipeline.md)。

## 三棵树

一棵逻辑树承载三层语义：

| 层 | 类 | 职责 |
|---|---|---|
| 配置 | `Widget` | 不可变描述；fluent 链式修饰；`seal()` 封存 |
| 身份 / State | `Element` | 持 `State`；按身份调和；帧末 `settle()` 脏重建；不画、不算几何 |
| 几何 / 绘制 | `RenderObject` → `RenderBox` | `layout()` 算尺寸与子盒相对位置；`paint()` 绘制；`hitTest()` 命中 |

`Element` 住在 `src/Widget/` 而不是独立的目录，它是三棵树的中间一层，归 Widget 层管。

造谁有唯一入口：`Widget::createElement()` 是唯一造元素入口，内部一处判断决定造哪种 Element；`Element::syncBox()` 是唯一造盒 / 换盒入口。这样避免每个控件各抄一句、漏抄却静默走错类型。挂载即封存：`Element::mount()` 先 `seal()` 掉 Widget，之后任何链式修改都会响亮失败，因此刷新只能整体换新描述，不会出现字段级半同步。

## 身份 = 位置 + Key

没有 Key 时，身份由它在 `children([...])` 里的位置决定；显式给 Key 后，重排也能保住 State。列表与 `ForEach` 的 Key 是必需的，否则最需要身份稳定的重排场景反而丢身份。

调和只有 `Element::updateChildren()` 一个入口：逐槽按类型与 Key 比对，命中的复用旧 Element 并 `update()`，否则卸载旧的、造并挂载新的。那种「拿旧字段直接改」的半同步写法在这里结构上不可能。

## 写即标脏、帧末统一重建

`State::setState()` 只把状态标脏，闭包当帧跑完，重建集中在帧末的 `settle()`。因此一个回调里连点多次只重建一次，「build 期 setState 触发重入」这类问题不存在。

标脏序号由管线维护，`Frame::run()` 用结构版本、盒换代序号与标脏序号判断是否跳过重建与重绘。跳过是正常路径：屏幕保持上一帧内容，不是失败。子层重建换了盒之后，祖先必须后序重新挂接盒——漏掉会让布局与绘制仍走旧树，画面纹丝不动且不报错，这条是静默坑的重点。

## 受控控件

控件不自己持有值。`ToggleBase::activate()` 这类激活逻辑只回调新值，值由应用在自己的 `State` 里改；`TextField` 同路。一次点击只表达「用户想要什么」，不直接写自己的显示。

这让值只有一个真相源：应用状态。控件重绘来自状态重建，而不是控件内部偷偷改字段。

## 组合根注入

平台实现只出现在 `bin/<platform>/Bootstrap.php`，共享源码零平台引用。控件层拿不到 `Application`，也没有 `$this->application()` 这样的方法。

需要能力时走 `Drift\Widget\Environment`（`Application::environment()` 的实现）。**契约住 Widget 层，实现住 App 层** —— 反过来就是 `Widget → App` 成环，那等于让控件 import 帧循环。三条判据：

1. **一个对象，不是一串闭包**：应用侧从"每加一项能力就多一个 `\Closure` 参数"变成只收一个门面；同型闭包写反是静默 bug，而门面的方法各有名字与类型。
2. **端口被投影成值参数**：`openFile(string, array): ?string`、`clipboardText(): ?string` —— 签名里没有 `Dialog` / `Clipboard`，那两个类型住 `Drift\Platform`，写进来就等于为"控件能拿平台实现"放开整层。缺端口返回空值 + 一条 Diagnostic，不抛异常。
3. **门面上没有 `frame()` / `attach()` / `input()`**：Store 拿到它也摸不到应用运行时。

框架已经接好线的声明式缝（`overlayPanel()` / `tooltip()`）仍然有效，那些能力不需要门面。

能力**向下传**依旧是显式的：`buildRoot()` 把门面交给根控件，根再交给需要它的子控件。**不做** `BuildContext::environment()` 式向上查找 —— 判据与 `theme()` 不同：主题沿途可能被 `ThemeScope` 覆盖，门面是每窗唯一那一个，藏进上下文只会让"它从哪来"更难查。

焦点是一个受控例外：控件请求焦点走 `Element::requestFocus()` 到 `Pipeline::focusHost()` 注册的回调槽，这是框架内部通道，不是给控件开访问 App 层的口子。

## 依赖方向 Core < Widget < App

- Widget 不依赖 App；`BuildContext` 是 `final readonly` 的环境快照，承载宽高、主题、字体度量与颜色 token，**没有 `of()` 式向上查找**。
- 分层由 lint 规则以 fail-closed 方式守，未登记的跨层依赖会被拦下。
- 有意的双向边仅限同一棵树的两个视角（Widget ↔ Layout、Widget ↔ State）与主题相关的一条文件例外；不照搬 `InheritedWidget` / `BuildOwner`，那要改公开签名并引入生成物风险。

深层控件要能力仍然逐层传 —— 差别只在传的是**一个门面**而不是"N 个按需增长的闭包"。

## 有意不做的机制

| 机制 | 为什么不做 |
|---|---|
| `BuildContext::of` / `InheritedWidget` 式查找 | 环境是只读快照，向上查找破坏单向下行与静态可判定性 |
| 自动依赖追踪 | 值缝靠显式订阅与 `===` 身份门控，不靠读写拦截 |
| `v-model` 式双向绑定 | 控件受控，值只由应用改 |
| CSS 层叠与流式布局 | 布局是 `RenderBox` 约束下行的纯函数结果 |
| 原生控件包装 | 一律自绘，保证跨后端一致的像素与行为 |
| 环境值泛型注入（`ProvidedValue<T>` 式） | 会在 AOT 生成物上引入风险，收益仅为「看起来没有环」 |

## 公共面能力索引

`Application` 的 public 方法按"找东西时先想到哪一类"分组。新增 public 方法必须补进这张表，判据用 Reflection 对账，漏一个就红；数量不写死。

| 能力域 | 方法 |
|---|---|
| 应用契约与装配 | `__construct()`、`attach()`、`buildRoot()`、`title()`、`theme()`、`scope()`、`root()`、`rootBox()`、`closeRequested()`、`environment()` |
| 帧与主循环 | `frame()`、`frameCount()`、`wantsFrame()`、`needsFrameImmediately()`、`nextDeadlineMs()`、`repaintForResize()`、`pipeline()`、`lastTimestampMs()`、`lastInputMs()` |
| 命令 | `commands()`、`commandGates()`、`register()`、`router()`、`dispatch()` |
| 输入与焦点 | `dispatchInput()`、`accelerators()`、`captureNode()`、`capturePointer()`、`releasePointer()`、`focus()`、`requestFocus()` |
| 浮层 · 菜单 · 模态 · 快捷消息 | `overlays()`、`dismissOverlay()`、`showMenu()`、`closeMenu()`、`isMenuOpen()`、`showModal()`、`closeModal()`、`isModalOpen()`、`modal()`、`showSnackbar()`、`dismissSnackbar()`、`hasSnackbar()`、`snackbarEntry()`、`snackbarQueueLength()`、`toggleWidgetPanel()` |
| 拖放 | `beginDrag()`、`updateDrag()`、`cancelDrag()`、`dropDrag()`、`drag()` |
| 异步 · 定时器 · IO | `after()`、`cancelAfter()`、`submit()`、`tasks()`、`taskHost()`、`io()` |
| 语义与 IME | `setSemanticsSink()`、`semanticsMode()`、`setSemanticsExpanded()`、`setSemanticsValue()`、`invokeSemanticsNode()`、`imeCaretRect()` |
| 量具 · 线程守卫 · 端口 | `frameCostMs()`、`worstFrameMs()`、`overBudget()`、`noteFrameCost()`、`zeroViewportFrames()`、`activeAnimationCount()`、`animationVisits()`、`metrics()`、`assertUiThread()`、`uiThreadId()`、`clipboard()` |

## 相关

- [帧管线](./frame-pipeline.md)
- [渲染](./rendering.md)
- [控件](../guides/widgets.md)
- [异步](../guides/async.md)
