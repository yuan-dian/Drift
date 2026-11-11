# §71 12k 尺子补调和复杂度判据（实现前分析）

> 2026-11-08 · 基准 HEAD `1bd8918`（§70），gate 18/18。
> 触发：`docs/REVIEW-drift3-blueprint21-render-arch-2026-11-08.md` §6 推荐「把 `pairChecks` 拿回 Drift2」。
> **方法：先回查代码，再决定动不动手。** 本棒不碰 `src/` 产品代码。

## 0. 结论先行

**上一棒的推荐判错了一半，但更窄的真缺口成立，且已有变异铁证。**

1. `pairChecks` **本仓早已落地**：`src/Core/Pipeline.php` 有唯一写点与访问器，
   `src/Widget/Element.php` 的 `updateChildren()` 两处调用，`tools/smoke.php` 有精确断言
   （200 子同序重建 ⇒ 增量 == 200，旧实现 20,100），`.workbuddy-ai/memory/2026-10-27.md`
   还记着 5 组变异全红。上一棒评审只 grep 了 `tools/frame-tax*` 与 `tools/Bench/`，
   **漏查 `tools/smoke.php` 与 `src/Core/Pipeline.php`**，于是把「已存在」写成「唯一真缺口」。
   ⇒ 评审稿的 §0 / #10 / §6 / §8 已在本棒订正，不重造第二套计数。
2. 但「`--scale` 只有耗时尺子」这句**在 12k 档这一层是对的**，而且比原推荐更值得修：
   `tools/frame-tax-probe.php --scale` 的 5 条判据量的是节点数、layout/paint 耗时与比值；
   调和发生在 `FrameTaxMeasure::run()` 里 `$app->frame()` **之外**的 `$rootEl->update()`，
   不进任何一条判据的分母。`tools/smoke.php` 那条结构断言虽在 gate，但它是**慢项**，
   `gate --fast` 跳过。
3. **实证（判死条件已满足）**：把 `updateChildren()` 前缀同步改回旧实现的
   「按序扫旧表 + 跳过已用槽位」（行为等价、复杂度 O(n²)）：
   `pairChecks` 增量从约 12 万涨到 **45,315,040**（10 帧 × 4.5M），
   `php tools/frame-tax-probe.php --scale` 总耗时 **6.8s → 12.7s（+87%）**，
   而 **5 条判据全绿、退出码 0**。⇒ 耗时比值判据对算法复杂度结构性无感，
   存在一组真实变异能让它打空、也能让新判据咬红。

⇒ **本棒做**：复用 `Pipeline::pairChecks()`（不另造第二套），在
`FrameTaxMeasure::run()` 导出「重建帧配对次数」，`--scale` 加第 ⑥ 条归一化自洽判据。
判据与实现不同源（读量具，不读 `updateChildren()` 的内部结构），且落在 gate 快项里。

## 1. 现状证据（逐条回查当前 HEAD）

| 事实 | 位置 | 判定 |
| --- | --- | --- |
| `pairChecks` 唯一写点 / 访问器 | `src/Core/Pipeline.php` 的 `bumpPairCheck()` / `pairChecks()` | 已存在 |
| 两处计数调用（前缀同步 / 桶命中） | `src/Widget/Element.php` 的 `updateChildren()` | 已存在 |
| 结构断言 + 旧实现数字 | `tools/smoke.php` 的「Element · 顺序不变的 200 子」 | 已存在，200 vs 20,100 |
| 5 组变异全红 | `.workbuddy-ai/memory/2026-10-27.md` | 已验过（桶 LIFO / 桶键前缀 / 前缀比 Key / 前缀不计次 / 前缀每次新建） |
| smoke 在 gate 里但是慢项 | `tools/gate.php` 的 `smoke` 项 | `--fast` 跳过 |
| `--scale` 是快项、5 条判据 | `tools/gate.php` 的 `scale` 项 + `tools/frame-tax-probe.php --scale` 分支 | 可接线 |
| 12k 尺子确实走调和 | `tools/Bench/FrameTaxMeasure.php` 的 `run()` ③ 段：`$rootEl->update($root())` | 变异实测计数 45M 佐证 |
| 5 条判据不覆盖调和 | ① 节点数 ② 四数非零 ③ `layoutUs/nodes` 比值 ④ 稳态帧耗时 ⑤ 重建 layout 占比 | 全是 layout/paint |

⇒ 缺口精确定位：**「12k 分母上的调和复杂度」没有判据，而唯一的同类判据只跑 200 子、且不在 `--fast` 里。**

## 2. 不重开的历史裁决

- **不另造第二套计数**：评审稿 §6 自己写了「不要另造第二套」；`Pipeline` 的
  「唯一写点 / 只增不减 / 消费者是测试探针」纪律是 §22 收过的账。
