# 用 Widget 组合界面

> 本篇解决：怎样用不可变的 Widget 描述搭出界面，并把"值归谁、状态住哪、线在哪里接"分清。

## 先记住三条底线

- `Widget` 是纯描述，链式调用设置字段，`Element::mount()` 时 `seal()` 封存；封存后再改会在 `mutate()` 里响亮失败。刷新不是改旧描述，而是整体换一份新描述。
- 造元素只走 `Widget::createElement()`，造渲染盒只走 `Element::syncBox()`。不要在自己的控件里另开一条路。
- 一个控件该不该有状态、该不该接命令，不是风格问题，是由"这个值要不要跨 build 活、谁才是值的权威"决定的。下面按这个顺序拆。

## 无状态与有状态

输出只由入参决定的组合用 `StatelessWidget`，它只有 `build(BuildContext)` 一个方法，必须纯：同样的入参与 `$ctx` 必须给出同样的子树。业务数据从构造器注入，不从 `$ctx` 取。

```php
final class BrandCaption extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Row::make()->children([
            Text::make('Drift')->body(),
            Text::make('声明式界面')->caption(),
        ]);
    }
}
```

要"记住"一点东西（悬停项、展开状态、编辑中的草稿、卡片的视图粒度）才用 `StatefulWidget`：它实现 `createState()`，值放进 `State` 的字段。判据是这条——这个值必须跨 build 活，而且只影响这一棵子树；业务数据、跨页共享、需要恢复的导航与滚动位置都不归它。

`createState()` 只在挂载时调一次，更新时 `Element` 复用同一个 `State`。想让 State 读到新描述，在 `State::didUpdateWidget()` 里处理，而不是换一个 State 实例。`StatefulWidget` 还可以覆写 `createRenderObjectFromState()` 直接从 State 产盒，适合"有态、有盒、没有子"的叶子控件；一旦有子就别走这条路，否则子层会被静默丢掉。

## setState 只标脏

`State::setState(callable $change)` 不是"立刻改画面"：它在回调里改字段，然后 `markDirty()` 打脏位，真正的 `build()` 在帧末由 `StatefulElement` 统一做。同一个字段写回原值也应该早退——

```php
if ($this->hovered === $index) {
    return;
}
$this->setState(function () use ($index): void {
    $this->hovered = $index;
});
```

这里 `state()->needsBuild()` 才是重建的判据，所以"值没变就不写"既省一次重建也省一次全树几何。动画另走 `State::tickFrame()` / `tickFrameStamped()`：它们由帧时钟驱动，改的是盒上的合成槽或动画相位，不该每帧 `setState` 重建子树。

订阅与清理也挂在 State 上：`State::watch()` 在 `initState()` 里订阅 `Subject`，`State::onDispose()` 登记退订，卸载时统一清掉。订阅必须先到位，否则首帧读不到值。

## Key 与身份

不给 Key 时，一个节点的身份由它在 `children([...])` 里的位置决定；给了 Key，重排后仍能认回自己。`Widget::key()` 设置身份，`Key::same()` 参与调和。

调和在 `Element::updateChildren()` 里逐槽进行：候选旧子和新子同类且 `Key::same()` 为真才复用、调 `update()`；否则卸载旧的、造并挂载新的。列表、分页、任何会重排的集合都要 Key——`Each::make($keyFn)` 把 Key 函数作为构造参数，就是为了不在最需要它的时候丢身份。

身份的另一半是"整体换新"：`Element::update()` 把新 Widget 交给 State，不存在字段级半同步。不要试图在刷新时复用旧 Widget 对象去改一个字段。

## 受控控件

受控的意思是：控件显示什么由应用给的值决定，点击只回调**新值**，控件自己不持有第二份真相。`Checkbox` / `Radio` / `Toggle` 共用 `ToggleBase`，值由 `checked()`（单选还有 `value()` / `group()`）给，变化由 `onChanged()` 回调：

```php
Checkbox::make('记住我')->checked($this->remember)->onChanged(fn(bool $v) => $this->remember = $v);
Radio::make('中')->value('m')->group($this->size)->onChanged(fn(string $v) => $this->size = $v);
Toggle::make('启用通知')->checked($this->notify)->onChanged(fn(bool $v) => $this->notify = $v);
```

