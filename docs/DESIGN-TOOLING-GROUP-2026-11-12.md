# 工具链 P1 裁决：判死 PHPUnit 化，改收「工具链组」

> 基准 `7608343`（2026-11-12 23:31）。**先裁决后改码**：本文 §0–§8 在实施前冻结，
> §9 记录实施结果。输入是 `.workbuddy-ai/memory/2026-11-12.md` 的「工具链重构最终方案」
> 与今日 23:30 收口提交，全部结论按当前 HEAD 重新对代码，不照抄清单。

## 0. 结论

**原 P1（把 5 对静态对账搬进 `tests/Unit/Tooling/`、registry 收成 1 项 `tooling`）判死。**
不是因为做不到，而是它要付的代价恰好是本项目反复写进仓的四条纪律：

| # | 硬伤 | 代码证据 |
|---|---|---|
| 1 | **与已入仓裁决正面冲突**：这批对账器存在的理由就是「与 PHPUnit 不同源」 | `tools/Support/CheckRegistry.php:49,53,58`、`tools/diagnostic-check.php:11`、`tools/build-stale-check.php:7`、`tools/error-shape-check.php:9` 逐条写着「那边验行为对不对，这边验接线还在不在，不同源」 |
| 2 | **判据实现层会被重写**：5 个脚本的判据与变异自证在同一文件内、内存合成变异体，无外部 fixture；搬进 PHPUnit 必须换表达 | `tools/abi-check.php:70-146`、`cpp-bridge-check.php:150-175`、`diagnostic-check.php:73-105`、`build-stale-check.php:145-288`、`error-shape-check.php:157-178` |
| 3 | **零依赖守卫退化为可跳过**：`maySkip()` 含 `unit`/`feature`，缺 `vendor/bin/phpunit` 时 `phpunit-run.php` exit 2 记 `skip` | `CheckRegistry.php:116`、`tools/phpunit-run.php:20-24`；4 个对账器头注明写「纯 PHP 内建，不 require autoload —— 对账器不该依赖它要检查的那套代码」 |
| 4 | **原始动机已被上一棒消化**：P1 方案写于 23:30 前后，痛点是「70 个顶层 CLI 的暴露面」；`7608343` 在 23:31 已把 CLI 收成 `bin/drift` 的 7 个子命令 | `bin/drift:38-52`、`composer.json` scripts 全部转发 |

第 3 条最致命：这 5 对是**没有前置依赖的纯 PHP 守卫**，绑到 PHPUnit 后，没装 composer
依赖的机器上一条都不跑，而 `ccb701a` 刚把 CI 的 `skip` 判红 ⇒ 本地静默、CI 红，
是标准的「静默失效」。

**采纳替代方案 C′：判据实现零改动，编排层把 10 项收成 1 项 `tooling` 组**，
用「组执行器 + 独立路径自证」保住逐项可寻址、可诊断、可咬红。gate **30 → 21**。

## 1. 重核事实（清单会腐烂，逐条落代码）

- `CheckRegistry::checks()` 返回 `array<string, [label, command[], slow]>`，键即短名；
  `maySkip()` 是独立方法，返回 `['pixels','decode','unit','feature']`。**30 项**。
- 目标 10 项在 `CheckRegistry.php:42-60`，全是 `slow=false`、命令为
  `[PHP_BINARY, tools/X.php, 可选 --selftest]`，**均不在 `maySkip()`**。
- `gate.php` 只执行：`--list`(:141)、`--fast`(:160)、`--only=`(:134-157)、`--json`(:132)，
  逐项 `proc_open`(:212) 且 **fail-fast**(:305 `break`)；通过项不带 stdout(:279-281)，
  失败项 stdout/stderr 进 `data`(:303)。
- CI 侧 `.github/scripts/assert-gate-report.php` 只被传 `unit`（:95）/ `unit feature`（:135），
  **不含这 10 个 id** ⇒ 收敛后 CI 断言参数无需改。`.github/workflows/ci.yml:31`
  的白名单是「不再写白名单」的历史说明，不是现行配置。
