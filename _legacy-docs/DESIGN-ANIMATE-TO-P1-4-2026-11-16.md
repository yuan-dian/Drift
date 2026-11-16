# 设计裁决：编程式动画滚动 `animateTo`（P1-4 第一条）

## 裁决

新增 `ScrollController::animateTo(offset, durationMs = 240, ?curve)`，摊成定时动画；
`scrollTo()` / `jumpTo()` 仍是"调完就到"的原语，**语义不改**。用户一动（滚轮 / thumb /
`ensureVisible`）动画立即让位，后一次调用顶掉前一次。2026-11-16 落地。

## 为什么现在做

能力面 review 的 P1-4 第一条："编程式滚动中途取样 offset 应单调递增而非一步到位"。
这是 P1-4 三项里唯一同时满足"有真实消费者、判据硬、未判死"的：

- 变长行虚拟化：`itemExtent` 必填是现行纪律（O(1) 算可见窗口），放宽要重写锚点算术，且无真实消费者。
- 表格固定列：`TableState` 的横竖轴与编辑提交深度耦合，等真实表格需求再做。
- `animateTo`：`Scrollable.php` 自己留了口子——"原语不动，另开一条要动画的入口"。

真实消费者：`apps/showcase` 的「加载更多」页新增"回到顶部"按钮。

## 成熟框架怎么处理

**Flutter**：`ScrollController.animateTo(offset, duration, curve)` 与 `jumpTo()` 并存。
`animateTo` 取消当前活动；用户手动滚动、另一次活动、到边界 overscroll 都会中断；
duration 为 0 用 `jumpTo`。惰性列表的 `maxScrollExtent` 只是估算，目标不随动画中新布局更新。
`ensureVisible()` 走"先算目标、再按需 jump/animate"，是独立入口。

**Qt**：`QScrollBar::setValue()` 立即改变位置；动画是另一套 `QPropertyAnimation` /
QML `NumberAnimation`（duration + easing），作用在属性上而非改 `setValue` 的语义。

**共同结论**：立即原语与定时动画是两条入口，不把前者改造成后者；动画要能被用户输入中断；
曲线/时长是动画入口的参数，不污染原语。本仓照此分工，不照搬 API 形状（本仓无 `Future` 返回）。

## 本仓落点

- `ScrollLane` 加 `journey`（`AnimationController` + 起止 offset），与滚轮 `motion` 互斥。
  两者都是"追一个值"，但 `motion` 用 `AnimatedScalar` 指数趋近（随时改向、无总时长），
  `journey` 是定时长 + `Curve`（`AnimatedScalar` 类注释已写明这两类语义不可合并）。
- **每帧位移仍交给 `$apply`**（State 的 `scrollBy`）：夹取 / 换窗 / 锚点 / 提交编辑复用既有那一条路。
  这是 `Scrollable::glideByAxis()` 早已定下的纪律，动画不另写一套写入口。
- 内部标志 `journeyApplying` 区分"动画帧回流到 `move()`"与"用户接管"：
  没有它，动画第一帧会被自己的 `move()` 取消。
- `ScrollController::animateTo` 是 `@api`；`durationMs <= 0` 退化成 `jumpTo`，未绑定容器是空操作。

## 判据（与实现不同源）

`tests/Unit/Widget/ScrollLaneJourneyTest.php` + `tests/Feature/Widget/ScrollControllerAnimateTest.php`
只读公共 `offset()` / `onChanged()`，不读 `journey` / `progress()`：

- 中途取样多帧、单调不回退、终点精确（收口帧不丢最后一小段）；
- 用户 `move()` / 滚轮 `glide()` 接管后不再被拽到旧目标；
- 后一次调用顶掉前一次；够不到目标时边界就地收口，不空转；
- 消费者视角：`ScrollController` 的 `onChanged` 每帧同步，不是一步到位。

变异自检（均已咬红、回滚复绿）：把 `$journey->value()` 改成 `1.0`（一帧直达）⇒ 取样判据红；
把 `move()` 里的 `cancelJourney()` 改成 `if (false)`（用户接管不取消）⇒ 中断判据红。

## 明确不做

- 不改 `scrollTo()` / `ensureVisible()` / thumb 拖动的"当帧到位"语义（81 处依赖，见 `Scrollable.php`）。
- 不给 `animateTo` 加完成回调：打断与跑完的区分会把 `AnimationController::onCompleted` 的
  语义拖进来，当前无消费者；真需要时再按"结束/中断"两态设计。
- 不动画化 `ensureVisible()`：Tab 跳转的中间帧锚点是错的。
- 不做多轴同时动画：`TableState` 两轴共用淡出节律但没有同时动画的消费者。
- 不碰变长行虚拟化与表格固定列（见上）。

## 失效条件

出现需要不同中断语义的消费者（例如动画途中动态延长目标并期望继续追），
或 `animateTo` 与滚轮互斥导致可复现的交互缺陷时，重开本裁决。
