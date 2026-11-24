# DESIGN-EVIDENCE-B3：干预（S6）

> 对象：`src/Debug/Protocol/`、`tools/Debug/`、`tools/debug-serve.php`。
> B2 把"驱动"与"观察"拆开了，这一期加上唯一一条反向流：**观察者能让它停**。
> 本文只记"为什么选这个做法"和"落地前先证伪的三件事"。

## 一、S6 要买的不是"暂停按钮"

三种"停"对观察者的含义完全不同，这是整期的立足点：

| 停因 | 含义 | 能恢复吗 |
|---|---|---|
| `finished` | 看吧，就是这样 | 不能 |
| `crashed` | 这里有问题 | 不能 |
| `paused` + `at` | 你要看的就在这一帧 | 能 |
| `paused` + `requested` | 我按了暂停，一会儿还动 | 能 |
| `paused` + `step` | 刚往前挪了一格，还停着 | 能 |

B2 已经把 `reason` 的形状留好了（`SessionState` 三个字段同行），本期只填值。所以 S6 的实质不是"加一个暂停功能"，而是**让"停下来"这件事有可区分的说法，并且让停下与继续都不改变记录内容**。

"不改变记录内容"这一条是本期的真判据。暂停要是能改变 `t` 序列或结构指纹，它就污染了证据——而证据是这套工具存在的全部理由。

## 二、动手前先证伪三件事

### 2.1 `--speed=0` 的宿主在跑完之前读不到一条消息

实测（`--frames=2000`，起宿主后立刻 attach 并查 `state`）：

```
speed=0 连上后立刻 state: finished lastFrame=2000
speed=1 连上后立刻 state: running  lastFrame=1
```

`--speed=0` 是**默认值**。它把整个脚本塞进**第一次** `tick()` 调用里——`Host` 的循环是"没消息才推帧"，而 `tick` 里一个 `while ($index < $total)` 推到底，中间没有一次 `wait()`。于是这条会话从开局到 `finished` 这段时间里不可中断：`pause` 发得出去，但要等脚本跑完才被读到，那时候 `pause` 已经没有意义了。

这不是"性能好"，是**默认配置下干预功能不存在**。不先修这个，S6 只是给 `--speed=1` 加了个开关。

### 2.2 `Host` 用"把回调扔掉"表达"不再推进"

`tick` 返回 `false` ⇒ `$tick = null` ⇒ 此后不再调。`--at` 到点走这条路，`finished` 走这条路，`crashed` 也走这条路。三种停法在宿主眼里是**同一件事**，而其中只有一种能恢复。

要加 `resume`，就得让"还能不能推"重新变成可读的状态。选项只有两个：在 `Host` 里再加一个 `bool $paused`，或者**去读会话已有的那个 `state`**。前者是第二份记账，必然与 `SessionState` 漂开（`state` 查询报 `paused` 而宿主还在推，或者反过来）。

### 2.3 驱动状态全在闭包引用里

`debug-serve.php` 的 tick 用 `use (&$index, &$pushed, &$pace)` 共享四个变量。加暂停要第五个、加单步要第六个。闭包 + 引用是**最难测**的形态：`LiveHostTest` 只能起子进程端到端验，验不了"限速算得对不对"、"`--at` 停在第几帧"。

而这三件事（`--at` 判定、单步限额、限速锚点）恰恰都是纯函数式的、该被单测的。

## 三、暂停状态放在哪：三个方案

**A. `Host` 存 `bool $paused`。** 简单，但 `state` 查询读的是 `SessionState`。查询报 `paused` 而宿主继续推（或反过来）是一种**必然**会发生的漂移，因为它需要"每次改一边就记得改另一边"。否决。

**B. `SessionState::state` 是唯一真相。** `Host` 每轮读一次，"不是 `running` 就不推"。`pause` / `resume` 直接改它。
**C. 独立 `Controller` 对象持有暂停标志，`state` 由它派生。** B 外面多包一层，多出来的那层不回答任何新问题。

选 **B**。它同时把 2.2 的三条停因统一了：`finished` / `crashed` / `paused` 在宿主眼里都是"不是 `running`"，一个条件管三种。附加效果是 `--at` 的 tick 不再需要用返回值表达停止——返回值可以专心表示"这一轮还有没有事做"。