- `tests/Unit/Tooling/` 不存在；`phpunit.xml` 有 `unit`/`feature` 两个 suite；
  `SmokeTestCase::check()` 只记录、`flushChecks()` 才 fail、`assertPostConditions()` 兜绿壳。
- 磁盘上 `tools/*-check.php` 共 6 个，其中 **5 个含 `--selftest`**、
  `api-doc-check.php` 不含 ⇒ 这 5 个可作为**独立于 `groups()` 的期望来源**（§5.3）。
- `tools/lint.php:49-51` 扫全仓（只排除 `tools/Lint/fixtures/`、`tools/_archive/`）
  ⇒ 新脚本要过 lint。`tools/_archive/` 是只读归档，5 个对账器无归档副本。

## 2. 五维分析

### 结构
静态对账是**「接线还在不在」**，行为单测是**「行为对不对」**，两者是不同层。
`docs/_archive/DESIGN-v2-2026-10-14.md:2291-2293` 已把这条写成设计前提。
合成一层会命中 V3「判据与实现同源」的变体：聚合层既能决定跑哪些，又能决定报什么。

### 实现
5 个脚本的 `--selftest` 用 `str_replace` 合成源码做变异，**变异体与判据同文件**，
且改一个锚点就会「没改到东西所以绿」（`abi-check.php:12-14` 头注专门防这个）。
这些变异史是资产：重写表达 = 变异史作废，即使逐字等价也没有「被咬红过」的证明。
`MEMORY.md` 已把「重写 3459 条 `check()` ⇒ 判据同源 + 变异史作废」列为长期纪律。

### 扩展性
「组」做成编排层一等公民后，将来 T1/T2 也能分组而不碰 `gate.php`；
把 5 对塞进 PHPUnit 则是**一次性收编**，第 6 对出现时又要在 `tests/Unit/Tooling/`
和 `CheckRegistry` 两处对齐。

### 易用性（DX）
原方案损失两个真实消费者：`gate --only=abi` 的单点调试、CI 报告里逐项可查。
C′ 用 `bin/drift tooling --only=abi` 与 `gate --only=tooling` 两层保住，
且组内**不 fail-fast**（一次看全 10 条失败），比现状更好。

### 维护性
`CheckRegistry` 注释明写「一份数一个名字」。C′ 让顶层项与组内子项**都住 registry**，
顶层项与组内子项同住一类，不出现第二份清单；`tooling` 的 label 是固定文案、
不写死项数（项数只信 `--list`）。

## 3. 成熟框架参考（只取能证伪的部分）

| 来源 | 事实 | 对本案 |
|---|---|---|
| Flutter `packages/flutter/test_fixes/README.md` | 修复规则数据在 `lib/fix_data/`，自证是 `test_fixes/` 下成对 `.dart` + `.dart.expect` golden，CI 由 `dev/bots/.../run_framework_tests.dart::runFixTests` **单一入口**调度 | 支撑「单一 CI 入口 + 独立期望文件」；**反驳并入 `flutter_test` 单一 suite** —— 它自成一层 |
| Chromium `PRESUBMIT.py` | 静态检查由同目录 `PRESUBMIT_test.py`（`unittest.TestCase`）+ `PRESUBMIT_test_mocks.py` 合成输入自测，与 gtest **不同阶段、不同文件** | 反驳「静态对账混进产品单测」；支撑「同层并列 + 合成输入自证」 |
| 仓内 `D:\TypePHP\Drift3\docs\research` | 17 份框架研读里**没有** lint 自证 / 静态对账测试组织的段落（`12-横向思路提炼.md:139-153` 只讲纯函数无头单测 + 软光栅 golden） | 不硬套；本案权威材料是本仓 `AGENTS.md` 与已入仓裁决 |

⇒ 两家都把静态对账**自成一层、自带独立自证、单一 CI 入口**。C′ 与之同构；
原方案把它们并进 PHPUnit 反而与两者都相反。

## 4. 三个方案比较

