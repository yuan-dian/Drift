# AOT 约束总表（Drift · tpc → C++17）

> 状态：**现行台账** · 结论复核 2026-11-11 · 编译器入口 `D:/TypePHP/typephp/bin/tpc.php`（`bff46481`）
> 定位：AOT 约束的唯一完整落点。回答两个问题：**现在必须守什么？每条结论的证据在哪？**
> 只列**当前有效**结论：已推翻 / 已解禁的旧约束不再列为条文，结案与边界见 §9。
> 历史方案稿（`docs/arch-v2/08-编译约束与实测.md`、`docs/ARCH-REVIEW-2026-10-21.md`）不回改，冲突以本文件为准。
> 上游修复的证据链见 §8（#186）/ §9（#183 / #184 / #185）。

---

## 0. 这个文件为什么存在

判据表原本分散在源码注释、构建工具注释、v6 方案册与本地记忆四处。本文件是唯一完整落点，
也是 M0 三条实测结论的家（`08` §8 要求产出必须写进仓库，不是只在会话里）。

**维护纪律**：新踩一条 AOT 坑，先加进 §1 判据表 + §6 错误码，再写进源码注释（若有具体位置）。
完整维护规则见 §7。

---

## 1. 判据表：约束 → 被否掉的候选 → 采用的形态

每条三列：约束 → 被它否掉的候选 → 采用的形态。只列现行约束。

### 1.1 类型退化

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| 真实混合联合 / `mixed` / `callable` / `?array` / 异型三元合流仍退化成 `php::Var` | 依赖它们做热路径设计 | 具体类型 + 哨兵 |
| 可空对象字段两种形态**无量级差**（都走 PHP 属性表，§2.2） | 指望 `?Obj` 字段比「非空 + `bool`」更快 | `RenderBox $child` + `bool $hasChild`（`RenderProxyBox` 的形态） |
| `$this->可空字段?->m()` 按运行时类名查表读属性 ⇒ 读成 `null` 只静默 | 跨实例读 `private`、可空字段上直接 `?->` | 拆成 `$x = $this->x; if ($x !== null)` |
| `?float` 标量字段每帧读不划算 | `?float $virtualExtent` 做每帧读字段 | `float $virtualExtent = \NAN` 哨兵（`RenderScrollView`；`02` §3.2 的 `Modifier::$width` 同法） |
| `?int` / `?string` 推断不退化（V-1，未实测） | 依赖它做设计 | 一律用 `NAN` / `-1` 哨兵 |

★ **已解禁、不再约束**：`?Obj` 形参 / 返回值、纯对象联合 / 交叉类型 / DNF 对象声明、
链式接收者都保留 `php::Object`（§9）。**类型退化 ≠ 空安全调用可用** —— 上表第三行是
属性查表机制，与静态类型无关，仍是现行约束；要避开的只是这一族写法（全仓 185 处 `?->`
并非全禁）。`Cell::$owner` 用 `State` + `bool` 的决策也不变（人因收益，见 §3.2）。

### 1.2 语法

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| `switch ($this->intProp)` 曾生成 `php::Int tmp = this_.attr(...)` ⇒ **C2440**；#186 已补 `php::toInt`，但全仓零 `switch`、本仓未在该形状上复测 ⇒ **仍按禁用处理**（§8.4） | `switch` 语句 | `int $kind` + `===` 比较链（`ToggleBase::activate()` / `Curve::map()`） |
| `?:` 两支类型不同 ⇒ **C2678** | `$cond ? $a : $b` 里两支不同型 | 拆 `if/else`（`ToggleBase.php:189-210` 两个 `?:` 拆开） |
| `match` 已用 **15 处**且 T2 通过 | —（`match` 可用） | 继续用（但**没测过它是否生成跳转表**，见 §4 V-4） |
| 可选参数排必选前 ⇒ Fatal（比 PHP 严） | `function f($a = 1, $b)` | 必选在前（`RenderScrollView::__construct` 注释写明） |
| 类常量与同类方法同名 ⇒ **C2872** | 同名 | 改名 |
| 常量链 `A = B \| C` 而 `B = X \| Y` ⇒ **C2065** | 嵌套常量表达式 | 平铺成一次 `\|`（`CanvasCapabilities::ALL_MASK`） |
| 命名空间内非限定函数**不回退全局** ⇒ 运行期 `Call to undefined function` | 裸 `strlen()` | 写 `\strlen()` |
| 函数外不能有语句（探针 P-4） | 文件级初始化 | 静态方法 |
| **双引号串里含 `$` 但无插值意图** ⇒ tpc 编译期 Fatal `The variable ... is undefined` | `"...$frame->run(...)"` 这类文案 | 拆成 `'半句' . "\n" . '半句'`（单引号保 `$` 字面量，双引号保 `\n` 真换行）。**W0 踩过一次，见 §2.4** |
| **同一函数里变量类型是静态的**，换个类型赋值 ⇒ Fatal `Cannot assign value to variable $k of type php::Int with type php::Var` | 同一个 `$k` 先当 `for` 计数器（int）再当数组键（string）——**解释器完全允许** | 两个名字（`$rep` 计数 / `$k` 键）。★ 2026-11-04 在 `FrameTaxMeasure` 撞到，是「解释器能跑、AOT 编不过」的典型 |
| **`sources` 是翻译单元白名单，不是类解析路径** | 写 `../../tools` 想"用到哪个类就编哪个" | 逐文件列。★ 目录里**所有** `.php` 都会被当程序的一部分预处理，而 `tools/Lint/fixtures/` 里全是**故意写坏**的夹具 ⇒ 当场死在 `All execution code must be within a function`。AOT 是静态链接，缺的类也不会按 PSR-4 现找（`class ... is undefined` 在**运行期**才炸） |
| **反射造任意类**（`new $class()` / `ReflectionClass`）在 AOT 下不可用 | `--pages` 的 `glob` + `class_exists` + 反射发现 | **显式清单**：`tools/Bench/ShowcasePages.php`（名字是键、构造闭包是值，一份数据）。★ AOT 静态链接 ⇒ 没列进 `sources` 的类**根本不存在**，列进去了也必须**编译期**确定构造点。代价是清单会漂移 ⇒ 配判据「磁盘可用页 == 清单」兜住（**只能在解释器侧做**，那一侧才有反射）。做这条时顺带发现页面档缺「页面真建起来了」的自证，一并补上，见 `DEBT` §35 |
| ★★ **`int / int` 是整数除法**（`7 / 2 === 3`）—— PHP 要求 `/` 永远返回 float，而 tpc 编成 C++ 的 `/` | 热路径写 `$a / $b` 且**两端都是 int** | 两端至少一个 `(float)`。★ **静默**：不崩、不报错、编译期不警告，只是数值偏。2026-11-04 在微基准里撞到（`DEBT` §36.7），`AspectPage` 里 10-24 写下的活体诊断当场被证实。★ **只有 `int/int` 错**：`int/float`、`float/int`、`float/float`、字面量 `16.0/9.0` 全对 —— 所以本仓 27 处除法实测**没有一处踩到**（几何指纹逐值相同） |

### 1.3 对象模型

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| 父类 `private` **不进子类属性表** ⇒ 子类实例上访问报 `Undefined property` | 基类用 `private` 给子类共享字段 | `protected`（`Element` 的 `$box`/`$mounted` 注释写明"语义不变，仅可见性放宽"） |
| 被继承的类，字段不能是 `private`（tpc 按运行时类型查属性表） | `RenderFlex` 的字段用 `private` | `protected readonly`（`RenderFlex.php:46-51` 注释：2026-10-16 真机跑 `users.exe` 当场暴露） |
| `readonly` 属性**不能**在构造之外赋值 | 可变值对象 | 可变字段用 `private`（`RenderBox::$composeOpacity`，`05` §5.4） |
| `#[\Native]` 类**不能**进 PHP 数组 / 不能持容器 / 不能作 Fiber 参数 | `#[\Native]` 做树节点 | 纯 PHP 类做节点；`#[\Native]` 只做叶子值对象。★ 本仓 `#[\Native]` **零使用** ⇒ 这条目前无消费者（2026-11-04 核） |
| `#[\Immutable]` 只作用于**实例方法**与**参数**，不能放类/属性/普通函数 | "零成本替代 `readonly`" | 不存在这种替代（`readonly` 是属性级、`#[\Immutable]` 是方法级） |

★ 第三行是最值钱的一条：`RenderAppBar extends RenderFlex`，而 `RenderFlex` 的
`$vertical` 如果是 `private`，子类实例上访问会**动态创建属性**并得到 `NULL` ⇒
布局拿到错值。**只在真机上暴露，解释器与 smoke 全绿。**

### 1.4 标准库

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| std 容器赋给对象属性会被 `toArray()` **拷成 PHP 数组** | `private array $kids` 用 `std::vector` | 用 PHP `array`（`RenderFlex::$kids`）；`Element::$children` 同 |
| 二进制串禁在循环里 `.=` 累加（含 `\0` 与高位） | `pack()` 结果逐段拼接 | 数组 + `implode`（lint `str-concat-loop` 守） |
| `Closure::call` / `bindTo` 不可用 | 用闭包换绑定 | 显式传参 |
| `global` 擦除类型 | `global $app` | 构造注入 |
| `__FILE__` / `__DIR__` 编译期固化 | 用它找运行时资源 | `Paths` 类（`src/App/Paths.php`） |
| 无 `$argv` | 命令行参数 | 环境变量 / 文件（`build/m4.on` 文件开关） |
| `getenv` / `putenv` / `eval` / `include` 动态路径被 `nano-hygiene` 禁 | 运行时配置 | 文件开关（`build/*.on`） |