代价：`Host` 每轮多读一次状态。一个字符串比较，可忽略。

## 四、单步不该走 `frame` —— 推翻 B2 那一句

B2 §四 写的是：

> `frame` 方法保留但语义降级：只在宿主「暂停」时可用，等于单步。

**这句话不成立。** 它要求客户端给一个 `t`，而在自走宿主里 `t` 属于脚本。让观察者去编一个时间戳推进"别人的时间线"，会立刻产生两条时间轴：脚本的 `t` 序列里插进一个客户端凭空给的数，而 `digest` 不含时间（它比不出这件事），只有 `t` 序列能被看出断裂——偏偏观察者很少去看它。

成熟工具都是"调试器知道下一个可停点在哪，客户端不需要提供时间"：CDP 的 `Debugger.stepOver`、DAP 的 `next`、gdb 的 `stepi` 都只带"走一步"这个意图，不带时间。

所以改成：**单步 = `resume` 带 `frames: 1`**。

- 不新增方法，`resume` 的参数就够；
- `t` 仍然只来自脚本，判据 1 的口径不用改；
- 在自走宿主上 `frame` 一律拒绝（`not_applicable`），而不是"暂停时放行"——两条时间轴的危险比"少一个用法"大得多。从属宿主（`debug-host.php`）不受影响：那里客户端本来就是驱动方。

这也顺带解释了为什么 `reason` 要多一个 `step`：`requested` 是"我按了停"，`step` 是"我要的那一格走完了"。自动化脚本靠这个值判断单步完成，靠 `requested` 判断"还是我按的那次停"。混成一个值，轮询方只能靠计帧数去猜。

## 五、`Drive`：把驱动抽成对象，一次只推一步

`Host::serve($open, ?Drive $drive)`，原来的 `$tick` 闭包与 `?Script $autoStart` 都收进 `Drive`：

```php
final class Drive
{
    public function __construct(Script $script, ?int $at = null, float $speed = 0.0);
    public function script(): Script;                        // 开局脚本（替代 $autoStart）
    public function advance(DebugSession $session): bool;     // 推一步；false = 这一轮到此为止
    public function pause(DebugSession $session): void;       // reason=requested
    public function resume(DebugSession $session, ?int $frames = null): void;
}
```

四个理由：

1. **`?Drive` 一个参数同时表达"自走"与"开局脚本"**。原来的 `$tick` 与 `$autoStart` 必须一起给（只给 `$tick` 会让自走宿主安静地一帧不推），而类型上没有任何东西挡着只给一个。现在这种半配置状态不可表达。
2. **可单测**。`--at` 停在第几帧、单步之后 `reason` 是 `step`、限速算得对不对，都不需要起子进程。
3. **`resume` 要重置限速锚点**——这个坑藏在闭包里永远显不出来（见 §七）。
4. **停下之后立刻改状态**：`advance` 推完一步就在同一函数里 `settle()`，不留"帧已推完但 `state` 还是 `running`"的窗口。原来的 `--at` 判定在**下一次** tick 的开头，中间那个窗口在 `--speed=0` 下是微秒级，但判据偶发红只需要一次。

### 为什么"一步一推"值得慢一点

一步一推意味着每次 `advance` 之后宿主可能回到 `wait(0)` 扫一眼消息。实测空通道 `wait(0)` 是 **5.35 µs/次**（Windows）。30 帧脚本约 90 步 ⇒ 约 0.48 ms 额外开销。

`--speed=0` 的总时长会因此涨三成左右（30 帧本身约 1.4 ms）。看起来很贵，但它买的东西是"默认配置下干预功能存在"（§2.1）。而且：

- **判据 3 量的是帧耗时 `cost`，不是总时长**——`wait()` 不在 `shell->frame()` 里面，也不在 `pump()` 的返回值里；
- `--speed=1` 时总时长由脚本的 `t` 决定（30 帧 = 480 ms），0.48 ms 占 0.1%；
- 线性增长：3000 帧脚本 9000 步 ≈ 48 ms，相对脚本本身约 138 ms 仍是三成，不爆炸。

