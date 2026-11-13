# 验证体系 V2：全删重建裁决（2026-11-13）

> 本文替代同日上午的「收敛」方案。用户裁决：**不再一轮轮修工具，全部删除，按标准验证流程重新设计重写**。
> 本文只加一个前置条件：**删之前先建缺陷题库**。理由不是保守，是逻辑 —— 删完 gate 必然全绿，没有对照组就无法证明新体系不比旧的差。

## 0. 决断

**同意全删重建。** 成熟 GUI 框架没有这么复杂，是因为它们没有 Drift 长出来的这一层：

- `tooling` 组 12 条里 **6 条是「判据的自证」**（`abiself` / `cppbridgeself` / `dxself` / `staleself` / `errself` / `scenesetself`），另有 `selftest`（lint 自检）、`probe`（探针骨架审计）、`sym` / `decode` / `m2` 的 `--selftest`。这是一层**元判据递归**：历史上出过「判据静默失效」，于是给每条判据再配一条判据。
- 6 份静态对账脚本 1,370 行，本质是用 PHP 文本扫描**模拟编译期保证**（tpc/AOT 没有静态分析器）。成熟框架的 analyzer / 编译器替它们做了这件事。
- 21 项判据无差别全跑，改一行 `src/` 也等 150.7s；`--fast` 只是把 3 项标 slow，没有真正的分层与增量。

**标准流程的答案不是「给每条判据配自证」，是「一个 mutation testing 运行器统一回答判据有没有牙」。** 这就是 V2 的核心：把 N 条元判据压成 1 份题库 + 1 个量具。

## 1. 删之前先有的那一件东西（本轮已落地）

`tests/Contracts/DefectCorpus.php`（纯数据）+ `tests/Contracts/run-corpus.php`（量具），**2 个文件，放在 `tests/` 不在 `tools/`**。每条缺陷是历史上真实修过的事故 + 把它复原到工作树的最小操作；判据不写进题库，由量具对每套体系跑覆盖率矩阵来比较。注入一律 `try/finally` 还原，锚点不唯一直接抛（不静默测错东西）。

首个 4 条缺陷 × 4 条 V1 判据的实测矩阵（`php tests/Contracts/run-corpus.php`）：

```
缺陷   sceneset     pages        theme        sceneself  抓到
D0      ·           ·           ·           ·         0   ← 空对照，全绿 ✓
D1      红          红          ·           ·         2   ← S0 删页静默全绿
D2      红          红          ·           ·         2   ← S1 孤儿基线
D3      ·           ·           红          ·         1   ← §43 语义 token 零消费者
D4      红          红          ·           ·         2   ← S0 冻结清单漏登记

覆盖 4/4；残留备份 0；注入后 git diff 为空；lint 1085 文件 0 error 0 warn
```

三个直接结论：

1. **`sceneself`（元判据）0/4**。它保障的是「判据自身的可信度」，不是框架行为 —— 这层可以由题库一次性验收，不需要常驻 gate。
2. **`sceneset` 与 `pages` 覆盖完全重合**（都抓 D1/D2/D4），4 条 probe 里 2 条就够。判据数量与缺陷覆盖不成正比，这是「全删」成立的实证。
3. `theme`（一条 1.2s 的 PHPUnit 文件）独立抓到 D3 —— **行为判据才是不可替代的**，静态对账不是。

## 2. 删除边界：`tools/` 不能整体删

粗分（`tools/` 下 `*.php` + `*.py`）：

| 层 | 文件数 | 处置 |
|---|---|---|
| 判据/验证层（`Lint/` `Smoke/` `DocRef/` `Golden/` `Bench/` `fakes/` + 顶层 `*-check.php` / `gate.php` / `smoke.php` …） | **292** | **全删重建** |
| 构建/发布/运行层（`drift-build.php` `pack-deps.php` `fetch-deps.php` `deps-common.php` `gen-version.php` `version.php` `doctor.php` `drift-dev.php` `drift-preview.php` `build-grab.php` `release-deps.php`） | 11 | **保留**，这是生产链不是验证工具 |
| 共享/真机探针/未定（`Support/` `Preview/` `Doctor/` `app-workers/` 13 个 `probe-*.py` …） | 57 | 逐条裁决，真机探针保留 |

