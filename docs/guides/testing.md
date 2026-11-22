# 验证体系

> 本篇解决：T0/T1/T2 三层各能抓什么，为什么 `verify` 是唯一入口、判据清单只在 `--list`，以及什么情况下 dev 绿不算绿、必须上真机。

## 三层：T0、T1、T2

| 层 | 入口 | 能否抓到 AOT 生成物问题 |
|---|---|---|
| **T0** | `bin/drift verify` 的日常档，纯 PHP | 不能 |
| **T1** | `php tools/drift-dev.php <app>`，开发态壳 | 不能 |
| **T2** | `drift-build --full` + 真机探针 | 能，唯一能 |

T0 装 PHP 就能跑；`composer.json` 声明的 PHP 版本无上界，所以 CI 在公开 runner 上用 8.4 / 8.5 两个版本都跑一遍，只在单版本上验等于承诺没兑现。T1 只编一次壳与 C++ 桥，业务 PHP 由壳内嵌 ZendVM 运行时加载，改 PHP 即时生效；它验解释器和壳层逻辑。T2 在公开 `windows-2022`（`windows-build.yml`）与 `ubuntu-24.04`（`linux-build.yml`，X11）runner 上从源码构建工具链，两条通道都按 PHP 8.4 / 8.5 双矩阵跑，本地也按需跑。

三层各自能抓的东西不同：T0 抓语言级静态问题、集合封闭、错误码贯通、版本锚点一致性，以及行为层的单元测试、IR 快照、整页像素基线和 smoke；T1 抓"真窗口里跑起来"与解释器 / 壳的语义等价；T2 抓 AOT 生成物与 OS 边界的一切。

## verify 是唯一入口，清单只在 `--list`

所有 `composer` 脚本都经 `bin/drift` 转发到 `tests/Verify/verify.php`：**不是第二个 runner，也没有第二套退出码口径**。`check` / `check:full` / `checks` / `tooling` 只是历史脚本名别名，一律走同一个 V2 runner。

判据的项数与顺序**只**在 `php bin/drift verify --list` / `composer checks` 里可见，会随新增判据变化，因此不抄进文档、不写进注释——手写白名单会漏掉新增项，漏掉的偏偏是最该进闸门的那批。单点调试用 `composer verify -- --step=<id>`，不要另抄一份白名单。

```bash
composer verify                      # T0 日常（static + behavior 快项）
composer verify -- --step=unit       # 单点调试；-- --json 机器可读
composer verify:full                 # T0 全量（含 feature / pages / smoke 慢项）
composer checks                      # 当前判据清单（--list，顺序即执行顺序）
composer dev -- <app>                # T1 开发态壳
composer build -- <app> --full       # T2 AOT 构建
composer probe -- <app>              # T2 真机探针
```

裸命令仍在 `tools/` 下（CI、真机探针、调试直接调），但不能据它另抄一份清单：`php bin/drift verify --json`、`php tools/phpunit-run.php unit`、探针脚本都只是转发后的落点。`bin/drift test` 在没装 PHPUnit 时返回 `2`，**不算通过**；AOT 等价检查用 `0 / 1 / 2` 分别表示等价、不等价、无法完成，别把 `2` 当绿。

计划按 `static / behavior / device / corpus` 组织，tier 分 `fast`（日常）、`full`（`--full` 才跑）、`manual`（`--device`）。题库全量对照是 `--layer=corpus` 的独立量具，**不进 verify 报告**。`--shard` 分片只回答"我改的这块还绿吗"，不能当验收报告；覆盖率由 `--full` + corpus 回答。

判据**实现**继续住在 `tools/`（lint 规则、smoke 夹具、静态对账、真机探针），计划文件 `tests/Verify/VerifyPlan.php` 只声明命令，不复制编排逻辑、不重判据。这样"清单"和"实现"不同源：清单能自动跟随新增判据，实现各自负责自己的牙齿。

