# 状态与数据

> 本篇解决：UI 局部态、业务数据广播和命令三条缝怎么分工，以及 `select` 切片门控为什么要求应用侧给出稳定的值身份。

## 三条缝先分开

- UI 局部态住 `State`：`setState()` 标脏、帧末重建。
- 业务数据广播住 `Subject`：`notifyChanged()` 手动调一次，订阅者异常隔离。子类 `Store` 只依赖 `Subject`，所以业务层零 GUI 依赖是结构事实。
- 动作走字符串名命令，见 [命令与动作](./commands.md)。

把业务数据塞进 `State`，跨页共享与恢复就没地方放；把悬停项塞进 `Store`，每次鼠标移动都要广播一次。两者别混。

## State：跨 build 的 UI 态

用 `StatefulWidget` + `createState()` 的判据是：值必须跨 build 活、且只影响这一棵子树。展开状态、悬停项、编辑草稿、日历的视图粒度都属于这一类。业务数据、跨页共享、需要恢复的导航栈与滚动位置归 `Store` 与 `AppScope::restoration()`，不放这里。

`initState()` 在首次 build 前跑，订阅要在里面到位，否则首帧读不到值。`State::watch(Subject, callable)` 返回 `Subscription`，`onDispose()` 登记退订，卸载时统一清掉；`watchSlot()` 给同一个 Subject 挂多个槽。`didChangeFocus(bool)` 与 `didUpdateWidget(Widget)` 分别处理焦点变化和新描述。

`State::setState(callable)` 只标脏，不做值比较：写回同值也会重建。真正想省重建，在调用点先判同值——

```php
if ($this->hovered === $index) {
    return;
}
$this->setState(function () use ($index): void { $this->hovered = $index; });
```

`State::needsBuild()` / `consumeDirty()` 供框架在帧末判定是否重建，业务代码一般不直接调。

## Subject 与 select 切片订阅

`Subject` 是广播端，`notifyChanged()` 由应用手动调（它不知道 GUI 长什么样）。`subscribeFor(object $owner, callable $handler)` 按属主订阅，`unsubscribeAllOf()` / `unsubscribeId()` 退订；每个订阅者异常隔离，先记账、通知完再报，避免第一个炸掉后面全收不到。`Subscription` 按自增 id 管理，遍历中退订不出错。

页面侧用 `SubjectBuilder` 把一个切片变成一棵子树：

```php
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): string => $s->query())
    ->builder(fn(string $q): Widget => TextField::make()->value($q));
```

`SubjectBuilder` 实现 `SubscribingWidget` 这个空标记接口，订阅责任只能落在这样的控件上。`select` 闭包每帧都跑，所以必须廉价、纯、无副作用：它省的是子树重建，不省计算。纪律是"页面里任何读 Store 的地方都必须在某个 `select` 之内"，否则读到的是快照；lint 的 `dead-subscription` 规则守这条，构造器中提升的属性也在它的检查范围内。

只有同时满足"同频变化、小子树、全标量、一致性敏感"时才把多个字段合进一个 `select`。`query` 每字符变、`running` 每次刷新变，这种不同频的组合会让每敲一个字符都重建整行，是反面例子。

## === 身份门控

门控比的是**两次 `select` 的输出**，不是 `Store` 的新旧值：框架不存 Subject 快照，旧值缓存在订阅者自己身上。默认门控是 `sameValue()`：两边都是 `Equatable` 就按值比较，否则走 `===`。

这条默认很重要：`===` 对对象是身份比较。如果 `select` 每次 `notify` 都 `new` 一个内容相同的值对象，门控会判定"变了"，子树白重建。`SubjectBuilder` 会检测"同值不同身份"的抖动并上报 `select/unstable` 诊断，但诊断不该成为常态。

数组走 `===` 时还比较键序：键序不同就是"不同"，所以合并成数组的切片要保证全标量且构造稳定。真正多字段一致的内容用值对象表达，别用随手拼的数组。

## Equatable 或复用实例：应用侧契约

想让门控按值比，应用侧两条路选一条：

