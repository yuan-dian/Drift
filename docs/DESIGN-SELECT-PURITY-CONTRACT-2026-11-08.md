# §72 / §73 方案：`select-purity` 与运行期门控的契约对齐（2026-11-08）

> 基准：Drift `d2fc77a`（§71）。输入：`docs/PLAN-NEXT-STEPS-2026-11-08.md` 的排序、
> `DEBT` §69.4 / §70.5，以及对**当前代码**的回查（不照抄计划）。
> 本棒 = 实施记录的前半：先分析、裁决、钉死判死条件；落地结果见 §8。
> 纪律来源：`AGENTS.md`、`MEMORY.md`（变异四律 / T0-T1-T2 / 判据三态）、`DEBT §40.1`。

---

## 0. 结论

**按推荐方向推进，但把计划 §73-A 的改法换掉了。**

- 计划给的两条改法是 B1「`readonly` 且体内不 `new` 才放行」与 B2「移除白名单」。
  回查代码后发现 **`readonly` 放行有两条独立通道**（返回类型 + 体内 `new`），
  B1/B2 都只描述了第一条 ⇒ 照做会修一半、漏一半。
- 更深一层的根因不是「`readonly` 的 `===` 是身份比较」，而是
  **`lint` 的放行条件与运行期门控的实际条件不一致**：
  `SubjectBuilderState::sameValue()` 只认 `Equatable` 与 `===`，对 `readonly` 没有任何特判。
  ⇒ 推荐 **B2+：抽一个 `isStableValue()` 单一判定，两条通道共用**，
  让静态判据精确等于运行期能保证稳定相等的三条正门。
- **73-B「无尺寸解码门禁」按 V2 判死**，不上仓。证据是三条硬事实，不是感觉（§5）。
- **§74 超时口径：本棒只做实现前分析，不实施**。回查发现计划里的路 A 不成立
  （批大小无上界），真修法要动 `src/`，应与 §77 合并一次 T2（§6）。
- **§72 文档收尾 + N5 命名登记** 搭车做完（P0，近零成本）。

**零 T2**：不新增类、不改 `src/` 逻辑（仅 `IoPoller` docblock）、不碰壳层。
**兼容性可破坏**：移除 `readonly` 白名单；实证全仓 1048 文件移除后仍 0 error（§1.3）。

---

## 1. 现状缺口（逐条对代码，不照抄清单）

### 1.1 双通道假绿

`tools/Lint/Rules/SelectPurity.php` 有两处独立放行 `readonly`：

```php
// typeProblem()：select 闭包的返回类型
if (($info['readonly'] ?? false) || in_array($fqn, self::IDENTITY_STABLE, true)) {
    return null;                                       // ① 放行
}

// check()：闭包体内的 new
if (($info['kind'] ?? '') === 'class' && !($info['readonly'] ?? false)) {
    $found[] = ...                                     // ② readonly 不报
}
```

夹具 `tools/Lint/fixtures/apps/SelectFixture.php` 里
`readonly class UserRow` 正是 **Good 形态**（`new UserRow(...)` 不响）。

而运行期 `src/State/SubjectBuilder.php`：

```php
private static function sameValue(mixed $a, mixed $b): bool
{
    if ($a instanceof Equatable && $b instanceof Equatable) {
        return $a->equals($b);
    }
    return $a === $b;
}
```

⇒ 三条正门是 **enum（单例）/ `Equatable`（值相等）/ `IDENTITY_STABLE`（框架保证同值复用同实例）**。
`readonly` 只保证属性不可变，**不保证同值复用同一实例**，也不提供值相等方法。
lint 放行它，运行期却按 `===` ⇒ 每次 `new` 的新对象永不相等 ⇒ 每帧重建且**零报错**。

### 1.2 计划漏掉的那一半

计划 §73-A 写的是「`typeProblem()` 对 `readonly` 直接放行」，只点了 ①。
实测证据：临时移除**两处**放行（未改夹具）后 `lint.php --selftest`
**期望 8 / 实际 10** —— 多出的 2 条正是 `UserRow` 的返回类型 1 条 + 体内 `new` 1 条。
⇒ 只修 ① 会留下 ② 这条静默通道。

### 1.3 影响面实证（决定「破坏兼容性」是否可行）

`build/probe-readonly-impact.py`：bytes 读写保 LF、串行改同一文件、`finally` 恢复。

