# 命令与动作

> 本篇解决：给动作起名字后怎样从控件走到处理器，什么时候该用命令而不是回调，以及命令里怎样做异步。

## 字符串名命令

命令用字符串名，不用整数 ID。`CommandRouter`（`src/Commands/`，`final`）是应用级注册表：`make(?self $parent)` 造表，`on(string $name, CommandHandler $handler, ?CommandGate $gate = null)` 注册，`dispatch(string $name, CommandArg $arg): bool` 派发。表内是 `array<string, CommandHandler>`，名字如 `nav.users`、`doc.save`、`showcase.refresh`。

参数走 `CommandArg`，它不是裸 int：两个槽 `i` / `s` 配三个工厂，`none()` 无参、`id(int)` 整 id、`key(string)` 字符串键。需要复合参数时用 `s` 传键、handler 自己查数据，不要往参数里塞业务对象。

`CommandHandler` 必须是**具名类的实例**，不是闭包：具名类可枚举、可单测、静态可达（死命令 lint 能从 `new UserCommands(...)` 看出它处理哪些名字）。`handle(string $name, CommandArg $arg): bool` 返回 `true` 表示"我处理了，停止冒泡"，`false` 表示"不是我管，继续"。

注册时同名重复会 Fatal（后写覆盖先写最难查）；覆盖父表是合法的本窗特化，但会留一条诊断。`names()` 是死命令 lint 的静态输入，抓未注册的命令名。

命令表分两层：组合根在 `AppScope::commands()` 注册全局命令（如 `app.quit`），各窗口在应用的 `commands()` 注册本窗命令（handler 持本窗 Store）。`CommandRouter` 以全局表为父，解析时沿父链**就近优先**：本窗注册的覆盖全局的。这样"注册一次、全窗可见、本窗隔离"同时成立，也不会让一个窗口改到另一个窗口的数据。

## Element 冒泡链

派发入口是 `Application::dispatch(string $name, ?CommandArg $arg = null): bool`：

1. 先判门控 `router->enabled($name, $arg)`。门控与 handler 同一写者，在 `dispatch()` 一处判定，所以按键、点击、UIA 走的是同一条判定。被拒要响亮上报，不能"按了没反应"。
2. 树内冒泡优先于注册表：`bubble($root, ...)` 从根 Element 深度优先遍历，每个节点问 `States::of($element)?->handleCommand($name, $arg)`，谁先返回 `true` 谁赢。
3. 冒泡没人接，再落到 `router->dispatch()`（第 ③ 环），沿父链找 handler。
4. 整条链都没人接，报 Diagnostic 级诊断——菜单项亮了却点了没反应是最难查的一类 bug，绝不静默。

`State::handleCommand()` 默认返回 `false`，页面要就近接管就覆写它。树内优先的理由是"页面自己最知道这条命令是不是它的"。注意冒泡是从根深度优先走完整棵树，不是从焦点沿父链上溯；`CommandRouter` 只负责第 ③ 环，链的组装在 `App` 层，因为依赖方向禁止 `Widget → Commands`。

菜单在派发成功**之后**才关：handler 可能自己开另一个面板，先关会把它关掉。模态不走这条，确认对话框在命令执行时必须还在。

## 控件是受控的

控件不自己发命令，它只声明"我是什么动作"：

- `Button` 两条输入路径语义不同：`onPressed(闭包)` 是纯 UI 回调；`command('名')` 加可选的 `arg()` 走命令系统。激活时命令优先、`onPressed` 兜底。按下即激活（Down 相位）。
- `ToggleBase` 三兄弟不派命令，只调 `onChanged(新值)`；值是应用给的受控值。
- `Menu` / `MenuItem` 把命令名写在菜单数据里，激活时经 `ActivationContext` 派发。

`Activatable::activate(ActivationContext $context)` 是统一的激活落点。框架在 `InputRouter::activateFocusedWidget()` 里打包 `ActivationContext`：里面是被激活的 `Element` 和一个 `\Closure(string, CommandArg): void` 派发出口。控件按需取用——`Button` 取派发器，`Menu` 取 Element，`Toggle` / `Segment` 什么都不要。派发器是闭包而不是 host 接口，为的是把 App 层知识挡在 Widget 层之外。

声明了 `overlayPanel()` 的控件优先：激活它等于开/关面板，不走命令也不走 `onPressed`。

## 组合根注册命令

应用覆写 `commands()` 返回映射，框架在 `attach()` 里用 `router()->on()` 拼链：

```php
public function commands(): iterable
{
    return [
        'showcase.refresh' => new ShowcaseCommands($notify),
    ];
}

public function accelerators(): AcceleratorTable
{
    return AcceleratorTable::make()->bind(KeyChord::ctrl('R'), 'showcase.refresh');
}
```

`ShowcaseCommands` 是具名 `CommandHandler`：只认 `showcase.refresh`，别的名字返回 `false`。handler 需要的平台能力（弹通知、剪贴板、对话框、开模态）由组合根在构造时按需递进去——常见形态是递一个 `Environment`，handler 自己从上面取；它住应用侧，拿门面合法，但具体平台实现仍只出现在 Bootstrap。

`commands()` 不在构造器里调：PHP 构造是父先子后，那时子类字段还是 null。注册推迟到 `attach()`，并用 `has()` 做可重入保护。菜单项与加速键声明了同一个名字时，`accelerators()` 必须真绑，否则按了静默无反应。

`commandGates()` 返回命令门控映射，与 handler 同一写者。禁用态下派发会响亮拒绝并保留浮层——"拒绝"不等于"放行"，否则 `Ctrl+W` 会绕过对话框。

## 何时用命令，何时用回调

用回调（`onPressed` / `onChanged` / `pointer`）：动作只属于这一个控件实例、没有名字也需要、只改本页 UI 态、不跨树复用。例如切页签、折叠一个节点、改一个受控值。

用命令：动作需要被多个入口触发（按钮、菜单项、加速键、UIA），需要跨树或跨窗口复用，需要一个稳定的名字供 lint 与诊断对账，或者需要门控统一禁用。命令名的代价是要维护一张表，别为一次性动作付这个代价。

`State::handleCommand()` 适合页面私有命令：`users.refresh` 只在这页有意义，就在页面的 State 里接，不必提到应用级。只有别页或全局入口也要触发时才升级到 `commands()`。

## 命令与异步动作

命令派发本身是同步的：一次 `dispatch()` 解析出 handler、调 `handle()`、返回是否有人接。它不等待、不持有 Future。异步活留在 handler 里做，调用应用侧入口：

- `Application::submit(Job)` 把后台任务交给 `TaskHost`，返回 `Future`；页面用 `AsyncValue` / `fold()` 呈现四态，或把 Future 交给订阅切片。
- `Application::after(int, \Closure)` 做一次性延时回调。
- `State::asyncCommand(\Closure $task, ?\Closure $combine = null)` 把异步结果包成 `AsyncCommand`，内部 `watch()` 它并在结果变化时 `markDirty()`，卸载时 `dispose()`。

页面拿两样东西：`submit()` 以能力闭包递下去（它只认"给我第 N 页的结果"，背后是进程池还是 `Future::resolved` 由接线层决定），定时器从 `Environment` 上取。handler 里点火一个异步任务后要立刻返回 `true`（表示已受理），结果回来再走状态更新；不要把 `handle()` 阻塞在等待上。

## 相关

- [用 Widget 组合界面](./widgets.md)
- [状态与数据](./state-and-data.md)
- [异步数据流](./async.md)
- [应用装配](../start/app-anatomy.md)
