# 命令速查

> 列出日常最常用的命令入口、各自解决什么问题，以及判据清单的唯一查看方式。

## 两条纪律先说清楚

**判据清单只在 `php bin/drift verify --list`。** 任何判据项、分层计划、步骤编号都从这条命令读，不往文档里抄清单内容——清单会随代码变化，抄进文档就会漂。想看某个步骤的细节用 `php bin/drift verify --step=<id>`。

**没有第二套 runner。** 所有命令都从 `bin/drift` 这一个入口进，`bin/drift` 只做路由：把子命令转发到 `tests/Verify/verify.php` 或 `tools/` 下的具体脚本，自己不实现任何判据。composer scripts 也只是 `@php bin/drift ...` 的别名，不是独立的验证逻辑。发现一条命令的行为和文档不符时，以脚本为准。

## 三个入口的关系

- `bin/drift` 是唯一入口，管所有子命令的路由。
- `tests/Verify/verify.php` 配合 `tests/Verify/VerifyPlan.php` 是验证核心，判据的分层与步骤都定义在这里。
- `composer` scripts 是 `bin/drift` 的薄封装，方便记忆，可用 `composer run --list` 列出全部可用脚本——这是唯一可发现入口。

## 验证类命令

| 命令 | 作用 |
|---|---|
| `php bin/drift verify` | 日常验证，跑 `static` + `behavior` 的快项 |
| `php bin/drift verify --full` | 全量口径，补 `feature` / `pages` / `smoke`，CI 用这条 |
| `php bin/drift verify --list` | 打印当前判据清单与分层计划，唯一权威 |
| `php bin/drift verify --layer=corpus` | 跑题库对照，`--full` 之外单独指定 |
| `php bin/drift verify --step=<id>` | 单点调试某一个步骤 |
| `php bin/drift test` | PHPUnit unit，缺 PHPUnit 时 exit 2、不算通过 |

对应的 composer 别名：`composer check`、`composer check:full`、`composer verify`、`composer verify:full`、`composer test`。`check` / `check:full` 是历史别名，等价于 `verify` / `verify:full`。`composer tooling` 等价于 `verify --layer=static`。

验证分三层：`static`、`behavior`、`device`。步骤按档位分 `fast`（日常）、`full`（`--full` 才跑）、`manual`（`--device` 手动跑）。题库 `corpus` 是独立量具，不算在三层里。更细的分层与 CI 用法见[协作流程](../contributing/workflow.md)。

## 静态检查

`php bin/drift lint` 跑全量 lint 规则，覆盖 AOT 纪律里能静态判定的部分，例如 `callback-arity`、`attribute-unqualified`、`str-concat-loop`、`internal-leak`。composer 别名是 `composer lint`。lint 规则的适用边界与已知盲区见[AOT 约束与代码纪律](./aot-constraints.md)。

同一个 lint 入口还有 `php tools/lint.php --api`，只生成公开 API 面清单，不做校验——它是 API 面的唯一事实源，用法见[公开 API 面](./api-surface.md)。

## 开发态与真机构建

- `php tools/drift-dev.php <app>`：T1 开发态壳，动态加载 PHP、开真窗口，免重编译。改 `apps/<app>/src/` 里的 PHP 即时生效。composer 别名 `composer dev`。`--project=<目录>` 指定宿主项目根（Drift 作为 composer 包引入时指向你的项目，相对路径按调用时的工作目录解析）；`--entry=<路径>` 覆盖动态入口（默认 Drift 包内的 `apps/dev/dev-entry.php`，相对路径按 `--project` 解析）；`--prebuilt` 用现成 `dev.exe`，跳过壳指纹检查与编译（发布包场景）。
- `php tools/drift-build.php <app>`：T2 AOT 构建，出 exe，需要完整工具链（PHPX、tpc、MSVC、Windows SDK、Skia；字体可选）。默认走 tpc 增量（不清缓存）；`--full` 透传 `--force` 全量重编。composer 别名 `composer build`。
- `python tools/probe-window.py <app>`：T2 真机探针，需要 Python，`DRIFT_PYTHON` 可覆盖路径。composer 别名 `composer probe`。

