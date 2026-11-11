# 评审：Drift3 蓝图 21 · 核心渲染架构推荐（基于 Drift2 当前 HEAD）

> 2026-11-08 · 评审对象：`D:/TypePHP/Drift3/docs/blueprint/21-核心渲染架构推荐.md`（433 行）
> 评审基准：`D:/TypePHP/Drift2` HEAD `1bd8918`（§70），gate 18/18。
> **方法：对代码，不对分析稿。** 文档引用的 Drift2 事实逐条回查当前源码与本仓实测裁决；
> 评审当时未跑 Drift3 探针、未改任何一仓代码、不代 Drift3 拍板。
>
> **2026-11-08 订正**：§2 #10 与 §6 的「`pairChecks` 是唯一真缺口」判错了 —— 本仓早已
> 落地，是评审自身漏查 `tools/smoke.php` / `src/Core/Pipeline.php` 所致。订正痕迹保留在
> §0 / #10 / §6 / §8，不静默改写历史判断；本文对 Drift3 的其余结论不变。

## 0. 结论先行

**这是一份结构质量不错、但证据底座已经过期的建议稿。**

1. **形式值得肯定**：把「建议 ≠ 已裁决」写在头部、把四个分叉显式化并留待拍板清单、
   三类数值分级、§11 如实承认「⑤–⑨ 一行真实代码都没有 / 接口形状均未编译验证 /
   任何性能承诺不成立」——这些纪律比多数设计册强。
2. **但它的立论是「Drift2 实测坑」的排除法**，而这张坑表大部分来自 2026-10-23 的快照。
   对当前代码逐条核查：**13 条里 1 条属实、5 条半对、6 条已过时、1 条与本仓实测裁决相反**
   （#10 于 2026-11-08 由「属实」订正为「半对」，见 §8）。
   尤其三条立论前提（三树代价放大 / 两份「变没变」记录会漂 / 增量布局是生死线）
   在 Drift2 当前代码或实测裁决面前**不成立**。
3. ⇒ 四个分叉的**推荐方向未必错**（那是 Drift3 自己的裁决范围），但**推荐理由需要重做**；
   其中分叉 B 推荐的「帧末统一清脏位」恰好在退回 Drift2 已排除过的布尔判据。
   对 Drift2 而言，全文**没有新的具体能力**值得拿回：被点名的 `pairChecks` 本仓早已落地
   （2026-11-08 订正，见 §6 / §8），只有 2 条纪律可对齐。

| 维度 | 评分 | 一句话 |
| --- | --- | --- |
| 文档结构 / 裁决纪律 | 良 | 分叉显式化、待拍板、诚实边界都到位 |
| 对 Drift2 的事实准确性 | **差** | 坑表停在 10-23，11-03 之后的演进完全没进视野 |
| 结构性论证 | 中 | 单树的收益用「没有实测」自我克制了，但 Element 层职责被低估 |
| 对 Drift2 的可迁移价值 | 低 | 无新能力可迁移；原判「`pairChecks` 唯一真缺」有误（§6 订正） |

## 1. 最核心的问题：证据时效

文档 §0 声明三条证据线，其中 ① 是「`D:/TypePHP/Drift2` 源码 + `docs/analysis/12,15,16,17,18,20,21,23`」。
实际时间戳：

- `docs/analysis/17-Drift2-渲染与布局层-深挖.md`、`18-...状态控件文本层...md`：**2026-10-23 22:26**
- `docs/analysis/21-成熟GUI分析与Drift2重构路线图.md`：**2026-11-03 11:22**
- Drift2 之后的关键演进：`docs/DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md`（11-04，
  12k 实测裁决不立项增量布局）、11-05 交互反馈、11-06 焦点/语义、
  11-06~11-07 连推 §61–§70（表格多键排序 / golden 全覆盖 / 焦点通道 / 语义状态 /
  表格行选中 / AOT 启动 Fatal 修复 / 异步欠账 / `Equatable` / UIA 事件判据）。