⇒ 「全部删除」= 删 292 个判据层文件，**不是 `rm -rf tools/`**。`bin/drift`、`composer.json` 的入口面保留并重定向到 V2。

## 3. V2 目标形态（标准验证流程）

参考 Flutter（一个 `flutter test` + `dev/bots` 分片 + golden 独立服务）、Chromium（`PRESUBMIT.py` 的 `AffectedFiles()` + gtest）、Qt（`tests/auto/<module>` 每模块一个 target）的共同点：**一个运行器、语言级静态检查、少量真实端到端、CI 分片**；没有元判据递归。

```
bin/drift verify                     ← 唯一入口（复用已有 CLI，不新增顶层脚本）
 ├─ static    lint（保留 32 规则；编译器没有的能力）
 ├─ behavior  PHPUnit(unit+feature) + golden + smoke，按目录分片，可增量选跑
 ├─ device    AOT 构建 + 少量端到端探针（T2 / 发版，人工触发）
 └─ corpus    缺陷题库验收（一次性，取代 12 条元判据）
```

判据可信度由 `run-corpus.php` 统一回答「每个缺陷至少有一条判据变红」，**不再给每条判据配一个 `--selftest`**。V1 的 6 对自证、`gate --list` 里的元判据项整体消失。

速度 SLA：`verify` 日常 ≤ 15s、`verify --full` ≤ 150s、CI 只认 `--full`；本地 `--affected` 默认关闭且不作为验收线（动态删判据 = 静默变绿，这条红线不因重建而放宽）。

## 4. 四步日程与硬验收线

| 步 | 交付 | 硬验收线 |
|---|---|---|
| **S1 扩题库**（本轮起步） | 从 `DEBT` 77 章 / `PITFALLS` 240 章 / 5 个 `_build/mutate-*.php` 提炼 **20–30 条可注入缺陷**，跑出 V1 覆盖率矩阵 | 每条缺陷都能机械注入、D0 恒绿、注入后 `git diff` 为空；**AOT/真机类缺陷标 `aot-only`**（解释器侧本来就抓不到，这是已知缺口不是假绿） |
| **S2 建 V2** | 一个 runner + 三层 + 分片；**从行为需求重写，不复制任何现有判据脚本** | `bin/drift verify` 能跑通；`tools/` 判据层零依赖 |
| **S3 对照验收** | 同一份题库分别跑 v1 / v2 矩阵 | **V2 覆盖率 ≥ V1**；差额逐条裁决（补行为判据 or 登记为已知缺口） |
| **S4 一次性删** | 删 292 个判据层文件 + 回写入口/文档/CI | 删前删后题库对照报告入库；`verify --full` 全绿；AOT + 真机 T2 复核 |

**为什么必须按这个顺序**：没有 S1 的题库，S4 之后的「全绿」毫无意义 —— 这正是本项目反复吃过的亏（`删判据会让 gate 更绿`）。题库是唯一能让「全删」变成可验收工程动作的东西。

## 5. 判死（重建也不做）

- ❌ 直接 `rm -rf tools/`：会删掉 11 个构建/发布脚本，构建链断。
- ❌ 把 V1 脚本改个名搬进 V2：换汤不换药，元判据递归会原样长回来。
- ❌ 给 V2 的每条判据再配 `--selftest`：题库是唯一可信度入口。
- ❌ `--affected` 设默认、或让裁剪结果能当验收报告。
- ❌ 把零依赖静态对账搬进 PHPUnit（缺 `vendor/bin/phpunit` 时 exit 2 静默消失）。

## 6. 本轮产出

- 新增 `tests/Contracts/DefectCorpus.php`、`tests/Contracts/run-corpus.php`（2 文件 / LF / `php -l` 通过）。
- 实测矩阵 4/4 覆盖、D0 全绿、还原零残留、`lint 1085 文件 0 error 0 warn`。
- S2–S4 路线（示例应用测试归位）已停放，未提交改动存 `_build/s2-parked.patch` 后回滚。
- 下一棒：**S1 扩题库到 20–30 条**，包含 AOT/真机类缺口的标注与独立量具。
