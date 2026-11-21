# 帧成本归因 · 2026-11-21

> 目的：盒复用（`updateRenderObject()`）落地后，重新分配"下一步该改什么"。
> 所有数字都可复现，脚本在 `.workbuddy-ai/tmp/measure_*.php`（不入库）。
> 本文是**量测报告**，不含方案设计；方案见 `ARCH-REDESIGN-2026-11-21.md`。
> §0～§8 是当时的快照；§9 是 settle 两条优化落地后的修正（含口径变更与逐项归因）。

---

## 0. 结论

| 项 | 占比（TablePage 510 元素，一次交互） | 判断 |
|---|---|---|
| settle（重建 + diff） | **71%**（5.2ms） | 当前最大，且是**下一个**该拆的对象 |
| paint（遍历 + 录绘制命令） | **29%**（2.0ms） | 纯框架问题，机制已有但需手开 |
| layout（重排） | **0.5%**（37µs） | 已收尾，再投入边际收益≈0 |

settle 的 5.2ms ≈ **510 元素 × 12µs**，而这 12µs 可以拆成三块：
`setMods()` 3.5~4.5µs、控件判等（Text）6.9µs、框架骨架 ~2µs。

⇒ 下一步的杠杆不在 layout，在 **`setMods()` 的重复全字段比较** 与 **`Text` 每帧重新解析样式**。

---

## 1. 口径

- **解释器** PHP（不是 AOT），1200×800，DPR 1.0，页面来自 `ShowcasePages`。
- 量的是**单帧**：把最深 State 标脏一次（= 用户在页面里点一下 / 改一个字段），推一帧。
- `SurfaceProfile` 的三段：`settle` / `layout` / `paint`，与 `Frame::run()` 的分段一致。
- **`paint` 不含光栅化**：`SurfaceProbe` 帧内只录 `DisplayList`，`endFrame` 不回放。
  真机走 Skia 时还会叠加光栅化 ⇒ 真机的 paint 占比只会比这里更高。
- 采样取中位、前两发丢弃；同一数字跨进程抖 ±15%，所以只读量级与比例。
- 对照实验：临时让 `RenderBox::isConfigurationFree()` 与四个控件的
  `updateRenderObject()` 返回 `false`（退化成"每次造新盒"），量完恢复。

---

## 2. 整体构成

TablePage，一次交互：

| | settle | layout | paint | 合计 |
|---|---|---|---|---|
| 优化前（每次造新盒） | 7.4ms | **1.60ms** | 2.0ms | **11.0ms** |
| 优化后（配置判等复用） | 5.2ms | **0.04ms** | 2.0ms | **7.3ms** |

另两页（同口径）：BigList 1.09ms（settle 526 / layout 26 / paint 541），
Slider 0.61ms（412 / 35 / 167）。

**收益与页面规模强相关**：SliderPage（32 元素）优化前后是 691µs → 631µs，
在噪声内。它本来就只有 30 次重排。吃到大头的是 500 元素级的页面。

---

## 3. layout：已收尾

| 页 | 元素 | 重排次数（前 → 后） | layout 耗时（前 → 后） |
|---|---|---|---|
| Table | 510 | 495 → **4** | 1600µs → **37µs** |
| BigList | 139 | 47 → **4** | 157µs → **26µs** |
| Form | 108 | 7 → 6 | 82µs → 151µs（噪声） |
| Slider | 32 | 2 → 2 | 14µs → 35µs（噪声） |

机制已经建立：`needsLayout` 标记 + `layout()` 的"约束相等且未脏 ⇒ 直接返回"快速路径。
剩下的 4 次是"从根到脏叶子"的那条路径，**这就是理论下界**，再压只能靠改帧结构，不值得。

一个反直觉的结论留在这里：**合并三棵树不会减少这 4 次**。重排的代价是
"路径长度 × 每盒 `performLayout`"，与树的数量无关。

---

## 4. settle 拆解

### 4.1 合成基准（500 元素的 `Column`，update 到等价新树）

| 形状 | update 总 | 每元素 |
|---|---|---|
| `SizedBox`（零字段盒，直接复用） | 2995µs | **5.99µs** |
| `Text` | 6114µs | **12.23µs** |
| `Text + padding` | 6519µs | 13.04µs |
| `Padding(Text)`（250 组 / 500 元素） | 5627µs | 11.25µs（两层） |

三条读数：

1. **哪怕最简单的盒也要 6µs/元素** —— 这是框架骨架的下限；
2. **`Text` 比 `SizedBox` 贵一倍**（+6µs），差距全在文本自己的判等；
3. **带不带修饰几乎不影响**（12.23 → 13.04），说明 `Modifier::equals()` 本身不贵。

### 4.2 三段（在 `syncBox()` 内插桩，6513 次调用累计折算）