文档 §10 只承认「`analysis/12`（11-03 v3）与 `15–20`（10-23）对 `RecordingCanvas`
是否生产入口结论相反」，**没有意识到 Drift2 在 11-03 之后仍在高速演进**，
于是把一份 16 天前的坑表当成当前基线来推 A2/B/C/D。

⇒ 这是方法论问题，不是某几条引文写错：文档一边写「Drift2 只作灵感与排坑、不作基线」，
一边用 Drift2 的旧坑做**排除法证据**。排除法对证据新鲜度的要求比「灵感」高得多。

## 2. 逐条事实核查（13 条）

| # | 文档断言（§2.1 为主） | 当前 Drift2 事实 | 判定 |
| --- | --- | --- | --- |
| 1 | `docs/_archive/DESIGN-v2-2026-10-14.md:210-217` 三树 Widget→Element→RenderObject | `docs/_archive/DESIGN-v2-2026-10-14.md` §3.2 仍是这个形状 | ✅ 属实 |
| 2 | `Element.php:504` reconcile 两阶段 O(k) | `src/Widget/Element.php:504-553`：前缀同步 + `(class,key)` 建桶 | ✅ 属实 |
| 3 | **Render 层没有脏边界、`relayout()` 永远全树重排** | `src/Layout/RenderObject.php:17` 的注释**仍在**；但全树 layout 是 11-04 的**主动裁决**（见 #7），且脏边界已另有实现：`Pipeline` 四个单调序号、`markPaintChanged()`、`LayerCache` 的 `RepaintBoundary`。注释腐烂 ≠ 实现没有 | ⚠️ 半对（引文真、结论过时） |
| 4 | **零脏空闲帧也全额支付 3 次全树遍历、每帧白分配 4MB** | `src/App/Frame.php:113-125` `needsSettle()` 三条序号都没动 ⇒ **整段跳过（含浮层第二棵树）**；`Frame.php:277-303` paint 另有五条门控，`run()` 直接 `return false`，注释写「屏幕是上一帧内容，是设计意图」 | ❌ 已修 |
| 5 | **`settle` 与 `layout` 对「变没变」是两份记录，迟早漂** | 记账是**一份** `src/Core/Pipeline.php`（`structureVersion` / `markedCount` / `layoutEpoch` / `paintEpoch`），Frame 只持上一帧的序号快照。`Frame.php:70-76` 原话：「不另设 `hasPainted`…两份记录迟早漂」——**本仓早就踩过并解决了** | ❌ 前提被证伪 |
| 6 | `RecordingCanvas` 生产路径**不录** | 真机 `Frame.php:307` 是 `$box->paintComposed($canvas)` 直接画 SkiaCanvas；但 `src/Rendering/LayerCache.php:52-85` 在**生产路径**录制 `DisplayList` 并 replay。是有意的双路径，不是「录制没接生产」。另：`src/Rendering/RecordingCanvas.php` 类 docblock 自称「生产路径的入口」，与同文件 `:38` 的注释自相矛盾 —— 这是 Drift2 自己待订正的注释 | ⚠️ 半对（且双方文档都腐烂） |
| 7 | `DisplayList::replay` 15 路 if/elseif、**不是可合批 IR** | if/elseif 属实（`src/Rendering/DisplayList.php:189-235`）；但录制侧是定长 `SLOTS` packed buffer + 游标，`src/Rendering/CanvasCapabilities.php` 有能力位分档、`RasterCanvas::unsupportedOp()` 响亮失败 ⇒ **已是 IR 雏形 + 能力协商雏形**。「不是 IR」过强，准确说法是「未合批、未做后端能力协商的正式矩阵」 | ⚠️ 半对 |
| 8 | paint 期每 op 一次桥调用、present 跨界拷贝占 e2e 50%+ | 文档自己括号承认「Skia 路径已改零 swizzle 直贴」 | ❌ 已过时 |
| 9 | 缓存无上限无淘汰、单调涨 | `LayerCache` 按 `epoch + w/h` 命中、`invalidate()` 失效，且每盒一份、盒卸载即释放；不是全局无上限缓存 | ⚠️ 半对（至少层缓存这块已修） |
| 10 | `Each` 1000 行 O(n·m)，修回 O(k) 后**重构改回 O(n²) 行为测试全绿** | 事故仍成立；但「本仓没有结构计数」**是错的**：`src/Core/Pipeline.php` 有 `pairChecks()` 与唯一写点，`src/Widget/Element.php` 的 `updateChildren()` 两处调用，`tools/smoke.php` 有 200 子精确断言（200 vs 旧实现 20,100），10-27 战史还有 5 组变异全红。真实但更窄的缺口是 `tools/frame-tax-probe.php --scale` 的 5 条判据全是 layout/paint 耗时，而 smoke 是 gate 慢项 ⇒ §71 补了第 ⑥ 条 | ⚠️ **原判半错**（2026-11-08 订正） |
| 11 | **增量布局是生死线**（`research/14 §6`）⇒ 明令禁止全树重排 | `docs/DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md` §0：12,003 节点 / 全量重排 49.8ms / 27 个真实页复核三条触发条件均未成立 / AOT 口径余量 4.8× ⇒ **裁决「不立项增量布局」，先做尺子**。同文 §3 明确点名「Raven『必须补增量布局』是**结论不是证据**」 | ⛔ 与本仓实测裁决相反 |
| 12 | AOT 去虚化 ⇒ 必须 `int kind` + 穷举 `match` | 去虚化属实；但 Drift2 用 `RenderBox::layout()` 声明 `final` + 子类实现抽象 `performLayout()`（`src/Layout/RenderBox.php:20,381`）**保住了继承分层**，没有改成 kind 分派 | ⚠️ 约束真、推论过强 |
| 13 | 三树 = 每帧全量 build 描述树、AOT 帧内不分配撑不住 | `State::build()` 只在 `setState()` 跑，`EqualGate` 同值不重建，`needsSettle()` 门控；不是每帧全量 build | ❌ 已过时 |

