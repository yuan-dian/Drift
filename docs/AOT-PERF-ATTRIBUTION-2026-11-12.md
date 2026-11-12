# AOT 减速归因与优化上界（Drift · tpc → C++17）

> 状态：**量化分析稿** · 2026-11-12 · 数据样本 `apps/users/build`（2026-11-11 18:04）
> 依赖：[`AOT-CONSTRAINTS.md`](./AOT-CONSTRAINTS.md) §2.5 / §2.6 / §2.7 / §9.4.1
> 定位：回答「AOT 比 PHP 解释执行慢，慢在哪、能拿回多少」。本文只做**静态站点普查 + 阶梯系数换算**，不修改编译器。

---

## 0. 摘要

1. 剩余动态分派只占调用点的 **8.4%**（1,125 / 13,348），#183/#184 已吃掉大头（1,593 → 1,128，−29.2%）。
2. 按 §2.7 阶梯系数，一次动态分派改为静态直调可省 **96.5 ns**。要单靠去虚拟化抹平 27 页 layout 的 **2.24×**，需要 **≈40 次动态调用/节点**；现实区间 10–20 次/节点只能回收 **25–50%**。
3. **更大的结构性问题是属性读**：6,167 个读点里只有 **502 个（8.1%）**走原生类型化读取，其余走属性表并返回 `php::Variant`。这一项**不依赖调用密度**，但需要 NativeStruct（XL）。
4. 低成本先行项：**IIFE 展开（1,332 处，热目录 780）**、`Canvas`/`Element` 的 3 路 class-id guard、`match` 字面量 int → 原生 `switch`。合计预期回收 **15–25%**。
5. 本文所有时间换算都依赖「每节点 k 次调用」假设；下一步用一次带计数器的构建把它替换成实测值（§7）。

---

## 1. 口径与样本

| 项 | 值 |
|---|---|
| 产物 | `apps/users/build/src`（355 个 `.cc`，338 个参与统计，148,760 行） |
| 编译器 | `D:/TypePHP/typephp/bin/tpc.php` @ `bff46481`（含 #183/#184/#185/#186） |
| 构建时间 | 2026-11-11 18:04（T2：`users.exe` 13.6 MB，EXIT=0，真机 5/5） |
| 对照 | `apps/bench/build` 为旧构建（`typephp_read_property_scoped` 计数为 0），**不作样本** |
| 性能基线 | 27 页 / 1200×800 / 50 帧：AOT layout 15,360.9µs，解释器 6,847.9µs，节点 2,208 |

**口径警告**：下文全部是**代码站点数**，不是执行次数。站点数给出「天花板」，换算成时间必须乘运行期密度（§4 敏感性表）。

统计方法（可复现）：

```bash
cd apps/users/build/src
files=$(find . -name '*.cc')
for p in 'typephp_call_method_cached\(' \
         'php_drift__[a-z_0-9]+__[a-z_0-9]+\(' \
         'typephp_read_property_scoped\(' \
         'typephp_read_property_cached\(' \
         'this_\.attr\(' '\.attr(Int|Float|Bool)\(' \
         '\[&\]\(\)' 'php::Var [A-Za-z_]' \
         'php::toInt\(' 'php::toBool\(' 'php::toFloat\(' 'php::toString\(' \
         'php::deindirect\(' 'php::same\(' 'php::equals\('; do
  printf '%-44s %7s\n' "$p" "$(rg -o --no-filename "$p" $files | wc -l)"
done
```

---

## 2. 调用点普查：动态分派只剩 8.4%

| 指标 | 数量 |
|---|---:|
| 静态直调 `php_drift__*__*()` | 12,223 |
| 动态分派 `typephp_call_method_cached()` | 1,125 |
| 合计 | 13,348 |
| 动态占比 | **8.43%** |
| `typephp_call_closure()` | 4（与评审稿「只多 4 处排序回调」一致） |

对照 §9.4.1 的 A/B：修复前 1,593 → 修复后 1,128（−29.2%），18:04 默认入口 1,131。**剩下的基本是真实多态面，不是编译器退化。**

### 2.1 接收者分类（前 30）

`rg -o 'typephp_call_method_cached\([^,]*'` 取首个实参：

| 接收者 | 站点 | 接收者 | 站点 | 接收者 | 站点 |
|---|---:|---|---:|---|---:|
| `canvas` | 138 | `tmp_var_1` | 31 | `tmp_var_8` | 17 |
| `kid` | 58 | `state` | 30 | `tmp_var_5` | 16 |
| `box` | 54 | `element` | 28 | `this_` | 13 |
| `tmp_var_0` | 46 | `tmp_var_4` | 22 | `platform` | 12 |
| `node` | 41 | `column` | 21 | `window` / `host` / `app` | 11 / 11 / 11 |
| `widget` | 38 | `tmp_var_6` / `tmp_var_3` | 18 / 18 | `plain` / `target` / `root` / `nav` | 10 / 9 / 9 / 9 |
| `tmp_var_2` | 34 | | | | |
| `e` | 34 | | | | |
| `child` | 33 | | | | |

关键读数：**`this_` 只有 13–14 处** ⇒ final/self 调用已全部静态化。其余是形参/局部接收者。