这三条分别对应"改应用 PHP"、"改框架或 C++ 代码"、"真机验收"三件事。只要动了 `src/` 或 `cpp-src/`，就必须重新跑 `drift-build` 做 T2 验证，dev 壳通过不能代替它。

## 手工工具

以下命令不进验证计划、不进 CI，需要时手动跑：

`debug eval` 是对**活宿主**求任意 PHP 表达式的兜底出口——固定出口答不了的一次性问题用它。先起一个开了开关的宿主（`drift debug serve --page=table --frames=1 --allow-eval`），再在另一个终端 `drift debug eval '$app->root()->id()'`；作用域注入 `$app` / `$session` / `$node`（`--node=N` 时）。默认关、只绑 loopback、结果不是证据；表达式若改了应用状态，回执会报 `evidenceChanged=true`。详见[调试器路线](../concepts/debugger-roadmap.md)与裁决 A55 / A56。

`debug probe` 是两个高频内省问题的结构化快捷方式：`--what=memory` 用 `gc_status()` / `memory_get_usage()` 报进程标量，并用 `WeakMap` 台账给出每个类型的对象从哪一帧起还活着（跨多次查询累积，`maxAge` 越大越可疑）；`--what=source --source=file:line` 用 `ReflectionMethod::getFileName()` 把 `debug report` 给出的调用点翻译成"哪个类的哪个方法、定义在第几行"。两者都是 A55 的 L2 内省——当场读、不进证据包、不进指纹；内存是进程级（含同进程其他窗口），源码只看已加载类。报文原文走证据面的独立出口（`bodies` 开关），不在这里旁路。

`debug pick-live` 是活窗口拾取：布防之后到真窗口里点一下，回执给节点号 / Widget 类型 / 树路径，可直接接 `debug-props --node=N` 追问。先以 `DRIFT_DEBUG_LIVE=1` 起开发壳，再在另一个终端 `drift debug pick-live`；被拾取的点击不进应用（否则选中一个按钮会顺手把它按下）；想提前收手可以撤防（参数见出口帮助）。只对 dev 态有意义，自走宿主回 `not_applicable`。裁决 A58。

`debug targets` 把所有活会话并列成一份只读视图：发现文件里每个进程一行，给发现数、最慢帧与指纹；连接不上的单独标成不可达并附诊断，其他会话照常。它只并列、不按节点号或帧号合并 —— 两个窗口的节点编号不是同一个节点；深挖仍走既有出口并点名会话。裁决 A59。

`debug a11y` 是读屏视角的出口：把生产 `SemanticsBuilder` 当场建出的语义树投影成前序行，也可以按 `--index=N` 看单个节点、按 `--x=N --y=N` 看那个坐标命中的最深控件。三态字段（`selectState` / `expandState` / `scrollAxis`）各自成列，不合成 bool；下标是 UIA 快照下标，**不是**结构节点号，不能拿去 `debug node --node=N`。它是 A55 的 L2 内省——不进证据包、不进指纹，离线包问它会明确回 `semantics-unavailable`。裁决 A77。

`debug capabilities` 回答"这个调试面说什么协议、有哪些能力、现在哪些能用"：不带参数只读本地声明（不用起应用、不用证据包），带 `--session=ID` 才接活会话取宿主的可用性（`eval` 开没开、帧谁推、拾取有没有窗口）。方法名来自动词表、查询形状来自查询规格表、错误码来自协议错误表，不在出口里另抄一份；客户端也可在 `open` 之前发 `capabilities` 消息做同样的事。老宿主回 `unknown_method`，那是"版本太老"而不是"没有能力"。裁决 A71。

`debug tree` 是结构树的导航出口：把节点注册表投影成扁平先序行，按 `--root` / `--depth` / `--limit` 收敛。`--frame` 决定**回放哪一帧**——那时还没出生的节点不出现，那时已经离开的节点同样不出现，而现在已回收、当时还活着的节点照样画出来并标 `已回收`；行尾的 `重建` 说明这一帧重建过它。默认帧是最新一帧，所以不带 `--frame` 时看到的就是当下。几何按同一帧在查询层贴好（几何窗口只有 60 帧且"有变化才记"，超出时夹到窗口内并标"沿用旧记录"）。断父链、孤儿与成环都会单独标出并计入覆盖率，不会静默丢节点；旧证据包缺出生帧时整张表会标 `生命周期 不完整`，不把"不知道"显示成"没有"。裁决 A64 / A67。

