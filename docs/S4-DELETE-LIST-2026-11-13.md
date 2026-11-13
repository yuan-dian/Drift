# S4 删除清单（路线 A · 执行账本）

> 状态：**路线 A 已执行完；删除 / 回写见 §5，删后验收（含 after 报告）见 §6**。
> 删前基准：`HEAD = 3c194e2`，`main` 领先 `origin/main` 14 笔，无 tag。
> 事实源：`git ls-files tools` = 369 个被跟踪文件。分类由 `.workbuddy-ai/tmp/s4-classify.php`
> 生成、依赖复核由 `.workbuddy-ai/tmp/s4-deps.php` 完成（两者都不进 git）。
> 本文是 S4 的执行账本，随文档整体重构退役。

---

## 1. 先纠正一个数字：设计稿的「292」已不成立

`docs/DESIGN-VERIFIER-CONVERGENCE-2026-11-13.md` §2 的粗分是
「判据层 292 / 生产链 11 / 共享与探针 57」，§4 把 S2 的硬验收线写成
「`tools/` 判据层零依赖」。**S2 实际落地时采用的是「重编排不重判据」**——
V2 只换了 runner 与清单，判据实现仍指向 `tools/`。实测：

| 类别 | 文件数 | 代表路径 | 处置 |
|---|---:|---|---|
| **V2 运行时闭包** | **285** | `tools/Lint/`（含新增命名规则）、`tools/Smoke/`（130）、`tools/app-workers/`、`tools/autoload.php`、`tools/fakes/`、`tools/Support/CheckReport.php` + `CheckItem.php`、V2 step 指向的 12 个对账 / device 脚本 | **必须留** |
| 生产 / 发布 / 资产生成 | 34 | `drift-build.php`、`version.php`、`Doctor/`、`Preview/`、`skia/` | 必须留 |
| 真机探针（T2 唯一通道） | 17 | `probe-*.py`、`probe-grab.cc` | 必须留 |
| 入口适配器 | 1 | `tools/phpunit-run.php`（`bin/drift test` 仍指向） | 必须留 |
| **实际可删** | **32** | 见 §4 | 路线 A |
| 合计 | **369** | | |

**为什么那 285 不能删**（这不是编排层，是判据的实现体与样本）：

- `tools/Lint/` = 33 条规则 + fixtures + 支撑类 + `selftest.php`（`lint.php:38` require），
  是 `static/lint` step 的实现体，也是 `lint --selftest` 唯一双向自证。
- `tools/Smoke/` 130 = 78 个夹具应用 + 状态 / 布局 / 帧 / 输入探针。
  `tests/Unit/*` 与 `tests/Feature/*` 里 **54 个文件** `use Drift\Dev\Smoke\App\*` 与
  `Smoke\Frame\SurfaceProbe`；`tests/Support/bootstrap.php` / `fatal_cases.php` 还要
  `require tools/autoload.php` + `tools/fakes/*`。
- `tests/Verify/ir-snapshot.php` 依赖 `tools/Bench/FrameTaxApp.php` + `PaintFingerprint.php`
  + `Smoke/Frame/SurfaceProbe.php`；`Inspect\Snapshot` 依赖同名目录下的
  `Inspect\RecordingSurface`（§2.4）。
- T2 逐值等价的 4 个入口（`aot-equivalence.php` / `fingerprint.php` /
  `dual-mode-equivalence.php` / `dual-mode-fingerprint-entry.php`）不是编排层：
  实现体 `Bench/AotEquivalence.php` + `Bench/Fingerprint.php` 本轮已保留，
  `probes/frame_tax_aot/build.sh` 还会**真的执行** `aot-equivalence.php`（§2.4 第五处）。

⇒ **照「292 全删」执行，S3 刚拿到的 21/21 会瞬间塌掉**。S4 的真实减量是 32 个（约 9%），
不是 292 个（79%）。要再大幅减量，只能「重判据」——把 Lint 规则与 Smoke 夹具整体迁进
`tests/` 并重写，这与已裁决的「可测性 API 是门面不是替代品 / 重编排不重判据」冲突，本轮不做。

