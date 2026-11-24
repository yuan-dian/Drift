# B4 · S5 消费者：一份投影，三种呈现

> 2026-11-24。前置：`DESIGN-EVIDENCE-B2`（S-live）、`DESIGN-EVIDENCE-B3`（S6 干预）。
> 本文先证伪三件事，再裁决形状；每一节末尾标出"哪句是实证、哪句是判断"。

## 一、这一期要买什么

分期表给 S5 的判据是：

> 同一份证据在三种消费者里结论相同。终端 / Web / 机器对同一证据给出同一根因；
> 按类名或 id 能直接跳到节点；三端裁剪账逐值一致。

前两条是好判据，第三条**不成立**——见 §4。这一期的真实目标是：

**把"投影"从"终端排版"里剥出来，让第三个消费者（浏览器）只负责画，不负责算。**

注意它不是"加一个 Web 界面"。如果只是加界面，最快路径是 `brief` 输出 HTML，
但那等于把终端搬进浏览器（`<pre>` 一模一样），一点新信息都没有。

## 二、三处现状缺陷

### 2.1 投影里混着终端排版（实证）

`Brief::project(array $facts, int $budget = 40, int $width = 100, ?Thresholds $t = null)`
返回：

```php
['lines' => $lines, 'omitted' => ['findings'=>…,'detail'=>…,'budget'=>…,'rows'=>…], 'findings' => $findings]
```

三个参数里有两个（`budget` / `width`）是**排版参数**，与"同一份证据给同一结论"毫无关系。
三处具体症状：

1. `lines` 是 `sprintf` 拼出来的终端文本，含 `Fit::spread` 的填充空格。`debug-brief --json`
   把它一起吐给机器消费者——机器拿到一坨对齐空格，没有任何用处。
2. `omitted['rows']` 记的是 **`count($lines)`**，即排版行数。Web 端按自己的排版显示时，
   这个数没有意义；而"裁剪了多少条证据"才是跨消费者可比的。
3. `detail()` 返回的是文本行列表，与 `header()` 一样是呈现。它们和 `findings()`（纯数据）
   在同一个类里、同一层上。

**后果**：Web 端只有两条路——渲染 `lines`（`<pre>`，无价值），或自己按 `findings` 重排
（必然与终端的行宽、排序、裁剪规则漂开）。这正是 S5 要防的漂移，而它现在就埋在签名里。

### 2.2 事实采集住在出口里（实证）

`debug-brief.php` 末尾有个全局函数 `gather(SessionQueries $session, string $label): array`，
约 60 行、7 个查询，产出 `facts`。第二个消费者要用它，只有三条路：复制（必然漂）、
`include` 出口文件（会把出口的 `exit()` 一起拉进来）、或改成静态类。

`facts` 是投影的唯一输入。它一旦有两份，"同一份证据"这个前提就没了。

### 2.3 浏览器说不了 NDJSON（实证）

协议传输层是 `Transport`（一行进一行出），三个实现：`ProcessTransport`（子进程 stdio）、
`StdioTransport`（宿主端）、`SocketChannel`（loopback TCP）。

浏览器只能 HTTP/WebSocket。`Transport` 抽象本身没问题（它能装任何一个"行流"），
**有问题的是"谁来把行流转成浏览器能说的东西"**。§5 裁决。

## 三、裁决一：投影只排序，裁剪归渲染

### 拆成两个函数

```php
// 纯数据：只依赖事实与阈值，与排版无关
Brief::project(array $facts, ?Thresholds $t = null): array{findings: list<Finding>, coverage: array, thresholds: array}

// 终端呈现：拿到投影再排版，裁剪发生在这里
BriefText::render(array $facts, array $projection, int $budget, int $width): array{lines: list<string>, omitted: array}
```

`detail()` / `header()` / `omissions()` 一并搬进 `BriefText`。**不重写它们**——它们是对的，
只是住错了房间。搬家的风险是"文本变了"，所以判据是 `BriefTest` 现有的逐字节断言全部照旧通过。

### `detail` 不结构化，这是有意的

有人会问：既然要"三端一致"，为什么 `detail`（帧明细、重建排行）不做成数据？

因为**终端明细和 Web 明细不是同一个东西**。终端受行宽限制，明细只能是"前 N 行文本"；
Web 有画布与结构树，它的"明细"是几何，比文本强一个数量级。硬把终端明细结构化，
得到的是一个 Web 端永远不会用的中间格式——那是为一致性付的假成本。

**只有 `findings` 是跨消费者共享的**，因为它回答的是"哪里不对"，而这是三者都要答的同一个问题。

## 四、裁决二：判据三要改，不是放弃

原文"三端裁剪账逐值一致"在 Web 没有行预算概念时不成立（除非 Web 也按 40 行截，
那它只是一块 `<pre>`）。改成两条能咬的：

1. **三端的 `findings` 序列逐值相同**（规则名 / 标题 / 地址 / 度量 / 阈值 / 复算方式）。
   这是"同一根因"的可判形式。
2. **终端的裁剪自报且可逆**：`--budget=N` 裁掉 K 条，`--budget` 放大到全量后条数为 0 裁剪。