`debug tree-diff` 回答另一个问题：两帧之间**发生了什么**（A70）。`--base` 是不含的起点、`--frame` 是含的终点，所以 `(2,4]` 与 `(4,6]` 拼起来正好是 `(2,6]`。事件取生命周期两端与帧记录：出生、离开、区间内重建；同一节点在区间内既出生又离开会给两条事件。它不是"两次 tree() 相减"——后者拿到的是两份被 depth / limit 截断的投影。没有逐帧父 / 类型快照，移位与改型不报；缺帧记录或起点早于节点保留期时，第二行会把缺口写出来，空结果不等于"没发生"。裁决 A70。

<!-- drift-cli-reference:start -->
下表由 `php tools/gen-cli-doc.php` 从出口注册表与参数 schema 生成，勿手改。

### 调试出口

| 动词 | 脚本 | 类别 | 摘要 | 特有参数 |
|---|---|---|---|---|
| `brief` | `debug-brief.php` | driver | 预算内的证据简报：哪里不对、为什么、下一步查哪 | `--budget` `--text-width` `--from` `--out` `--session` `--attach` `--rules` `--baseline` `--save-baseline` `--assert` `--tolerance` `--slow` `--fanout` `--repeat` `--rule` `--without` `--min-severity` |
| `targets` | `debug-targets.php` | protocol | 把当前目录所有活会话并列成一份视图（不跨窗口 join） | — |
| `capabilities` | `debug-capabilities.php` | protocol | 协议版本与能力目录：方法 / 查询形状 / 错误码 / 宿主可用性 | — |
| `report` | `debug-report.php` | driver | 帧因果报告：哪些节点重建了、因为谁 | `--verify` `--top` `--depth` `--why` `--frame` `--save` `--replay` |
| `panel` | `debug-panel.php` | driver | 同一个会话上反复追问：时间轴 + 因果树 + 原因 | `--shots` `--replay` `--keys` `--frame` `--screen` `--save` |
| `network` | `debug-network.php` | driver | 网络骨架与报文（按 requestId 取，脱敏 + 截断） | `--request` `--part` `--limit` `--maxBytes` `--from` `--out` `--attach` `--session` |
| `async` | `debug-async.php` | driver | 异步任务时间线：提交 / 派发 / 结算帧、状态与落地节点 | `--job` `--limit` `--from` `--out` `--attach` `--session` |
| `tree` | `debug-tree.php` | driver | 结构树：第 N 帧那棵树（谁在、谁已消失、谁这一帧重建了） | `--root` `--depth` `--limit` `--frame` `--from` `--out` `--attach` `--session` |
| `tree-diff` | `debug-tree-diff.php` | driver | 两帧结构对比：谁出生、谁离开、谁在中间重建了 | `--base` `--frame` `--limit` `--from` `--out` `--attach` `--session` |
| `a11y` | `debug-a11y.php` | driver | 语义树：读屏看到的节点与状态（L2 内省，不是证据） | `--index` `--x` `--y` `--limit` `--attach` `--session` |
| `node` | `debug-node.php` | driver | 一个节点的三域对齐：因果 / 几何 / 网络 | `--node` `--frame` `--attach` `--session` |
| `find` | `debug-find.php` | driver | 在证据里找：给一段文本，回一串可追问的地址 | `--pattern` `--domain` `--limit` `--case-sensitive` `--from` `--attach` `--session` |
| `eval` | `debug-eval.php` | protocol | dev 壳内省：对活宿主求任意 PHP 表达式（默认关，不是证据） | — |
| `pick` | `debug-pick.php` | driver | 按视口坐标点选，给命中链与每层路径 | `--x` `--y` `--node` `--attach` `--session` |
| `pick-live` | `debug-pick-live.php` | protocol | 布防后到真窗口点一下，回命中节点的身份与路径（live attach） | — |
| `props` | `debug-props.php` | driver | 按节点读 Widget / State 配置 | `--node` `--frame` `--maxDepth` `--maxFields` `--attach` `--session` |
| `probe` | `debug-probe.php` | driver | dev 轻量内省：内存存活跨度 / 源码位置（不是证据） | `--what` `--source` `--limit` `--attach` `--session` |
| `highlight` | `debug-highlight.php` | driver | 让一个节点在屏上被描边，并回答"真的上屏了没" | `--node` `--attach` `--session` |
| `inspect` | `debug-inspect.php` | driver | 真机叠加层：全体边界 / 盒模型四层 / HUD | `--layer` `--attach` `--session` |
| `host` | `debug-host.php` | protocol | 宿主进程（被 --remote 拉起，也可手工对着 stdio 发消息） | — |
| `serve` | `debug-serve.php` | driver | 常驻宿主：自己按脚本推帧，等人 attach 上来看 | `--speed` `--allow-eval` |
| `ctl` | `debug-ctl.php` | protocol | 控制面：让自走的宿主停一下 / 走一步 / 接着跑 | — |
| `web` | `debug-web.php` | driver | 浏览器视图：真机画面 + 几何叠加 + 发现，跟着应用自动刷新 | `--from` `--attach` `--session` `--port` `--host` `--slow` `--fanout` `--repeat` `--rule` `--without` `--min-severity` |
| `loop` | `debug-loop.php` | driver | 重开 N 次：证明关掉这一页再打开是等价的 | `--runs` `--out` `--assert` |