| 状态 | `lint.php` 全仓 | `lint.php --selftest` |
|---|---|---|
| 基线 | 1048 文件 / 0 error / 0 warn | 全绿（select-purity 该响 8） |
| 移除两处 `readonly` 放行 | **1048 文件 / 0 error / 0 warn** | 红（期望 8 / 实际 10，来自只读夹具） |
| 恢复 | 0 error | 全绿 |

真实业务里 `select` 声明类返回类型只有 4 处：
`apps/users/src/Pages/UsersPage.php` 的 `AsyncValue`（`IDENTITY_STABLE`）、
`apps/watermark/src/Widgets/StatusBar.php` / `TaskCard.php` / `UploadCard.php`
的 `StatusMessage`（§69 已实现 `Equatable`）。
⇒ **移除白名单对业务零迁移成本**，因为 §69 已经把值对象全部迁到显式契约。
这正是「有更好方案可以适当破坏兼容性」的适用情形。

### 1.4 一处判据陷阱（差点拿假绿当基线）

`php tools/selftest.php` 只定义 `drift_lint_selftest()` 函数、不执行，直接跑恒 `EXIT=0` 且无输出。
真判据是 `php tools/lint.php --selftest`（gate 里接的也是这条）。
本棒所有 selftest 结论一律用后者。

---

## 2. 不重开的判死项

`Image::url()` / NetworkImage / Audio / Video 组件、§18 可继承作用域、自动依赖收集、
`Cell` / `Computed` / `Bound`、`Subject` 框架级自动广播、Store 每字段 Cell 化、
拆第二个 `AsyncCommand`、单树 / 增量布局 / 清位式 `DirtySet` / 重造 `DrawOp`。
理由与重裁触发条件见 `docs/PLAN-NEXT-STEPS-2026-11-08.md` §6，本棒不重复论证。

---

## 3. 成熟 GUI / 语言的借鉴与排坑

| 机制 | 借到什么 | 明确不借什么 |
|---|---|---|
| **.NET `IEquatable<T>` / Java `equals` + `hashCode`** | 值相等必须是**显式声明的契约**，不能靠「不可变」推断 ⇒ 直接支撑「移除 `readonly` 白名单」 | 把 `equals` 做成编译器隐式生成（本仓 AOT 下要显式接口） |
| **Kotlin `data class`** | `equals` 由编译期按属性生成，但**仍是显式契约**；`val` 不可变本身不产生 `equals` ⇒ 反证「readonly 近似」 | 自动依赖收集 |
| **Flutter / Riverpod `Selector`** | `shouldRebuild` 按值比较，默认用 `==`；值对象要自己实现相等语义 | 把「不可变」当成「相等」 |
| **Compose `derivedStateOf` / `Snapshot` 批量写** | 反证自动 notify 撕裂读（候选 E 判死依据） | 自动依赖收集 |
| **SwiftUI `@Observable` / Vue `ref`** | 不借：自动依赖收集本仓已判死 | — |
| **WPF `BitmapImage.DecodePixelWidth`** | 13 号评审用它论证「`targetWidth=0` 应受禁」；本仓 `width<=0` 是**合法布局语义**，结论不可搬（§5） | 受禁默认 |
| **WPF `AutomationPeer` / FlaUI 事件库** | 判据必须独立于实现、走客户端可观察量（§70 已落地） | — |

**最值钱的一条**：.NET / Java / Kotlin 三者一致地把「相等」做成
开发者必须**写下**的契约。本仓 §69 的 `Equatable` 已经是这个设计；
`readonly` 白名单是它之外残留的一条近似通道 —— 修法不是再补一层近似，是删掉它。

---

## 4. 五维方案比较与裁决

| 方案 | 结构 | 实现 | 扩展性 | DX | 维护性 | 裁决 |
|---|---|---|---|---|---|---|
| **B1** `readonly` 且体内不 `new` 才放行 | − 仍是近似 | ++ 改动最小 | − 再加一条特例 | − 错误信息继续误导 | − 新隐性契约 | **否**：`fn() => $factory->make()` 体内无 `new` 却返回新实例，照样假绿 |
| **B2** 直接移除白名单（两处各删） | + | ++ | + | + 漏写当场报 | ± 两处判定各写一遍，易漂 | 方向对，实现不够好 |
| **B2+** 抽 `isStableValue()`，两通道共用 ★ | **++** 单一事实源，与 `sameValue()` 三正门对齐 | ++ 一个私有方法 + 两处调用，零 T2 | **++** 未来新稳定类型只改一处，不再加白名单 | **++** 统一错误信息，明确指路「实现 `Equatable` 或登记」 | **++** 删掉会腐烂的近似；Good/Bad 夹具自证 | **推荐** |