第 2 条把"我裁了多少"从隐含变成数据，且给了读者取回路径。这与 S1 的"自报裁剪"纪律
同源，只是单位从"行"改成"条"。

## 五、裁决三：轮询，不是 SSE

Web 需要"帧在动"的观感。三条路：

| 方案 | 成本 | 与既有设计的关系 |
|---|---|---|
| SSE | 服务端要保存订阅者集合，写完一条不关连接 | 引入一份"谁在看"的状态，而它会与"帧推进"耦合 |
| WebSocket | 握手 + 帧协议（掩码 / ping-pong / 分片），约 300 行 | 为"发送命令"设计，而我们只读 |
| **轮询 `frames(since)`** | 零服务端状态 | **正好用上既有的无状态游标** |

`debug-model.md` 里原本就写着 `frames(since)` 是**无状态游标**——它是为"消费者随时接上、
不问过去"设计的。轮询是它的自然用法，不是退让。

成熟工具对照：Chrome DevTools 走 WebSocket，因为 CDP 是**双向命令**通道（`Runtime.evaluate`
要回值）；Flutter DevTools 走 WS，因为 Dart VM Service 本来就是 WS。我们这层的控制只有
`pause` / `resume` 两个低频动作，普通 POST 足够。**不为了"像成熟工具"而引入 WS。**

代价：默认 250ms 一次轮询，帧率 60fps 时一次看到 15 帧。这是"看趋势"够用、"看单帧"靠点选的
取舍，与 DevTools 的 Performance 面板一致（它也是采一段再展示）。

## 六、裁决四：HTTP 自己写，不用 `php -S`

`php -S` 是独立进程：事实状态只能落在磁盘上或走环境变量传给路由脚本，而 `facts` 是一个
含几百帧的数组。写成 JSON 落盘再读，等于每次请求重做一遍 `gather()`（7 个查询 × 几百帧）。

自己写一个最小 HTTP 服务（约 130 行）能在**同一个进程**里持有 `SessionQueries`，每次请求现查。
它只需要：`GET`、路径匹配、`Content-Type`、`Content-Length`、`Connection: close`。

不做：keep-alive / Range / 分块编码 / 压缩。这些是给公网服务用的；本机调试工具一次请求
一条连接，多一次 TCP 握手的代价是微秒级。

**安全边界**（能咬的判据）：默认只绑 `127.0.0.1`；请求头超 8 KB 断开；静态路径含 `..` 拒绝。
调试工具把 `--host=0.0.0.0` 写成默认，等于给局域网开了个读全部内存结构的门。

## 七、裁决五：页面不重算任何东西

Web 端的纪律只有一条：**它不知道自己看的是什么**。

- 阈值由 `/api/brief` 响应里的 `thresholds` 给出，页面不许有第二个 `16.6`。
- 排序由 `findings` 的数组顺序给出，页面不许 `sort()`。
- 几何由 `/api/boxes?frame=N` 给出，页面不许算"内容盒 = border - padding"。
- 地址 `node:341` 的反查走 `/api/node?node=341`，页面不许自己遍历树。

这条的可判形式不是 grep（数字可能恰好出现在别处），而是：**`thresholds` 必须随 CLI 参数变**。
`--slow=5` 时响应里 `slowMs` 必须是 `5`。页面若写死，它显示的阈值与响应矛盾——那时至少
有一处是错的，而审查者能看见。

## 八、形状定义

### 8.1 文件落点

| 文件 | 状态 | 职责 |
|---|---|---|
| `tools/Debug/View/Facts.php` | 新 | `Facts::gather(SessionQueries, string $label): array`，从出口搬家 |
| `tools/Debug/View/Brief.php` | 改 | `project($facts, ?Thresholds)` 只出数据；其余搬走 |
| `tools/Debug/View/BriefText.php` | 新 | `render($facts, $projection, $budget, $width)`，原 `header`/`detail`/`omissions` |
| `tools/Debug/HttpServer.php` | 新 | 最小 HTTP：`listen(host, port)` / `serve(callable $handler)` |
| `tools/Debug/View/Web.php` | 新 | 路由表 + JSON 应答（不含 HTML/CSS/JS） |
| `tools/Debug/View/web/index.html` | 新 | 页面骨架 |
| `tools/Debug/View/web/app.js` | 新 | 渲染（只画，不算） |
| `tools/Debug/View/web/style.css` | 新 | 样式 |
| `tools/debug-web.php` | 新 | 出口：解析参数、起服务、打印地址 |
| `tools/debug-brief.php` | 改 | 用 `Facts::gather` + `BriefText::render` |

### 8.2 路由表

全部 `GET`，全部无状态（除 `POST /api/ctl`）：