### 共用驱动词

| 参数 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `--page` | string | `null` | 页面路由 id（与位置参数同义） |
| `--frames` | int | `1` | 驱动几帧 |
| `--rows` | int | `0` | 取数替身参数 |
| `--hold` | int | `1` | 动作后再推几帧 |
| `--at` | int | `0` | 只重放到第 N 帧（查询侧的选帧，不改脚本） |
| `--view-width`（别名 `--width`） | float | `800` | 视口宽度 |
| `--height` | float | `600` | 视口高度 |
| `--key` | append | `[]` | 按键，可重复 |
| `--text` | append | `[]` | 输入文本，可重复 |
| `--click` | append | `[]` | 点击 x,y[,键]，可重复 |
| `--wheel` | append | `[]` | 滚轮 x,y,格[,h]，可重复 |
| `--resolve` | flag | `false` | 结算一次异步取数 |

> 每个出口的完整参数以 `php bin/drift debug <动词> --help` 为准；两级 help 与这张表同源。
<!-- drift-cli-reference:end -->

### 退出码

`drift debug` 的出口共用一套退出码，不按出口各定一套：

| 码 | 含义 | 典型场景 |
|---|---|---|
| `0` | 完成 | 证据取到、判据通过 |
| `1` | 进程异常 | 宿主崩了、内部错误（`serve` / `host` 的「宿主异常退出」） |
| `2` | 这次不能继续 | 参数不合法、找不到页面 / 会话、连不上、宿主不支持这个动作（`not_applicable`） |
| `3` | 判据不通过 | `--verify` 两遍不一致、`--assert` 基线不符、`loop --assert` 不等价 |

> 应用自身的 Fatal 退出码也是 `3`（见[调试指南](../guides/debugging.md)），那是**产物进程**的码，与 CLI 无关；两者不要混用。

`network` 看网络骨架与报文，`async` 看三个 TaskHost 后端调度过的 job 时间线（默认关，用 `--async` 开），`node` 把一个节点的因果 / 几何 / 网络三域对齐，`find` 在证据里按字面文本找出一串可追问的地址，`pick` 按视口坐标给出命中链，`props` 读 Widget / State 配置，`highlight` 让一个节点在屏上被描边并用像素回答"真的上屏了没"，`inspect` 往真机窗口上叠布局边界、选中节点的盒模型四层与 HUD（覆盖式开关），`loop` 用重开同一页来证明等价；这些出口的参数见上表与各自的 `--help`。