**B2+ 的实现契约**：

```text
isStableValue(fqn, info) ≡
    enum
  ∨ IDENTITY_STABLE 登记
  ∨ implements Equatable
```

`typeProblem()` 与 `newTargets()` 都只问它；仓库外类型 / `mixed` / 无返回类型
沿用既有「不判」诚实边界，不动。

**五维逐条理由**

- **架构**：不引入第二套异步簿记或第二套状态通道；把「静态判据」与「运行期门控」
  收敛到同一份契约，守住单一事实源（§18 组合点注入不受影响）。
- **实现**：只动 `tools/Lint/`，不碰 `src/` 逻辑、不新增类 ⇒ 零 T2；
  变异能咬红（§7）。
- **扩展性**：`IDENTITY_STABLE` 从「绕过白名单的例外表」升级为
  「稳定契约的登记处」，语义清晰；第二个同类消费者出现时只需注册，不改判定。
- **DX**：错误信息从「只许标量 / enum / readonly class」改成
  「只许标量 / enum / `Equatable` / 已登记的稳定值对象」，漏写时直接告诉开发者怎么修；
  不再需要记住「readonly 到底算不算」这条隐性规则。
- **维护性**：`summary()` / docblock / 两处 `sprintf` 里写死的「readonly class」
  一并订正，避免下一棒又照着腐烂文案改回去；夹具同时钉 Good（含 `readonly` + `Equatable`）
  与 Bad（纯 `readonly`），防矫枉过正。

---

## 5. 73-B「无尺寸解码门禁」按 V2 判死（实证，不做）

计划的判死条件是「先证明存在变异能让它报红」。回查结果是**三条硬事实都指向没有牙**：

1. **warn 不进退出码**：`tools/lint.php` 只有 `error` 计入 `$errors > 0 ? 1 : 0`；
   计划设计的 73-B 是 `warn`（因为 `width<=0` 合法），⇒ 即使响了，
   gate 的 `lint` 项也恒绿。要进 gate 得改 error，但那会误伤合法形态（下一条）。
2. **`width<=0` 是合法布局语义**：`src/Widget/Image.php` 的 `decodeWidth()` 明写
   「`width <= 0` ⇒ 尺寸由原图决定 ⇒ 不能在解码期缩」；
   `tests/Feature/Widget/ImageTest.php` 的缺图占位用例就是
   `Image::make()->asset('demo/no-such-image.png')` **不带 `width()`**。
   把它判成 error 会破坏 `ImageTest` 钉住的行为。
3. **静态扫描脆弱且当前零违规**：`asset()` 链跨行
   （`apps/watermark/src/Widgets/ImageThumb.php` / `ResultCard.php`），
   宽度也可能是变量 / 条件分支；当前所有真实调用点都给了尺寸
   （showcase 5 处、watermark `SIDE=110` / `PREVIEW_WIDTH=560`）。
   ⇒ 唯一能构造的「变异」是人工删掉某处 `width()`，属夹具式自证，不是真实回归。

**结论**：按 `MEMORY.md` §83「删掉仍全绿的是死规则 / 找不到能咬红的变异 ⇒ 不上仓」，
本棒不建这条规则，也不造恒 0 空壳判据。

**更优替代（登记为候选，不在本棒）**：把「运行期真的按原尺寸解了一张大图」
做成结构计数判据（复用 §71 `pairChecks` 的模式：`ImageLoader` 导出计数、
`smoke.php` 断言全仓页面渲染后计数为 0）。它外层可观察、不与实现同源、
变异「删掉某处 `width()`」能咬红。触发条件：出现第二个同类消费者，
或 `ImageLoader::load()` 的内存纪律第一次有真机判据。

---

## 6. §74 超时口径：实现前分析结论（本棒不实施）

### 6.1 静态链复核（与计划一致）

```text
apps/watermark/bin/win32/Bootstrap.php 只传 2 参
  ⇒ requestTerminateMs = 30_000（ProcessTaskHost 构造默认值）
Config::UPLOAD_TIMEOUT_S = 300.0    ⇒ 单文件 HTTP 允许 300s
UploadBatchJob 串行传 N 张图        ⇒ 整个 Job 墙钟远超 30s
flush() 每帧调 sweepTimeouts()      ⇒ 30s 到就 dropWorker(..., true)
```

`apps/watermark/src/Api/Config.php` 的 `@design-param` 自己写着「口径待对齐（当前零判据）、
真机没量过」。⇒ 三条核心判据全中，但**本轮未跑真机上传**，措辞不得写成「用户实际遇到了」。