- **不改 `Element::updateChildren()`**：10-27 已裁决「前缀同步 + (class,key) 建桶」两阶段 O(k)，
  并否掉 Flutter 三扫（尾同步省不出量级，多一份推理成本）。本棒对它的唯一动作是临时变异后恢复。
- **不改 `syntheticRoot()` 树形状**：每行 4 节点是 `docs/_archive/DESIGN-v2-2026-10-14.md` §13.5 表、`--scale` 归一化比值、
  AOT 探针复量的共同分母，改了所有历史读数不可比。
- **不立项增量布局**：`docs/DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md` §0 三条触发条件均未成立。
- **不给 `--check` 加结构判据**：那 6 条的前提是「静止合成树的固定税」，语义不合；
  `--scale` 已在 gate 快项，加在那里就够。
- **不碰 `Cell` / 自动依赖收集 / 单树**：均判死或不在本棒范围。

## 3. 成熟 GUI 框架：借鉴与排坑

- **Flutter**：`MultiChildRenderObjectElement.updateChildren()` 的两阶段 diff 与本仓同形；
  Flutter DevTools 的 frame chart 只给 build/layout/paint **分段耗时**，没有配对次数。
  ⇒ 本仓 10-27 的「复杂度没有行为差异 ⇒ 只能计数」比 Flutter 多走了一步，不能退回去靠耗时。
- **React Fiber**：`reconcileChildrenArray` 也是「前缀 + Map」，Profiler 量 commit 耗时；
  一个行为正确但退回平方的 reconciler 在 React 的耗时图里同样可能被 GC / 光栅噪声淹没。
- **WPF / UIA / Compose**：与「结构计数」无对应物，不借。
- **反面教材（本棒实证）**：`--scale` ③ 的「归一化耗时比值」看起来像复杂度判据，
  实际上它量的是 `layoutUs / nodes`，而调和在 frame 外 ⇒ **代理量选错了段**。
  判据必须落在被测那个量上，不能用「总共慢了」当代理（MEMORY §85 判据必要条件）。
- **排坑**：`rva2sym --selftest` 加第 ⑥ 条后总结行还印着「5 项判据全过」
  （`.workbuddy-ai/memory/PITFALLS.md`）⇒ 本次 `CheckReport` 的 summary 用
  `\count($scaleItems)`，不硬编码条数；`gate.php` 头部注释同步改 6 条。
- **排坑**：变异必须真改变被测语义。第一版平方扫描在同序时第一个旧子即命中 `break`，
  复杂度没变 ⇒ `--scale` 6.7s、5 条全绿，**是变异打空不是判据无感**；
  改成「跳过已用槽位」后计数才真涨到 45M。这与 10-27 记的 M2 第一版是同一个坑。

## 4. 候选方案四维比较

| 方案 | 结构 | 实现 | 扩展性 | DX / 维护性 | 裁决 |
| --- | --- | --- | --- | --- | --- |
| A 给 `--scale` 重造一套专用计数器 | 两份账本，写点分裂 | 动 `Element` 生产码 | 差：别的尺子读不到 | 同一事实记两处必漂 | **否** |
| **B 复用 `Pipeline::pairChecks()`，量具导出到 `--scale`** | 单一写点，量具多一个消费者 | 2 个工具文件，产品码零改动 | 好：`--pages` / AOT 探针可直接读 | 一个字段 + 一条判据 | **★ 选** |
| C 给 `--scale` 加 settle 耗时红线 | 不动结构 | 1 文件 | 差：换个机器就误报 | 墙钟阈值违反「判据只认自证 / 比值 / 结构事实」 | **否** |
| D 把 smoke 的 200 子断言搬进 `--scale` | 与现判据重复 | 1 文件 | 无 12k 分母 | 多一份副本 | **否** |
| E 只订正评审稿、不加判据 | 无 | 0 | 无 | 缺口留着，`--fast` 永远抓不到 O(n²) 回潮 | **否**（已有变异铁证） |

## 5. 实施范围

| 文件 | 改动 |
| --- | --- |
| `tools/Bench/FrameTaxMeasure.php` | ③ 重建帧前后读 `Pipeline::current()->pairChecks()`，返回 `rebuildPairs` |
| `tools/frame-tax-probe.php` | `--scale` 加 ⑥「调和复杂度归一化自洽」；表格印配对数；文案 5→6；summary 用 `count()` |
| `tools/gate.php` | 头部注释 `scale` 项 5 条 → 6 条（**不改判据接线**） |
| `docs/REVIEW-drift3-blueprint21-render-arch-2026-11-08.md` | 订正 §0 / #10 / §6 / §8：承认 `pairChecks` 已存在，缺口收窄到 12k 档 |
| `src/Layout/RenderObject.php` / `src/Rendering/RecordingCanvas.php` | 订正两处注释腐烂（评审 §8 登记的欠账） |
| `docs/DEBT-2026-11-02.md` | §71 章节 |
| `.workbuddy-ai/memory/2026-11-08.md` | 战史 |

