# 帧管线

> 这篇说明一帧按哪些段推进、FrameClock 如何决定何时跑下一帧、后台结果如何回贴，以及跳帧闸门与多窗口的状态归属。

## FrameStage：有序段与只计时段

`FrameStage` 是一帧各段的**唯一名字表**，段名与顺序集中在一处，避免同一段在不同量具里有不同名字。段分两类。

有序段九段，按帧内正确顺序排列：

| 段 | 组 | 越界或缺失的后果 |
|---|---|---|
| `pumpIo` | settle 前 | 外部数据晚一帧到，回调里的 `setState` 等下一帧 |
| `pumpTasks` | settle 前 | 后台结果晚一帧生效，loading 到 ready 会闪一帧空 |
| `pumpTimers` | settle 前 | 延时回调晚一帧，tooltip 晚出、snackbar 晚走 |
| `tickAnimations` | settle 前 | 动画晚一帧起手，第一帧不动 |
| `placeOverlays` | 核心 | 浮层布局晚一帧，菜单与模态抖一下 |
| `settle` | 核心 | 帧末重建没跑，这一帧的 `setState` 全部作废 |
| `tickLateMount` | settle 后 | 迟到挂载的 State 不被 tick，时钟不上膛后永久静止 |
| `focusCollect` | settle 后 | 焦点链握着已移除的 Element，按键静默丢给死节点 |
| `pumpSemantics` | settle 后 | 读屏停在上一帧 |

只计时段四段：`layout` / `paint` / `beginFrame` / `present`。它们有耗时，但不参与跨段顺序校验——不要把它们和有序段混成一套口径。

`FrameStage` 提供 `all()` / `beforeSettle()` / `afterSettle()` / `core()` / `consequence()`，以及表完备性自检 `selfTest()`：常量集、表集、后果登记、分组与重名都会被检查，拼错段名或未登记新段会响亮失败。

## 一帧的实际顺序

`Application::frame()` 把段按上表推进；`Frame::run()` 内部是 `settle → layout → paint → present`：

```text
pumpIo → pumpTasks → pumpTimers → tickAnimations → placeOverlays
  → Frame::run（settle → layout → paint → present）
  → tickLateMount → focusCollect → pumpSemantics
```

所有会在 `settle` 里产脏的工作——IO 回调、后台结果、定时器、动画——都排在 `settle` 之前，保证同帧生效。`settle` 之后的三段观察的是**新**树：`tickLateMount` 补刚挂上来的 State，`focusCollect` 重建焦点链，`pumpSemantics` 推语义树，顺序有依赖，不能颠倒。

## FrameClock：何时跑下一帧

`FrameClock` 只出「何时跑下一帧、这一帧花了多久」的策略，不持有平台资源；叫醒归平台。

- `nextWaitMs($app, $now)` 给三态值：`< 0` 无限等（无欠账、无定时器、无异步在途）；`0` 尽快跑帧（有一笔只欠一次的活）；`> 0` 等到某个时刻（按帧节奏或最早定时器）。`-1` 是不投票的哨兵，多窗口取最小值时不参与。
- `advance()` 跑一帧并量帧耗时，时间戳由平台注入，不读环境时钟；原生模态挂起的时长会从两帧间隔里剔除，避免「弹一次框报一次卡顿」。
- `stallCount()` / `worstGapMs()` 是观测面；没有 `pace()` / `tick()` 这类方法。

欠账来自 `Pipeline` 的 `frameRequests > frameServed` 一个计数器：变更现场自己调 `scheduleFrame()` 举手，`wantsFrame()` 只读计数，不去枚举谁还活着。欠账清零，帧时钟停摆，CPU 归零。挂起的动画若没人举手，光靠 `wantsFrame()` 轮询不会触发——循环是阻塞等消息的，没人问就没人答。

## 事件泵与后台结果回贴

组合根只剩 `EventLoop::run($platform, [$handle => $app])`；循环形状是**等 → 派发 → 跑帧**：

```text
waitEvents(nextWaitMs) → 判断唤醒原因 → pumpEvents
  → 逐条 poll() 派发输入 → FrameClock::advance() 跑帧
```

UI 单线程亲和：后台结果不直接碰树。`TaskHost` 有同步 / 线程 / 进程三后端，作业是 `Job` 类；发出的结果回贴到 UI 线程后 `setState()` 标脏，由同帧或下一帧的 `settle` 消费。作业在后台线程运行时不许碰 Widget、Element、State。定时器与 `after()` 的回调在 UI 线程上跑，因此可以直接 `setState`。

错误按「谁的错」分流：框架契约被破坏走 `Errors::report()` 并中止；子树渲染失败走 `ErrorBoundary` 只坏那一块；外部 IO 慢操作走 `Job` + `after()`，不在 UI 线程同步等。

## 跳帧闸门

跳帧是设计意图，不是失败；屏幕保持上一帧内容。

**零视口**：视口为 0 表示「没有像素可画」，不是「内容放不下」。泵全部照跑——最小化期间动画与延时回调要继续推进，否则还原时一下跳好几秒；`placeOverlays` 与 `Frame::run()` 跳过，表面一帧都不起；根盒保持最后一次有效尺寸，还原时走正常帧。判据放在框架层，因为任何平台端口都可能给出零视口。

**帧内闸门**：`Frame::run()` 用几组序号判断是否跳过：

- 重建：标脏序号、盒换代序号、结构版本三者都没变，就既不重建也不挂接。
- 重绘：本帧没跑布局、`paintEpoch` 没变、没有应用级重绘请求、浮层集合也没变，就整段跳过 paint 与 present。
- 浮层栈不属于主树，它的增删不动主树结构序号，所以浮层集合单独作为一路信号，避免关掉模态后遮罩永久残留。

约束反馈在 layout 之后检测：只有布局实际拿到尺寸才知道「这层多大」，变了就同帧再来一轮，收敛后才 paint，不存在「先画错版式、下一帧跳一下」的中间态。

## 多窗口状态归属

`WindowSet` 管窗口集合，每窗一个应用实例、一个 `FrameClock`。状态归属分三层：

- **进程级共享**：字体、任务池、IO、主题、全局命令住 `AppScope`，由组合根显式建，多窗共用一份。
- **每窗口**：各自的 `AppShell`（由 `Application` 持有）与 `FrameClock`；`EventLoop` 每轮向所有时钟问 `nextWaitMs()` 取最小等待时长。窗口几何按槽位存，槽位重名会互相覆盖且不报错。
- **每帧**：帧属于一条管线，`Frame` 的绘制门控读的是本管线的序号，避免多应用交错时读错对象的状态。

关闭请求不进命中链，是窗口级生命周期；关闭时框架统一卸掉平台 `hooks()` 端口持有的静态钩子与对象图，否则 AOT 关窗会崩。

## 相关

- [心智模型](./model.md)
- [渲染](./rendering.md)
- [异步](../guides/async.md)
- [测试](../guides/testing.md)