★ 第 14 条纪律的判据：`grep -rn "__DIR__\|__FILE__" src/ --include=*.php`
当前只应命中 `FontLocator` 3 行（1 处代码 + 2 注释）。

### 1.5 性能

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| `#[\Native]` 值对象省装箱（v6 V-2，未实测） | — | 现在用 `final readonly class`（32 个） |
| **属性读直接当实参** ⇒ **C2440**（本仓 5 次，**无 lint**） | `$f($this->box->maxWidth)` | 先落局部标量再传 |
| ★★ **AOT 不必然更快**（2026-11-04 实测，详见 §2.5） | 「AOT 产物一定比解释器快」这个默认假设 | 性能判断一律在 **AOT 口径**复量；解释器数字只作相对参考。实测 12k 节点布局栈 AOT 反而**慢 1.7–2.7×** |

★ 属性读直接当实参是**本仓最高频的 AOT 坑**（5 次 C2440 同一形状），且**没有 lint** ——
「哪个形参是 `float`」要跨类类型推导，PHP 没有静态类型 ⇒ 只能靠纪律 + T2 兜。
上游 #186 已修根因、本仓 T2 验过（§8.4），但 workaround 仍保留：C2678 不在 #186 范围、
未初始化 readonly 槽抛 `Error` 的新语义未专门验、撤它无消费者（三条理由见 §8.4）。

### 1.5.1 链式调用（已解禁）

上游 `b7e94b39`（#183）让链式接收者带静态类。本仓 `bin` 口径夹具（§9.3）显示
`Box::make()->add(1)->add(2)->value()` 与 `(new Box())->add(3)->value()` **全静态直调**、
零 `typephp_call_method_cached`、中间量 `php::Object` ⇒ 原 §5 第 4 / 15 条已撤，
热路径不必再拆语句。

**边界**：

- `lib` 口径（`tools/drift-codegen.php` / SDK 分发）类型保留生效但**去虚化不生效**，
  链式第二跳起仍动态分派；说「解禁」必须带 `bin` 口径（§9.3）。
- 上游回退条件：`eval` / `include` 接收者保留 Zend 帧、不可访问声明 / 子类覆盖 /
  `__call` / interface contract 那一跳会回退动态分派（§9.4.1）。
- 本仓 `Widget` fluent 全在 `build()` / dirty 重建（冷路径），`RenderBox` 热路径是字段直读；
  解禁后不改代码 —— 本来就没有热点，改了只多一个回归面。也不做构建期链式展开。

### 1.6 编译期注解（`10` §2 的 9 条）

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| 命名空间内 `#[X]` 解析成 `当前命名空间\X`，**静默无效** | `#[Override]` / `#[Getter]` / `#[Immutable]` 裸写 | `#[\X]`；lint 规则 `attribute-unqualified` 守（W0/A0 已落地） |
| `#[\Immutable]` 不能放类/属性/普通函数 | "全类不可变"的一行标注 | 只标实例方法与参数 |
| `#[\With]`/`#[\Setter]`/`#[\Native]` 都不支持 `readonly` | `readonly` 值对象 + 这三个注解 | `private` + `#[\Getter]`（`Modifier` 的形态） |
| `#[\Getter]` 生成 `getXxx()`（**不是** `xxx()`），且是普通方法 | "getter 免费" | 类内一律直读 `$this->x`，**不走 getter** |
| `#[Native]` 不能做节点 | 原生类做树节点 | 纯 PHP 类做节点 |
| `#[Hot]`/`#[Cold]` 在 MSVC 下"安全忽略" | 指望它们生效 | 标了不亏也不赚；换 clang 后端那天自动生效 |

---

## 2. M0 三条实测：结论与证据

> 量具：`apps/bench`（`M0Readonly.php` / `M0Nullable.php` / 既有 `M2.php`）
> 跑法：`php tools/drift-build.php bench` → `php tools/bench-run.php` → `cat build/bench-out.txt`
> ★ **必须跑 AOT 产物**（`apps/bench/README.md` 原话：解释执行与 AOT 的开销分布不同）。
> ★ `tools/bench-run.php` 而不是 `./bench.exe`：bench 是 GUI 子系统，bash 起的进程被沙箱回收
>   （现象是"1.5 秒返回、rc=0、无产出"）。另注意 `build/m4.on` 存在时只跑 M4 分支。

### 2.1 M0-1 `readonly` 的成本 —— **v6"慢 10×+"在本仓不成立**

三组，各跑 3 次（`08` §9 边界情况 4：波动 > 20% 要跑 3 次取中位）：

| 组 | 形态 | 跑1 | 跑2 | 跑3 | 中位 |
|---|---|---|---|---|---|
| **A** | `final readonly class` + `public float $v` 直读 | 64 | 65 | 65 | **65 ns/read** |
| **B** | `private float` + `v()` getter | 87 | 87 | 89 | **87 ns/read** |
| **C** | `final class` + `public float $v` 直读（**对照组，非 readonly**） | 66 | 64 | 67 | **66 ns/read** |

| 差 | 跑1 | 跑2 | 跑3 | 含义 |
|---|---|---|---|---|
| **A − C** | −2 | +1 | −2 | **`readonly` 自身开销** |
| **B − C** | +21 | +23 | +22 | **getter 方法调用的净成本** |
| **A − B** | −23 | −22 | −24 | 两个候选形态的直接差 |

#### 结论

1. **`readonly` 自身零开销**：`A − C` 三次分别是 −2 / +1 / −2 ns，|值| ≤ 2ns，
   在计时噪声内。生成物也印证：`readonly` 属性的读法是 `typephp_read_property_cached`，
   与非 readonly 的 `public` 属性**逐字相同**（见 `apps/bench/build/src/M0Readonly.cc`）。
   ⇒ **v6 `13` §7 纪律 3「热路径禁 `readonly`」在本仓不成立**，本条从判据表 §1.3/§1.5 划掉。
2. **getter 是方法调用，不是免费**：`B − C ≈ +22 ns/read`。生成物里 getter 走
   `typephp_call_method_cached`，属性读走 `typephp_read_property_cached`。
   ⇒ `02` §3.1 注释里"`#[\Getter]` 编译期零成本"要理解为**"不用手写"**，不是**"运行时免费"**。
3. **`readonly` 直读比 getter 更快**：`A − B ≈ −23 ns/read`。

#### 为什么必须有 C 组

只比 A/B **回答不了"慢 10×+"那条断言** —— A 快可能只是因为
**属性读本来就比方法调用便宜**，与 `readonly` 无关。
全仓 **187 处 `readonly` / 32 个 `final readonly class`** 关心的是
"readonly 到底有没有 Hook"，那只可能由 `A − C` 回答。

★ 防"测出来的不是想测的"：64 个对象**循环取**（`$ro[$i % 64]`）——
只有一个对象时 `$acc += $ro->v` 会被提升成 `$acc += READS * $ro->v`，A 组直接归零。

#### 判据核算（`08` §3.3 修正版）

```
(readonly_ns − private_ns) × 每帧读次数(≈10) × 60 帧
  = (−23) × 10 × 60 = −13,800 ns/秒 = −0.0138 ms/秒
远小于 1ms/秒门槛 ⇒ 不构成"为它牺牲简洁"的理由
```

### 2.2 M0-2 `?Obj` 是否退化 —— **字段层两种形态无量级差**（现行结论）

★ 这条**不计时**（`08` §3.4：答案在生成物里，是二值的；计时会被内联/缓存干扰）。
夹具 `M0Nullable.php` 的两个类都**真的跑一遍并断言读回值** —— 否则 AOT 只编译可达代码
⇒ 类被优化掉，grep 到的是「不存在」而不是「退化了」。

#### 证据一：形参层（**已由上游解禁**，不再是约束）

10-21 的旧产物里可空对象形参曾退化成 `php::Var`；上游 #184 之后同一形态是
`php::Object` + nullable guard（§9.2 的 A/B 硬判据）。那段旧签名快照已作废，
`Frame::run` 现在的形参是 `?\Closure $afterPaint`。
⇒ 下面证据二/三/四讲**字段存储与读点**，走的是 PHP 属性表机制，不随形参层变化。

#### 证据二：字段存储层（**推翻 `08` §3.4 的预期**）

`08` §3.4 预期"A 的字段声明是 `php::Var` 或 `php::Variant`"。
实测（`apps/bench/build/src/M0Nullable.cc`）**不是这样**：

```cpp
// NullableA（private ?RO $maybe）与 NullableB（private RO $always）
// 的写入逐字同形：
this_.attr(get_persistent_prop(PersistentPropertyId{584}, ...), php::AttrMode::Update) = r;
this_.attr(get_persistent_prop(PersistentPropertyId{585}, ...), php::AttrMode::Update) = r;
```

⇒ tpc **不给普通类生成 C++ 结构体字段**，可空与非空对象字段都走同一个
PHP 属性表（`this_.attr(persistent_prop)`）。**存储形态相同。**