反过来说，"吞掉整段脚本才能换来的那点快"是拿干预能力换的，而这期就是来买干预的。

## 六、`--at` 与 `resume` 的关系

`--at=N` 的意思是"跑到第 N 帧停下，那儿有东西给你看"。观察者看完说继续，那这个观察点就**不该再挡第二次**——否则 `resume` 一步都走不动（`pushed >= at` 恒成立）。

所以 `settle()` 里命中 `at` 之后立刻把 `$this->at` 置 `null`：**观察点只生效一次**。`limit` 同理（单步完成后清掉），否则下一次 `resume` 会被上一次的限额立刻叫停。

`at` 与 `limit` 恰好命中同一帧时（`at=6` 且从 `pushed=5` 单步），报 `at`：预设观察点承载"这里有问题"的语义，比"你要的那一步走完了"更该被优先告知。两者都会被清掉，所以不会留一个立刻叫停的悬空限额。

## 七、`resume` 必须重置限速锚点

限速靠一个锚点算：`due = 锚点墙钟 + (本帧 t − 锚点 t) / speed`。暂停 500 ms 之后，`due` 比 `now` 早 500 ms ⇒ `waitMs` 是个大负数 ⇒ **连续推 31 帧把欠账补完**。

这个 bug 特别隐蔽，因为：

- `t` 序列一个字节都没变（`t` 来自脚本）⇒ 判据 1 恒绿；
- `digest` 也不变 ⇒ 指纹判据恒绿；
- 观察者只看到"恢复之后突然多了一堆帧"，会归因到"暂停期间还在跑"。

所以 `resume()` 里 `$this->pace = null`，下一次帧步重新锚定。判据要专门咬它（§十 判据 6），咬法是**恢复后短窗口内的帧数上限**——不重置的话 500 ms 的欠账会在几毫秒内补完，`lastFrame` 直接跳几十帧。

## 八、`ctl`：干预需要一个出口

现有九个出口都是"查一次就退"。干预必须能单独发一条命令，因为做干预的人往往不在同一个终端里看着输出。

形态选 `redis-cli` / `systemctl` 式的**一次一命令**：

```
drift debug ctl                          # 不带动作 = state
drift debug ctl pause
drift debug ctl resume
drift debug ctl resume --frames=1        # 单步
drift debug ctl state --session=ab12cd34
```

否决的三种：

- **给 `brief` 加 `--pause`**：`brief` 的职责是"出一份简报"，让它在出简报的同时按一下暂停，读代码的人会先愣三秒。
- **`panel` 加交互键**：`panel` 是交互式的，但它连的是自己造的会话，不是 attach 的。要让它 attach，等于把 `panel` 变成另一种东西。
- **交互式 REPL**：没有 UI 时 REPL 不比一行命令好用，而且它会把"一次一动作"变成"一段脚本"，可判性更差。

`ctl` 只需要 attach 一种来源，所以它不经 `Source::resolve()` 的四选一，而是取 `Source::remote()`——`Source` 已经收口了"挑会话 / 连上去 / 报诊断"三件事，`ctl` 不该抄一份。

## 九、`driven` 标志：客户端不该靠猜

`attach` 回执加一个 `driven: bool`——"帧是这个宿主自己推的"。客户端拿到 `true` 就别用 `frame`（会被拒）；拿到 `false` 就知道自己得推。

不加这个字段的替代方案是"发一次 `frame` 看它拒不该拒"，但那会在真正需要推帧的场景里浪费一次往返，而且错误码本身分不清"这个宿主自走"与"这个方法在这个宿主上不支持"。

`open` 回执不加：自走宿主上 `open` 会被 `already_open` 挡住（`autoStart` 已经建了会话），观察者只能 `attach`。

## 十、判据

