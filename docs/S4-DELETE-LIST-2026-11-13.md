# S4 删除清单（路线 A · 执行账本）

> 状态：**路线 A 已确认；缺口已补，删除 / 回写与删后验收见 §5、§6**。
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
| **V2 运行时闭包** | **270** | `tools/Lint/`（含新增命名规则）、`tools/Smoke/`（130）、`tools/app-workers/`、`tools/autoload.php`、`tools/fakes/`、`tools/Support/CheckReport.php` + `CheckItem.php`、V2 step 指向的 12 个对账 / device 脚本 | **必须留** |
| 生产 / 发布 / 资产生成 | 34 | `drift-build.php`、`version.php`、`Doctor/`、`Preview/`、`skia/` | 必须留 |
| 真机探针（T2 唯一通道） | 17 | `probe-*.py`、`probe-grab.cc` | 必须留 |
| 入口适配器 | 1 | `tools/phpunit-run.php`（`bin/drift test` 仍指向） | 必须留 |
| **实际可删** | **47** | 见 §4 | 路线 A |
| 合计 | **369** | | |

**为什么那 270 不能删**（这不是编排层，是判据的实现体与样本）：

- `tools/Lint/` = 33 条规则 + fixtures + 支撑类 + `selftest.php`（`lint.php:38` require），
  是 `static/lint` step 的实现体，也是 `lint --selftest` 唯一双向自证。
- `tools/Smoke/` 130 = 78 个夹具应用 + 状态 / 布局 / 帧 / 输入探针。
  `tests/Unit/*` 与 `tests/Feature/*` 里 **54 个文件** `use Drift\Dev\Smoke\App\*` 与
  `Smoke\Frame\SurfaceProbe`；`tests/Support/bootstrap.php` / `fatal_cases.php` 还要
  `require tools/autoload.php` + `tools/fakes/*`。
- `tests/Verify/ir-snapshot.php` 依赖 `tools/Bench/FrameTaxApp.php` + `PaintFingerprint.php`
  + `Smoke/Frame/SurfaceProbe.php`；`Inspect\Snapshot` 依赖同名目录下的
  `Inspect\RecordingSurface`（§2.4）。

⇒ **照「292 全删」执行，S3 刚拿到的 21/21 会瞬间塌掉**。S4 的真实减量是 47 个（约 13%），
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

### 2.4 清单自查抓到的三处误列（本轮新增）

| 文件 | 原判 | 真实依赖 |
|---|---|---|
| `tools/selftest.php` | 顶层 V1 元判据 | `tools/lint.php:38` `require __DIR__ . '/selftest.php'` —— lint 双向自证实现体 |
| `tools/Support/CheckItem.php` | V1 判据清单连带删 | `CheckReport::json()`、`golden-raster.php`（6 处 `new`）、`smoke.php` 硬依赖 |
| `tools/Inspect/RecordingSurface.php` | 「零消费者」 | `tools/Inspect/Snapshot.php:88` 同 namespace `new RecordingSurface()`；原扫描把它与 `Smoke\Frame\RecordingSurface` 同名混淆 |

⇒ 照原 62 清单删，`lint --selftest`、`golden-raster`、`smoke`、`Inspect\Snapshot` 当场断，
而 S3 的题库矩阵**不会报红**（没有对应缺陷项）—— 正是本项目反复吃亏的「删了不报错」。
加上 §2.2 提升为 V2 step 的 12 个文件，可删集 **62 → 47**。

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

## 4. 精确删除集（47 个文件）

### `tools/` 顶层 23
```
aot-equivalence.php          aot-micro-compare.php      aot-micro-probe.php
api-doc-check.php            bench-run.php              bench-taskhost-rtt.php
docref.php                   dual-mode-equivalence.php  dual-mode-fingerprint-entry.php
fingerprint.php              frame-paint-probe.php      frame-tax-probe.php
gate.php                     group-run.php              inspect.php
method-weight.php            narrow-audit.php           press-sequence.php
probe-async.php              probe-audit.php            probe-frame-gates.php
probe-material.php           probe-real.php
```
> 顶层原 31 项中，8 个已改判保留（`selftest.php`、5 个 `*-check.php`、
> `rva2sym.php`、`M2Conformance.php`），见 §2.4 与 §2.2。
> `probe-*.php` 是 V1 的 PHP 侧元判据 / 调试脚本，**不是**真机探针；探针只有
> `.py` / `.cc`，已按此分开。