#### 证据三：读点层（唯一的真实差异，且无量级差）

```cpp
// A：?RO —— 多一次空检查
php::Var m;
m = this_.attr(...);
if (php::toBool(php::same(m, php::null))) { return 0.0; }     // ★ 空检查
tmp_var_0 = typephp_read_property_cached(m, get_str(189), ...);

// B：RO + bool —— 多一次布尔检查
if (php::toBool(!(php::toBool(this_.attr(...has...))))) { return 0.0; }   // ★ 布尔检查
tmp_var_0 = typephp_read_property_cached(this_.attr(...), get_str(189), ...);
```

⇒ **两者都只是一次比较**。`?RO` 付空检查，`RO`+`bool` 付布尔检查，**没有量级差**。

#### 证据四：静态分派（**`08` §3.4 表第 3 行的预期不成立**）

`08` §3.4 写"`B` 的字段声明是具体类型，且 `B->v()` 的调用是**静态**（无 `php::call_method`）
⇒ 非空引用可静态分派 ✅"。

实测：`RO $always` 上的 `->v` 仍是 `typephp_read_property_cached(...)` ——
一次带 `PropertyCacheId` 的**单态内联缓存读**，**不是静态偏移读**。

⇒ **"非空对象字段可静态分派"在本仓不成立。**

#### 结论

| 层 | `?Obj` | 非空 `Obj` |
|---|---|---|
| 形参 / 返回值 | `php::Object` + nullable guard（§9.2） | `php::Object` |
| 字段存储 | PHP 属性表（同形态） | PHP 属性表（同形态） |
| 字段读点 | 多一次空检查 | 多一次布尔检查（若用 `+bool` 哨兵） |
| 属性访问分派 | 内联缓存读 | 内联缓存读（**非静态**） |

⇒ `03` 的 `Cell::$owner` 从 `?State` 改成 `State` + `bool` **没有性能收益**；收益是
**人因**的（读点不写 `?->`）。`02` §3.1 的 `?Color` → `Color` + `bool` 同理。
⇒ 「非空对象字段可静态分派」不成立（证据四）；§3.2 决策不变。

### 2.3 M0-3 `mixed` 装箱成本 —— **复用 M2，不重测**

`09` W0 第 1 步写的"M0Mixed"与既有 `M2` **逐字重叠**：M2 的 A 组（`mixed` + `toInt()`）
与 B 组（`int` 直读）就是这条判据，问的是同一个问题（`Cell` 要不要分裂）。

三次跑（`build/bench-out.txt`）：

| 组 | 跑1 | 跑2 | 跑3 |
|---|---|---|---|
| A `mixed(int)` + `toInt()` | 30 | 30 | 30 ns/read |
| B `int` 直读 | 31 | 32 | 32 ns/read |
| A / B | 0.97 | 0.94 | 0.94 |

⇒ **与类型化属性同阶** ⇒ `Cell` **不分裂**（`03` §3.4 判据：`A/B > 5` 才优先分裂）。
★ 反例组 D（`mixed` 装 `string`）D/A ≈ 1.17–1.24 ⇒ 非 int 型 mixed 稍贵，
   但同样远不到 5×，**不改变结论**。

### 2.4 顺带抓到的两条（都不在 M0 计划内）

#### ① 双引号串里的 `$` ⇒ tpc 编译期 Fatal

`drift-build bench` 当场抓到（`src/App/FramePhase.php`，W0/L5-1 的产物）：

```
Fatal error: The variable `$frame` is undefined in ...FramePhase.php:124
```

原因是一条 Fatal 文案写成双引号串 `"…确认 frame() 里 $frame->run(...) 那段…"`，
`$frame` 被 PHP 当变量插值。同文件另两处 `$frame` 在单引号里，本就安全。

★ 这与 MEMORY「补丁脚本嵌中文 ⇒ 串一律单引号」是**同族坑**，只是发生在源码而非脚本。
  修法：`'半句' . "\n" . '半句'` —— 单引号保 `$` 字面量，双引号保 `\n` 是真换行
  （单引号里 `\n` 会变成两个字面量字符）。
★ 又是"`php -l` 全绿、只有 T2 构建看得见"的一例。
**已进判据表 §1.2 最后一行。**

#### ② `build/m4.on` 忘删 ⇒ 无头基准空跑

`Runner::run()` 开头按 `\is_file('build/m4.on')` 分流：有这个文件只跑 M4（要窗口）、
写 `build/bench-m4.txt`；没有才跑 M0–M3。M4 真机压测留下的 0 字节开关忘删，
表现为"bench.exe 1.5 秒退出、rc=0、`build/bench-out.txt` 根本没生成"。

⇒ `tools/bench-run.php` 现在会**检测到就退出并打印修法**，不自动删（破坏性动作要人确认）。

### 2.5 AOT 口径的第一把尺子：帧税三数（2026-11-04）

**为什么量**：`docs/_archive/DESIGN-v2-2026-10-14.md` §13.5 与 `DEBT` §28 里「12k 全量重排 51ms / 6.2× 触线 /
稳态帧 8.7µs」这些数，全部来自 `php tools/frame-tax-probe.php --scale`，也就是
**PHP 解释器 + 软光栅**。工具自己都印着「口径：PHP 解释器；AOT 未复量」，
而「增量布局要不要立项」正压在这把尺子上。

**怎么量**：`probes/frame_tax_aot/`（`-m bin`，`sources` 引真实 `src` + 量具类本身）——
编排与树形状都取自 `Drift\Dev\Bench\FrameTaxMeasure`，与解释器那边**同一份代码**。
解释器侧 `opcache.enable_cli=Off` ⇒ **无 JIT**，对比干净。

| 指标（12,003 节点 / 30 帧） | 解释器 | AOT | AOT ÷ 解释器 |
|---|---|---|---|
| 首帧 `cold` | 1,034,736µs | 2,152,561µs | **2.08×** |
| 稳态帧 `perFrame` | 7.3µs | 19.5µs | **2.67×** |
| 全量重排 `layout` | 51,082µs | 88,439µs | **1.73×** |
| 归一化 `us/节点` | 4.256 | 7.368 | **1.73×** |
| 重建帧 settle/layout/paint | 6,009 / 49,869 / 48,681 | 12,277 / 125,594 / 93,866 | 2.04 / **2.52** / 1.93 |

★ **AOT 更慢，而且慢得不小。** 12k 全量重排相对 8ms 产品线的倍数从 **6.4×**
变成 **11.1×** —— 也就是说「拿解释器数字判『还没到该优化的程度』」是**偏乐观**的。

**原因（13 级阶梯实测，§2.7）**：不是装箱，是「虚调用 + 小对象分配 + 闭包创建」
在 AOT 下各慢 1.6–2.0×；这个负载几乎不含能被 AOT 优化掉的算术（算术部分快 15–20×，
但布局里本来就少）。`mixed` 拆箱在 AOT 下反而**快 2.3×**（与 §2.3 的 M2 一致）。

**边界（诚实版）**：
- 只在这个负载上成立：**小对象极多 + 软光栅录命令**。真实应用的文本塑形 / 图片解码
  走 native，AOT 大概率更快。**不能推广成「AOT 全面更慢」。**
- 两边的 `paint` 都是「录 `DisplayList`」，不是真 Skia 光栅化 —— 这条口径问题
  在本轮**没有变**（`SurfaceProbe` 的设计使然），它影响绝对值、不影响 AOT/解释器之比。
- 12k 档在 AOT 下**超过默认 128MB `memory_limit`**（探针放宽到 1024M 才跑起来）；
  解释器侧是 `-1`。这本身是个数据点：软光栅 + 12k 节点 + 装箱 > 128MB。

★ 复现：`bash probes/frame_tax_aot/build.sh && ./frame_tax_aot_probe.exe`（约 3 分钟构建）。
不进 `gate`（构建太重），与 `probes/fiber_scope` 同一条纪律。

### 2.6 AOT 口径的第二把尺子：27 个真实页（2026-11-04 晚）

**为什么还要一把**：§2.5 量的是**合成树**（`Row` + 3×`Text` 重复 3000 次）。裁决的另一根柱子是
「27 个真实页还有余量」，而那个数一直是解释器口径。这一节把它搬过来。

| 指标（27 页 / 1200×800 / 50 帧） | 解释器 | AOT | AOT ÷ 解释器 |
|---|---|---|---|
| 最重页 `TablePage` 全量重排 | 1,616.9µs | 3,474.9µs | **2.15×** |
| 27 页 `layout` 合计 | 6,847.9µs | 15,360.9µs | **2.24×** |
| 27 页稳态合计 | 1,394.7µs | 3,150.1µs | 2.26× |
| 最重页占 16.7ms 预算 | 9.7% | **20.8%**（余量 **4.8×**） | |

页面清单在 `Drift\Dev\Bench\ShowcasePages`（显式，§1.2 最后一行），解释器与 AOT **同一份**；
两侧 27 页合计节点都是 2208，这是清单一致的可观测证据。

★ **真实页的减速比合成树更大（2.24× vs §2.5 的 1.73×）**。真实页更"碎"（`Text` shaping、
`Card`/`Row`/`Wrap` 混搭、样式计算多），**对象操作在单位工作量里的占比更高** ——
这正是 §2.7 归因的那个方向。**合成树不是真实页面的替身，两个比值都要留着。**

