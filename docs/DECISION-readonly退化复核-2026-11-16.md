# 决策记录：`readonly` 退化到 checked Zend 读，要不要改

> 触发：用户指出上游 `bff46481`(#186) / `41d91fe6` 明确写了 readonly 被排除在标量引用
> 提升之外、普通读走 checked Zend result / `php::Variant`，后者只把结果转回标量、
> **未采纳 direct-slot 优化**。要分析本仓是否该因此改写法。

## 0. 一句话结论

**用户提醒属实，旧结论过期；但不改 410 处 `readonly`。**
真正要做的是**订正文档口径**（旧结论说"readonly 零开销、与非 readonly 逐字相同"，
在 #186 之后是错的）。代码保持现状——退化真实存在，但量级远低于阈值，
且去掉 readonly 换不回等价的 direct-slot 读，反而丢不可变性语义。
属性钩子全仓 0 处，不是优化对象。

## 1. 事实核验（两提交说明属实，且有本仓当前编译器的直接生成物对照）

### 1.1 上游提交原文

- `bff46481`(#186, 11-11)："Readonly properties are **intentionally excluded from
  scalar reference hoisting**, so their generic read yields `php::Variant`."
  修法 = `typephp_read_property_scoped` + `php::toInt/toFloat/toBool`，未初始化槽仍抛 `Error`。
- `41d91fe6`(11-16)："Readonly property reads already convert their checked Zend result
  to a native scalar... **without adopting its broad direct-slot property read optimization**."
  修的是"checked 读的结果被二次装箱导致 C++ 编译失败"，不是性能优化。

### 1.2 本仓当前编译器的 A/B 生成物（决定性证据）

用当前源码树 tpc(`41d91fe6`)对同一份探针翻译，`readonly float` 与 `非 readonly float` 的读：

```cpp
// readonly：checked Zend 读 + 作用域类解析
php::toFloat(typephp_read_property_scoped(o, get_str(2),
    get_persistent_class(PersistentClassId{2}, get_str(3)), php::AttrMode::Get));

// 非 readonly：direct-slot 偏移读
php::toFloat(o.attr(get_persistent_prop(PersistentPropertyId{1}, ...), php::AttrMode::Get));
```

上游源码 `PropertyAccessTrait::emitNativeInstancePropertyTypedFetch` 的关键分支：

```php
if ($def->isReadonly()) {
    // "Readonly properties cannot use hoisted references, regardless of the receiver."
    $getter = 'typephp_read_property_scoped(...)';   // checked 读
    return $this->convertExprFromType($def->type, $getter);
}
// 非 readonly：$this 接收者走 .attrInt/.attrFloat（direct-slot），SSA 稳定对象走引用提升
```

⇒ **用户描述的两条提交说明、以及"退化到 zendvm 处理"，逐字属实。**

### 1.3 旧结论为什么是错的（时间线陷阱）

`docs/AOT-CONSTRAINTS.md` §2.1 写"readonly 自身零开销，生成物
`typephp_read_property_cached` 与非 readonly public 逐字相同"，判据是 M0-1 三组实测
(A=65 / B=87 / C=66 ns/read)。但：

- M0 生成物 `apps/bench/build/src/M0Readonly.cc` 的时间戳是 **2026-10-22**；
- #186 是 **2026-11-11**，#41d91fe6 是 **2026-11-16**；
- M0 测的是旧编译器的 `typephp_read_property_cached`（内联缓存），
  **不是** #186 之后的 `typephp_read_property_scoped`（checked 读 + 作用域类解析）。

⇒ §2.1「零开销 / 逐字相同」在 #186 之后**不再成立**，是过期结论。
另：M0 量具的 `$acc += $ro->v` 会被 `php::Var` 吸收，**即便今天重跑也测不出退化路径**
（scoped 读只在读结果喂给标量上下文时触发），所以它不能用来翻新结论。

## 2. 影响面（本仓实测）

| 项 | 数量 | 是否受 #186 退化影响 |
|---|---|---|
| Property Hook（get/set） | **0 处** | 不适用——无需动作 |
| `readonly` 总计 | 410 处 | 见下分层 |
| ├ 标量类型（int/float/bool/string，精确 192 处） | 192 | ✅ 受影响：`usesNativeScalarStorage` 走 checked scoped 读 |
| └ 对象/array/闭包类型 | 218 | ❌ 本就走属性表，readonly 不额外退化 |
| `final readonly class` | 49 个 | 多为全标量值对象（Geometry/Input/Widget），是架构收益 |

热路径分布：`src/Layout` 136 处、`src/Widget` 119 处、`src/Rendering` 30 处——
多为 `RenderXxx` 的构造注入标量（`spacing` / `fontSize` / `strokeWidth` 等），
每帧 `layout()`/`paint()` 会被读。

## 3. 多维裁决：为什么不改

### 3.1 性能量级：真实退化，但远低于阈值

scoped 读比 direct-slot 多的是"一次函数调用 + 一次 `get_persistent_class` 作用域类解析"。
这不是 10× 量级（旧 v6 断言），是常数级固定开销。
按 M0 自己定的折算判据（`docs/AOT-CONSTRAINTS.md` §2.1）：

```
即使悲观假设 scoped 读比 direct 慢 20ns/read：
20 × 10 次/帧 × 60 帧 = 12,000 ns/秒 = 0.012 ms/秒  ≪  1ms/秒门槛
```

全渲染层所有 readonly 标量读加起来（每帧数百次）也到不了显著量级。
且 M0 原始 A−C = −2/+1/−2 ns 本就在噪声内——旧测法**根本分辨不出**这个函数调用差异，
它测的 `cached` 内联缓存路径与 scoped 路径不是一回事。

### 3.2 去掉 readonly 换不回等价 direct-slot 读

这是最关键的一点：**退化由编译器对 `isReadonly()` 的强制分支造成，不是 readonly 语义慢**。

- 把 `public readonly float $v` 改成 `private float $v` + getter ⇒ 走 `typephp_call_method_cached`，
  M0 实测 **+22 ns/read**，比 scoped 读更慢——负优化。
- 改成 `public float $v`（非 readonly、无 getter）⇒ 能走 direct-slot，但**丢失不可变性**：
  49 个 readonly class 的值对象语义（Paint interning 共享安全、`select()` 的 `===` 门控、
  BuildContext 固定字段）全部失效，这是架构性倒退。
- 保留 `final` 不能替代——`final` 只封继承，不封属性改写。

⇒ 三条路两条更差，一条是架构倒退。**没有"既拿回 direct-slot 又保住不可变性"的写法**——
除非上游采纳 #188 的 direct-slot 优化（41d91fe6 明确说未采纳）。

### 3.3 成熟框架对照

Flutter / SwiftUI / Kotlin 的值对象普遍用语言级不可变（`const` / `struct` / `val`），
不会为了读性能把不可变性拆掉。Rust 的 `&self` 只读同理。
为常数级读开销放弃值对象不可变性，在这些框架里也是负优化。

### 3.4 维护性与风险

- 改 410 处要同步改 49 个 class 的语义、所有 `clone` / `withX` 路径、`select()` 门控判据、
  以及依赖 readonly 的 lint 规则（select-purity 白名单、dead-subscription）——牵一发动全身。
- AOT 判据（C2440 workaround、未初始化槽语义）都建立在 readonly 之上，回归面巨大。
- 收益 0.012 ms/秒，风险远大于收益。

## 4. 裁决

| 项 | 裁决 | 理由 |
|---|---|---|
| Property Hook | 无动作 | 全仓 0 处 |
| 410 处 readonly | **不改** | 退化真实但量级远低于阈值；去 readonly 换不回 direct-slot、反丢不可变性 |
| AOT-CONSTRAINTS §2.1 / §3.4 | **订正口径**（本次做）| "零开销 / 逐字相同"已过期，会误导未来决策 |
| 新增一条 AOT 纪律 | **本次做** | "readonly 标量读走 checked scoped，非 direct-slot；别为读性能拆 readonly" |
| 上游 #188 进展 | 持续跟踪 | 若 upstream 采纳 direct-slot 读，本裁决自动失效，届时重新评估 |

## 5. 诚实边界

- 本裁决基于**生成物结构对照** + M0 折算判据，未在真机上实测 scoped vs direct 的 ns 差
  （量具测不出退化路径，需专门改夹具喂给类型化返回/switch 并做 AOT 真机构建，~数分钟）。
- 若将来某处出现"readonly 标量读在极热循环（>1000 次/帧）"的实测瓶颈，
  应针对该**单点**裁决（如把该字段提升为局部变量缓存），而非全量迁移。
- 本文是快照式决策，不随后续编译器升级回填结论。

---

> 验证：本仓当前 tpc(`41d91fe6`) A/B 生成物对照见 §1.2；上游提交说明见 §1.1。