| composer 脚本 | 作用 |
|---|---|
| `composer codegen` | T1 的 tpc 翻译层门，秒级、不编译，需要源码树里的 `bin/tpc.php` |
| `composer preview` | T0 无头跑完整 layout + paint，输出 SVG 或 PNG |
| `composer aot-equiv` | T2 解释器与全 AOT 的几何逐值等价，退出码 0/1/2 |
| `composer dual-equiv` | T2 解释器与 dev 壳的语义逐值等价 |
| `composer fingerprint` | 解释器侧双通道指纹，供等价比对 |
| `composer debug -- <AppClass｜路由id>` | 无头跑 N 帧，看哪些节点重建、因为谁。目标可以是冒烟夹具类名，也可以是示例台路由 id（`--page=table`、`async-table` 等，依赖页经页面工厂表自动装配）。动作用 `--key=` / `--text=` / `--click=x,y` / `--wheel=x,y,格` / `--resolve`（结算异步取数），`--hold=N` 决定每个动作后推几帧；`--why=<节点id>` 单点追问重建链路（默认取最近一次重建它的帧，`--frame=N` 指定）；报告含最慢帧的分段耗时与"段外"耗时、按控件类型的重建构成、因果覆盖树（结构根 / 子树规模 / state 写入点 / `×N` 同形折叠 / `>` 单链折叠，`--depth=N` 决定展开几层）；`--json` 出机器可读结果。**`--save=<脚本.json>`** 把这次驱动存成可重放脚本（帧边界 + 输入 + 具名副作用 + 目标 / 视口 / 环境）；**`--replay=<脚本.json>`** 照它重跑（目标与视口取自脚本，命令行显式给了就校验一致），`--at=N` 只跑到第 N 帧；**`--verify`** 把同一脚本跑两遍并比对节点表与逐帧因果，不同则退出码 3（判据不通过，与 `--assert` 同口径；`--verify` 与 `--save` 不能同用）。**`--remote`** 把这次会话交给一个宿主子进程跑（同样的脚本、同样的查询），报告由同一段代码组装 —— 它的用途不是省时间，而是让"跨进程读到的值与同进程一致"成为一条可执行的判据，也是"一次驱动、多次追问"的地基 |
| `composer debug -- host` | 帧因果的**宿主进程**：从 stdin 读一条条命令、往 stdout 回一行行结果，中间跑一个真实页面。一般不手工调用（`--remote` 会起它），调试协议本身时可以 `echo '{"id":1,"method":"query","params":{"name":"stats"}}' \| php devtools/bin/debug-host.php` |
| `composer debug -- serve …` | **常驻宿主**：自己按脚本推帧（`--speed=0` 尽快 / `1` 实时 / `N` 倍速；`--at=N` 跑到第 N 帧停下，状态记 `paused` + `reason=at`，与「跑完了」的 `finished` 分开），把「怎么连上我」（端口 / 令牌 / pid）写进 `.drift/session.json`，然后阻塞等人来看 —— 跑完也不退进程。另一个终端用 `composer debug -- brief --attach` 连上去读（同目录有多个会话时 `--session=<id>` 点名），观察者只读不驱动、退出只断开，不会结束别人正在看的会话。**每推一步都会回头看一眼有没有人说话**，所以 `--speed=0` 也能被叫停（它表示"不按实时节奏等待"，不表示不考虑响应性） |
| `composer debug -- ctl …` | **控制面**：让一个自走的宿主停一下 / 走一步 / 接着跑。`pause`、`resume`、`state`（省略动作 = `state`），`--session=<id>` 点名，`--json` 结构化。`resume --frames=1` 是**单步**——推进的是脚本自己的下一帧，命令会等到真的停下来才打印结果（`paused` + `reason=step`）。`--at` 停住的会话 `resume` 后能接着跑（观察点只生效一次）。从属宿主（`--remote` 起的那个）上这些动作回 `not_applicable`：那里客户端停手就是暂停 |
| `composer debug -- web …` | **浏览器视图**：起一个本机 HTTP 服务（默认 `127.0.0.1`，`--port=0` 让系统挑，`--host=0.0.0.0` 会警告），页面把该帧所有盒按几何画出来、左边列发现。数据与 `brief --json` 同源（`/api/brief` 与它共用一份投影），页面不重算任何数字；`--slow` / `--fanout` / `--repeat` 与 `brief` 同义。来源与 `brief` 一样支持 `--page` / `--attach` / `--from`；接自走宿主时页面上有暂停 / 单步 / 继续按钮 |
| `composer debug -- panel -- <AppClass｜路由id>` | 在**同一次运行**里反复追问：时间轴 + 因果树 + 原因浮层（`Tab` 切换）。驱动参数与 `composer debug` 同一套（目标 / `--page=` / `--frames=` / `--rows=` / `--hold=` / `--key=` / `--text=` / `--click=` / `--wheel=` / `--resolve` / `--replay=` / `--at=`），`--remote` 让它对着宿主子进程问。`--frame=N` 直接落在某一帧（默认落在最慢的**有重建**的帧上）；`--screen=WxH` 设终端尺寸，默认取 `COLUMNS` / `LINES`——它与 `--view-width`（别名 `--width`） / `--height` 的页面视口是两件事。键：`j/k` 移动、`J/K` 翻页、`g/G` 首尾、`⏎` 展开 / 下钻、`w` 原因、`n/N` 推 1 帧 / 10 帧、`[` `]` 换帧、`Tab` 切换、`?` 帮助、`q` 退出。**`--keys=j,j,enter,w,q`** 是非交互模式：按键依次施加，只打印最后一屏（`--shots` 每步一屏，`--json` 出结构化结果）—— 交互本身也要有判据，否则"能点"只是手测过。光标按行键（树行 `t:<节点id>`、帧行 `f:<帧号>`）记而不是按下标：展开、换帧、树重算都不会把它挪到别的节点上；选中的那行消失时退回最近的活着的祖先，并在状态栏说明 |
| `composer font-scan` | 扫描字面量里的非 ASCII 字符，生成字模资产输入 |
| `composer golden-report` | 把 golden 检查的失败现场渲染成自包含 HTML |
| `php tools/gen-route-manifest.php <app>` | 按应用参数从 `#[Route]` 重生成 AOT 页清单；`--check` 只查是否过期 |
| `php tools/gen-cli-doc.php [--check]` | 从出口注册表 / 参数 schema 重写本页生成块；`--check` 只核对不改 |
| `composer grab` | 用 `cl.exe` 重建真机抓像素工具，需要 MSVC |