应用拿到新值后自己在 `setState()` 里改字段，下一次 build 把新值传回控件。激活路径只有一条：`Application::activateFocusedWidget()`，键盘 Enter/Space 与指针按下都汇到这里，控件不装自己的点击监听器。`TextField` 同理：`value()` 给值，`onChanged()` 回调新文本，语义快照与输入事件也跟着这份值走。

受控纪律换来的是"表单提交时读到的值"与"屏幕上显示的值"永远是同一份。把值让控件自己存，这两份就会分叉，而且分叉不会报错。

## 零接线的声明式缝

有些交互不需要你注册命令、也不需要你写回调，控件上声明一句，框架在命中链或焦点链上替你消费：

- `tooltip(string)`：沿父链找最近的提示声明者，悬停由 `OverlayManager` 延时弹出，超时与显隐都在框架里。
- `overlayPanel(\Closure $factory, ?float $maxWidth = null)`：声明"激活我时开这块面板"。`InputRouter::activateFocusedWidget()` 先按能力读 `overlayPanelValue()`，有就直接开/关面板并 return，不走 `onPressed`、也不走命令。`overlaySignature()` 让面板在锚点数据变化时换内容。
- `contextMenu(ContextMenu)`、`hover(\Closure)`、`cursor(CursorShape)`、`draggable()` / `dragTarget()`：都是字段声明，命中链取最深者解析；右键与 `Shift+F10` 在 `InputRouter::openContextMenu()` 合流。
- `focusOrder(int)` / `skipFocus()` / `autoFocus()` / `markFocusable()`：焦点链每帧重建，复合控件用这些声明控制哪些格子占 Tab 站。

这类缝的特征是"只有声明，没有应用侧动作"。能用它解决，就别再包一层命令或回调。

## 何时在组合根接线

有些能力控件层够不到——浮层、剪贴板、对话框、定时器、后台任务都归 `Application` 或端口，而依赖方向禁止 `Widget → App`。这类线只能在组合根接：`Application::buildRoot()` 是唯一持有 `$this` 的地方。

页面依赖走**构造参数 + 页面工厂表**，不用一束同型闭包：

```php
// 组合根：键是页面类，值只负责 new（host() 由 PageRegistry 统一做）
private function pageFactories(): array
{
    $env = $this->environment();
    $fetchRows = fn(int $page): Future => $this->submit(new FetchRowsJob($page, 20, $this->rowsMode));

    return [
        // 环境能力：从门面取具名方法（一等可调用）
        ComboBoxPage::class => static fn() => new ComboBoxPage($env->delay(...)),
        FeedbackPage::class => static fn() => new FeedbackPage($env->showModal(...)),
        // 领域依赖：具体类型
        ButtonsPage::class => static fn() => new ButtonsPage($this->buttons),
        AsyncTablePage::class => static fn() => new AsyncTablePage($fetchRows),
    ];
}
```

零依赖页**不写进表里** —— `PageRegistry` 默认 `new $class()`。页面构造器就是依赖声明：`__construct(private readonly ButtonsStore $buttons)`。

**为什么不用"一束能力"**：一束同型闭包靠位置区分语义，`notify` 与 `delay` 写反解释器不报错、真机才 Fatal，只能再写一个反射测试去钉签名。改成构造参数后依赖各有类型，错位当场 `TypeError` 并指出期望的类型。

判据有两条：加一个领域依赖只改组合根一行，不穿透 Root / State / Nav；某页要参数却漏注册工厂，切到那页立刻 Fatal（`tests/Unit/App/ShowcaseWiringContractTest` 把它提前成静态红）。

需要给动作起一个能跨树复用、能被菜单和加速键引用的名字时，才升级成命令：在应用侧 `commands()` 里返回 `命令名 => CommandHandler` 映射，键表放在 `accelerators()`。单纯"这个按钮点了改本页一个字段"用 `onPressed()` / `onChanged()` 就够了，不值得占一个命令名。

## 相关

- [状态与数据](./state-and-data.md)
- [交互与输入](./interaction.md)
- [命令与动作](./commands.md)
- [应用装配](../start/app-anatomy.md)