### 6.2 计划路 A 不成立（新事实，改变裁决）

计划 A 是「组合根按『批大小 × 单文件上限 + 余量』显式传 `requestTerminateMs`」。
回查 `apps/watermark/src/Store/WatermarkStore.php`：
`items` 来自用户选文件，只有 `\count($items)` 用于文案，**没有最大数量上界**
（全仓无 `MAX_FILES` / `MAX_ITEMS`）。⇒ 按最坏批算出的值没有上界，
等于把「防卡死兜底」废掉；按保守值算又会误杀大批上传。路 A 不可执行。

路 B（收窄 `UPLOAD_TIMEOUT_S`）改变业务能力；路 C（Job 声明式上限）动 `src/` ⇒ 必须 T2。

### 6.3 更优方向：把「墙钟上限」升级为「无进度看门狗」

成熟框架的通行做法是**用进度心跳刷新超时**，而不是拉长墙钟：
Kubernetes liveness probe、Chrome hung-renderer 检测、.NET 基于 `IProgress<T>` 的取消窗口。
本仓的现成信号是 `JobProgress::report()` —— §68 的 `AwaitProgress` lint 规则已保证
`UploadBatchJob` / `DownloadResultsJob` 的进度被 `await()` 第二参订阅。
把 `sweepTimeouts()` 的判据从「起算后 > 30s」改成「**连续 30s 无任何进度回包**」，
批量任务持续上报就不会被误杀，真卡死（无进度）仍在 30s 内回收；
短 Job 也不必为长 Job 放宽全局上限。

**为什么本棒不做**：它要动 `src/App/ProcessTaskHost.php`（inflight 记账 + `readReplies()`），
按 `AGENTS.md` 必须 T2；而排在最后的 B4 EOF 双判改的是**同一个文件**。
两棒合并一次 `drift-build --full` + 真机探针，比分两次重建 AOT 更省且不互相覆盖。
⇒ **登记为下一棒的头号候选**，判死条件仍是「不许直接把 30s 改成 300000ms 把保护废掉」；
判据必须写死期望值、不从常量推导（§68 踩过 `POLL_MAX_ROUNDS` 跟着放大仍全绿的坑）。

---

## 7. 实施范围、变异清单、验证分层

### 7.1 改动面

| 文件 | 改动 |
|---|---|
| `tools/Lint/Rules/SelectPurity.php` | 抽 `isStableValue()`；两通道共用；订正 docblock / `summary()` / 两处错误信息 |
| `tools/Lint/fixtures/apps/SelectFixture.php` | `UserRow` 迁成 `EquatableRow implements Equatable`（Good，且带 `readonly`）；新增 `readonly ReadonlyBox`（Bad）；`staticShape()` 补 readonly 形态 |
| `tools/selftest.php` | `select-purity` 期望数按新夹具调整 |
| `tests/Unit/State/SubjectBuilderTest.php` | 加一条**运行期**判据：纯 `readonly` 值对象（不实现 `Equatable`）同内容新实例仍标脏 —— 与 lint 不同源，双层钉住契约。`tests/` 不在 lint 扫描范围，故可写最自然的「声明类型 + 体内 `new`」形态 |
| `src/App/Io/IoPoller.php` | docblock 补 N5 同名不同义登记（IO 就绪 / 业务周期 / 输入出队 / 约束漂移），仅注释 |
| 三个 untracked 文档 | §72 收尾入仓 |
| `docs/DEBT-2026-11-02.md` | §72 / §73 |
| `.workbuddy-ai/memory/2026-11-08.md` | 战史 |

### 7.2 变异清单（每条先 `php -l`、串行改同一文件、`finally` 恢复）

```text
M1  isStableValue() 重新放行 readonly（旧规则回退）  ⇒ --selftest 红（12→8，少 4）
M2  只给 newTargets() 开 readonly 后门               ⇒ --selftest 红（12→10，少 2）
M3  只给 typeProblem() 开 readonly 后门（反向通道）  ⇒ --selftest 红（12→10，少 2）
M4  Good 夹具 EquatableRow 去掉 implements Equatable  ⇒ --selftest 红（12→14，多 2）
M5  真实业务 StatusMessage 去掉 Equatable 契约        ⇒ lint.php 红（3 处 select 报错）；恢复 0
基线 全部恢复 ⇒ --selftest 12/0 全绿、lint 0 error
```

`M2` / `M3` 是最关键的一对：分别只放行一条通道，专门验证「计划漏掉的那一半」
真的被补上了。初稿的 `M0`（新夹具 + 旧规则）与 `M1` 等价（旧规则回退就是
重新放行 `readonly`），故合并；另补 `M3` 反向通道与 `M5` 全仓真实诊断。