| | A 原 P1（PHPUnit 化） | B 不合并，只给 `--list` 加分组显示 | **C′ 工具链组（采纳）** |
|---|---|---|---|
| gate 项数 | 21 | 30 | **21** |
| 判据实现改动 | 重写 5 对 | 0 | **0** |
| 变异史 | 作废 | 保留 | **保留** |
| 缺 PHPUnit 时 | 5 对全跳过 | 正常跑 | **正常跑** |
| `--only` 细粒度 | 丢 | 保留 | **保留（两层）** |
| 一次看全失败 | 否（fail-fast） | 否 | **是（组内跑完）** |
| 新增文件 | 5 个测试类 | 0 | 1 个 `group-run.php` |
| 风险 | 高 | 无 | **中：聚合层吞失败，靠 §5.3 自证 + §6 牙检** |

B 其实最安全，但没有减量；`bin/drift list` 折叠只治「看着长」，不治
「30 个顶层名字 + 10 个同族短名」的认知负担。C′ 用一层可自证的聚合换到减量。

## 5. 采纳方案设计

### 5.1 事实源
`CheckRegistry::groups()` 新增，声明 `tooling` 组的 10 条子项（id / label / command）；
`checks()` 删掉 10 项、新增 1 项 `tooling`，命令
`[PHP_BINARY, tools/group-run.php, '--group=tooling']`；label 不含项数，
项数以 `--list` 为准。
`gate.php`、`bin/drift` 的清单消费方式不变（返回类型不变）。

### 5.2 组执行器 `tools/group-run.php`
- 参数 `--group=<name>`（必填）、`--only=<id,...>`、`--json`、`--selftest`。
- 逐条 `proc_open`（数组式 + `bypass_shell`，与 `gate.php` 同法），**跑完所有**再汇总；
  逐条打印 `ok/fail <id> <label>`，失败附子进程输出。
- 退出码 **0 全过 / 1 有子项红 / 2 用法错**，与 gate 同口径；不进 `maySkip()`。
- `bin/drift` 加 `tooling` 子命令转发，`composer.json` 加同名 script，
  细粒度用法 `composer tooling -- --only=abi`。

### 5.3 自证走独立路径（防「聚合层吞失败」）
每次正常运行的**第一步**跑内置自证，且自证不读 `groups()` 的声明当期望：

1. **合成 step 反证**：构造 3 条临时 `php -r` step（过 / 红 / 红），要求
   整体非 0、两条红**都被点名**（钉「不 fail-fast」「不吞退出码」）。
2. **磁盘推导期望**：glob `tools/*-check.php`、过滤「文件内含 `--selftest`」，
   得 5 个文件；要求组里每个文件恰好 2 条 step（一条带 `--selftest`、一条不带）。
   期望来自**文件系统**，删 `groups()` 里任一条都会红 —— 不是拿自己的清单验自己。
3. **`--only` 契约**：限定一个 id 时，报告里只出现该 id，且退出码仍由它决定。

自证失败直接 exit 1，不继续跑组。它硬编码在执行器里，`groups()` 删不掉它。

## 6. 判据与变异牙检

不碰判据实现 ⇒ 原有 5 对的全部变异史继续有效。新增判据只针对**聚合层**：

| 变异 | 判据 |
|---|---|
| M1 从 `groups()` 删掉全部判据 step（只剩自证） | 红：自证 ②「磁盘 5 文件 × 2 step」不成立 |
| M2 执行器吞掉子项非 0 退出码 | 红：自证 ① 合成红 step 被判过 |
| M3 执行器 fail-fast（首个失败即 break） | 红：自证 ① 第三条合成 step 没被跑到 |
| M4 自证 step 摘掉 `--selftest` | 红：自证 ②「恰好 1 条带 `--selftest`」不成立 |
| M5 子项真的红（附加未知参数，`abi-check.php` exit 2） | 红：全组 exit 1 且自证 ② 仍绿 ⇒ 失败冒泡没被自证掩盖 |
| M6 对照：跳过内置自证 + M1 | **绿** ⇒ 证明 M1 的检测路径只能是自证 |

