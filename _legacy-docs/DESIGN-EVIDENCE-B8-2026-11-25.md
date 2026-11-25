# B8：结束状态——「跑完了」必须是一个动作，不是一处方言

> 上轮推荐：「本地跑完会标 `finished`，跨进程那条路没人标」。实测四种组合里
> **三种是错的**，而且错的形状各不相同。「给远端补一个标记」只能修好其中一种，
> 还会把已经错掉的本地 `--at` 留在原地。

## 一、实测：四种组合，只有一种对

探针用 `Source::resolve()` 跑 3 帧的 `table`，紧接读 `query('state')`：

| 路径 | 脚本跑法 | `state` | `reason` | 对不对 |
|---|---|---|---|---|
| 本地 | 整份 | `finished` | `null` | ✅ |
| 本地 | `--at=2` | `finished` | `null` | ❌ 应是 `paused` / `at` |
| `--remote` | 整份 | `running` | `null` | ❌ 应是 `finished` |
| `--remote` | `--at=2` | `running` | `null` | ❌ 应是 `paused` / `at` |

`lastFrame` 四种都对（3 / 2 / 3 / 2）。所以不是"没跑完"，是**跑完了没人说**。

上轮说错的地方：`Source::resolve()` 里那行 `if ($live instanceof DebugSession)`
是无条件 `markState('finished')`——它不看 `$at`。`Drive::settle()` 却明明白白
把 `at` 判成 `paused` / `reason=at`。同一个 `--at`，自走宿主与一次性出口给出两个
不同的答案，而且一次性出口那个是错的。远端则整条路都没人标。

## 二、问题不是「少一个协议方法」，是「结束状态没有单一规则」

现在有三处决定会话何时停、停在什么状态：

1. `Source::resolve()` — 一次性出口，无条件 `finished`（`--at` 也这么标）；
2. `Drive::settle()` — 自走宿主，区分 `at` / `step` / 跑完 / 崩溃，**这处是对的**；
3. 远端从属宿主 — 一处也没有，状态停在初始的 `running`。

三处里只有一处对。若只给远端补一个 `finished` 标记，得到的是「四种里两种对」，
而 `--at` 的两种仍错，且本地与远端对同一脚本给出不同状态。

判据的立足点因此不是「远端也有 finished」，而是：

> **同一份脚本、同一个 `--at`，本地与远端读回的 `{state, reason}` 必须逐值相同。**
> 剥掉会话令牌之后，调用方不该知道自己连的是哪一个。

## 三、方案比较

| 方案 | 做法 | 为什么否决 / 采纳 |
|---|---|---|
| A. 协议加 `finish` | 客户端发完最后一帧后通知宿主 | **采纳**。客户端确实知道"我发完了"；与 LSP `exit` / DAP `terminated` 同构（方向相反：我们没有反向事件通道） |
| B. 把状态写塞进 `query` | `query('state', ['mark' => 'finished'])` | 破坏「query 只读」这条纪律；读面一旦可写，谁都能改状态 |
| C. 用 `close` 携带最终状态 | 关会话时顺手标 | `close` 之后查询回 `NOT_OPEN`，观察者根本读不到那个 `finished` |
| D. 最后一帧带 `last: true` | 宿主收到末帧就标 | 最后一步可能是 input / effect；`--at` 提前 break 时也不是脚本末帧 |
| E. 宿主自己数脚本帧 | `open` 已有脚本，宿主自行判停 | 第二套"何时结束"逻辑；与客户端的 `Replay::apply` 分离后，`--at` 的边界会各自漂 —— 正是 B3 裁决要避免的两条时间轴 |

A 是唯一同时满足「谁结束谁通知」与「状态规则只有一处」的做法。

## 四、三条裁决

### 1. 结束是一个动作：`Methods::FINISH`

客户端在 `Replay::apply()` 之后调 `finish(?int $at)`，参数是**它知道的事实**
（「我因为 `--at=N` 停了」/「我跑完了」），不是它猜的状态名。宿主用一个规则翻译成
`{state, reason}`。让客户端直接写 `state` 会把 `crashed` 也开放出去，那不是它有权判的事。

`finish` 只对从属宿主有意义。自走宿主自己判停，收到它回 `not_applicable`——
与 `frame` / `pause` 的现有处理同构，不新开先例。

### 2. 状态规则只有一处

`SessionState::afterDrive(?int $at)` 是纯函数：

```
$at === null  → finished / null
$at !== null  → paused   / at
```