**产品代码 `src/` 零改动**：`Element.php` 的变异已恢复，`git diff` 必须为空。
`RenderObject.php` / `RecordingCanvas.php` 只改注释，不动签名与逻辑。

## 6. 验证分层与变异清单

- **T0**：`php -l` 两个工具文件；`php -l` 两个注释订正文件。
- **T1**：`--scale` 基线 **6/6**；`--check` 6/6 与 `--pages --check` 读数不变；
  `tools/smoke.php` 全绿；`--json` 带上两档配对数。
- **T2**：`probes/frame_tax_aot/build.sh` —— `FrameTaxMeasure.php` 被
  `probes/frame_tax_aot/project.yml` 收进 tpc 编译，加了字段必须验构建 +
  `aot-equivalence.php` 几何等价，不能只跑解释器。
- **变异四律**（每条跑前基线必须绿、产物先 `php -l`、不并行打同一文件、恢复后复验）：

  | # | 变异 | 期望 |
  | --- | --- | --- |
  | M1 | 删掉 `Element::updateChildren()` 两处 `bumpPairCheck()` | ⑥ 红（计数恒 0），①–⑤ 绿 |
  | M2 | 前缀同步退回「按序扫旧表 + 跳过已用槽位」（行为等价 O(n²)） | ⑥ 红（比值 ~6×），①–⑤ 绿 ← **核心证据** |
  | M3 | `FrameTaxMeasure::run()` 的 `rebuildPairs` 返回常量 1 | ⑥ 红（比值偏离节点比） |
  | M4 | 把 ⑥ 的容许区间改成恒真 | M2 在 ⑥ 被关掉时应全绿 ⇒ 证明 ⑥ 是唯一红线 |

- **判死条件**：若 M2 咬不红 ⑥，撤回第 ⑥ 条与 `rebuildPairs` 字段，
  只保留评审稿订正与注释订正，**不造假判据上仓**。
- **回归**：`gate.php` 18/18 + `gate.php --fast` 15/15 + `docref.php` + `api-doc-check.php`。

## 7. 重裁触发条件

- `syntheticRoot()` 的每行节点数改变，或 `--scale` 两档行数改变 ⇒ `nodeRatio` 与阈值重定。
- `pairChecks` 语义扩展（例如把 layout 层配对也计入）⇒ 阈值与判据文字重定。
- 出现第三个需要「归一化结构自洽」的量具 ⇒ 把这段形状抽进 `tools/Support`，
  不要在每个探针里各写一份（同 `CheckItem` 的收口理由）。
- 若以后给 `--scale` 补上 settle 段的绝对耗时判据，需说明它与 ⑥ 的分工，
  避免又用代理量代替结构量。

## 8. 落地纪要

- **基线**：`--scale` **6/6**，⑥ = **1.00×**（500 档 20,020 次 / 3000 档 120,020 次，
  节点比 5.99×）；`--check` 6/6、`--pages --check` 27 页 6/6 读数不变；
  `docref` 134 ok / 0 FAIL；`php -l` 五个文件全过。
- **变异**：`build/mutation-71.py`（bytes 读写保 LF、串行、`finally` 恢复、日志
  `build/mutation-71.log`）。M1 删计数写点 ⇒ ⑥ 红（0.00×）、①–⑤ 绿；
  M2 平方扫描 ⇒ ⑥ 红（**5.81×**）、①–⑤ 绿；M3 `rebuildPairs` 返回常量 ⇒ ⑥ 红
  （0.17×）；M4 M2 + 关掉 ⑥ ⇒ **6/6 全绿** ⇒ ⑥ 是唯一红线。恢复后 1.00×、EXIT=0。
- **产品代码零改动**：`src/Widget/Element.php` 的变异已恢复，`git diff` 为空；
  `RenderObject.php` / `RecordingCanvas.php` 只改注释。
- **T2 通过**：`bash probes/frame_tax_aot/build.sh`（385 文件 / 4m59s）编译链接成功，
  `aot-equivalence.php` **8 项全过**（27 页 × 2 通道、2253 节点 / 9207 条命令逐值相同）
  ⇒ 新增 `rebuildPairs` 在 tpc 下编得过、几何与绘制无差异。
- **诚实边界**：⑥ 与 10-27 的 smoke 断言同源（都读 `Pipeline::pairChecks()`），
  不是独立第三方实现；M1 证明写点被删会红，但「量具本身写错」仍需 10-27 那组
  语义等价变异兜底。⑥ 的阈值只在当前两档与树形状下成立，重裁条件见 §7。