1. 值对象实现 `Equatable`（`src/State/`），实现 `equals(Equatable $other): bool`。相等语义由实现者保证，框架只在门控点问一次；不要求不可变，但别在门控之外偷偷改它。
2. 让 `select` **复用同一个实例**——值没变就返回旧对象。同实例的 `===` 为真，门控自然挡住重建。

`StatusMessage` 实现 `Equatable`，单值对象切片本来就稳定。`AsyncValue` 刻意**不实现** `Equatable`，四态必须保留对象本身并用 `fold()` 分支，不要拿 `stateName()` 当分支，也不要把它塞进数组切片——那会白重建。

## 受控控件的数据流

受控控件的值是应用给的，控件只回调新值。完整一圈是：

```text
应用字段 → setState → build 把值传进 checked()/value()
   → 用户操作 → onChanged(新值) → 应用改字段 → setState → 下一帧
```

`Checkbox` / `Radio` / `Toggle` 用 `checked()` + `onChanged()`，`TextField` 用 `value()` + `onChanged()`。激活统一走 `Application::activateFocusedWidget()`，控件不自持状态。这样"提交时读到的值"与"屏幕上的值"是同一份。

注意 `TextField` 的输入态标脏：受控值、语义树与 `TextChanged` 事件都跟着这份值走，所以 `onChanged` 里要用 `setState()` 改字段。只写字段不 `setState()`，依赖该值显示的部分不会更新；但仅在提交时一次性读取的草稿字段可以采用后者——先想清楚这个值有没有被显示在屏幕上。

## 页面状态与应用状态边界

- 页面私有、跨 build、不需要恢复：放 `State`（视图粒度、草稿、悬停）。
- 业务数据、跨页共享、别页也要读：放 `Store`，页面用 `SubjectBuilder` 订阅。
- 需要跨进程冷启动恢复（表单、导航栈、滚动位置）：走 `AppScope::restoration()` 配 `RestorableValue` / `NavigationStack`。一个 bucket 是唯一真相源，窗口几何另归 `WindowStateStore`。
- 一次性 UI 动作（弹通知、开模态、延时回调）：由组合根以能力闭包递下来，不塞进业务状态。

判断标准不是"这个字段属于哪个页面"，而是"谁在写它、谁要读它、进程重启后还要不要它"。

## 多事件源仲裁用 Machine，线性流水线用 AsyncScope

两者不是替代关系，看事件从几个方向来：

- **多事件源仲裁**（防抖、拖拽、对话框流程、远程搜索）——几个事件随时都能进来，
  只有"当前持有的那一次"的答案算数：用 `Machine` + `Seq` + `Effects`。`step` 是纯函数
  `(state, event, seq) → Effects`，返回"变成什么 + 该执行什么命令"，不碰外部；`Machine`
  应用新状态、把命令原样交回调用方执行。`Seq::next()` 给每次请求发 attempt 号，
  状态变体持有它，号不匹配的迟到答案在 step 里落到默认分支、什么也不做
  （attempt fence）。示例台的 `RemoteSearchStore` 是样板：快连打时只有最后一次
  查询的结果落地。
- **线性流水线**（签名 → 上传 → 轮询 → 下载这种一条道走到黑的异步链）——状态就是
  协程的指令指针，天然互斥：用 `AsyncScope::task()` 写成一段顺序的 `await`。
  它的取消靠 `AsyncCommand::abandon()` + `Future` 取消硬契约 + `await()` 的
  `guardCancelled()`，已是更强的 fence；不要把这类流程拆成 Machine 的状态变体，
  那会把线性代码拆回"回调 + 标志 + 二次守卫"。

`Machine` 的状态用 `int` 常量 + `===` 链表达，不用 enum with payload 或状态子类
（tpc 对 `switch` 出 C2440，子类是去虚化缺陷面）。"没有在途"用 `0` 而非 `null`，
避免 AOT 下 nullable 属性退化 `php::Var`。

## 相关

- [用 Widget 组合界面](./widgets.md)
- [交互与输入](./interaction.md)
- [异步数据流](./async.md)
- [数据模型](../concepts/model.md)