---

## 2. 路线 A 的三处缺口已补齐

### 2.1 `project-name` 命名纪律（阻断项）—— 已落地

S2-1 承诺「命名纪律并入 `lint` 保留」，但 `tools/Lint/` 里零命中，唯一实现仍在待删的
V1 文档检查层。本轮把判据逐字迁入 `tools/Lint/Rules/ProjectName.php`，
`Linter::defaultRules()` 32 → **33** 条，`tools/selftest.php` 补期望表，
新增 `ProjectNameBad.md` / `ProjectNameGood.md` 两份夹具。
实测 `php tools/lint.php` = 1090 个文件 · 0 error · 0 warn；
`php tools/lint.php --selftest` 的 `project-name` = **该响 3 / 不该响 0**。

### 2.2 V1 判据 → V2 step 的差集 —— 已落地

`tests/Verify/VerifyPlan.php` 新增 8 个 `static / fast` step：
`abi` `cppbridge` `dxdiag` `stalepolicy` `errshape` `version` `genversion` `devshell`；
新增 3 个 `device / manual` step：`sym` `decode` `m2`。
`php bin/drift verify` 日常档实测 13 项全过。

组内 `--selftest`（V1 的 6 对变异自证）**按设计稿 §5 显式退役**：V2 判据可信度只由
缺陷题库回答，每条判据再配一个自证就是元判据递归。注意这 12 个脚本本身**一行未改、
全部保留**，退役的是「组内自证子项」这一层编排，不是静态对账本身。

### 2.3 依赖链复核

- `tools/Support/CheckReport.php` + `CheckItem.php` 必须保留：`golden-raster.php`、
  `smoke.php`、`.github/scripts/*` 断言、`tests/Feature/Tools/CheckReportTest.php`、
  `tests/Unit/Support/CheckReportUtf8Test.php` 都在用。
- `tools/Support/CheckRegistry.php` 只被 `gate.php` / `group-run.php` 消费，两者同批删 ⇒ 可删。
- `tools/doctor.php --scope=gate` 只是字符串参数，不读 `CheckRegistry` ⇒ 保留。
- `tools/_archive/`、`frame-tax-probe.php`、`probe-audit.php` 等在保留代码里的命中
  全部是注释（历史说明），不是 `require` ⇒ 可删；悬空引用随文档整体重构处理。

### 2.4 清单自查抓到的五处误列（本轮新增）

| 文件 | 原判 | 真实依赖 |
|---|---|---|
| `tools/selftest.php` | 顶层 V1 元判据 | `tools/lint.php:38` `require __DIR__ . '/selftest.php'` —— lint 双向自证实现体 |
| `tools/Support/CheckItem.php` | V1 判据清单连带删 | `CheckReport::json()`、`golden-raster.php`（6 处 `new`）、`smoke.php` 硬依赖 |
| `tools/Inspect/RecordingSurface.php` | 「零消费者」 | `tools/Inspect/Snapshot.php:88` 同 namespace `new RecordingSurface()`；原扫描把它与 `Smoke\Frame\RecordingSurface` 同名混淆 |
| `tools/Bench/` 11 个 | 「一次性基准」 | `tests/Unit/Support/SamplesTest.php` 单测 `Samples`；`probes/aot_micro` 与 `probes/frame_tax_aot` 两个 tracked AOT 探针工程引用 `AotMicro` / `Micro\*` / `Fingerprint` / `FrameTaxMeasure` / `Samples`；`FrameTaxApp` + `PaintFingerprint` 还是 V2 `ir` 通道的实现体 |
| T2 逐值等价 4 个入口 | 「一次性基准」 | `probes/frame_tax_aot/build.sh` 构建成功后立刻执行 `aot-equivalence.php --exe=...`（2.5s，仅 `SKIP_EQUIV=1` 可跳）；`docs/AOT-CONSTRAINTS.md` 与 `DESIGN.md` 仍把它当改 AOT 代码后的手动判据。四个入口共用已保留的 `Bench/Fingerprint.php`，删入口 = 实现还在但够不着 |