### `tools/Bench/` 11
```
AotEquivalence.php  AotMicro.php  Fingerprint.php  FrameTaxMeasure.php
GeomFingerprint.php Micro/MicroNode.php  Micro/MicroOther.php
Micro/MicroShape.php  Micro/MicroSize.php  PageFrame.php  Samples.php
```
保留 `Bench/ShowcasePages.php`（`sceneset`）、`FrameTaxApp.php` + `PaintFingerprint.php`（`ir`）。

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

### 从原 62 清单改判保留的 15 个
`tools/selftest.php`、`tools/Support/CheckItem.php`、`tools/Inspect/RecordingSurface.php`、
`tools/abi-check.php`、`tools/cpp-bridge-check.php`、`tools/diagnostic-check.php`、
`tools/build-stale-check.php`、`tools/error-shape-check.php`、`tools/rva2sym.php`、
`tools/M2Conformance.php`、`tools/decode-selftest/` 整目录 5 个。

---

## 5. 回写清单（删文件只是 S4 的一半）

| 位置 | 现状 | 改成 |
|---|---|---|
| `.github/workflows/ci.yml` | `gate.php --fast --json` / `--json` + `assert-gate-report.php` | `bin/drift verify --json`（matrix）/ `--full --json`（nightly）+ `assert-verify-report.php`，断言 `unit` / `unit feature` |
| `.github/workflows/release.yml` | `version.php --check` + `gate --fast --json` + 断言 | `bin/drift verify --full --json` + 断言 `unit feature` |
| `.github/workflows/deps-build.yml` | `gate --fast --json` + 断言 | `bin/drift verify --json` + 断言；verify 把缺依赖记成 `exitCode=127 / ok=false`，天然判红 |
| `bin/drift` | `check` / `list` / `tooling` 指向 `gate.php` / `group-run.php` | 旧脚本名作别名转发 V2 runner；`test` / `lint` / `dev` / `build` / `probe` 不动 |
| `composer.json` | `check` / `check:full` / `checks` / `tooling` | 重定向到 `verify` / `verify --full` / `verify --list` / `verify --layer=static` |
| `tests/Contracts/run-corpus.php` | V1 通道含 `docref` / `apidoc` | **删前显式退役**这两条通道；否则脚本不存在会被题库当成「缺陷被抓到」，构成删后假绿 |
| `docs/` | 60+ 文件引用 `php tools/<旧命令>` | 本轮不逐份订正，随文档整体重构统一处理 |
| 设计稿 §2 / §4 | 「292」与「`tools/` 判据层零依赖」 | 改成 §1 的实测口径，登记 S2 采用「重编排不重判据」 |

**硬红线**：删判据不能让覆盖率矩阵变绿；任何「删完更绿」都按失败处理。
`--defect` / `--probe` / `--shard` 裁剪结果一律不能当验收报告（`exit 2` = 没测到）。

---

## 6. 删后验收

- **before**：`tests/Contracts/baseline/v2-full.json`（`3c194e2`），
  `ok=true`、`graded=21`、`covered=21`、`blind=[]`、`regress=[]`、`gain=[I1,I2,I3,I4]`。
- 删后重跑 `--suite=v2 --tier=full --json`，要求 `ok=true`、`graded=21`、
  `covered=21`、`blind=[]`、`regress=[]`；报告入库作 after。
- 再跑 `php bin/drift verify --full` 与 `php tools/lint.php --selftest`。
- CI 三份 workflow 在未 push 的提交里从未实跑，push 后要盯首轮。
- AOT / 真机 T2 不在本轮解释器侧验收范围内，属发版前复核。