## 环境与依赖脚本

环境自查用 `php tools/doctor.php`，加 `--strict` 会把警告也当失败。它是环境判据的单一真相源，构建脚本和它共用一套组件定位逻辑，所以 doctor 绿了、构建却找不到组件的情况应按 doctor 的输出排查。环境档位、组件版本与覆盖变量见[环境搭建](../contributing/setup.md)。

依赖包相关命令：

- `php tools/fetch-deps.php`：按 `DEPS_VERSION` 指定的 tag 与宿主 OS 取当前平台依赖包，校验、解压到仓库根；`--platform=<id>` 可显式指定。
- `php tools/fetch-deps.php --tag=<tag>`：临时钉某个 tag ，`--force` 强制重下。
- `php tools/fetch-deps.php --latest`：取仓库最新 release。
- `php tools/fetch-deps.php --url=<路径>` 或 `DRIFT_DEPS_URL=<目录>`：从本地包或目录取，走同一套校验。
- `php tools/pack-deps.php --platform=<id>`：组当前平台包并写 `DEPS-MANIFEST` 与锁中对应平台条目。
- `bash tools/skia/build-skia.sh --replace`：本地重编 Skia，字体需另行备齐。

依赖包的获取、校验与发布流程见[依赖与发布](../guides/dependencies-and-release.md)。

## 相关

- [AOT 约束与代码纪律](./aot-constraints.md)
- [诊断与错误码](./diagnostics.md)
- [环境搭建](../contributing/setup.md)
- [协作流程](../contributing/workflow.md)