★ **量具纪律：一段一进程。** 第一版把两档串在一个 `main()` 里跑（页面在前），
合成档 `layout` 从 88.4ms 涨到 104.1ms（**+18%**）、稳态 19.5 → 24.3µs（+25%）——
页面档把 PHP 内存池撑大并打碎了，后一段实际在量 allocator 的历史。
`PROBE_SECTION=pages|synthetic` 分开跑即恢复（复跑 87.4–97.8ms，与 §2.5 一致）。
★ 这不是 AOT 特性：**任何同进程连跑多档的量具都有**，只是 AOT 侧分配更重、放大更明显。

★ 复现：`PROBE_SECTION=pages ./frame_tax_aot_probe.exe`（构建同 §2.5，378 翻译单元 / 约 1m20s）。

### 2.7 第三把尺子：13 级阶梯 —— 「慢在哪一种操作」被拆开了（2026-11-04 深夜）

**为什么还要一把**：§2.5 / §2.6 给出了两个比值（1.73× / 2.24×），但**成因是靠猜的**
（原话是「最可能的原因：`php::Var` 装箱」）。而 §2.3 的 M2 早就显示装箱几乎免费。
两个结论不能同时成立 ⇒ 需要一条能**逐种操作**归因的尺子。

**怎么量**：`tools/Bench/AotMicro.php`（13 级，每级只比上一级多一种操作），
两侧跑同一份；每侧 4 次按级取 min，再由 `tools/aot-micro-compare.php` 逐级相除。
★ 零框架依赖（只编 5 个文件，构建 **8 秒**，对比 frame_tax_aot 的 1m20s）——
正因为便宜，才敢随时重跑。

| 级 | 测什么 | 解释器 ns/op | AOT ns/op | **AOT ÷ 解释器** |
|---|---|---|---|---|
| L00 fold | 可折叠空循环 | 11.70 | 0.00 | **0.00**（整段折叠） |
| L01 arith | 纯 int 算术 | 16.58 | 1.14 | **0.07（快 14.5×）** |
| L02 floats | float 算术 + 分支 | 29.73 | 1.37 | **0.05（快 21.7×）** |
| L03 arrInt | int 键数组 | 31.75 | 28.74 | 0.91 |
| L04 arrStr | string 键 hash | 40.95 | 51.36 | 1.25 |
| L05 objNew | 可变对象分配 | 96.40 | 154.02 | **1.60** |
| L06 valNew | readonly 值对象分配 | 100.46 | 179.93 | **1.79** |
| L07 mixed | `mixed` 拆箱 | 16.24 | 7.20 | **0.44（快 2.3×）** |
| L08 method | 同类 final 方法 | 45.30 | 13.76 | **0.30（快 3.3×）** |
| L09 virt | 接口方法（双实现） | 56.18 | 110.26 | **1.96** |
| L10 closure | 闭包创建 + 调用 | 105.64 | 215.88 | **2.04** |
| L11 treeWalk | 数组读 + 多态调用 | 3511.22 | 7044.03 | **2.01** |
| **L12 mimicLayout** | 复刻 `layout()` 的调用/分配序列 | 388.53 | 758.59 | **1.95** |

**读法（三句）**：

1. **算术是 AOT 的主场**：`int64`/`float64` 直算，快 15–20×；`mixed` 拆箱也**更快**（2.3×）。
2. **对象操作是 AOT 的客场**：分配 1.6–1.8×、虚调用 1.96×、闭包 2.04× 更慢。
3. **L12 = 1.95× 正好落在 §2.5 的 1.73× 与 §2.6 的 2.24× 之间** ⇒ 减速由这条
   「调用 + 分配」形状本身决定，与树大小无关；真实页更慢是因为**对象操作占比更高**。

★ **L00 的 0.00µs 不是 bug，是结论**：tpc 会把 `$s = $s + 1` 的循环折叠成常量
（而 `checksum` 仍然正确 —— 所以「结果对」不能证明「代码跑过」）。

★ 量具自身的五个坑（冷启动 / GC 分批 / 单位 / 折叠 / **量具踩了 `int/int` 的坑**）
记在 `DEBT` §36.3，比这张表更值得先读。

**复现**：

```text
php tools/aot-micro-probe.php > build/micro/i1.txt     # ×4
bash probes/aot_micro/build.sh && ./aot_micro_probe.exe > build/micro/a1.txt   # ×4
php tools/aot-micro-compare.php --interp=i1,i2,i3,i4 --aot=a1,a2,a3,a4
```

### 2.8 正确性口径：几何指纹 —— 27 页逐值相同（2026-11-04 深夜）

**为什么需要**：§2.7 撞到 tpc 的 `int / int` 整数除法（见 §1.2 那一行）。它是**静默**的 ——
不崩、不报错、只是数值偏。而"性能慢 2×"是可接受的，"算错尺寸"是不可用的。
⇒ AOT 的第一条判据不是速度，是**数值等价**。

**怎么量**：两个通道，同一帧的两个投影（`tools/Bench/Fingerprint.php` 统一口径）。

| 通道 | 内容是 | 回答 |
|---|---|---|
| `geom` | 27 页每个 `RenderBox` 的 `宽/高@x,y`（三位小数，正序 DFS） | 布局算对了吗 |
| `paint` | 每条绘制命令的 op 与全部操作数（颜色 / 画笔 / 渐变 / 虚线 / 路径 / 图像摘要 / 段落行几何） | 画对了吗 |

★ 两个都要，因为**它们的盲区不重叠**：几何看不见颜色、画笔、op 顺序、裁剪范围、
文字排版内部；而 `int/int` 落在颜色混合或文本度量上时，几何树一个字节都不会变。
实测（2026-11-04）：把 `Paint::fill()` 的 alpha 减 1，**`geom` 27 页全绿、`paint` 27 页全红**。

```text
php tools/aot-equivalence.php          # 一条命令，看退出码
```

| 退出码 | 含义 |
|---|---|
| `0` | 等价（27 页 × 2 通道；2278 个 `RenderBox` / 9302 条命令逐值相同） |
| `1` | 不等价 —— 会自动**归因**（AOT 自己飘？）并**定位**（哪页、哪节点/哪条命令、两侧各是多少） |
| `2` | 无法判定 —— AOT 产物不存在（构建要 50s，不默认要求） |

★ 三态不是洁癖：`2` 不冒充 `0`，因为「没验」与「验过没问题」是两件事。

★ **判据分开报**：`E③` 几何 / `D①` 绘制。合成一条会让"红了"失去方向 ——
几何错和颜色错是两种修法。

★ **构建后自动跑**：`bash probes/frame_tax_aot/build.sh` 末尾已经接上 ——
性能数字建立在"算对了"之上，没理由让一个没验过的产物存在超过 3 秒
（`SKIP_EQUIV=1` 可跳过）。

**结果**：27 页 / **2278 个 `RenderBox` + 9302 条命令逐值相同**（8 项判据全过）。

★ 第一次跑**红了一页**（`AspectPage`，`sumW` 差 94），定位到单个节点：同一行文字
解释器算 384px、AOT 算 290px。原因不是框架 —— 是那一页里 2026-10-24 写下的
**活体诊断**（`'16 / 9 = ' . (string) (16 / 9)`），AOT 下真的打印 `1`。
⇒ 那行改成静态说明（它会让本页几何在两侧**必然**不同，指纹就永远有脏行），
检测交给 §2.7 探针每次打印的「前提」一行。改完后 27 页全绿。

★ **单页破案用明细**：`php tools/fingerprint.php --detail=AspectPage --channel=paint` /
`FP_PAGE=AspectPage FP_CHANNEL=paint PROBE_SECTION=fingerprint ./frame_tax_aot_probe.exe`

- 几何明细按**深度缩进**，定位报**最浅 + 最深**两处：最浅给影响范围，最深给发生位置
  （报"第一个不同的节点"等于报根，而根是子树综合，报了没方向）。
- 绘制明细每行带 `#i` 序号，定位报**首处 + 末处**：命令是线性的，一条 `PUSH_CLIP`
  错了会污染它之后的一整段。实测同一次变异里，几何报 `584 / 624`（还得心算 padding），
  绘制直接报 `R:0,0,600,337.5 / R:0,0,640,360`。


### 2.9 统计量口径：中位 + 误差棒（2026-11-04 夜）

§2.5–§2.7 三把尺子的主数一律用**预热后取中位**，`min` / `max` 作误差棒
（`tools/Bench/Samples.php`，AOT 与解释器侧同一份实现）。原先用「N 次平均 / 总时间 ÷ 帧数」
是**系统偏差**而非噪声：

```text
同一棵 12k 节点树连续 layout 20 次
  第一发    63,263µs     ← 首次强制重排，缓存最冷（+42%）
  第 2..20  41,413 – 46,838µs（中位 43,056）
  前 5 次平均 48,533µs   ⇒ 比典型高 13%
```

| 主数 | 旧口径 | 偏差来源 |
|---|---|---|
| `layoutUs` | 5 次平均 | 冷发占 1/5 |
| `perFrameUs` | 30 帧总时间 ÷ 30 | **一帧撞上 GC 就抬走整段**，实测浮动 6× |
| `rebuild` 三段 | 10 帧平均 | 新树首帧最冷 |