| 形状 | `updateRenderObject()` | `setCursor` + 默认样式派生 | `setMods()` |
|---|---|---|---|
| `SizedBox` | 0.87µs | 0.43µs | **3.55µs** |
| `Text` | **6.93µs** | 0.55µs | 4.04µs |
| `Text + padding` | 6.65µs | 0.54µs | 4.24µs |
| `Padding(Text)` | 3.71µs | 0.89µs | 4.55µs |

### 4.3 交叉验证

510 元素 × 12.2µs = 6.2ms，与 `measure_settle` 实测的 6.35ms、`measure_interaction`
的 settle 段 5.2ms 吻合 ⇒ 合成基准没有偏。

### 4.4 三个成本项

**① `setMods()` 3.5~4.5µs/元素/次** —— 最大单项。
它对每个元素每次 update 都做两次全字段比较（`geometryEquals` 19 项 + `equals` 9 项），
再加一串落值（`applyCompose` / `setRepaintBoundary` / `setFlexIntent` / `setStackIntent` /
`applyTransform`）。而绝大多数控件的修饰**这一帧根本没变**。
→ 候选：空 `Modifier` 共享单例 + `$mods === $this->mods` 身份短路（O(1) 跳过全部比较）。
可行性已核：`Modifier` 的写方法全部 `clone` 后改副本，唯一原地赋值在构造函数里。

**② `Text` 判等 6.9µs** —— 因为它每帧都 `resolveStyle()`：重新造 `TextStyle` / `Color` /
`Scale` 三个值对象，再逐字段比。
→ 候选：改为比较**控件侧的原始声明**（role / explicitSize / explicitBold / color token）
加主题身份，不做解析。等价性由"派生函数是纯函数"保证。

**③ 骨架 ~2µs** —— `update()` → `seal()` → `bumpContent()` → `visitChildren` →
`effectiveBox()`。属结构性开销，单看可接受；但注意它按**元素数**计费，
所以"页面拆段"对它同样有效。

---

## 5. paint：全树无条件

`RenderBox::paintComposed()` 无差别下钻，每帧重录整棵树的绘制命令。
`LayerCache`（`repaintBoundary`）能整棵子树重放，但它是**手动**的：
`showcase` 34 页里开了 **0 处**。

⇒ 现在"改一个字段"的帧里，layout 只跑 4 个盒，paint 仍然跑 510 个。

两条约束决定了这一步不能照抄 Flutter：
- 开得太细（每个文本一层）比直接画还慢 —— 层缓存的收益要求子树足够大；
- 边界必须**自动**判定，否则又变成"开发者要记住的第 8 条规则"。

---

## 6. 对业务开发的判据

1. **页面可以放心做大**。单字段改动只重排脏路径（510 元素页 = 4 个盒），不再需要为了
   性能把状态下沉到小组件、手工拆重绘边界。
2. **优化是 opt-in**。自定义控件不实现 `updateRenderObject()` 就退回"每次造新盒"，
   正确但慢，不会静默错；新增控件没有额外负担。
3. **交互态不再丢**。复用路径不走 `inheritRuntimeState()`，拖滑杆、悬停按钮不会因为
   "盒换了"把悬停态清零。
4. **小页面没有体感**。32 元素的页面优化前后都在 0.6ms 量级，测不出来；这套优化的
   价值门槛大约在 200 元素以上。

---

## 7. 下一步优先级

| 动作 | 预计收益（TablePage 一次交互） | 风险 |
|---|---|---|
| `setMods()` 身份短路（空 Modifier 单例） | settle ≈ −1.8ms（估） | 低：`Modifier` 已是纯值对象 |
| `Text` 判等不解析样式 | settle ≈ −2.0ms（估，仅文本多的页） | 中：要保证派生与原路径同源 |
| 自动重绘边界 | paint 减 1ms 级（估） | 高：判定"子树内容没变"是新增的状态机 |
| `RenderText` 就地改内容 | 排序场景现为 76 新盒 / 332 重排，做完预计降到 ~10 次重排 | 中：`paragraph` 必须重新度量 |
| showcase 的 `Section` 拆分 | settle 5.2ms → ~1ms，同时省 18 页外壳 | 低（业务侧）；需框架加 `PageBody` |

**顺序建议**：先做前两条（纯框架、收益确定、不改公共 API），再评估自动重绘边界；
`Section` 拆分可以与框架优化并行——它把 settle 的成本从"整页"切到"一段"，
对前两条的收益是**乘数**而不是替代。

★ 前两条已落地，实测 settle −1.98ms（不是估的 −3.8ms），归因与修正见 §9。

---

## 8. 复现

```bash
php .workbuddy-ai/tmp/measure_boxes.php        # 新盒数 / 重排次数
php .workbuddy-ai/tmp/measure_relayout.php     # 单 State 脏后的重排比例
php .workbuddy-ai/tmp/measure_interaction.php  # 一次交互的 settle/layout/paint
php .workbuddy-ai/tmp/measure_phases.php       # FrameTaxMeasure 口径的三段
php .workbuddy-ai/tmp/measure_settle.php       # build 与 update 的耗时构成
php .workbuddy-ai/tmp/measure_diff.php         # 按盒形状分档的每元素 diff 成本
php .workbuddy-ai/tmp/measure_sort.php         # 排序（文本几乎全变）场景
```