补充两处字段级问题（文档内部自相矛盾，非 Drift2 事实）：

- §2.1 自己引用 `Key.php`「类型 + 显式 key，`1` 与 `'1'` 不等」，§4.3 的 `Node` 却写
  **`int key`** ⇒ 字符串 Key 与类型区分直接丢失。Drift2 `Element::slotKey()` 用「带类型前缀 +
  字符串带长度前缀」的编码，正是为了防 `'i5'` 与 `5` 撞桶。
- §1.2 把 `Cell` 冻结为「状态原语，属渲染层 M2」；Drift2 `docs/_archive/DESIGN-v2-2026-10-14.md:1527-1530` 与 §69 方案
  已把 `Cell` / 自动依赖收集 / `Computed` / `Bound` / Subject 自动广播**判死**（`MEMORY.md` §60
  原话「没有 `Cell` 了」）。Drift3 当然可以有自己的 `Cell`，但**不能从 Drift2 借证据**。

## 3. 四个分叉重评

### 3.1 分叉 A（三树 vs 单树）：推荐理由被同一工具链的实测反证

文档克制地写了「不是『单树更快』（没有本项目实测，不得这样论证）」，这点对。
但它的结构性论证是「AOT 四条硬约束同时成立 ⇒ 三套对象与每帧描述重建代价最高」。
**Drift2 用的是同一个 tpc / AOT 工具链，三树已跑到 12k 节点、AOT 等价 27 页、gate 18/18**。
若那四条硬约束真让三树不可行，这个交付状态不该存在。⇒ 前提至少需要用 Drift3 自己的
`--dry` 与真机数字重测，不能拿 Drift2 的旧坑代证。