### 2.2 闭世界实现数（决定能否 guard 化）

`rg '(extends|implements)[^{]*\bT\b' src -g '*.php'`：

| 类型 | 实现/子类数 | 对应站点（按接收者名） |
|---|---:|---:|
| `Canvas`（interface） | **3** | 138 |
| `Element` | 3 | 28 |
| `Widget` | 6 | 38 |
| `InputEvent`（interface） | 10 | 34（`e`） |
| `State` | 15 | 30 |
| `RenderBox` | **18** | ≈216（`kid`/`box`/`node`/`child`/`column`/`root`） |
| `this_` | 已知 | 13 |
| `tmp_var_*`（类型已丢） | — | **243** |

### 2.3 可转化上界

| 桶 | 站点 | 占 1,125 | 方案 |
|---|---:|---:|---|
| ≤4 实现，低代价 class-id guard | ≈170 | 15% | `Canvas`(3)/`Element`(3)/小类 |
| 5–18 实现，多路 guard | ≈310 | 28% | `Widget`(6)/`InputEvent`(10)/`State`(15)/`RenderBox`(18) |
| 类型追踪缺口（非多态） | 243 | 22% | 回填 `ATTR_LOWERED_TYPE` / `detectClassOfExpr`，不是去虚拟化 |
| 开放多态 / 可覆写 / 接口契约 | ≈400 | 35% | 按官方口径保留动态分派 |

### 2.4 目录分布（执行热度无数据，仅结构参考）

| 目录 | 动态 | 静态 | IIFE | `php::Var` 声明 |
|---|---:|---:|---:|---:|
| Widget | 337 | 5,471 | 346 | 4,376 |
| App | 369 | 1,583 | 295 | 2,281 |
| Layout | 289 | 1,415 | 196 | 2,019 |
| Rendering | 29 | 1,096 | 181 | 1,983 |
| Text | 26 | 679 | 67 | 978 |
| Geometry | 0 | 323 | 41 | 396 |
| State | 14 | 151 | 15 | 134 |
| 其余 | 61 | 1,505 | 191 | 1,566 |

`Widget`/`App` 多为冷路径（build/dirty/输入）；**压在 layout 热路径上的动态分派 ≈ Layout + Rendering + Text = 344 处（31%）**。

---

## 3. 每操作系数（来自 §2.7 阶梯，AOT 侧）

| 操作 | 解释器 ns/op | AOT ns/op | 差 | 单次可回收 |
|---|---:|---:|---:|---:|
| 接口虚调用（双实现）L09 | 56.18 | 110.26 | +54.08 | 改静态直调 13.76 ⇒ **96.5 ns** |
| 同类 final 方法 L08 | 45.30 | 13.76 | −31.54 | （已是现状） |
| 可变对象分配 L05 | 96.40 | 154.02 | +57.6 | ≤57.6 ns |
| readonly 值对象 L06 | 100.46 | 179.93 | +79.5 | ≤79.5 ns |
| 闭包创建 + 调用 L10 | 105.64 | 215.88 | **+110.2** | 消除 IIFE ⇒ ≤110 ns |
| `mixed` 拆箱 L07 | 16.24 | 7.20 | −9.04 | AOT 已更快 |

目标基线（27 页 layout）：

```
AOT   15,360.9µs / 2,208 节点 = 6.96 µs/节点
PHP    6,847.9µs / 2,208 节点 = 3.10 µs/节点
需抹掉的超额            = 3.86 µs/节点（2.24×）
```

---

## 4. 敏感性：单靠去虚拟化能回收多少

`回收 = k × 96.5 ns`（k = 每节点动态调用次数）：

| k（次/节点） | 回收 µs/节点 | 占超额 |
|---:|---:|---:|
| 5 | 0.48 | 12% |
| 10 | 0.97 | 25% |
| 20 | 1.93 | 50% |
| 30 | 2.90 | 75% |
| **40** | **3.86** | **100%** |

⇒ 结论：**devirt 无法单独关闭 2.24×**，除非每节点有 ≈40 次动态调用。§2.7 的 L12（mimicLayout 1.95×）已独立说明「调用 + 分配」形状本身贵。

组合估算（k=10、每节点 5 个 IIFE、8 次分配）：

```
0.97（虚调用）+ 0.55（闭包）+ 0.46（分配）≈ 1.98 µs/节点 ≈ 51% 的超额
```

剩余 ≈1.9 µs/节点 主要来自**属性表读 + Variant 中转/装箱**。

---

## 5. 结构性问题：属性读与临时量

| 指标 | 数量 | 说明 |
|---|---:|---|
| 属性读点合计 | **6,167** | 见下拆分 |
| `this_.attr(...)` | 3,422 | 属性表 + `php::Variant` 返回 |
| `typephp_read_property_scoped(...)` | 1,859 | #186 路径，含 `php::to*` 转换 |
| `typephp_read_property_cached(...)` | 384 | 接收者非 `$this` |
| 原生 `attrInt/attrFloat/attrBool` | **502** | 仅占 **8.1%** |
| IIFE `[&]()` | **1,332** | 热目录（App/Layout/Rendering/Text/Geometry）780 |
| `php::Var` 声明 | 13,733 | ≈生成代码 9% |
| `php::deindirect` | 1,409 | 参数/返回值中转 |
| `php::same` / `php::equals` | 1,696 / 18 | match / switch lowering |
| `typephp_static_*` | 29 | 带类型静态属性 |