对照实验（关掉复用）的做法：把 `RenderProxy::isConfigurationFree()` 与 `Text` /
`Flex` / `Stack` / `Slider` 的 `updateRenderObject()` 临时改成返回 `false`，量完恢复。
`measure_diff.php` 的分段数字需要在 `Element::syncBox()` 里临时插桩，仓库里不应留。

---

## 9. 落地结果：settle 的两条优化

§7 的前两条已实现。**先修正口径**：§1～§8 的数字是"单帧采样"，跨进程抖 ±25%；
现在 `measure_interaction.php` 每页推 20 帧、每帧重新标脏同一 State、各相位取中位。
两组数字**不可直接相减** —— 下面是对照实验（同一脚本，只开关那两条优化）。

### 9.1 对照（中位，1200×800）

| 页 | 元素 | settle 前 → 后 | 整帧 前 → 后 |
|---|---|---|---|
| Table | 510 | 4.98ms → **3.00ms**（−40%） | 7.00ms → **4.85ms**（−31%） |
| BigList | 139 | 0.53ms → **0.32ms**（−40%） | 1.11ms → **0.88ms**（−21%） |
| Form | 108 | 0.11ms → **0.079ms**（−29%） | 0.75ms → **0.62ms**（−17%） |
| Slider | 32 | 0.031ms → **0.024ms**（−24%） | 0.21ms → **0.17ms**（−17%） |

分段占比（Table）：settle 71% → **62%**，paint 27% → **36%**，layout 0.5% → 0.6%。

### 9.2 改了什么

1. `Modifier::diff()`：一趟比较同时给出"几何变了"与"只有绘制变了"；几何一变立即返回
   （重排必然重画，绘制维不必再比）。
2. `Widget` 的默认修饰改用共享实例 `Modifier::empty()`；`RenderBox::setMods()` 见到同一
   实例整段跳过（投影是纯函数）。跳过**不**还原 `setCompose()` 写下的动画量。
3. `TextStyle::equals()` 从 `cacheKey()` 字符串比较改成逐字段（1.36µs → 0.13µs/次）；
   `with*()` 传入当前值返回 `$this`。
4. `ThemeData` 解析 role→`TextStyle` 与 token→`Color` 时**驻留**实例；`RenderText::matchesConfiguration()`
   先比身份再比值。
5. （复查）跳过放宽到**值等价**：控件每次 `build()` 都重造修饰链，实例几乎不会相同 ⇒
   只认同实例时，等值重建仍会把 `setCompose()` 写下的动画量打回声明值。现在
   `diff() === 0` 也整段跳过。同会话交替 A/B（3 对中位）：TablePage settle 3.32 → 3.04ms。
6. （复查）驻留改成**静态**：放在实例上会让"配置相同、缓存冷热不同"的两个 `ThemeData`
   判 `!=`，`AppScope::enter()` 的多窗口主题一致性检查因此误报。空 `BoxStyle` 也共享，
   `diff()` 靠身份跳过样式那 6 项比较（收益含在上一行的 A/B 里）。

### 9.3 逐项归因（TablePage settle，开关法）

| 项 | 省下 |
|---|---|
| `setMods()` 身份短路（508 盒里 188 个是空修饰） | 0.45ms |
| 文本侧（样式缓存 + 逐字段 `equals`） | 0.28ms |
| 其余（`diff()` 合并两趟 + 不再每帧造 `Modifier` / `BoxStyle` / `Color`） | 1.25ms |

★ 第三项比预估大：`new Modifier()` 里还藏着 `BoxStyle::none()`，`ThemeData::color()`
每次 `Color::fromRgb()` 还带四次分量校验 —— 这些是"每元素每帧"的固定税，共享实例
一次性消掉。**教训：估"某方法多贵"时只算了它的比较，没算它每帧新造的值对象。**

### 9.4 剩余热点

每元素 settle 从 12.2µs 降到 **5.9µs**（合成基准：零字段盒 4.4 / 文本 3.1 / 带修饰 6.2）。

| 项 | 现状 | 判断 |
|---|---|---|
| 框架骨架（`update` → `seal` → `visitChildren`） | ~2µs/元素 | 只能靠减少重建元素数（`Section` 拆段） |
| 带修饰盒的 `diff()`（508 里 320 个） | ~1.5µs/元素 | 26 个字段"确认相等"无法提前退出，已接近下限 |
| paint | 1.77ms / **36%** | 现在是第一大项，全树无条件录制 |
| `RenderText` 就地改内容（排序场景 76 新盒 / 332 重排） | 收益 0.15ms 级 | **不做**：要破"盒不可变"，性价比不成立 |

⇒ 下一步按"乘数"排序：`Section` 拆段（同时压骨架与 paint 的遍历面），再评估自动重绘边界。

