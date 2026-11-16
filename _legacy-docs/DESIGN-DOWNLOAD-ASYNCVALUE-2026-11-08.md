# 下载段收成 `AsyncValue` + 修「整体失败后永远转圈」（§75）

> 方案，非实施记录。基准 HEAD `dd864b3`（§74）。计划来源
> `docs/PLAN-NEXT-STEPS-2026-11-08.md` §75 与分析稿 §7.1。

## 0. 结论

按计划做 §75，但**改形状**，并与体检新发现的真缺口合并成一棒：

1. 下载段五个散字段 → 一个不可变 `AsyncValue`；
2. `ResultCard` 走 `fold()` 四支，**`error` 支第一次有消费者** —— 修掉
   「整条下载抛异常后，每个 URL 仍画『正在下载结果图…』转圈且失败文案不可见」。

两件事是同一处改动：真缺口之所以存在，正是因为「整体失败」没有独立的一态。

## 1. 体检：不照抄计划

| 计划写的 | 代码真相 | 结论 |
|---|---|---|
| 五个字段收成一个值 | `localPaths` / `failedDownloads` / `downloadFraction` / `downloadMessage` 是字段；`isDownloading()` 是 `phase` 派生，不是字段 | 成立，实际是 4 字段 + 1 派生 |
| `imagesSection()` 手写四态分支走 `fold()` | 它是**按服务端顺序逐 URL 三分支** + 顶部进度条，且 5 个 getter 有 **8 处既有测试消费者**、`MessageValueGateTest` 还用反射直接写 `downloadMessage` 私有字段 | 药方与真实形状错配，直接照搬会丢「部分成功」可见性并打碎既有判据 |
| `AsyncValue` 已有 `IDENTITY_STABLE` 例外，select 门控通 | `AsyncValue` **不实现 `Equatable`**；`sameValue()` 对它是 `===` 身份比较，`IDENTITY_STABLE` 的意思是「同值复用同一实例」（`UsersPage.php:117-119` 明写） | 稳定态必须复用同一实例；只在状态真变化时赋值一次，不能每帧 new |
| 零 T2 | 只动 `apps/` 与 `tests/`，不碰 `src/`、不新增框架类 | 成立 |

**新发现的真缺口（`WatermarkStore.php` 741-748 + `ResultCard.php` 172-224）**：
整条 `await()` 抛异常时，`localPaths` / `failedDownloads` 都是空、`phase` 已归位
⇒ `isDownloading() === false`，而失败文案只在 `downloading === true` 时显示；
逐 URL 分支里既没有 local 也没有 failed ⇒ **每张图都画转圈，永远不结束**。
用户只看得到底部 `statusLine`，结果图区是一片假的「还在下」。

## 2. 裁决（五维）

- **结构**：真值从 4 个可变字段 → 1 个不可变值，消除「这五个字段要一起改、
  `notifyChanged()` 别漏」。`phase` 仍管流水线互斥（`canUpload/canSubmit`），
  下载段**展示态**归 `AsyncValue` 派生，两者职责分开（不是两份同义记录）。
- **实现**：零 `src/`、零新增框架类、零 T2。5 个 public getter **全部保留为投影**
  ⇒ 8 处既有测试原样复用，成为「收敛没改坏行为」的**独立判据**（它们读 getter，
  不读 `AsyncValue` 内部）。
- **扩展性**：载荷是 `['locals' => ..., 'failed' => ...]`，`urls` 仍留在切片里
  —— URL 属于「结果」，不属于「下载」，不硬塞进同一个值。
- **DX**：`fold()` 漏写一支当场抛（开发期），公开 getter 不变，`API-SURFACE.md` 不动。
- **维护性**：`error` 态第一次有消费者；取消（`AsyncCancelled` 穿透）时
  `runFlow()` finally 把停在 loading 的值归 `idle`，不再留一个永久转圈。

**成熟 GUI 边界（分析稿 §3 已核实）**：SwiftUI `AsyncImagePhase` / Coil
`AsyncImagePainter.State` / Flutter `AsyncSnapshot` 都是「四态 + 载荷」，但
**批量部分失败汇总、按服务端顺序**从来不由组件负责 ⇒ 裁决：部分失败仍走
`data` 态（载荷带 `failed`），`error` 只表示整条失败。生搬「四态四选一」
会让已下载成功的图在部分失败时整块消失。