**第 0 条**：先跑基线绿，再做 M1–M6，每条还原后复验基线绿。
执行器 `_build/mutate-tooling.php` 是临时件，不进 git。
停止线：清单与 `--list` 不一致 / 任一变异不咬红 / `composer check` 不全过 /
`docref` FAIL / `git diff --check` 有输出 / CR>0 ⇒ 回滚该阶段。

## 7. 明确不做

- 不搬 `_archive/`、不删 5 个对账器、不合并两个 equivalence 执行体。
- 不把 `tooling` 加进 `maySkip()`（它是零依赖守卫，跳过 = 静默失效）。
- 不改 `gate.php` 的 fail-fast 语义（只让组内不 fail-fast，越界会动到另外 20 项）。
- 不写 `InProcessTester` / 不碰 `tests/Support/`（P2 另议）。
- 不在文档 / 注释 / CI 里抄项数（项目已漂过两次，只信 `--list`）。

## 8. 诚实边界

- 本文的「30 → 21」是**编排层项数**，不是判据条数；10 条判据一条没少。
- 成熟框架调研只找到能支撑「自成一层 + 单一 CI 入口」的证据；
  「把纯文本对账收进单一 test target」在 Qt / Chromium 侧**未搜到明确惯例**，
  故 C′ 是结合本仓纪律的裁决，不是照抄某框架。
- 未跑 T1/T2：本棒不碰 `src/`、`cpp-src/`、构建产物。

## 9. 实施结果（2026-11-12）

### 落地

| 文件 | 改动 |
|---|---|
| `tools/Support/CheckRegistry.php` | `checks()` 删 10 项、增 1 项 `tooling`；新增 `groups()` 声明组内 10 条 |
| `tools/group-run.php` | 新建：组执行器 + 内置自证（合成 step / 磁盘期望 / `--only` 契约） |
| `bin/drift` | 加 `tooling` 子命令 + help 一行 |
| `composer.json` | 加 `tooling` script 与说明 |
| `docs/DESIGN-TOOLING-GROUP-2026-11-12.md` | 本稿 |
| `docs/CONTRIBUTING.md` | §3 补组内单跑用法与「组内不 fail-fast」 |
| `CHANGELOG.md:38` | 「28 项闸门」改成不带数字（项数以 `composer checks` 为准） |
| `.github/workflows/ci.yml` | 白名单历史注释补一句收敛说明 |

判据实现层（5 个 `*-check.php`）、`gate.php` 的判据循环、`tests/` **一行未动**。

### 数字

- `bin/drift list` ≡ `gate --list` **21 项**（原 30）；`--fast` 18 项（原 27）。
- 组内 **10 条**一条没少：5 对判据 + 5 对变异自证，全组 3.4s。
- `tooling` 不在 `maySkip()` ⇒ 缺 PHPUnit 也照跑。

### 验证

- 牙检 `_build/mutate-tooling.php`：第 0 条基线绿，M1–M5 全红，M6 对照绿，还原后复验绿。
- `composer check` **18 项全过 / 44.6s / EXIT=0**。
- `composer check:full` **21 项全过 / 129.1s / EXIT=0**（含 `feature` 59.3s、`smoke` 473 条、`pages`）。
- `docref 116 ok / 0 FAIL`；`apidoc` 未变；`composer validate` 通过；
  `git diff --check` clean；8 个改动文件全 LF。
- 未碰 `src/` / `cpp-src/` / 构建产物 ⇒ 按 T0，未跑 T1/T2。

### 兼容性破坏（有意）

`gate --only=abi` 等 10 个顶层短名不再可用，改 `composer tooling -- --only=abi`
（或 `php tools/group-run.php --group=tooling --only=abi`）。组内未知 id 会明确列出
已知 id 并 exit 2，不静默。CI 的 `assert-gate-report.php` 只传 `unit` / `unit feature`，
无需改。

### 遗留

- 原 P1 的 P2（`InProcessTester`）、P3（AOT 三口径对照）仍未开工。
- `#187` 三 hash 台账登记仍缺翻译层 / T2 产物对照，未落盘。