被低估的是 **Element 层的职责面**：State 宿主、`(class,key)` 复用、`Element::syncBox()`
唯一换盒点、焦点通道宿主（§64）、语义节点与选中事件（§65/§66）、`Offstage` 保活、
Overlay 锚点重建检测。把这些压进「一套 Node + 三种角色」，复杂度不是消失，而是从
对象边界挪进 `int kind` + flags + 穷举 `match` 的分支里 —— 而 §1.2 自己把 `Cell`
划给 M2，等于承认状态宿主与几何绘制**本来就得共存**。
建议 Drift3 若推 A2，先补一张「Element 现有职责 → Node 字段/match 分支」的映射表，
否则 A2 的「无三角引用」收益无法与迁移成本对账。

顺带：A2 用 `int parent` / `packed int children` 把三角引用变成整数索引、避开无 GC 下的
悬挂对象，**这是真实优点，应保留**，与上面 criticism 不冲突。

### 3.2 分叉 B（唯一 DirtySet + 帧末清位）：★ 正在退回本仓已排除的判据

文档立论引 `12 §5 #42`「两份记录迟早漂」，推荐 `DirtySet` 三档位掩码 +
「⑨ commit 原子切换后**统一清位**」。对当前代码有两个硬问题：

1. **清位 = 布尔判据的变体。** `src/App/Frame.php:39-45` 原文：
   「用只增不减的序号而非全局 `pendingDirty()`：后者会漂移（**已卸载的脏 State 永远 >0**），
   拿它判『要不要 settle』会退化成永不跳过」。
   Drift2 的解法恰恰是**不清位**：`Pipeline` 单调序号 + Frame 持上一帧快照做比较。
   `DirtySet.structCount` 归零同样要回答「帧外 / ⑩ defer / 已卸载节点标脏」落在哪一帧，
   文档 §4.4 没给这个竞态的形状。
2. **三档脏位缺「树外状态」通道。** `src/App/Frame.php:82-88` 的 `lastOverlayIds` 是
   被真机静默 bug 逼出来的第四条信号：浮层栈不属主树，`structureVersion` / `boxEpoch` /
   `paintEpoch` 三条都不动 ⇒ 关模态那一帧会被整段跳过、屏幕永久残留遮罩。
   `DirtySet` 只有 STRUCT/LAYOUT/PAINT，没有这类「树外可见性」位置；
   §4.3 三张结构里也没有它的字段。

⇒ 建议 Drift3 把 B 的推荐从「清空计数」改成「单调序号 + 上一帧快照」，并显式列出
树外状态通道。这一条是本文最该改的。

### 3.3 分叉 C（DrawOp 真录 + 一帧一次跨界）：方向对，但对 Drift2 的诊断半错

「可合批 IR 必须真录」作为 Drift3 的 greenfield 理由（C3）成立，不必反对。
但对 Drift2 的判断要收窄：`DisplayList` 已有定长槽 packed buffer + 游标，
`CanvasCapabilities` / `supportedCapabilities()` / `unsupportedOp()` 已有能力分档与
响亮失败——**这正是 §3.3 想要的 `SupportLevel` 三态的雏形**。
而「生产路径不录」是对双路径的误读：真机主树直接画 Skia、层缓存走录制重放，
两者是**同一份命令流**（`LayerCache.php:15-20` 注释明确「为什么不是双轨」）。
⇒ 文档若拿这条当「Drift2 没有 IR」的证据，会把自己 C3 的必要性论证建立在半错的前提上。

### 3.4 分叉 D（commit 原子 / present 一次）：方向正确，但等价物已在用

