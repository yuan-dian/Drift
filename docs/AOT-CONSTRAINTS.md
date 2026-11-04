# AOT 约束总表（Drift2 · tpc → C++17）

> 状态：**已落地** · 2026-10-21 · W0 的 D-1 步
> 来源：`docs/arch-v2/08-编译约束与实测.md` §2（判据表）+ W0 的 M0 三条实测结论
> 回答两个问题：**哪条约束否掉了哪个 API？哪三条实测的结论是什么？**

---

## 0. 这个文件为什么存在

`08` §0 的账：**18 条约束分散在四个地方，没有一处完整** ——
源码注释只记 5 条、构建工具注释只覆盖工具链、MEMORY 记 15 条但不在仓库里、
v6 `13` 册记 12 条但口径是"从零设计"。

★ 其中最贵的一条：**`C2653` / `C2660` / `C2129` 曾在 `cpp-src/` 里零线索**
（`08` §1.2 当时的账；D-2 已把三条补进 `cpp-src/drift_skia.cc` 的对应代码处）。
★ 顺带纠一个**张冠李戴**：`08` §1.2 把 C2653 描述成"命名空间内非限定函数
不回退全局 ⇒ 必须 `\`"—— **那条是 PHP 侧规则，报的是运行期
`Call to undefined function`，不是 MSVC C2653**。本仓真正的 C2653 是
**Skia API 改名**（`SkGradientShader` → `SkShaders::LinearGradient`）。
两个都叫"C2653"纯属巧合，别混。

本文件是那"一处完整"。它是 `08` §2 判据表的落点，也是 M0 三条实测结论的家
（`08` §8：M0-a 的产出必须写进仓库，**不是只在会话里**）。

**维护纪律**：新踩一条 AOT 坑，先加进这里，再写进源码注释（如果有具体位置）。

---

## 1. 判据表：约束 → 被否掉的候选 → 采用的形态

格式抄 v6 `13` §10。每条三列。

### 1.1 类型退化

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| `?Obj` / `A\|B` / `mixed` / `callable` 退化成 `php::Var`（**M0-2 已确认，见 §2.2**） | 用 `?RenderBox` 做**热路径**字段 | `RenderBox $child` + `bool $hasChild`（`RenderProxyBox` 的形态） |
| 同上 | `?float $virtualExtent` 做**每帧读**的字段 | `float $virtualExtent = \NAN` 哨兵（`RenderScrollView` 的形态；`02` §3.2 的 `Modifier::$width` 同法） |
| 同上 | `Cell::$owner` 用 `?State` | `State $owner` + `bool $bound`（`03` §3.2） |
| `?int` / `?string` **推断**不退化（v6 V-1，未实测） | 依赖它做设计 | 一律不用（用 `NAN` / `-1` 哨兵） |

★ 第三行是 `03` 的核心改动之一。**M0-2 之后这条的性能理由被撤掉了**
（见 §2.2：字段层两种形态无量级差），**人因理由仍在**（读点不写 `?->`）。

### 1.2 语法

| 约束 | 否掉了 | 采用了 |
|---|---|---|
| `switch ($this->intProp)` 生成 `php::Int tmp = this_.attr(...)` ⇒ **C2440** | `switch` 语句（**全仓零使用**，所以从未验过） | `int $kind` + `===` 比较链（`ToggleBase::activate()` / `Curve::map()`） |
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
| `readonly` **慢 10×+**（v6 断言） | 热路径字段用 `readonly` | ~~`private` + getter~~ **M0-1 已推翻此条，见 §2.1** |
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
| `readonly` 慢 10×+（**M0-1 已推翻**） | `final readonly class` 做热路径 | 只给几何值对象用（`Size`/`BoxConstraints`/`EdgeInsets`/`Rect`/`Point`/`Scale` 六个） |
| 临时构造立即调用无法优化 | `(new Foo())->bar()` | 先落局部变量 |
| `#[\Native]` 值对象省装箱（v6 V-2，未实测） | — | 现在用 `final readonly class`（32 个） |
| **属性读直接当实参** ⇒ **C2440**（本仓 5 次，**无 lint**） | `$f($this->box->maxWidth)` | 先落局部标量再传 |
| ★★ **AOT 不必然更快**（2026-11-04 实测，详见 §2.5） | 「AOT 产物一定比解释器快」这个默认假设 | 性能判断一律在 **AOT 口径**复量；解释器数字只作相对参考。实测 12k 节点布局栈 AOT 反而**慢 1.7–2.7×** |