与 §2.2 证据四一致：**普通类不生成 C++ 字段**，92% 的属性读走属性表。该项不依赖调用密度，是唯一能把 2× 拉到 ~1.2× 的杠杆，但属 NativeStruct 级工程（XL，需 feature flag）。

---

## 6. 结论

1. **去虚拟化上界明确**：8.43% 的调用点；其中 15% 可低成本 guard，28% 需多路 guard，22% 其实是类型追踪缺口。
2. **现实回收 25–50%**；要 100% 需 k≈40 次/节点。
3. **优先级**：
   - P0（1–2 天，低风险）：IIFE 展开、`match` 全 int 字面量 → 原生 `switch`、`Canvas`/`Element` 3 路 guard ⇒ 15–25%。
   - P1（1–2 周）：闭世界多路 guard 生成（含 `RenderBox` 的 profile-guided 2–4 类特化）、`tmp_var_*` 类型回填 ⇒ 再 15–20%。
   - P2（XL）：NativeStruct（原生字段/静态偏移）⇒ 关闭属性表开销；这是唯一能接近 1.2× 的路径。
4. **不要撤官方口径**：真实混合联合 / 引用 / 可覆写调用保留运行时表示；剩余 35% 动态分派属于设计，不是缺陷。
5. **边界**：本文只对 `apps/users` 的 `bin` 产物成立；`lib`（`drift-codegen`）口径去虚化不生效（§9.3），不可外推。性能绝对值受「paint 只录 DisplayList」口径影响，但 AOT/解释器比值不受影响。

---

## 7. 下一步：把假设换成实测

当前时间换算依赖「每节点 k 次调用」。最便宜的消除方式（不需要重建 Drift 全量）：

1. TypePHP 侧在 `typephp_call_method_cached` / IIFE 生成点 / `attr()` 读点各加一个 `static uint64_t` 计数器，由环境变量（如 `TYPEPHP_STATS=1`）在进程退出时打印；
2. 用现有 `probes/frame_tax_aot`（`PROBE_SECTION=synthetic|pages`）**一次构建**取数；
3. 用实测次数替换 §4 的 k，并按 §3 系数换算；再决定先做 devirt 还是 NativeStruct。

资源纪律：单次构建、`--job 1`、`flock -n /run/user/1000/codex-build-1000.lock`、`systemd-run --user --scope -p MemoryMax=7G -p MemorySwapMax=2G`、内核/内存/进程数监控（AGENTS.md）。

### 验收判据

- 计数器为 0 时产物与现状逐字节一致（无统计开关 = 零开销、零代码差异）；
- `STATS=1` 时输出 `calls_dyn` / `iife` / `attr_read` / `alloc` 四项，且同一进程内两段量具分开跑（§2.6 的「一段一进程」纪律）；
- 换算后的回收比例与 §4 表一致（误差来自 k 的真实值，而不是系数）。

---

## 8. 复现

```bash
# 调用点普查
cd apps/users/build/src
rg -o --no-filename 'typephp_call_method_cached\(' -g '*.cc' . | wc -l
rg -o --no-filename 'php_drift__[a-z_0-9]+__[a-z_0-9]+\(' -g '*.cc' . | wc -l

# 接收者分类
rg -o --no-filename 'typephp_call_method_cached\([^,]*' -g '*.cc' . \
  | sed 's/typephp_call_method_cached(//' | sort | uniq -c | sort -rn | head -30

# 属性读拆分
rg -o --no-filename 'this_\.attr\(' -g '*.cc' . | wc -l
rg -o --no-filename 'typephp_read_property_scoped\(' -g '*.cc' . | wc -l
rg -o --no-filename '\.attr(Int|Float|Bool)\(' -g '*.cc' . | wc -l

# 闭世界实现数
cd /mnt/d/TypePHP/Drift2
for cls in RenderBox Widget Element State Canvas; do
  echo "$cls: $(rg -o "(extends|implements)[^{]*\b$cls\b" src -g '*.php' | wc -l)"
done
```

---

## 附：与 AOT-CONSTRAINTS.md 的对应

| 本文结论 | 对应台账条目 |
|---|---|
| 动态分派 8.4%、接收者分类 | §9.4.1「仍有动态分派是设计」、§9.3 口径差异 |
| 阶梯系数 96.5 / 57.6 / 79.5 / 110.2 ns | §2.7 L05/L06/L09/L10 |
| 2.24× 与 3.86 µs/节点 | §2.6 27 页、§2.5 帧税 |
| 属性读 6,167 / 类型化 8.1% | §2.2 证据二/三/四（字段走属性表、非静态偏移） |
| IIFE 1,332 | §2.7 L10、V-4（match 无跳转表） |
| `int/int` 不参与本归因 | §1.2、§2.8（正确性问题，非性能） |