`src/App/Frame.php:149-151`：「收敛完才 paint ⇒ 不存在『先画错的版式、下一帧再跳一下』的
中间态（commit 原子）」；布局约束反馈最多 4 轮同帧收敛、`LayoutSelfCheck` 守纯函数、
paint 五条门控、`endFrame()` 前 `afterPaint` —— Drift2 用「同帧收敛 + 单次 endFrame」
实现了 §3.4 想要的「无撕裂、帧中间态不可读」，没有 React 双缓存。
⇒ 文档 §9 第 3 项「是否复用 `Frame::run` 接口形状」值得改成引用这个**语义**，
而不只是「段位顺序」。另有一处未答：present 抛异常时 ⑨ commit 不执行、脏位是否保留？
清位方案需要给出与异常路径的顺序约定，序号方案则天然无此问题。

## 4. AOT 硬边界（§1.4）：约束属实，推论过强

- 「虚方法去虚化静默走基类」真；Drift2 的对策是 `final` 模板方法 + 抽象 `performLayout()`，
  不是取消继承。§4.3 的 `int kind` 是一条路，不是唯一路。
- 「`?T` / `mixed` 退化 `Var`」真；但 `src/Layout/RenderObject.php:24` 就有
  `protected ?RenderObject $parent = null`，`private readonly DisplayList $list` 也在用。
  准确表述是「能编译，但有跨实例读 private / 可空链静默变 null 的语义坑」（`MEMORY.md` §22），
  不是「不能用」。同理 `readonly` 慢 10× 需要本项目实测，本仓 12k 尺子没测过这一项。
- 「无自定义注解 / codegen 扩展点」——Drift2 用的是 tpc **内建**编译期注解
  （`#[\Getter]` / `#[\With]` / `#[\Setter]` / `#[\Constructor]`），与「用户自定义注解处理器」
  不是一回事；文档由此推出「不能靠注解自动装配节点」这条链需要限定范围。
- 「每帧全量新建描述树」不成立（见 §2 #13）。

## 5. 文档值得肯定的五点（别一棍子打死）

1. 头部把「建议 ≠ 已裁决」「不新增裁决编号」「事实源一字未改」写死，避免建议稿悄悄变成基线。
2. 四个分叉显式化 + §9 六项待拍板，比直接给一套「最终架构」可裁决得多。
3. 三类数值分级（实测 / 设计参数 / 借鉴值）与「不得从借鉴值反推」——与本仓 §68 吸收的
   `@design-param` 纪律同源，且明确点名 ArkUI / Raven / GPUI 的数字只是口径示例。
4. §11 诚实边界：零代码、未编译验证、未跑新探针、性能承诺不成立，一句不含糊。
5. R7「自动机制必须有唯一真实触发点、可 dump」、软光栅↔后端逐像素 golden、
   `verifyLayout()` 增量==全量自校验 —— 三条判据纪律都对。

## 6. 对 Drift2 的可迁移价值（按收益排序）

| 项 | 结论 | 理由 |
| --- | --- | --- |
| ~~`pairChecks` 结构计数~~ | **订正：本仓已有，不拿回** | 2026-11-08 回查发现 `src/Core/Pipeline.php`（计数 + 唯一写点）、`src/Widget/Element.php`（两处调用）、`tools/smoke.php`（200 子精确断言）已闭环，10-27 战史有 5 组变异全红。原判「唯一真缺」是评审漏查所致 |
| **12k 档的调和复杂度判据** | **§71 已补，但不是从 Drift3 拿回** | `--scale` 5 条全是 layout/paint 耗时；实测 `updateChildren()` 退回平方扫描时 5/5 全绿、总耗时 +87%。`FrameTaxMeasure` 导出 `rebuildPairs`，`--scale` 加第 ⑥ 条归一化判据，4 组变异全咬红 |
| 「唯一真实触发点 / 可 dump」 | 已在做，可对齐命名 | §69 刚修过 `SelectPurity` 假绿；`FrameStats` 式的统一导出面可借鉴，不要另造第二套 |
| 分叉显式化的文档形态 | 可借（零代码） | 本仓 DEBT「下一棒」反复腐烂（`MEMORY.md` §96），把「待拍板项 + 触发条件」写进方案比写进 DEBT 尾部稳 |
| 单树 / 增量布局 / 清位式 DirtySet / 重造 DrawOp / `Cell` | **不借** | 前三项本仓已有裁决或实测反证；DrawOp 已有 IR 雏形；`Cell` 已判死 |