★ 最后一行是**本仓最高频的 AOT 坑**（5 次 C2440 同一形状），而它**没有 lint**
—— "哪个形参是 `float`"要跨类类型推导，PHP 没有静态类型 ⇒ **只能靠纪律 + T2 兜**。
这是编译约束里唯一一条不能自动守的。

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

### 2.2 M0-2 `?Obj` 是否退化 —— **形参层确认退化；字段层两种形态无量级差**

★ 这条**不计时**（`08` §3.4：答案在生成物里，是二值的；计时会被内联/缓存干扰）。
夹具 `M0Nullable.php` 的两个类都**真的跑一遍并断言读回值** ——
否则 AOT 只编译可达代码 ⇒ 类被优化掉，grep 到的是"不存在"而不是"退化了"。

#### 证据一：形参层（铁证，同一函数签名内并列）

`src/App/Frame.php` 的 `run(Element $root, BoxConstraints $constraints, float $timestampMs, ?Element $popup, ...)`
生成物（`apps/bench/build/src/App/Frame.cc:25`）：

```cpp
void php_drift__app__frame__run(
    php::Object &this_,
    php::Object root,          // Element $root      → php::Object
    php::Object constraints,   // BoxConstraints     → php::Object
    php::Float  timestampMs,   // float              → php::Float
    php::Var    popup,         // ?Element $popup    → php::Var   ★ 退化
    php::Float  popupX,
    php::Float  popupY);
```

⇒ **可空对象在形参层确实退化成 `php::Var`**，非空对象是 `php::Object`。
调用侧也随之变成 `php::takeValue(arg_popup)`（`arg_popup` 本身是 `php::Var`）。

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
| 形参 / 返回值 | **退化成 `php::Var`** | `php::Object` |
| 字段存储 | PHP 属性表（同形态） | PHP 属性表（同形态） |
| 字段读点 | 多一次空检查 | 多一次布尔检查（若用 `+bool` 哨兵） |
| 属性访问分派 | 内联缓存读 | 内联缓存读（**非静态**） |

⇒ **`03` 的 `Cell::$owner` 从 `?State` 改成 `State` + `bool` 没有性能收益**；
   收益是**人因**的（读点不写 `?->`）。这与 `08` §2.1 第三行已写的一致
   （"M0-2 只影响性能理由，不影响人因理由"）—— 现在**性能理由也被确认是"无收益"**。
⇒ `02` §3.1 的 `?Color` → `Color` + `bool` 同理：收益是人因，不是性能。

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

### 2.4 顺带：本轮新抓到的两条（都不是 M0 计划的）

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

**为什么量**：`DESIGN.md` §13.5 与 `DEBT` §28 里「12k 全量重排 51ms / 6.2× 触线 /
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

**原因（2026-11-04 晚已定位，见 §2.7）**：**不是装箱**。是「虚调用 + 小对象分配 + 闭包创建」
这三样在 AOT 下各慢 1.6–2.0×，而这个负载几乎不含能被 AOT 优化掉的算术（算术那部分在
AOT 下快 15–20×，但布局里本来就少）。
★ 原先这里写的是「最可能的原因：`php::Var` 装箱」（假说），已撤回 —— 阶梯实测 `mixed`
拆箱在 AOT 下**快 2.3×**，与 §2.3 的 M2 一致。

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

**怎么量**：`tools/Bench/GeomFingerprint.php`。27 页每个 `RenderBox` 的
`宽/高@x,y`（三位小数）按 DFS 顺序拼接取 md5。

```text
php tools/geom-fingerprint.php --tsv > build/geom-interp.tsv
PROBE_SECTION=geom ./frame_tax_aot_probe.exe | grep -P '\t' > build/geom-aot.tsv
diff build/geom-interp.tsv build/geom-aot.tsv     ⇒ 空
```

**结果**：27 页 / **2278 个 `RenderBox` 逐值相同**。

★ 第一次跑**红了一页**（`AspectPage`，`sumW` 差 94），定位到单个节点：同一行文字
解释器算 384px、AOT 算 290px。原因不是框架 —— 是那一页里 2026-10-24 写下的
**活体诊断**（`'16 / 9 = ' . (string) (16 / 9)`），AOT 下真的打印 `1`。
⇒ 那行改成静态说明（它会让本页几何在两侧**必然**不同，指纹就永远有脏行），
检测交给 §2.7 探针每次打印的「前提」一行。改完后 27 页全绿。

★ **单页破案用明细**：`php tools/geom-fingerprint.php --detail=AspectPage` /
`GEOM_PAGE=AspectPage PROBE_SECTION=geom ./frame_tax_aot_probe.exe` —— 指纹说
"这页不一样"，明细才说"从哪一层的哪一轴开始不一样"。


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

## 5. 代码纪律 14 条