| 路由 | 返回 |
|---|---|
| `GET /` | `index.html` |
| `GET /app.js` · `/style.css` | 静态（限定 `web/` 目录） |
| `GET /api/brief` | `{label, window, digest, thresholds, findings[], omitted}` —— 与 `brief --json` 同一份 |
| `GET /api/frames?since=N&limit=M` | `frames` 查询原样 + `session` |
| `GET /api/boxes?frame=N` | `boxes` 查询原样 |
| `GET /api/tree?frame=N&node=K` | `tree` 查询原样 + `hit` |
| `GET /api/node?node=K&frame=N` | `boxModel` + `props` 合并（选中节点的事实卡） |
| `POST /api/ctl` | 透传 `pause` / `resume`（只对自走宿主有效，从属宿主回 `not_applicable`） |

`/api/*` 的响应体一律是**查询层的原样数组**，`Web` 类不加字段、不改名、不排序。
这一条是"不重算"在服务端的对应物：任何加工都会成为第四份真相。

### 8.3 页面结构

三栏，无框架（原生 DOM，约 400 行）：

```
┌──────────────────────────────────────────────────────┐
│ label · window · digest · 帧数 · 状态        [刷新]  │
├──────────┬───────────────────────────┬───────────────┤
│ findings │  几何画布（该帧所有盒）   │ 结构树        │
│ (可点)   │  hover 显示 node/尺寸     │ 选中高亮      │
│          │                           │ 约束链        │
└──────────┴───────────────────────────┴───────────────┘
```

画布是真的：`boxes(frame)` 给 `rect` 四值 + `applied`（约束）+ `node` + `type`。
按 `border` / `padding` / `content` 三层描边，颜色区分 `type`。点一个矩形 ⇒ 树上滚到
那一行 ⇒ 右侧显示 `boxModel` + `props`。

这是终端永远做不到的一件事：**几何可被看见**。而几何的可信度（S3a 的四层真值）已经钉过了。

## 九、判据

| # | 判据 | 咬什么 |
|---|---|---|
| 1 | `GET /api/brief` 的 `findings` 与 `brief --json` 的 `findings` 逐值相同（同 label / 窗口 / 阈值） | Web 自己算一遍 |
| 2 | `Brief::project()` 的返回**不含** `lines` / `width` / `budget` 键 | 排版漏回投影 |
| 3 | `--slow=5` 时 `/api/brief` 的 `thresholds.slowMs === 5` | 阈值写死在页面里 |
| 4 | `--budget=5` 时 `omitted.findings > 0`；`--budget=300` 时 `omitted.findings === 0` 且 findings 条数等于全量 | 裁剪账不报 / 裁了还报没裁 |
| 5 | `/api/node?node=K` 的 `node` 等于 `K`，且该节点在 `/api/tree` 里唯一命中 | 忽略 node 参数 |
| 6 | `/api/boxes?frame=N` 每行的 `node` 都能在 `/api/tree?frame=N` 找到 | 画布上出现树里没有的盒子 |
| 7 | 请求头 > 8 KB 或路径含 `..` 时连接被关，服务仍存活 | HTTP 解析不设限 |
| 8 | `HttpServer` 默认绑定 `127.0.0.1`（非 `0.0.0.0`） | 默认对局域网开放 |

判据 1 是这一期的中心：它把"同一份证据"变成一次字符串比对。变异方向很直接——让 `/api/brief`
用 `Thresholds::default()` 而 CLI 传了 `--slow=5`，立刻红。

判据 2 是结构断言而非行为断言：`array_key_exists('lines', $project)` 为 false。变异（把
`'lines' => $lines` 塞回 `project`）会红，且它咬的正是这一期的主要重构。

判据 6 盯的是 S4 的"一个节点三张脸"：几何、结构、因果必须指同一个节点集。画布上出现一个
树里没有的盒子，说明几何采样与结构树用了不同的帧或不同的走法。

## 十、落点

1. `Facts::gather()` 从 `debug-brief.php` 搬进 `tools/Debug/View/Facts.php`，出口改调用。
   搬运不改行为：`gather()` 内注释一并搬（它们是判据的来历，不是装饰）。
2. `Brief::project()` 去掉 `$budget` / `$width`，返回 `{findings, coverage, thresholds}`；
   `lines` / `omitted` 的产生搬进 `BriefText::render()`。`BriefTest` 的 10 处调用同步改。
3. `--json` 输出改为 `{label, window, digest, thresholds, findings[], omitted}`，**去掉 `lines`**。
   破坏兼容，消费者只有 `debug-brief` 自己与测试，都同步改。理由：机器消费者拿到排版文本
   是个错误示范，留着就有人用。
4. `HttpServer` + `Web` 路由 + 静态页面 + `debug-web.php` 出口。
5. `bin/drift` 加 `web` 动词（十个 → 十一个），`docs/reference/cli.md` 同步。
6. 判据 1–8，配变异自检。

## 十一、欠账

- 驱动词表三处各一份（`brief` / `network` / `serve`），S5 之后是四处（`web`）。抽 `DriveOptions`
  仍然没做——但 `web` 只接 `--attach` 与驱动词两路，可以让它复用 `Source::resolve` 而不新写一份。
- `detail()` 的文本投影仍与 `findings` 同层（只是搬了房间）。真正的结构化要等"Web 明细有
  明确需求"再做，现在做是给假需求付成本。
- 判据 3 只能钉"阈值是数据"，钉不住"页面没写死"。这一条靠审查，文档里明说。