**判死条件（§71 已满足）**：新判据必须先证明「存在一组变异能让它报红」，否则又是一条
恒 0 的空壳判据（`MEMORY.md` §83 变异四律）。§71 用 M1–M4 四组真变异验证：删计数写点 /
平方扫描 / `rebuildPairs` 返回常量 ⇒ ⑥ 红且 ①–⑤ 绿；关掉 ⑥ 后同一平方变异 **6/6 全绿**，
证明 ⑥ 是那条唯一的红线。

## 7. 给 Drift3 的修订建议（可操作）

1. 把证据快照刷新到 11-08，或把全部 Drift2 引用显式降级为「历史反例，重跑后才可作排除法证据」；
   §10 的「历史快照差异」小节要补上 11-03 之后的演进。
2. §3.2 把「⑨ 统一清位」改成「单调序号 + 上一帧快照」，并补树外状态通道（浮层 / Overlay 类）。
3. §4.3 `int key` 改成带类型前缀的编码，与 §2.1 自己引用的 Key 规则对齐。
4. §3.3 先复核 `src/Rendering/DisplayList.php` / `LayerCache.php` / `CanvasCapabilities.php`
   再断言「不是可合批 IR」；把 C3 的必要性论证放在「未合批」上，不放在「没有 IR」上。
5. §3.1 若推 A2，补 Element 职责映射表（State / Key / 换盒 / 焦点 / 语义 / Offstage / Overlay）；
   并解释「同一 tpc 下 Drift2 三树已 12k 节点可用」这个反证。
6. §3.2 / §6 引用 `research/14 §6`「增量布局是生死线」时，请连同
   `docs/DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md` §3「结论不是证据」一起引 ——
   本仓已用 12k 三数与 27 个真实页把这条结论挡回去了。
7. §9 第 3 项从「借不借接口形状」改成「借不借同帧收敛 + 单次 endFrame 的 commit 语义」。

## 8. 诚实边界

- 本评审**未改任何一仓代码**，未跑 Drift3 探针 / `tpc --dry` / 真机性能测量；
  结论只用于「Drift2 当前 HEAD `1bd8918` 的事实核查」与「Drift3 文档论证质量」。
- 不替 Drift3 拍板四个分叉：A2 / B / C / D 是否采纳取决于 Drift3 自己的 AOT 实测，
  本文只指出**现有论证里哪些前提在当前 Drift2 代码面前不成立**。
- 「Drift2 当前实现更好」不是本文结论：#3 的全树 layout 仍是真实成本，11-04 裁决只说明
  **在现有分母下没到该做的线**，且该裁决自带「不是 AOT 生产结论」的边界。
- `src/Layout/RenderObject.php` 与 `src/Rendering/RecordingCanvas.php` 的两处注释腐烂已在 §71
  订正：前者「Render 层没有脏边界」与 `markPaintChanged()` / `LayerCache` 矛盾；后者
  「生产路径的入口」与同文件构造器注释、以及 `LayerCache` 才是真实生产消费者这一点矛盾。
- **本稿自身的证据缺口（2026-11-08 订正）**：§2 #10 与 §6 原判「`pairChecks` 是唯一真缺」
  是错的。核查时只 grep 了 `tools/frame-tax*` 与 `tools/Bench/`，**漏查 `tools/smoke.php`
  与 `src/Core/Pipeline.php`**。这与本文 §1 批评 Drift3「用过期的坑表做排除法」是同一类
  方法论错误 —— 排除法证据要求覆盖全域，不能只查自己预期的那几个文件。
  订正后对 Drift2 的收益从「1 件能力」降为「0 件」；§71 补的是**本仓自己的** 12k 档缺口，
  不是从 Drift3 迁移。