| # | 判据 | 咬什么 |
|---|---|---|
| 1 | `pause` 之后 `lastFrame` 在 100 ms 内一动不动 | `Host` 忽略 state 继续推 |
| 2 | 暂停态 `state=paused` / `reason=requested`；`resume` 后 `running` / `reason=null` | `reason` 不写 / 写成 `at` |
| 3 | 单步之后 `paused` / `reason=step`，且 `lastFrame` 恰好多 1 | `resume` 忽略 `frames` 参数 |
| 4 | 暂停期间 `frames` / `digest` / `state` 照常返回，内容含暂停前的全部帧 | 暂停时把循环堵在等消息上 |
| 5 | `--at` 停住的会话 `resume` 后能跑到 `finished` | `at` 不失效（`resume` 立刻又被叫停） |
| 6 | `--speed=1` 暂停 500 ms 后恢复，50 ms 内 `lastFrame` 涨幅 ≤ 8 | 不重置限速锚点（会一次补几十帧） |
| 7 | 直跑与"中途暂停 500 ms"两份的 `t` 序列与 `digest` 逐值相同 | 暂停污染记录 |
| 8 | 自走宿主 attach 回执 `driven=true`，从属宿主 `false` | 恒真或恒假 |
| 9 | 自走宿主上 `frame` 返回 `not_applicable` | 放行 ⇒ 两条时间轴 |

判据 7 是这一期最重要的一条：它把"暂停改变结果"这个最可怕的失败模式钉死。它与判据 1（B2）同构——那一条证明"喂帧的节奏不影响记录"，这一条证明"停下来也不影响记录"。

## 十一、落点

1. `tools/Debug/Drive.php`：§五的形状，`settle()` 里清 `at` / `limit`。
2. `Host::serve(callable $open, ?Drive $drive = null)`：删掉 `$tick` / `$autoStart` 两参；循环改读 `$session->canAdvance()` 决定 `wait()` 超时与是否推进；退出条件仍是"没连接 且 没有 drive"。
3. `Methods` 加 `PAUSE` / `RESUME`，注释从"只有六个"改成"按角色分组：会话 / 驱动 / 读 / 干预"（B2 §十二 已预告，这期兑现）。
4. `ProtocolError::NOT_APPLICABLE`：一个码管两种情形——"这个宿主不由客户端驱动"（`frame`）与"这个宿主自己不会动"（`pause` / `resume`）。它们是同一个问题的两面。
5. `RemoteSession::pause()` / `resume()` / `driven()`；`DebugSession::canAdvance()`。
6. `tools/debug-ctl.php` + `Source::remote()`；`bin/drift` 加 `ctl`（九个出口变十个），`docs/reference/cli.md` 同步。
7. `debug-serve.php`：闭包整体搬进 `Drive`，出口只留 `--at` / `--speed` 两个词的解析（它们是这个命令自己的话）。
8. 判据 1–9，其中 6 与 7 属端到端（要真子进程才能"暂停 500 ms"）。

## 十二、不做

- **不起 `--watch` 自动重跑**（S-live-b）：它的判据要 S5 的 Web 视图才看得出差别。
- **不做 `highlight` / 断点**：断点是"条件暂停"，而条件的表达（哪个节点、什么谓词）是一整套设计，不是加一个方法的事。
- **不建议"暂停是观察者级的"**：`SessionState` 是会话级，A 按下暂停 B 也停。这是干预的固有后果，不是缺陷——多观察者共享**同一次运行**，共享它的调度状态才是自洽的。真要做观察者级，得先有"观察者身份"，而协议里没有这个概念。
- **不顺手抽 `--page` / `--frames` / `--click` 那套词表**（B2 记的欠账）：它是纯搬迁，与本期的能力改动正交。本期把 `debug-serve.php` 的闭包搬进 `Drive` 之后，欠账范围反而缩到了"三个出口各抄一份解析"，下一轮处理更清楚。

## 十三、`--speed=0` 变慢这件事要说给人听

一步一推让默认配置的总时长涨三成。这不是回归，但它会被当成回归——"我上次跑 2000 帧 3 秒，现在 4 秒"。

所以 `debug-serve.php` 的 `--help` 里要写一句：`--speed=0` 表示不按实时节奏等待，**不表示不考虑观察者的响应性**；宿主每推一步都会回头看一眼有没有人说话，这一步的成本是几微秒。

不说这句，下一个人会去猜"是不是 select 没优化"，然后花半天优化一个本就不该优化的东西。