⇒ 照原 62 清单删，`lint --selftest`、`golden-raster`、`smoke`、`Inspect\Snapshot` 当场断，
而 S3 的题库矩阵**不会报红**（没有对应缺陷项）—— 正是本项目反复吃亏的「删了不报错」。

第四处是**删后复核**才抓到的：先按 47 个清单物理删除，再用「被删类名的裸引用」扫一遍
全部 tracked PHP，发现 `SamplesTest` 与两个 AOT 探针工程会断；实测 `verify --step=unit`
在恢复前必然红。整目录恢复后 621 tests / 1929 assertions 全绿。
⇒ 加上 §2.2 提升为 V2 step 的 12 个文件，可删集 **62 → 47 → 36 → 32**。

第五处是**扫可执行调用**才抓到的：裸类名扫描只覆盖 PHP 的 `new` / `use`，盖不住
`build.sh` 里的命令行调用、量具通道表里的 `cmd`、以及现行文档里让人照抄的命令。
整组 4 个入口恢复后，`probes/frame_tax_aot/build.sh` 不再断链。

另有一处**编辑事故**：`20c91cf` 本该把 `run-corpus.php` 的 `docref` / `apidoc`
两条 V1 通道摘掉，结果一次性编辑脚本的片段被写进文件头 docblock，预期编辑
**根本没生效**。删掉脚本后 `--suite=v1` 会把「脚本不存在 → 非 0」当成「缺陷被抓到」，
恰好是 §5 自己列的假绿红线。本轮修掉头注并真正摘除两条通道。

第一笔提交（`20c91cf`）里的「47」是删前推断，此处是删后实测，不回改历史提交信息。

> 另有两处同名假命中已在删后复核里排除：`Doctor\Check`（`tools/doctor.php`）不是
> `DocRef\Check`；`Lint\Rules\ProjectName`（迁入的新规则）不是 `DocRef\Checks\ProjectName`。

---

## 3. V1 21 项判据逐条退役登记（不许悄悄删）

题库的 `regress=[]` 只证明「21 条 live 缺陷 V2 覆盖 ≥ V1」，**不构成**
「V1 gate 21 项判据所守的全部缺陷 V2 都还守得住」的证明。因此逐条裁决：

| V1 项 | 裁决 | V2 落点 / 退役原因 |
|---|---|---|
| `doctor` | 退役（编排项） | 环境诊断不是判据；`tools/doctor.php` 保留，CI 可继续单独跑 |
| `version` | 保留 | `static / version` |
| `genversion` | 保留 | `static / genversion` |
| `lint` | 保留 | `static / lint`，33 规则（含迁入的 `project-name`） |
| `selftest` | 退役（元判据） | `lint.php --selftest` 仍在，但不是 V2 step；可信度由题库回答 |
| `tooling` | 拆分保留 | 5 对主判据 → `abi` `cppbridge` `dxdiag` `stalepolicy` `errshape`；6 对组内自证按 §2.2 退役 |
| `devshell` | 保留 | `static / devshell` |
| `docref` | **显式退役** | 文档体系整体重构；题库 `R1/R2/R3/L6` 已标 `retired` 且从 `v1-postdocs` 基线派生 |
| `apidoc` | **显式退役** | 同上；`docs/API-SURFACE.md` 同批删 |
| `probe` | **显式退役（已知缺口）** | `probe-audit.php --selftest` 是探针骨架元判据；真机 T2 由 `bin/drift probe` + `probe-*.py` 承担。题库无对应缺陷项 |
| `pixels` | **显式退役（已知缺口）** | `probe_pixels.py --selftest` 是探针自证；像素正确性由 `behavior / pages` 与真机 `probe_pixels.py` 承担 |
| `tick` | **显式退役（已知缺口）** | 每帧固定税是性能尺子，不是正确性判据；`frame-tax-probe.php` 删除 |
| `scale` | **显式退役（已知缺口）** | 12k 节点尺子同上；绝对耗时随机器抖动，本就不该做红线 |
| `sym` | 保留 | `device / manual / sym` |
| `decode` | 保留 | `device / manual / decode` |
| `m2` | 保留 | `device / manual / m2` |
| `unit` | 保留 | `behavior / unit` |
| `feature` | 保留 | `behavior / feature` |
| `golden` | **显式退役（已知缺口）** | `--canvas` 原语级像素基线；`--pages` 整页基线保留（`behavior / pages`），原语级是重复判据 |
| `pages` | 保留 | `behavior / pages` |
| `smoke` | 保留 | `behavior / smoke` |