| # | 纪律 | 依据 |
|---|---|---|
| 1 | 热路径禁 `switch`，用 `int` + `===` 链 | C2440（`Curve.php:92`） |
| 2 | `?:` 两支类型不同 ⇒ 拆 `if/else` | C2678（`ToggleBase.php:159`） |
| 3 | ~~热路径禁 `readonly`~~ | **M0-1 已撤销（readonly 零 Hook）** |
| 4 | 禁 `(new Foo())->bar()` | 临时构造不能优化 |
| 5 | 父子类不声明同名 `private` | 父类 `private` 不进子类属性表 |
| 6 | **属性读不当实参**：先落局部标量 | C2440 ×5（本仓最高频，**只能靠 T2 兜**） |
| 7 | 常量链平铺成一次 `\|` | C2065（`CanvasCapabilities`） |
| 8 | 类常量不与同类方法同名 | C2872 |
| 9 | 命名空间内调全局函数写 `\` | 运行期 `Call to undefined function`（**不是** C2653，见 §6） |
| 10 | 循环内禁 `.=` 累加二进制串 | lint `str-concat-loop` |
| 11 | 可空对象字段优先用"非空 + `bool` 哨兵"或 `NAN` | `?Obj` 退化（**人因为主，见 M0-2**） |
| 12 | 二进制复合数据过桥只走打包字节串 | 桥 ABI 只认标量 + string |
| 13 | **内置编译期注解必须写 `#[\X]`** | lint `attribute-unqualified`（W0/A0 已落地） |
| 14 | **运行时路径禁 `__DIR__`/`__FILE__`**；用 `getcwd()` / 环境变量 / 显式参数 | 编译期常量 |

★ 第 13/14 两条的共同形状是**"不报错、只是没效果"**，与第 5 条（父类 `private`
静默不生效）、`instanceof` 漏 `use` 同一族。**这一族纪律的价值不在"记住它"，
而在"配一条 lint 或一条 grep 判据"** —— 第 13 条已有 lint，第 14 条的判据是
`grep -rn "__DIR__\|__FILE__" src/ --include=*.php`（当前只应命中 `FontLocator` 3 行）。

---

## 6. 错误码速查

| 错误码 | 约束 | 本仓痕迹 |
|---|---|---|
| **C2440** | 三元/属性读被推成 `php::Variant` 传给 `float`/`Int` 形参；`switch ($this->intProp)` 同型 | 源码 5 处注释 |
| **C2678** | `?:` 两支类型不同 ⇒ 无 `operator?:` 重载 | `ToggleBase.php:159` `:190` |
| **C2065** | 常量链 `A = B \| C` 而 `B = X \| Y` | `CanvasCapabilities::ALL_MASK` 注释 |
| **C2872** | 类常量与同类方法同名 | 改名（无残留） |
| **C3861** | `drawImageRect` 有 4 参/6 参两重载；`SkPath` 无构造方法 ⇒ `SkPathBuilder` + `detach()` | `BACKPORT-19OP.md` |
| **C2653** | **Skia API 改名**：`SkGradientShader`（旧，一个类+静态工厂）→ `SkShaders::LinearGradient/RadialGradient`（新，命名空间下的自由函数）。★ 与 PHP 侧"非限定函数不回退全局"**不是一回事**（那条报运行期 Fatal） | `cpp-src/drift_skia.cc` `php_drift_skia_set_gradient` docblock |
| **C2660** | 函数参数个数不符：`SkDashPathEffect::Make(intervals,count,phase)` 3 参 → `Make(SkSpan,phase)` 2 参 | `cpp-src/drift_skia.cc` `php_drift_skia_set_dash` docblock |
| **C2129** | 匿名命名空间在第 230 行关闭 ⇒ NS 内 forward-declare 的 static helper 定义也必须留在 NS 内 | `cpp-src/drift_skia.cc` `} // namespace` 处 + `applyPaintSource()` docblock |

★ 后三行是 `08` §1.2 的记账缺口（"仓库里零线索"）。本文件补上**判据**，
`cpp-src/` 的具体位置注释由 **D-2** 补。

---

## 7. 维护

- **新踩一条 AOT 坑**：先加进本文件（§1 判据表 + §6 错误码），再写进源码注释（如果有具体位置）。
- **M0 重跑**：`php tools/drift-build.php bench` → `php tools/bench-run.php`。
  数字波动 > 20% 时跑 3 次取中位（`08` §9 边界情况 4）。
- **本文件与 `08` 的关系**：`08` 是方案稿（含推演与备选与否决），本文件是**结论台账**。
  两者冲突时以本文件为准，并回头修 `08`。