`DebugSession::finish()` 调它；宿主进程里的 `finish` 分支也调同一个会话方法
（宿主里的会话本来就是 `DebugSession`）。`Drive::settle()` 保持自己的 `step` /
`crashed` 分支，但 `at` 与跑完两种结局与它同义。

### 3. `Source` 不再 `instanceof`

`Session` 接口加 `finish(?int $at): array`，本地与远端各实现一次。
`Source::resolve()` 里那行特判换成 `$live->finish($at)` —— **一行代码同时修好
本地 `--at` 与两种远端组合**，而且以后加第三种执行器时没有新分支要记得改。

## 五、判据

| # | 判据 | 防的是什么 |
|---|---|---|
| 1 | 四种组合 `{state, reason}` 逐值相同（令牌除外） | 上轮只修远端会留下的本地 `--at` |
| 2 | 远端剥掉令牌后与本地同形 | 两套执行器各自漂移 |
| 3 | `--at` 的 `reason === 'at'`，不是 `null` | `Source` 的无条件 `finished` |
| 4 | `finish` 回执与 `query('state')` 同形 | 控制面与观察面两套形状（B3 的既有纪律） |
| 5 | `Methods::ALL` 含 `FINISH`，宿主能处理 | 登记了没实现 / 实现了没登记 |
| 6 | 自走宿主收到 `finish` 回 `not_applicable` | 两套"谁判停"打架 |
| 7 | `finish` 的 `at` 非法（0 / 负数 / 字符串）报 `bad_request` | 协议参数不校验，错误变成状态撒谎 |
| 8 | 现有 `close` / `frame` / `pause` 行为不变 | 回归 |

## 六、破坏性说明

- `Session` 接口多一个方法：两个实现者加 `DebugSession` 测试夹具要跟上（可控）。
- 协议多一个方法：老宿主会回 `unknown_method`。`--remote` 的宿主由本仓库自己起，
  不存在真实的跨版本场景；`RemoteSession` 不吞这个错误——吞了就等于回到撒谎。
- `Source::resolve()` 对外返回的东西不变；变化只在 `query('state')` 的结果，
  而且是从「错」变成「对」。

## 七、落地记录

（实现后追加。）

## 七、落地记录

### 7.1 一行换掉 `instanceof`，同时修好三种错

`Source::resolve()` 原来那句「本地才标 finished」换成 `$live->finish($at)` 之后，
四种组合一次全对：本地/远端 × 整份/`--at` 给出同样的 `{state, reason, lastFrame}`。
三种错不是三个 bug，是一个动作没有统一入口。

### 7.2 状态规则落在纯函数上

`SessionState::afterDrive(?int $at)` 是唯一规则：整份 → `finished`，`at` → `paused(at)`。
`DebugSession::finish()` 调它并返回 `query('state')` —— 回执与观察面**同一份组装**，
不是两次拼出来再比。宿主里的 `finish` 分支也只调到会话的这个动作，没有第二套翻译。

### 7.3 自走宿主拒绝 `finish`

与 `frame` / `pause` 的现有处理同构：它有 `Drive::settle()` 自己判停，客户端说"我发完了"
在它身上没有意义，回 `not_applicable` 而不是装作接受。否则两套"跑完了"会各自说一套。

### 7.4 `at` 校验放在宿主，不放在客户端

参数是标量，过管道时可能变成字符串或 0。宿主只在 `finish` 一处校验正整数；
非法值回 `bad_request`，之后会话还活着（错误信封不是终止）。客户端不做第二遍检查——
两处校验会各自漂，而漂开的表现是"一边报错一边继续跑"。

### 7.5 七组变异全咬红

| 变异 | 咬住的判据 | 现象 |
|---|---|---|
| 协议表删 `FINISH` | 5 + 1 | 5/6 条失败 |
| `Source` 丢掉 `at` 事实 | 3 | 1/6 条失败（`local-at` 变 `finished`） |
| 规则把 `at` 标成 `finished` | 1 | 1/2 条失败 |
| 宿主收 `finish` 但不标 | 1 | 2/6 条失败（远端仍 `running`） |
| 自走宿主也接受 `finish` | 6 | 8 个 error |
| 远端不发 `at` | 1 | 1/6 条失败 |
| 不校验非法 `at` | 7 | 1 个 error |

### 7.6 判据落点

- `FinishTest`（新）：一条纯函数判据 + 四组合逐值对比（真子进程跑 `--remote`）。
- `HostTest` +2：`finish` 回执与 `state` 同形、`FINISH` 已登记；非法 `at` 被拒且会话还活着。
- `LiveHostTest`：在既有的自走三面判据里补第四面 —— `finish` 报 `not_applicable`。