## 判据要有牙齿：变异自证纪律

新增 lint 规则或判据，必须能回答"什么变异能让它报红"，答不上的不上仓。判据有没有牙由缺陷题库 `tests/Contracts/run-corpus.php --suite=v2` 统一回答：它直接读计划表生成通道集合，不会出现第二份清单；组内不再给每条判据配一个 `--selftest`，避免元判据递归。

典型的有牙判据是"接线对账 + 变异自证"成对出现：静态对账检查声明与消费一致，变异检查把声明撤掉后确实会红。只断"有像素变化"不算牙齿——形状退回整盒矩形时它照样绿，要成对断言（顶点不吃色 + 弧线吃色）。文档检查不在这套体系里，命名纪律只保留 lint 的 `project-name` 规则。

## dev 绿 ≠ AOT 绿

以下触发条件只在 T2 暴露，dev 壳全绿也照炸或静默错：

- 属性读直接当实参 → `C2440`；
- 闭包 arity 不符 → 真机 `expects exactly N arguments`，解释器忽略多传参数；
- 会被继承的类字段用 `private` → 子类读到错值；
- `$this->不存在的方法()` → AOT 编译成功、真机崩；
- `$this->可空字段?->m()` → 按运行时类名查表读，静默读空；
- 两个原生整数相除 → 截断而非 PHP 语义；
- `#[Native]` 做树节点、注解漏写反斜杠、新增类未进全量构建 → 启动即崩或注解没效果。

因此：改 `src/` 或 `cpp-src/` 必须 T2 重编译；改 `apps/<app>/src/` 虽在 dev 壳即时生效，发版前仍要 T2。解释器与 AOT 的几何逐值等价由 `aot-equivalence` 守，解释器与 dev 壳的语义逐值等价由 `dual-mode-equivalence` 守，二者共用 fingerprint 作唯一 expected。完整清单见[AOT 约束与代码纪律](../reference/aot-constraints.md)。

## CI 矩阵与全量档

- **PR / push 主分支**：`verify.yml` 跑 `php bin/drift verify --json` 日常档，`windows-2022` + setup-php 的版本矩阵，`coverage: none` / `fail-fast: true`，并有环境与版本 / 扩展断言（不信任安装结果）；不需要 MSVC / tpc。
- **手动全量**：按需触发 `verify.yml` 的手动档，跑 `--full --json`（含 feature / pages / smoke）；发版 tag 由 `release.yml` 自动跑同一档。题库 `--layer=corpus` 是独立量具，不并进报告。
- **报告断言**：跑 `verify --json` 的 workflow 都调 `.github/scripts/assert-verify-report.php <报告> [step id...]`。`verify.yml` / `release.yml` 必须给 `unit`（手动全量与发布还给 `feature`）；"PHPUnit 没装"被记成 `skip` 时，断言走独立路径读 `items[].status`，把"没测到"判红，不让整份报告仍显示通过。`windows-deps.yml` 只跑 `--layer=static` 且不传 step id，因为它的职责是产出依赖包，PHPUnit 不在其中——不装 composer 也不会把"没测到"记成通过。
- **AOT / T2**：`windows-build.yml` 在公开 `windows-2022` runner 上从源码构建 tpc / PHPX，取依赖包，全量编译 `hello` 并跑 `probe-window` 五判据；`linux-build.yml` 在 `ubuntu-24.04` 上按 PHP 8.4 / 8.5 双矩阵编 Linux ELF（工具链取 swoole 官方 TypePHP / PHPX 的钉死 commit，不用 fork），并在 Xvfb + fluxbox 下跑端到端门（外部 `xclip` owner 的剪贴板判据、XSettings/环境外观、假助手验 dialog/tray 接线、XIM 插入符定位、WM/输入/IO/解码/干净退出）。前置是 `deps-v2` 双平台资产已发布。`verify.yml` 的结构判据守着这两条通道不退化。CI 里的纯 PHP 判据不等于 AOT 真机构建通过。
- **依赖包构建**：`deps.yml` 是唯一监听 `DEPS_VERSION` 的发布编排，等 `windows-deps.yml` / `linux-deps.yml` 两岸构建成功后才组包、提交锁、打 tag、发布并双平台回验；`release.yml` 只消费 artifact，不覆盖公共 deps tag。依赖与发版流程见[依赖与发布](./dependencies-and-release.md)。

