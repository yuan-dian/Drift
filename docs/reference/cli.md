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
- `php tools/drift-build.php <app>`：T2 AOT 构建，出 exe，需要完整工具链（PHPX、tpc、MSVC、Windows SDK、Skia；字体可选）。`--full` 走完整口径。composer 别名 `composer build`。
- `python tools/probe-window.py <app>`：T2 真机探针，需要 Python，`DRIFT_PYTHON` 可覆盖路径。composer 别名 `composer probe`。

这三条分别对应"改应用 PHP"、"改框架或 C++ 代码"、"真机验收"三件事。只要动了 `src/` 或 `cpp-src/`，就必须重新跑 `drift-build` 做 T2 验证，dev 壳通过不能代替它。

## 手工工具

以下命令不进验证计划、不进 CI，需要时手动跑：

<!-- drift-cli-reference:start -->
下表由 `php tools/gen-cli-doc.php` 从出口注册表与参数 schema 生成，勿手改。

### 调试出口

| 动词 | 脚本 | 类别 | 摘要 | 特有参数 |
|---|---|---|---|---|
| `brief` | `debug-brief.php` | driver | 预算内的证据简报：哪里不对、为什么、下一步查哪 | `--budget` `--text-width` `--from` `--out` `--session` `--attach` `--rules` `--baseline` `--save-baseline` `--assert` `--tolerance` `--slow` `--fanout` `--repeat` `--rule` `--without` `--min-severity` |
| `report` | `debug-report.php` | driver | 帧因果报告：哪些节点重建了、因为谁 | `--verify` `--top` `--depth` `--why` `--frame` `--save` `--replay` |
| `panel` | `debug-panel.php` | driver | 同一个会话上反复追问：时间轴 + 因果树 + 原因 | `--shots` `--replay` `--keys` `--frame` `--screen` `--save` |
| `network` | `debug-network.php` | driver | 网络骨架与报文（按 requestId 取，脱敏 + 截断） | `--request` `--part` `--limit` `--maxBytes` `--from` `--out` |
| `node` | `debug-node.php` | driver | 一个节点的三域对齐：因果 / 几何 / 网络 | `--node` `--frame` |
| `pick` | `debug-pick.php` | driver | 按视口坐标点选，给命中链与每层路径 | `--x` `--y` `--node` |
| `props` | `debug-props.php` | driver | 按节点读 Widget / State 配置 | `--node` `--frame` `--maxDepth` `--maxFields` |
| `host` | `debug-host.php` | protocol | 宿主进程（被 --remote 拉起，也可手工对着 stdio 发消息） | — |
| `serve` | `debug-serve.php` | driver | 常驻宿主：自己按脚本推帧，等人 attach 上来看 | `--speed` |
| `ctl` | `debug-ctl.php` | protocol | 控制面：让自走的宿主停一下 / 走一步 / 接着跑 | — |
| `web` | `debug-web.php` | driver | 浏览器视图：同一份证据的第三个消费者（几何画布 + 发现） | `--from` `--attach` `--session` `--port` `--host` `--slow` `--fanout` `--repeat` `--rule` `--without` `--min-severity` |
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

`network` 看网络骨架与报文，`node` 把一个节点的因果 / 几何 / 网络三域对齐，`pick` 按视口坐标给出命中链，`props` 读 Widget / State 配置，`loop` 用重开同一页来证明等价；这五个出口的参数见上表与各自的 `--help`。