**硬约束（写死）**：① 不新增 `AsyncCommand`（Flow 仍是单一命令，`Phase` 六态不动）；
② 进度仍由 `await()` 第二参喂，只是改写进 `withProgress()`；
③ `downloadMessage()` 仍返回 `StatusMessage`（`Equatable`），§69 的门控判据不失效。

## 3. 形状

```
WatermarkStore
  private AsyncValue $downloads = idle()          // 唯一真值
  downloadResults():
    loading() → withProgress(f) → data([locals,failed])->withProgress(1.0)
                                ↘ error(msg)      // 整条失败
  localPaths() / failedDownloads() / downloadProgress()
  isDownloading() / downloadMessage()            // 五个 getter 投影，签名不变
  runFlow() finally: 仍是 loading ⇒ idle()        // 取消穿透

ResultCard::imagesSection()
  select → ['urls' => …, 'downloads' => AsyncValue]
  fold(['idle'=>空, 'loading'=>进度条+逐 URL 转圈,
        'data'=>逐 URL 混合渲染, 'error'=>整块失败条])
```

`urls` 用 `use` 捕获进四支闭包；四支签名统一 `fn(AsyncValue $v): Widget`
（`fold()` 的 AOT 形状约束，见 `AsyncValue.php:226-227`）。

## 4. 判据与判死条件

**判据必须量最终 Widget 树里的文本**，不量 `fold()` 内部（反同源）。
新增 `ResultCard::imagesFor()`（`@internal` 纯函数，不建 Element 树），
测试用反射遍历 Widget 收集 `Text` 内容：

- 四态各有一组可区分文本；`error` 态**不含**「正在下载结果图…」且含失败文案；
- `data` 态部分成功时，已下载图与失败条**同时**出现（守住业务语义）；
- 既有 `DownloadProgressTest` / `StoreHostChainTest` / `PollRhythmTest` /
  `MessageValueGateTest` 全绿（getter 行为不变）。

**变异清单（`build/mutation-75.py`，实跑 4/4 真红）**：
M1 `error` 支退回逐 URL 转圈 ⇒ 整体失败判据红；
M2 `data` 支丢掉 `failed` ⇒ 部分失败判据红；
M3 `withProgress()` 不写回 ⇒ 既有进度判据红；
M4 `downloadMessage()` 投影恒 `none` ⇒ §68 进度文案 + §69 门控判据红。

★ **订正初稿**：原本 M4 写的是「取消时不归 `idle`」，但本棒没有取消态判据
（构造 `AsyncCancelled` 穿透需要逐趟 host）。按「不造假证据」纪律换成有真实
判据的投影变异；取消归位属防御性改动，登记为**未做独立变异**。
脚本 bytes 读写、串行、每轮 `finally` 恢复，恢复后 phpunit 绿、无残留。
改了没红先怀疑**变异打空**（§71 / §73 各踩过一次）。

## 5. 验证分层 / 诚实边界

- T0 `php -l`；T1 全量 gate 18/18 + `--fast` + `docref` + `lint --selftest`；
  **无 T2**（不碰壳层 / 跨进程 / 新增框架类）。
- 本棒**不减行数大头**：Widgets 1126 行 / Store 1057 行的主体是静态控件树与
  getter 样板（分析稿 §1 F/B 类）。减的是 A 类「漏写一支就界面空白 / 永久转圈」。
- `IDENTITY_STABLE` 靠「稳定态复用同一实例」，收敛后由 Store 保证；
  若将来有人在 getter 里每次 new，判据抓不住，需靠 lint 补（本棒不做）。
- 未跑真机上传 / 下载；改的是界面分支与 Store 内部表示，不是线上症状修复。
- `runFlow()` finally 的「取消归 idle」是防御性改动，**没有独立变异牙检**
  （见 §4 订正）；若下一棒补取消场景，应回来补 M4'。
- `downloadMessage()` 是投影（每次调用 new 一个 `StatusMessage`），靠
  `Equatable` 按值门控不额外重建；`MessageValueGateTest` 的反射写点已从
  `downloadMessage` 字段改到 `downloads`，判据性质不变（仍量重建次数）。