判据集合自动跟随计划表，所以新增判据不需要改 CI 白名单——这也是反对在 workflow 里手写 step id 清单的理由。报告断言里显式要求的 step id 只表达"这一项必须真的跑过"，不是判据全集。

## 真机探针与自关

起窗探针是黑盒验证：启动应用 exe，找窗口并比对标题，量客户区尺寸，抓像素确认有非白内容且颜色分布合主题，查 DPI 感知级别，最后**主动关窗并断言退出码为 0**。它不碰输入，所以在桌面锁定的非交互会话里也能跑；但那只是"能跑通"的证据，交互路径要另走真实输入的探针。

关窗不是收尾的装饰：引擎 shutdown 段曾出现"窗口关得掉、画面也对，最后一步崩"，根因是对象图被静态钩子留到 PHP shutdown 才释放。所以探针必须自己 `App.close()`、等进程退出并检查退出码，而不是只看一眼窗口出现过。关闭语义另有专用探针验四态：关前句柄有效、拒绝后窗口还在、同意后进程自己退出、退出码为 0。脚本靠 `App.close()` 的幂等语义在收尾时再关一次，不会留下僵尸进程。

### 异步链路的两层探针

`probe-window.py` 的五条只证明"窗口能起、内容非白、关窗不崩"——`submit → send → worker → reply → drain → deliver` 这条链在 AOT 上从未被它覆盖。补法是两层独立探针，不往五条里加：

| 探针 | 验什么 | 前置 | 命令 |
|---|---|---|---|
| `probe-worker.py` | AOT worker 的行协议往返：成功回包、未注册任务回 error、错误后仍活着、关 stdin 退出 0 | 只要有 exe 和管道，**不需要桌面 / 前台** | `python tools/probe-worker.py watermark` |
| `probe-async.py` | UI 提交 → worker → 回贴 → UI 解锁全链：按 UIA 进异步页，读「重试」`IsEnabled` 的禁用→恢复翻转，翻页再走一遍 | **需要前台桌面**（真点导航） | `python tools/probe-async.py` |

两道不能合成一道：`probe-worker.py` 在桌面锁定的会话里照样能跑，`probe-async.py` 必须真输入；混在一起会让窗口五条和协议五条一起变 SKIP。分开还带来失败归因——协议层红就先查 worker，只有它绿而端到端红才是 UI 侧的问题。

`probe-worker.py` 用 `CoreJobs` 自带的 `drift.fetchImage`：url 传本地临时文件时直接短路返回，零网络、零应用改动，所以任何已构建的应用都能验。`probe-async.py` 读按钮而不是读文本：本仓 `Text` 控件不进语义树（真机 dump 证实），而「重试」的 `IsEnabled` 直接来自 `AsyncCommand::isRunning()`，是任务生命周期在 UI 上的真实投影。

两者都走 `probe_check` 的三态协议：全过 0，有判据不过 1，前置不成立（没 exe / 抢不到前台）2。`2` 是"没测到"不是"没通过"，别当绿灯看。验收前可反向变异：把 `PING_JOB` 换成未注册名、或把 `RETRY` 换成恒启用的按钮名，两条探针都必须变红——不变红说明判据是空话。

## 相关

- [命令速查](../reference/cli.md)
- [协作流程](../contributing/workflow.md)
- [环境搭建](../contributing/setup.md)
- [诊断与错误码](../reference/diagnostics.md)