★ `sort()` 在 tpc AOT 下可以编译（§39.6 验证过）。
★ **比值类结论不受影响**（分子分母同比例偏高）：§2.5 的 1.73×、§2.6 的 2.24×、
`us/节点` 都是比值 ⇒ 裁决不变；**绝对值要重跑才能横向比**。
★ 离散度不进判据，只印：它是机器的属性（调度 / 频率 / GC），判它等于判机器。
但 `--scale` 判据 ③ 的文本带两档离散度 —— 那个比值的单次精度只有 **±20%**，
不标出来会被当成「超线性增长」。

### 2.10 第三类等价：**盒级动画的状态机**（2026-11-04 夜）

§2.8 的指纹比的是「布局出来的尺寸」与「要画的命令」，两者都是**一帧的静态结果**。
按下态不一样：它是**一条斜坡**（`pressProgress` 0→1），而斜坡的推进走
`RenderObject::tickAnimations()` ⇒ `Application::wantsFrame()` 的第四条判据 ——
**AOT 产物里帧时钟会不会为盒级动画重新上膛**，前两类判据一个字节都看不到。

```text
PROBE_SECTION=press ./frame_tax_aot_probe.exe     # AOT：8 行
php tools/press-sequence.php                      # 解释器：同一份 8 行
```

每行五项：`pressed` · `hover` · `pressProgress` · `hoverProgress` · **采样像素的 RGB**。
**两侧逐字相同**才算过（含 `223,230,239` 这种值 —— 它同时钉住状态位与"真的画了"）。

★ 为什么不用判据表而用**逐行文本对表**：这一段没有阈值可定。斜坡中间的
  `0.43` 是错的还是对的，只有"另一侧也是 0.43"能回答。
★ 序列里必须有 `down2`（第二次按下）：**状态机的洞通常在"回退再前进"上**，
  只测一次 `down/up` 是测不出来的。
★ `press` 段**不进 `all`**，与 `fingerprint` 同档 —— 它不出性能数字。


---

## 3. M0-c 决策记录（`08` §3.6 后果矩阵的落地）

> `08` §3.6 已承认：`02`/`03` **已经按最保守的假设写完了** ⇒
> M0 的作用从"决定形状"**降级为"确认能否简化"**。本节记录"简化还是不简化"。

### 3.1 `02` §3.1 的 `Modifier`：**不简化，保留 `private` + `#[\Getter]`**

M0-1 表面上支持简化（`readonly` 更快、可少写 10 个 getter），但**不改**，三条理由：

1. **性能理由本来就不是首要理由。** `02` §3.1 原文给 `private` 的首要理由是
   "`private` 在类外同样不可访问，但**保留完整的优化能力**"，以及
   **不堵死 `#[\With]` / `#[\Setter]` / `#[\Native]` 三条路**（`10` §2 A3/A5：
   这三个注解都不支持 `readonly`）。后者与性能无关，M0-1 动不了它。
2. **读点不在热路径。** `Modifier` 的读点是 `RenderBox::setMods()`，
   **只在构造/换描述时读一次，不是每帧**（`02` §3.1 注释原话）。
   getter 的 +22 ns/read 乘进去可忽略。
3. **改动面与收益不成比例。** 20 个字段 × 所有读点，收益只是"少写 10 个 getter"。

⇒ **动作只有一条**：把 `02` §3.1 注释里"且密集访问慢 10×+"这句**改掉** ——
   它现在是**错的**（M0-1 已推翻）。保留 `private` + `#[\Getter]` 的结论不变。

### 3.2 `03` 的 `Cell::$owner`：**不改**（已按 `State` + `bool` 写）

M0-2 证明**无性能收益**，但**人因收益成立**（读点不写 `?->`），
且 `08` §2.1 第三行原本就这么写。⇒ 维持现状。

### 3.3 6 个几何值对象类：**不改**

`Size`/`BoxConstraints`/`EdgeInsets`/`Rect`/`Point`/`Scale` 保持 `final readonly class`。
M0-1 证明 `readonly` 免费 ⇒ **原先唯一的迁移理由（"慢 10×+"）已破产**。

### 3.4 全仓 32 个 `final readonly class` / 187 处 `readonly`：**不迁移**

同上。`08` §7 破坏清单里"若 M0-1 结论是'readonly 慢'：6 个几何类改 `private` + getter"
这一行**作废**（连带它标注的"大"破坏面也作废）。

### 3.5 汇总

| 项 | M0 前 | M0 后 | 变化 |
|---|---|---|---|
| `Modifier` 字段形态 | `private` + `#[\Getter]`（待确认） | 同 | **无**（只改注释里那句错的） |
| `Cell::$owner` | `State` + `bool` | 同 | **无** |
| 6 个几何类 | `final readonly class` | 同 | **无** |
| `readonly` 纪律 | "热路径禁 `readonly`" | **撤销** | **有**（M0-1） |
| "`#[\Getter]` 编译期零成本" | 注释原话 | 改成"不用手写，运行时要一次方法调用" | **有**（M0-1） |
| "非空对象字段可静态分派" | `08` §3.4 表第 3 行 | **不成立**（内联缓存读，非静态偏移） | **有**（M0-2） |

---

## 4. 还没实测的（V 清单）

| # | 推断 | 如果错了会怎样 | 影响 |
|---|---|---|---|
| **V-1** | `?int` / `?string` **不**退化成 `Var` | 我们**没依赖**它（一律用 `NAN`/`-1` 哨兵）⇒ **无影响** | 无 |
| **V-2** | `#[\Native]` 值对象省装箱 | 现在用 `final readonly class`（M0-1 证明它免费）⇒ 省不出多少 | 低 |
| **V-3** | 闭包捕获成本可接受 | `SubjectBuilder` 的 `select` 闭包每次 `notifyChanged()` 跑一次。若闭包创建贵 ⇒ 构造时缓存（已经是这样） | 低 |
| **V-4** | `match` 生成跳转表 | 已用 15 处且 T2 通过 ⇒ 正确性没问题。若生成 if-else 链 ⇒ `RenderFlex::placeChildren` 每次布局最多 4 次比较 | 极低 |
| **V-6** | `const` 数组零成本 | 本仓 `const` 数组很少（`RenderDock::SLOTS` 5 元素、`DeadCommand::EMITTERS` 2 元素）⇒ 有拷贝也无所谓 | 极低 |
| **V-7** | 能否出 NTS 产物 | 与本次改造正交（打包/分发的事） | 无 |

**已由"存在性"证明的四条**（不用再测）：

| 推断 | 证据 |
|---|---|
| 带类型的静态属性可用 | **10 处**在用，936 smoke + T2 全过 |
| `match` 可用 | **15 处**在用 |
| `final readonly class` 可用 | **32 个**在用 |
| `?float` 作字段可用 | `RenderScrollView::$virtualExtent`（`?float`）在用 |

---

## 5. 代码纪律（现行 12 条）

> 编号沿用历史 15 条清单；**3 / 4 / 15 已撤销**（`readonly` 零开销 / #183 链式解禁，
> 见 §2.1 / §9.3），故跳号 —— 保留编号是为了让源码注释与本册其它章节的 §5-N 引用不漂移。

| # | 纪律 | 依据 |
|---|---|---|
| 1 | 热路径禁 `switch`，用 `int` + `===` 链 | C2440（`Curve.php:92`）；零 `switch` ⇒ 撤纪律无消费者，仍生效（§8.4） |
| 2 | `?:` 两支类型不同 ⇒ 拆 `if/else` | C2678（`ToggleBase.php:159`）；#186 不覆盖此形状 |
| 5 | 父子类不声明同名 `private` | 父类 `private` 不进子类属性表 |
| 6 | **属性读不当实参**：先落局部标量 | C2440 ×5（本仓最高频，**只能靠 T2 兜**）；仍生效，理由见 §8.4 |
| 7 | 常量链平铺成一次 `\|` | C2065（`CanvasCapabilities`） |
| 8 | 类常量不与同类方法同名 | C2872 |
| 9 | 命名空间内调全局函数写 `\` | 运行期 `Call to undefined function`（**不是** C2653，见 §6） |
| 10 | 循环内禁 `.=` 累加二进制串 | lint `str-concat-loop` |
| 11 | 可空对象字段优先用「非空 + `bool` 哨兵」或 `NAN` | 字段层无量级差（M0-2）+ `?->` 静默失效（§1.1、§2.2） |
| 12 | 二进制复合数据过桥只走打包字节串 | 桥 ABI 只认标量 + string |
| 13 | **内置编译期注解必须写 `#[\X]`** | lint `attribute-unqualified` |
| 14 | **运行时路径禁 `__DIR__`/`__FILE__`**；用 `getcwd()` / 环境变量 / 显式参数 | 编译期常量 |

★ 第 13/14 两条的共同形状是**「不报错、只是没效果」**，与第 5 条（父类 `private`
静默不生效）、`instanceof` 漏 `use` 同一族。**这一族纪律的价值不在「记住它」，而在配一条
lint 或一条 grep 判据** —— 第 13 条已有 lint，第 14 条的判据是
`grep -rn "__DIR__\|__FILE__" src/ --include=*.php`（当前只应命中 `FontLocator` 3 行）。

---

## 6. 错误码速查