> **M5 首版打空，已修正**：初版把变异下在 `tests/Unit/State/SubjectBuilderTest.php`，
> 但 `Project::isToolchain()` 明确把 `TESTS_DIR` 算进工具链，`SelectPurity::check()`
> 对工具链文件直接 `continue` ⇒ `lint.php` 根本不扫 `tests/`，改了必然全绿。
> 按「改了没红先怀疑变异打空」定位后，改成动真实业务文件
> `apps/watermark/src/Store/StatusMessage.php`。这条同时证明
> 「`Equatable` 是 3 处真实 `select` 的唯一正门」。

### 7.3 验证分层

- **T0**：`php -l` 所有改动 PHP；`python -m py_compile` 变异脚本。
- **T1**：`lint.php`（全仓 0 error）、`lint.php --selftest`、单测、
  `gate.php --fast` 15/15、`gate.php` 18/18、`docref`、`api-doc-check.php`。
- **T2**：**不需要**。不新增类、不改 `src/` 逻辑、不碰壳层；
  `IoPoller.php` 只改 docblock。`docs/API-SURFACE.md` 不受影响（`@api` 面未变）。

### 7.4 退出条件

基线全绿、M1–M5 逐条真红、恢复后绿、gate 18/18、DEBT/方案稿/记忆入仓、
`git status --short` 为空。

---

## 8. 落地纪要

- **规则**：`SelectPurity.php` 抽出 `isStableValue()`（enum ∨ `IDENTITY_STABLE` ∨
  `implements Equatable`），`typeProblem()` 与 `newTargets()` 共用；两处 `readonly`
  放行删除，规则里对 `readonly` 只剩解释性文字。错误信息改成
  「只许标量 / enum / `Equatable` / 已登记的稳定值对象」，体内 `new` 那条直接给修法。
- **夹具**：`UserRow` → `readonly EquatableRow implements Equatable`（Good，`readonly`
  保留以证明它本身无害）；新增 `readonly ReadonlyBox`（Bad）；`staticShape()` 补
  `fn` / `static fn` 两种 readonly 形态。`--selftest` **该响 12 / 不该响 0**。
- **运行期判据**：`SubjectBuilderTest::testReadonlyWithoutEquatableStillRebuilds()`
  量 `needsBuild()`，与 lint 不同源；`tests/` 不在 lint 扫描范围，故写最自然形态。
- **实测数字**：lint **1048 文件 / 0 error / 0 warn**；unit **588 tests / 1813 assertions**；
  feature 全过；smoke **473**；golden 整页 34/34；`docref` **136 ok / 0 FAIL**；
  **gate 18/18（138.8s）**。
- **变异 5/5 真红**，逐条读数见 §7.2；M5 首版打空的根因与修正已回写 §7.2。
- **T2 未跑且不需要**：不新增类、不改 `src/` 逻辑（`IoPoller` 仅 docblock）、不碰壳层；
  `@api` 面未变，`API-SURFACE.md` 无需刷新。

---

## 9. 诚实边界

- 本棒修的是**静态判据与运行期门控的契约对齐**，不是运行期 bug：
  当前全仓零违规，纯 `readonly` 值对象被 `select` 消费的情形**今天不存在**。
  所以它是预防性修真，不能宣称「修好了每帧重建」。
- `isStableValue()` 与 `sameValue()` 是**人工对齐的两份代码**，不是同一份实现；
  若未来 `sameValue()` 增加新放行条件而 lint 没跟上，仍会漂。
  判据能抓的是「规则回退」与「夹具形态变化」，抓不住「两处同时改错」。
- 73-B 判死是当前证据下的裁决；若出现第二个同类消费者或真机内存判据，
  按 §5 的替代方案重裁，不是永远不做。
- §74 只做了静态链核对与方案裁决，**未跑真机上传、未改 `ProcessTaskHost`**；
  「无进度看门狗」是方向建议，未做实现前实现与变异验证。
- 三个收尾文档里 13 号评审稿 / 异步 UI 分析稿 / 推进计划都是**只读评审与规划**，
  其结论未被本棒实施的部分照旧标注「未验证」。
- 棒号以落地时 `DEBT` 尾部为准；本方案不推翻 `docs/PLAN-NEXT-STEPS-2026-11-08.md`
  的排序，只修正 §73-A 的改法、判死 73-B、并把 §74 的路 A 证据补上一条反证。