| composer 脚本 | 作用 |
|---|---|
| `composer codegen` | T1 的 tpc 翻译层门，秒级、不编译，需要源码树里的 `bin/tpc.php` |
| `composer preview` | T0 无头跑完整 layout + paint，输出 SVG 或 PNG |
| `composer aot-equiv` | T2 解释器与全 AOT 的几何逐值等价，退出码 0/1/2 |
| `composer dual-equiv` | T2 解释器与 dev 壳的语义逐值等价 |
| `composer fingerprint` | 解释器侧双通道指纹，供等价比对 |
| `composer debug -- <AppClass｜路由id>` | 无头跑 N 帧，看哪些节点重建、因为谁。目标可以是冒烟夹具类名，也可以是示例台路由 id（`--page=table`、`async-table` 等，依赖页经页面工厂表自动装配）。动作用 `--key=` / `--text=` / `--click=x,y` / `--wheel=x,y,格` / `--resolve`（结算异步取数），`--hold=N` 决定每个动作后推几帧；`--why=<节点id>` 单点追问重建链路（默认取最近一次重建它的帧，`--frame=N` 指定）；报告含最慢帧的分段耗时与"段外"耗时、按控件类型的重建构成、因果覆盖树（结构根 / 子树规模 / state 写入点 / `×N` 同形折叠 / `>` 单链折叠，`--depth=N` 决定展开几层）；`--json` 出机器可读结果。**`--save=<脚本.json>`** 把这次驱动存成可重放脚本（帧边界 + 输入 + 具名副作用 + 目标 / 视口 / 环境）；**`--replay=<脚本.json>`** 照它重跑（目标与视口取自脚本，命令行显式给了就校验一致），`--at=N` 只跑到第 N 帧；**`--verify`** 把同一脚本跑两遍并比对节点表与逐帧因果，不同则退出码 1。**`--remote`** 把这次会话交给一个宿主子进程跑（同样的脚本、同样的查询），报告由同一段代码组装 —— 它的用途不是省时间，而是让"跨进程读到的值与同进程一致"成为一条可执行的判据，也是"一次驱动、多次追问"的地基 |
| `composer debug-host` | 帧因果的**宿主进程**：从 stdin 读一条条命令、往 stdout 回一行行结果，中间跑一个真实页面。一般不手工调用（`--remote` 会起它），调试协议本身时可以 `echo '{"id":1,"method":"query","params":{"name":"stats"}}' \| php tools/debug-host.php` |
| `composer debug -- serve …` | **常驻宿主**：自己按脚本推帧（`--speed=0` 尽快 / `1` 实时 / `N` 倍速；`--at=N` 跑到第 N 帧停下，状态记 `paused` + `reason=at`，与「跑完了」的 `finished` 分开），把「怎么连上我」（端口 / 令牌 / pid）写进 `.drift/session.json`，然后阻塞等人来看 —— 跑完也不退进程。另一个终端用 `composer debug -- brief --attach` 连上去读（同目录有多个会话时 `--session=<id>` 点名），观察者只读不驱动、退出只断开，不会结束别人正在看的会话。**每推一步都会回头看一眼有没有人说话**，所以 `--speed=0` 也能被叫停（它表示"不按实时节奏等待"，不表示不考虑响应性） |
| `composer debug -- ctl …` | **控制面**：让一个自走的宿主停一下 / 走一步 / 接着跑。`pause`、`resume`、`state`（省略动作 = `state`），`--session=<id>` 点名，`--json` 结构化。`resume --frames=1` 是**单步**——推进的是脚本自己的下一帧，命令会等到真的停下来才打印结果（`paused` + `reason=step`）。`--at` 停住的会话 `resume` 后能接着跑（观察点只生效一次）。从属宿主（`--remote` 起的那个）上这些动作回 `not_applicable`：那里客户端停手就是暂停 |
| `composer debug -- web …` | **浏览器视图**：起一个本机 HTTP 服务（默认 `127.0.0.1`，`--port=0` 让系统挑，`--host=0.0.0.0` 会警告），页面把该帧所有盒按几何画出来、左边列发现。数据与 `brief --json` 同源（`/api/brief` 与它共用一份投影），页面不重算任何数字；`--slow` / `--fanout` / `--repeat` 与 `brief` 同义。来源与 `brief` 一样支持 `--page` / `--attach` / `--from`；接自走宿主时页面上有暂停 / 单步 / 继续按钮 |
| `composer panel -- <AppClass｜路由id>` | 在**同一次运行**里反复追问：时间轴 + 因果树 + 原因浮层（`Tab` 切换）。驱动参数与 `composer debug` 同一套（目标 / `--page=` / `--frames=` / `--rows=` / `--hold=` / `--key=` / `--text=` / `--click=` / `--wheel=` / `--resolve` / `--replay=` / `--at=`），`--remote` 让它对着宿主子进程问。`--frame=N` 直接落在某一帧（默认落在最慢的**有重建**的帧上）；`--screen=WxH` 设终端尺寸，默认取 `COLUMNS` / `LINES`——它与 `--view-width`（别名 `--width`） / `--height` 的页面视口是两件事。键：`j/k` 移动、`J/K` 翻页、`g/G` 首尾、`⏎` 展开 / 下钻、`w` 原因、`n/N` 推 1 帧 / 10 帧、`[` `]` 换帧、`Tab` 切换、`?` 帮助、`q` 退出。**`--keys=j,j,enter,w,q`** 是非交互模式：按键依次施加，只打印最后一屏（`--shots` 每步一屏，`--json` 出结构化结果）—— 交互本身也要有判据，否则"能点"只是手测过。光标按行键（树行 `t:<节点id>`、帧行 `f:<帧号>`）记而不是按下标：展开、换帧、树重算都不会把它挪到别的节点上；选中的那行消失时退回最近的活着的祖先，并在状态栏说明 |
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