| 错误码 | 约束 | 本仓痕迹 |
|---|---|---|
| **C2440** | 三元/属性读被推成 `php::Variant` 传给 `float`/`Int` 形参；`switch ($this->intProp)` 同型。**上游 #186 已修根因、本仓 18:04 T2 已验；workaround 仍保留（理由见 §8.4）** | 源码 5 处注释（暂不删） |
| **C2678** | `?:` 两支类型不同 ⇒ 无 `operator?:` 重载 | `ToggleBase.php:159` `:190` |
| **C2065** | 常量链 `A = B \| C` 而 `B = X \| Y` | `CanvasCapabilities::ALL_MASK` 注释 |
| **C2872** | 类常量与同类方法同名 | 改名（无残留） |
| **C3861** | `drawImageRect` 有 4 参/6 参两重载；`SkPath` 无构造方法 ⇒ `SkPathBuilder` + `detach()` | `BACKPORT-19OP.md` |
| **C2653** | **Skia API 改名**：`SkGradientShader`（旧，一个类+静态工厂）→ `SkShaders::LinearGradient/RadialGradient`（新，命名空间下的自由函数）。★ 与 PHP 侧"非限定函数不回退全局"**不是一回事**（那条报运行期 Fatal） | `cpp-src/skia/drift_skia.cc` `php_drift_skia_set_gradient` docblock |
| **C2660** | 函数参数个数不符：`SkDashPathEffect::Make(intervals,count,phase)` 3 参 → `Make(SkSpan,phase)` 2 参 | `cpp-src/skia/drift_skia.cc` `php_drift_skia_set_dash` docblock |
| **C2129** | 匿名命名空间在第 230 行关闭 ⇒ NS 内 forward-declare 的 static helper 定义也必须留在 NS 内 | `cpp-src/skia/drift_skia.cc` `} // namespace` 处 + `applyPaintSource()` docblock |

★ 后三行是 `08` §1.2 的记账缺口（"仓库里零线索"）。本文件补上**判据**，
`cpp-src/` 的具体位置注释由 **D-2** 补。

---

## 7. 维护

- **只记当前有效结论**：已推翻 / 已解禁的旧约束从条文里删掉，结案与边界集中放 §9；
  不在条文里堆「当时 / 现已 / 别串台」的订正层。
- **新踩一条 AOT 坑**：先加进本文件（§1 判据表 + §6 错误码），再写进源码注释（若有具体位置）。
- **外部证据**（Drift3 / 成熟框架探针等）：登记时写清来源、编译器版本与**本仓是否独立复测**；
  没复测的只进「纪律」，不得写成 §2 那种实测结论。
- **口径要写清 `bin` / `lib`**：`apps/*` 是 `bin`（出 exe），`tools/drift-codegen.php` 是 `lib`
  （SDK 分发）。同一份源码在两种模式下分派形态**可以不同**（§9.3 的链式就是），
  只写「编译器修了」会把 `lib` 口径的遗留当成已解禁。
- **上游编译器修复**：登记时必须写清**提交哈希 + 实际构建入口含不含 + 本仓复测到哪一层
  （翻译层 / T2 编译链接 / 真机）**。「上游已修」≠「本仓已生效」≠「可以删 workaround」——
  三层结论要分别给证据（§8 / §9）。
- **M0 重跑**：`php tools/drift-build.php bench` → `php tools/bench-run.php`。
  数字波动 > 20% 时跑 3 次取中位（`08` §9 边界情况 4）。
- **本文件与 `08` 的关系**：`08` 是方案稿（含推演与备选），本文件是**结论台账**；
  冲突时以本文件为准，历史方案稿不回改。

---

## 8. 上游 #186 与当前编译器状态（2026-11-11）

> `swoole/typephp` `bff46481`（#186）是当前默认入口的 HEAD。本节只记**现行结论与证据层级**；
> pin 事故与订正过程已结案，不复述。

### 8.1 修了什么（生成不出可编译 C++，不是运行期静默 bug）

| 形状 | 旧 codegen | #186 之后 |
|---|---|---|
| **`readonly` 标量属性读取**（喂给 `switch` 判别式 / 类型化返回 / 标量形参） | readonly 被排除在标量引用提升之外 ⇒ 通用读返回 `php::Variant`，拿来初始化 `php::Int`/`php::Float` 局部变量 ⇒ C2440 | 走 `typephp_read_property_scoped(...)` + `php::toInt` / `toFloat` / `toBool(...)`；覆盖 `$this`、形参、局部、表达式接收者；**未初始化槽仍抛 `Error`**，不会悄悄变 0 / false |
| **string 三元两支「声明类型相同、存储类型不同」** | 属性读降到 `php::Variant`、字面量降到 `php::String` ⇒ 无公共 C++ 类型，编不过 | 两支统一 `php::toString(...)`；**其它标量本就有公共存储类型，无需改** |

★ **不在修复范围**（别顺手撤纪律）：
- `?:` 两支**声明类型不同** ⇒ C2678 / `php::Var` 合流（§1.2、§5-2、评审稿 §8 第 6 条）
  **仍成立**；#186 统一的是「存储类型」，不是「静态类」。
- 上游只验证 Linux PHP 8.4/8.5（51 测试 × 171 断言 + 103 个 PHPT），
  **未覆盖 Windows/MSVC**。

### 8.2 本仓独立复测（翻译层 + MSVC 产物）

| 层级 | 证据 |
|---|---|
| 翻译层 `drift-codegen`（同一份 `src/`） | 源码树 `bff46481`：510 php / 450 cc / 0 skip，`php::to*(typephp_read_property_scoped` **1751** 处；对照旧 pin（`d4f8c3ca`）同形状 **0** 处 |
| MSVC 产物 `apps/users/build`（355 个 `.cc`，18:04） | 同形状 **1729** 处、`typephp_call_closure` **4** 处（与评审稿 §4.1 预测的「只多 4 处排序回调」吻合） |
| 可编译 / 链接 / 起窗 | §8.4 的 T2 |

样本 `apps/bench/src/M0Nullable.cc`：`#186` 下是
`tmp_var_0 = php::toFloat(typephp_read_property_scoped(...))`；旧 pin 下是
`php::Var tmp_var_0` + `throwReturnTypeError` + `php::toFloat(tmp_var_0)`。

**未独立复测**：string 三元那一半（本仓 `Segment` / `ToggleBase` 都拆成 `if/else`，
刻意避开该形状），只有上游 codegen/PHPT 覆盖；「未初始化 readonly 槽抛 `Error`」也属
#186 新增语义，未做专用真机探针。

### 8.3 当前入口（pin 已解开）

旧 Windows PHPX（`typephp-windows-x64-0.9.6`）缺 `typephp_call_closure` ⇒ 曾显式
`DRIFT_TPC_ENTRY` 指向 `tpc-pin`（`d4f8c3ca`）；用户更新 phpx 并重编 tpc 后，新 `phpx.lib`
补齐该符号（COFF 符号表 5716 → 5718，差异恰好是这一个），pin 的理由消失。

⇒ **`DRIFT_TPC_ENTRY` 现为空**，实际构建入口 = 默认源码树 `D:/TypePHP/typephp/bin/tpc.php`
（HEAD `bff46481`，含 #186）；`doctor --scope=gate` 已确认。旧包只是归档目录，不参与构建。

### 8.4 T2 实证，以及为什么不顺手撤 workaround

| 判据 | 结果 |
|---|---|
| `php tools/drift-build.php users --full`（默认入口 + 新 PHPX） | **EXIT=0** · `users.exe` 13.6 MB / 183.9s · 无 C3861 / LNK2019 / C2440 |
| 产物形状（`apps/users/build`，355 个 `.cc`） | `php::to*(typephp_read_property_scoped` **1729** 处 · `typephp_call_closure` **4** 处 |
| `probe-window users 用户管理`（真机） | **0 FAIL / 5 条**（含 DPI V2 与退出码 0 ⇒ 新 `phpx.dll` 运行期导出也接上） |

⇒ **#186 在 Windows/MSVC 下可编译、可链接、可运行**（上游只验了 Linux PHP 8.4/8.5，
这份是它缺的平台证据）。但 §1.2 / §1.5 / §5-1、§5-6 与源码 5 处 C2440 workaround
**仍保留**，理由换成三条，不再靠「pin 未含」：

1. #186 **不修** `?:` 两支声明类型不同（C2678），`ToggleBase` 的拆写仍必要；
2. 「未初始化 readonly 槽抛 `Error`」是 #186 新增语义，**本轮未专门验**；
3. 这些写法有真实消费者、零成本，全仓 `switch` 零使用 ⇒ 撤掉没有收益，只多一个回归面。

**诚实边界**：本轮未跑帧税 / `aot-equivalence`（后者要另建 `frame_tax_aot_probe.exe`，
~1m20s），也未做「未初始化 readonly 槽」的专用真机探针；要撤 workaround 得先补这三条。
别把「T2 构建 + 真机起窗」写成「AOT↔解释器等价已复验」。

---

## 9. 上游 #183 / #184 / #185 已解禁的能力与边界（2026-11-11）