**已知缺口口径**：`probe` / `pixels` / `tick` / `scale` / `golden` 五项在题库里
没有对应缺陷，删掉不会让矩阵变红，也不会让它变绿——这是**已登记的能力缺口**，
不是「覆盖率没掉所以没问题」。承接方式：真机侧并入 T2 探针复核，性能尺子不进闸门。

---

## 4. 精确删除集（32 个文件）

### `tools/` 顶层 19
```
aot-micro-compare.php        aot-micro-probe.php        api-doc-check.php
bench-run.php               bench-taskhost-rtt.php      docref.php
frame-paint-probe.php       frame-tax-probe.php         gate.php
group-run.php               inspect.php                 method-weight.php
narrow-audit.php            press-sequence.php          probe-async.php
probe-audit.php             probe-frame-gates.php       probe-material.php
probe-real.php
```
> 顶层原 31 项中，12 个已改判保留（`selftest.php`、5 个 `*-check.php`、
> `rva2sym.php`、`M2Conformance.php`，以及 T2 逐值等价的 4 个入口），见 §2.4 与 §2.2。
> `probe-*.php` 是 V1 的 PHP 侧元判据 / 调试脚本，**不是**真机探针；探针只有
> `.py` / `.cc`，已按此分开。

### `tools/Bench/` 0 —— 整目录保留

删后复核判定该目录是三处共同依赖闭包，原「一次性基准」的分类不成立：
`tests/Unit/Support/SamplesTest.php` 单测 `Samples`；`probes/aot_micro` 与
`probes/frame_tax_aot` 两个 tracked AOT 探针工程引用 `AotMicro` / `Micro\*` /
`Fingerprint` / `FrameTaxMeasure` / `Samples`；`FrameTaxApp` + `PaintFingerprint`
还是 V2 `ir` 通道的实现体。删掉会让 `unit` 当场红，并让两个探针工程断链。

### T2 逐值等价 4 个入口 —— 整组保留
```
aot-equivalence.php             dual-mode-equivalence.php
dual-mode-fingerprint-entry.php fingerprint.php
```
`probes/frame_tax_aot/build.sh` 构建成功后立刻执行 `aot-equivalence.php --exe=...`；
`docs/AOT-CONSTRAINTS.md` 把它列为改 AOT 代码后的手动判据。实现体
`Bench/AotEquivalence.php` + `Bench/Fingerprint.php` 本轮已保留，删掉入口只会让
能力够不着，不会减少维护面。

### `tools/DocRef/` 6 — 文档检查显式退役
```
Check.php  DocSet.php  Checks/FileLineRefs.php  Checks/MarkdownLinks.php
Checks/ProjectName.php  Checks/SectionRefs.php
```
前置条件 §2.1 已满足。

### `tools/Support/` 1
```
CheckRegistry.php
```
（`CheckItem.php` / `CheckReport.php` 保留，见 §2.4。）

### 归档与一次性自证 6
```
tools/_archive/map-size.py          tools/_archive/method-map.php
tools/_archive/pe-sym.py            tools/_archive/probe-raster-page.php
tools/_archive/reach.py             tools/crash-selftest/selftest.cc
```