> **结论先行**：链式调用、`?Obj` / 纯对象复合类型退化、typed property / 常量 / 容器 /
> 接口场景的丢类**均已解禁**；本仓默认入口 `bff46481` 全部包含。
> 本节是「解禁了什么 + 哪些边界仍在」的结论台账，也是 §1 / §5 里撤销条目的依据。
> 官方 Issue 原文：[swoole/typephp#184](https://github.com/swoole/typephp/issues/184)。

### 9.1 上游提交链（按时间）

| 提交 | 时间 | 主题 |
|---|---|---|
| `963c0ae1` | 11-09 12:04 | `?Class` 声明保留 typed object 存储 |
| `340a8589` | 11-09 12:26 | 消除已证明的多余可空对象检查 |
| `186f8921` | 11-09 13:32 | 自举期处理 nullable typed objects |
| **`b7e94b39`** | **11-09 15:49** | **#183 链式调用保留静态类型**（复合 PR） |
| `95e4f3e6` | 11-10 | 保留标量常量存储与 lowered types（外部类常量求值成 0 的上游修复） |
| `517d0df0` | 11-10 | typed property locals 保留对象类 |
| `2665ac53` | 11-11 | 保留 `??` / `?->` 的结果类型 |
| `c456b154` | 11-11 10:47 | object-only 复合类型用对象存储 |
| `9df22dc8` | 11-11 15:18 | **#185** 可空 interface 返回仍保留运行期检查 |
| `bff46481` | 11-11 17:01 | #186（见 §8.1） |

★ 上表 11-10 ~ 11-11 的 6 个提交里，`95e4f3e6` / `517d0df0` / `2665ac53` /
`c456b154` 属于 **`swoole/typephp#184`**（**Issue 不是 PR**，基线 `master 3d01d5ab`）；
`9df22dc8` 是 #185、`bff46481` 是 #186，都不在这份清单里。官方完整修复清单共
**11 个 commit**：`517d0df0`、`2665ac53`、`95e4f3e6`、`129b9af9`、`55222720`、
`0e5568df`、`e59be996`、`d38265a8`、`c456b154`、`f6185155`、`49818941`
（逐条修了什么见 §9.4.1）。`c3e52aee` / `3959b2ef`（Closure invoke 特化）不属这一批。

硬判据：`git merge-base --is-ancestor <commit> HEAD` 对 `tpc-pin`（`d4f8c3ca`）与
默认源码树（`bff46481`）都是 **11/11 为真**，`9df22dc8` / `bff46481` 对 pin 为假
⇒ 评审稿 §3.1 的「修复后」就是含 #184 的口径。

#183 不只改了链式：同一 PR 里还有 `eval` / `include` 保留 Zend 调用帧、
「不可访问声明 / 子类覆盖 / `__call`」回退动态分派、`: static` 返回类型、
深链编译从指数降为线性。上游 `ChainCallTypeInferenceTest` 的判据是
`assertStringNotContainsString('typephp_call_method_cached', $body)` —— 断言的是
**去虚化**，不是"类型不退化"这么弱。

### 9.2 本仓硬证据一：`?Obj` 形参不再退化（同一源码、新旧产物 A/B）

| 产物 | 编译器 | `Frame::run` 的 `?\Closure $afterPaint` | 调用侧 |
|---|---|---|---|
| `apps/bench/build/src/App/Frame.cc:168`（11-05） | #183 之前 | **`php::Var afterPaint`** | `php::Var arg_afterPaint = php::getCallArg(4, ...)` |
| `apps/users/build/src/App/Frame.cc:161`（11-11 18:04） | `bff46481`（含 #183/#185/#186） | **`php::Object afterPaint`** | `php::Object arg_afterPaint = ([&](php::Var v) -> php::Object { ...isNull() ... php::toObject(v, PersistentClassId{31}) })(...)` |

⇒ 同一个 `Frame::run` 签名、同一个形参 `?\Closure $afterPaint`，两次构建相隔 6 天、
方法签名未变，只换编译器版本 ⇒ 退化消失；新产物经过 **T2**
（`drift-build users --full` EXIT=0 + 真机 `probe-window` 5/5，见 §8.4）。
`apps/users/build` 里 79 个 `.cc` 出现 `typephp_nullable_object_value` guard，不是孤例。
§2.2 的形参层结论已按本节更新；字段层证据与 §3.2 决策不受影响。

★ **不是孤证**：`docs/REVIEW-TPC-DEVIRT-2026-11-11.md` §8 第 1 条（同一天下午、
独立做的产物统计）已登记 `?Klass` 声明处 属性 140 / 形参 97 / 返回 198「已不再降级」，
归因同为 `963c0ae1` + `340a8589`，并给出 `$this->focus->collect()` ⇒
`php_drift__app__focusmanager__collect(focus, …)` 的直调证据。
那份是**声明处 + 产物统计**，本节的 A/B 是**同一形参跨编译器版本**，两条路径互不相同。

### 9.3 本仓硬证据二：链式在 `bin` 下已去虚化（最小夹具）

夹具 `_codegen/chain-probe/`（`ChainProbe.php` + `Main.php`，两个 yml 分别 `mode: lib` /
`mode: bin`）—— **一次性诊断物，位于 gitignore 的 `_codegen/`，不入仓**；
等价最小源码见 §9.5，clone 后按那份自建。同一份源码用**当前默认入口**
`tpc --dry` 各跑一次：

| 写法 | `bin`（真实应用口径） | `lib`（SDK / `drift-codegen` 口径） |
|---|---|---|
| `Box::make()->add(1)->add(2)->value()` | 4 跳**全静态直调**（`php_chainprobe__box__*`），`typephp_call_method_cached` **0** | 中间量已是 `php::Object`，但 3 跳仍 `typephp_call_method_cached` |
| `split()`（落局部变量） | 全静态直调，与上者**同形** | 全静态直调 |
| `(new Box())->add(3)->value()` | 全静态直调 | 2 跳仍 `typephp_call_method_cached` |

⇒ `bin` 下链式是 **4 直调 / 0 动态 / 0 个 `php::Var`**（§1.5.1）。
⇒ 但**口径差异是真的**：`lib` 模式下类型保留生效、去虚化不生效。
`tools/drift-codegen.php` 走 `lib`，拿它的产物断言应用性能会看反。

### 9.4 结论台账（现行）

| 项 | 现行结论 |
|---|---|
| `?Obj` 形参 / 返回值退化 | **已解禁**（`php::Object` + guard，§9.2） |
| 链式 / 临时对象接收者 | **已解禁**（`bin` 口径全静态直调，§9.3）；`lib` 口径仍有动态分派 |
| `?Obj` **字段**用「非空 + bool」 | **不变**：字段层无量级差（§2.2），且 `?->` 静默坑仍在 |
| `?->` 空安全调用 | **不变**：属性查表机制，与类型退化无关；只约束跨实例读 `private` / `$this->可空字段?->` 那一族 |
| `?:` 两支声明类型不同 ⇒ C2678 | **不变**（#183 / #186 都不覆盖） |
| `int / int` 整数除法 / `switch` / 反射静态链接 / `__DIR__` | **不变**（§1.2 / §1.4 / §8.4） |
| typed property→局部 / 常量算术 / `??` / typed array / 单实现 interface / 闭包 / 纯对象联合 | **#184 已解禁**（§9.4.1）；真实混合联合 / 引用 / 可覆写调用仍保留运行时表示 |

#### 9.4.1 #184 去虚化批次：官方 Issue 原文摘要与本仓复核

**官方 Issue 事实**

| 项 | 值 |
|---|---|
| 类型 | **Issue**（不是 PR）· `swoole/typephp#184` |
| 标题 | `[AOT] 静态类型在属性赋值、常量、容器和接口场景丢失，导致去虚化失效并回退到动态派发` |
| 基线 / 环境 | `master 3d01d5ab`，`build-mode: bin`；**Linux x86_64 + GCC，PHP 8.4.21** |
| 判据 | 生成的 `.cc` 里目标函数是否出现 `typephp_call_method_cached` / `php::Var` |
| 复现工程 | `issueprobe`（`shapes.php` / `models.php` / `main.php`），`--dry --job 1 --no-progress` |
| 状态 | 评论确认「已在 `master` 完成六项主体修复及附加优化」，并补生成代码断言 + 运行时回归 |

**五个主场景**（每个都附最小复现与实测生成代码）：

| # | 写法 | 修复前现象 | 根因落点 |
|---|---|---|---|
| 1 | `$s = $this->style; $s->sum()`（typed property → 局部） | `php::Var` + 动态分派；**同类的直接链式 `$this->style->sum()` 反而已能静态直调** | `detectClassOfExpr()` 的 `PropertyFetch` 只认 native object；`AssignOpTrait::parseAssignFinally()` 拿不到类就不绑定 dispatch class；沿返回值 / `clone` / 形参属性级联 |
| 2 | 命名空间常量 / 类常量参与算术位运算 | `php::Var(_const_var_…)`、`php::Var(1023L)` 装箱 | 四类独立退化：全局常量声明期 `Type::VAR`、fetch 返回 VAR 全局槽、类常量内联时 `valueExpr === null` 留空类型、预处理只推 Array/Str |
| 3 | `$this->style ?? new WbStyle(0)` / `?->` | 两侧都装箱、后续全动态；一条 `$this->maybe?->sum() ?? 0` 生成 3 个 IIFE | `detectTypeOfExpr` 无 `Coalesce` 分支、`getOrderedOperandTmpType` 对非 native 降 VAR |
| 4 | 单实现 interface 形参 `$c->countUp()` | `typephp_call_method_cached` | 无 `interface → implementors` 注册表；`findNativeMethod()` 失败即落动态派发 |
| 5 | `#[\StdList(User::class)]` 属性的 `$list[0]->name()` / `foreach` | 循环变量 `php::Var`、属性读 `typephp_read_property_cached`、累加器被拖成 VAR | `getTypedArrayDefinition()` 只识别 `Variable` 根，不识别 `PropertyFetch`；SSA 显式不对 foreach 目标收窄 |

其它已确认同类退化：`clone`、数组字面量、解构、闭包参数 / `use` 捕获 / 闭包返回、
union / nullable / intersection 声明、`$o instanceof T` 之后调用。

**官方修复清单（11 个 commit）**

| commit | 修了什么 |
|---|---|
| `517d0df0` | typed property / 局部变量 / clone / 方法返回链 / `??` / nullsafe 保留可证明对象类型与固定存储 |
| `2665ac53` | 属性读取、惰性求值、未初始化与 unset 语义回归覆盖 |
| `95e4f3e6` | 常量从 AST 与结构化元数据恢复存储类型 |
| `129b9af9` | 固定字符串、布尔表达式、内建函数真实联合返回类型 |
| `55222720` | typed array 属性索引读取与 foreach 元素契约（保留元素校验 / 引用限制 / COW） |
| `0e5568df` | 接口**同文件单实现者**生成精确类守卫 + 静态快路径 + 动态回退 |
| `e59be996` | 闭包已声明参数、按值捕获、可证明局部返回类型 |
| `d38265a8` | 未修改且无引用逃逸的数组字面量、已知键、嵌套解构元素类型 |
| `c456b154` | 纯对象联合 / nullable 对象联合 / 交叉类型 / DNF 对象声明 → `php::Object` |
| `f6185155` | 复合对象声明的安全动态局部存储；`instanceof` 真分支类型上界、final 直调 |
| `49818941` | 回归：trait 等值常量合并时「推断存储类型 ≠ 显式声明类型」 |

★ `c3e52aee` / `3959b2ef`（Closure invoke 特化）**不在 #184 清单**，属另一批。

**官方验证（只覆盖 Linux/GCC）**：PHP 8.4 纯 PHP 回归 **3484 项 / 10160 断言**通过；
PHP 8.5 **3484 项 / 10143 断言**通过（4 项依赖可选 `phpy` 跳过）。Linux PHP 8.4 定向回归
`devirtualize` / `dynamic_call` / `optimizations` / `typed-array` + 新增闭包 / 数组 / 复合类型 /
instanceof / varint 溢出 / trait，累计 **59 PHPT + 2 追加**通过。最小复现工程实跑输出 `u`、
退出码 0，**10 个目标函数**生成代码检查通过。自举生成 **181 个 C++**，15 个受影响翻译单元
过 GCC C++17 语法检查（trait 修复后 Translator 4 个翻译单元再查一遍），单任务、
进程树内存上限 2 GiB、禁 swap、无 OOM。
官方收口原话：**「动态值、真实混合联合、引用和可覆写调用保留其必要的运行时表示与校验」**。

**本仓 A/B 与复核**（同一份 `src/`，只换编译器；评审稿 §3.1）：

| 指标 | 修复前 | 修复后（12:45 pin） | 18:04 默认入口 |
|---|---:|---:|---:|
| `typephp_call_method_cached` | 1593 | **1128**（−29.2%） | **1131**（+3 ⇒ #186 不改去虚化） |
| 静态直调 `php_drift__*__*()` | 12293 | 12663（+3.0%） | 未重数 |
| `php::Var` 出现次数 | 27381 | 24336（−11.1%） | 未重数 |
| exe 体积 | 13.6 MB | 13.3 MB | 13.6 MB（含 #186） |

本轮夹具（`_codegen/chain-probe/`，`bin --dry`）：`Box|Probe`（纯对象联合）
⇒ `php::Object x`（命中 `c456b154`）；`int|string`（真实混合联合）⇒ `php::Var x`
（命中官方「真实混合联合保留运行时表示」）。两条都走了与官方不同的路径，不是抄判据。

**结论台账与边界**

- **§1.1 结论**：`?Obj` 与**纯对象联合 / 交叉类型 / DNF 对象声明**不再退化；仍退化的是
  真实混合联合、`mixed`、`callable`、`?array`、异型三元合流。
- **#183 与 #184 不是同一件事**：#183 修「方法返回值再当接收者」的链式接收者静态类；
  #184 修「typed property / 常量 / 容器 / 接口」六类丢类。官方 Issue 里
  `styleSumChain()`（直接链式）**在修复前就已静态直调**，正是二者边界的证据。
- **仍有动态分派是设计**：非 final 基类方法可覆写 ⇒ 保留动态派发（Issue 场景 1 明写）；
  interface 特化**只对同文件单实现者**、且必须带 `instanceof` 守卫（`eval` 可运行期新增实现类、
  形参可收外部对象）；`instanceof` 收窄只在**真分支作用域内**，分支外恢复；
  复合对象声明后续若有动态重赋值 / 引用操作，退回安全动态局部存储。
  ⇒ 与评审稿 §8「别把多态当缺陷」及本仓 1131 处剩余分派一致。
- **别顺手撤的**：§5-7 常量链 C2065 / §5-8 类常量与同名方法 C2872 是 **C++ 语法与命名冲突**，
  与 #184「常量参与算术时类型退化」不是一回事；§5-1/2/6 的 C2440 / C2678 系列 #186 也不覆盖。
  §2.2 字段层与 §3.2 决策仍按评审稿「不该改」不变。
- **诚实边界**：官方只验 Linux x86_64 + GCC / PHP 8.4-8.5；本仓 Windows/MSVC 侧只有
  18:04 的 T2（可编译 / 链接 / 起窗）与本节翻译层夹具，**没有**重跑帧税 / `php::Var` 计数 /
  27 页尺子，也没有把官方 10 个目标函数的生成代码断言搬进本仓。

**上游回退条件（别把"解禁"读成"无条件"）**：`eval` / `include` 接收者必须保留
Zend 帧；声明不可访问、子类覆盖、`__call`、interface contract 那一跳会**回退动态分派**
（上游 `testInterfaceContractKeepsTypeAndResumesDirectDispatch` 明确断言还有 1 次
`typephp_call_method_cached`）。本仓 `Widget` fluent 是 `final` / `self` 链，落在已解禁那一半。

### 9.5 诚实边界与复现

- 夹具只走 **`tpc --dry` 翻译层**；`users.exe` 的 T2（§8.4）证明的是整棵 `src/` 在
  Windows/MSVC 下可编译链接可运行，**不是**专门测链式热路径的性能。
  没有重跑帧税 / `aot-equivalence`，也没有给链式做 AOT 计时。
- 字段层（§2.2 证据二/三/四）、`A|B` / `mixed` / `callable`、`?int` / `?string`（V-1）
  **本轮未复测**，只在有直接证据的两条上改写结论。
- `lib` 口径差异是本轮新发现，未评估它对 SDK 分发形态的性能影响
  （本仓目前不分发 SDK，`mode: lib` 只服务于 `drift-codegen` 这道门）。
- 历史稿按 §7 不回改：`DEBT` §55.4、`docs/arch-v2/*`、`ARCH-REVIEW-2026-10-21.md`
  里"链式只留冷路径"是当时的登记，冲突以本节为准。

**前置**：先跑一次 `php tools/drift-codegen.php`，让它生成 `_codegen/fake-toolchain/cl.exe`
与 `_codegen/ini/php.ini`（`--dry` 也要校验工具链，假 `cl.exe` 够用）。
夹具（放 `_codegen/chain-probe/`）：

```php
// ChainProbe.php
<?php
declare(strict_types=1);
namespace ChainProbe;

final class Box {
    private int $v = 0;
    public static function make(): self { return new self(); }
    public function add(int $x): self { $this->v = $this->v + $x; return $this; }
    public function value(): int { return $this->v; }
}
final class Probe {
    public function chained(): int { return Box::make()->add(1)->add(2)->value(); }
    public function split(): int { $b = Box::make(); $b->add(1); $b->add(2); return $b->value(); }
    public function fromNew(): int { return (new Box())->add(3)->value(); }
    public function run(): int { return $this->chained() + $this->split() + $this->fromNew(); }
}
```

两份 yml 只差 `mode` 与 `build-dir`：`mode: lib` → `build-dir: ./build`；
`mode: bin` + `platform: win32` → `build-dir: ./build-bin`，`sources` 里再列一个
`Main.php`（全局 `function main(): void` 调一次 `run()`，避免整段被优化掉）。

```bash
cd _codegen/chain-probe
PHPRC='D:/TypePHP/Drift2/_codegen/ini' PHPX_HOME='D:/TypePHP/typephp-windows-x64/phpx' \
  D:/TypePHP/typephp-windows-x64/php D:/TypePHP/typephp/bin/tpc.php \
  --project project-bin.yml --dry --compiler ../fake-toolchain/cl.exe
grep -n 'probe__chained\|probe__fromnew\|typephp_call_method_cached' build-bin/ChainProbe.cc
```

判据：`php_chainprobe__box__*` 出现、`typephp_call_method_cached` **不出现**于
`chained()` / `fromNew()`；再把 `--project` 换成 `project.yml` 重跑一次，
`lib` 产物里这两处应当**仍有** `typephp_call_method_cached`（口径差异才能被证伪）。