### 同时退役（不在 `tools/` 下）
- `docs/API-SURFACE.md`（`apidoc` 的产物，抬头写「自动生成、勿手改」但已漂）。
- `.github/scripts/assert-gate-report.php` → 换成 `assert-verify-report.php`（读 verify 报告）。

### 从原 62 清单改判保留的 30 个
`tools/selftest.php`、`tools/Support/CheckItem.php`、`tools/Inspect/RecordingSurface.php`、
`tools/abi-check.php`、`tools/cpp-bridge-check.php`、`tools/diagnostic-check.php`、
`tools/build-stale-check.php`、`tools/error-shape-check.php`、`tools/rva2sym.php`、
`tools/M2Conformance.php`、`tools/decode-selftest/` 整目录 5 个、
`tools/Bench/` 整目录 11 个（§2.4 第四处）、T2 逐值等价 4 个入口（§2.4 第五处）。

⇒ `tools/` 下实删 32 个；连同 `docs/API-SURFACE.md` 与
`.github/scripts/assert-gate-report.php`，**物理删除共 34 个文件**。

---

## 5. 回写清单（删文件只是 S4 的一半）

| 位置 | 现状 | 改成 |
|---|---|---|
| `.github/workflows/ci.yml` | `gate.php --fast --json` / `--json` + `assert-gate-report.php` | `bin/drift verify --json`（matrix）/ `--full --json`（nightly）+ `assert-verify-report.php`，断言 `unit` / `unit feature` |
| `.github/workflows/release.yml` | `version.php --check` + `gate --fast --json` + 断言 | `bin/drift verify --full --json` + 断言 `unit feature` |
| `.github/workflows/deps-build.yml` | `gate --fast --json` + 断言 | `bin/drift verify --layer=static --json` + 断言（只验报告本身，不传 step id）；零依赖静态层不启动 PHPUnit，自托管 runner 没 composer 也不会卡住或假绿 |
| `bin/drift` | `check` / `list` / `tooling` 指向 `gate.php` / `group-run.php` | 旧脚本名作别名转发 V2 runner；`test` / `lint` / `dev` / `build` / `probe` 不动 |
| `composer.json` | `check` / `check:full` / `checks` / `tooling` | 重定向到 `verify` / `verify --full` / `verify --list` / `verify --layer=static` |
| `tests/Contracts/run-corpus.php` | V1 通道含 `docref` / `apidoc` | **删前显式退役**这两条通道；否则脚本不存在会被题库当成「缺陷被抓到」，构成删后假绿。★ `20c91cf` 的退役编辑其实没生效（脚本片段被写进头注），本轮补做 |
| `docs/` + 根 `README.md` / `CHANGELOG.md` / `AGENTS.md` | 活入口文档里的死命令 | **本轮回写**：`CONTRIBUTING.md` / `00-README.md` / `architecture/12` / 根 README / AGENTS / CHANGELOG；历史设计稿（`DEBT`、`ASYNC-*`、`_archive/*`）随文档整体重构统一处理 |
| 设计稿 §2 / §4 | 「292」与「`tools/` 判据层零依赖」 | 改成 §1 的实测口径，登记 S2 采用「重编排不重判据」 |

**硬红线**：删判据不能让覆盖率矩阵变绿；任何「删完更绿」都按失败处理。
`--defect` / `--probe` / `--shard` 裁剪结果一律不能当验收报告（`exit 2` = 没测到）。

---

## 6. 删后验收（已完成）

后台三段连跑（37m56s）：

| 检查 | 命令 | 结果 |
|---|---|---|
| V2 全量 runner | `php bin/drift verify --full --json` | `VERIFY_EXIT=0`；16 项全 `ok`，`partial=false` |
| CI 报告断言 | `php .github/scripts/assert-verify-report.php <report> unit feature pages smoke` | `ASSERT_EXIT=0` |
| 题库全量矩阵 | `php tests/Contracts/run-corpus.php --suite=v2 --tier=full --json` | `CORPUS_EXIT=0`，`ok=true` |

**after 报告已入库**：`tests/Contracts/baseline/v2-post-s4-full.json`
（加 `note`，`compare` 归一为仓库相对路径，其余字段逐字节来自实测 JSON）。
与 before `v2-full.json`（`3c194e2`）逐条对比：

- `graded=21`、`covered=21`、`blind=[]`、`regress=[]`、`gain=[I1,I2,I3,I4]` 完全一致；
- 28 行里 **27 行 `caught` 逐字相同**，唯一差异是退役项 `R3` 新增 `lint:1`
  —— 正是 §2.1 把 `project-name` 迁进 `Lint` 后由 `lint` 兜住的命名缺陷；
  **退役 ≠ 失去检测**，且它不计入 graded 分母；
- 8 个新增静态对账通道（`abi` / `cppbridge` / `dxdiag` / `stalepolicy` /
  `errshape` / `version` / `genversion` / `devshell`）在空对照 `D0` 全绿、
  28 行里零命中 ⇒ 没有「脚本不存在 → 返回非 0 → 判成抓到」的删后假绿；
- 退出码分布仍含 `1 / 2 / 3 / 255`，与 before 一致，runner 没有归一化退出码。

**`compare` 的可移植性修复**：`run-corpus.php` 原先直接把
`__DIR__ . '/baseline/…'`（绝对路径）写进 JSON，换台机器就带着本机目录名、
无法逐字节比对；现输出仓库相对路径。本轮入库快照用同一规则归一，
字段之外的矩阵内容不改。裁剪矩阵按设计在输出 JSON 前就 `exit 2`，
当时用「从 runner 源码抽出该闭包、喂 after 原始绝对路径」自证，输出与入库值一致。

**端到端实证：裁决为「不必补跑」（21:30 收口）**：曾想把 `corpus` 挂进
`verify --full` 的报告断言，核对后发现前提不成立 —— `VerifyPlan::LAYERS`
只有 `static / behavior / device`，`--full` 的 `items` 里**没有 corpus**；
题库对照是 `--layer=corpus` 的独立量具，S3 的两次 `CORPUS_EXIT=0` 都是
单独跑出来的。把 corpus 列进必需项只会让全量档判红，已撤销。
`compare` 是纯字符串变换（去掉 `$root . '/'` 前缀），源码闭包自证 + 两份
入库快照归一一致已足以定型；`--dry-run` 不输出 JSON、裁剪矩阵在对照阶段
`exit 2` ⇒ 没有秒级端到端通道。要实证只需手动跑一次
`php bin/drift verify --layer=corpus --json`，看输出里的 `compare` 字段。
本轮不为它再花 ~38min，也不为它造新入口。
⇒ 可复用判据：**「报告里有这个 step」不能凭 `--list` 的展示行推断**，
`--list` 的 `[corpus]` 是硬编码的说明行，真正的 step 集合只有 `steps()`。

**同轮 CI 简化**：去掉 `on.schedule`（不日构建），`gate-nightly` →
`gate-full`，只由 `workflow_dispatch` 手动触发；提交构建（PHP 8.4/8.5
matrix）仍是主闸门，发版 `v*` tag 由 `release.yml` 跑 `--full --json`。
`feature / pages / smoke / corpus` 属 full tier，提交构建不跑它们；
需要时手动触发 gate-full，题库仍用 `--layer=corpus`。

其余删后快验：`php tools/lint.php` = 1049 文件 / 0 error / 0 warn；
`php tools/lint.php --selftest` 里 `project-name` 该响 3 / 不该响 0；
`verify --step=unit` = 621 tests / 1929 assertions。

**仍未覆盖**：CI 三份 workflow 在尚未 push 的提交里从未实跑，push 后要盯首轮；
AOT / 真机 T2 不在本轮解释器侧验收范围，属发版前复核。
