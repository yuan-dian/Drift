# Drift 架构 v2 · 10 AOT 能力增补

> 状态：方案增补稿 · 2026-10-21 · 未写一行实现代码
> 前置：`00`–`09`。本册**不改架构**，只回答一个问题：
> **编译器给了我们哪些"零成本"的东西，我们一个都没用？**
> 输入：重读 `Drift3/docs/TypePHP-docs-总览.md`（中立版）+ `typephp-docs/zh-cn/best_practice.md`（官方纪律）+ 本仓实测

---

## 0. 一句话

**`src/` 里 10 种编译期注解的使用量是 `0`。其中 `#[\Override]` 一个注解就能把 `09` 里最危险的那处"静默失效"变成编译错误 —— 顺带让 `tools/overrides.php` 这个新工具不必存在。但前提是写对名字：`#[Override]` 在命名空间里是静默无效的（A0）。**

`best_practice.md` 另外给了两条本仓**还没内化**的硬纪律，都不是"优化"而是"避开陷阱"：
`__DIR__`/`__FILE__` 在 AOT 下是**编译期**路径（A8），以及 `readonly` 的替代**只有**
`private` + `#[\Getter]` 一种官方认可形状（A3 的背书 —— 恰好是本册已选的那条）。

---

## 1. 现状：零 Attribute

```bash
cd /d/TypePHP/Drift2
# ★ `\\?`：注解既可能写成 `#[\Override]`（正确）也可能写成 `#[Override]`（静默无效），两种都要数
grep -rnE '#\[\\?(Native|Getter|Setter|With|Immutable|Hot|Cold|Override|MustUse|Constructor)\]' \
     src/ --include='*.php' | wc -l                # 0
grep -rn "^#\[" src/ --include='*.php' | wc -l     # 0（行首注解）
```

★ 那个 `\\?` 不是装饰：`grep '#\[Override\]'` 只命中**漏反斜杠**的那种写法 —— 恰恰是不想要的。
本册的每一条 grep 判据都按这个写法给（`09` §12 ⑦ 组同）。

| 事实 | 数字 |
|---|---|
| `src/` 里 Attribute 使用 | **0** |
| `final readonly class` | **32**（几何 6 / 绘制 8 / 文本 7 / 输入 5 / 其它 6） |
| `readonly` 总出现 | **187** |
| `paint(Canvas $canvas)` 签名出现 | **22**（接口类型参数） |
| C++ 侧 Skia 句柄解析 | `std::map<int, SkiaSlot*>`，**18 个桥函数各一次 `find`** |
| **无类型参数**（退化 `any`） | **0** |
| **无类型属性**（退化 `any`） | **9** —— 全是 callable 槽，见 §4 |
| **`global` / `$GLOBALS`** | **0** |
| **`__DIR__` / `__FILE__`** | **3 行 / 1 处代码**（全在 `FontLocator::candidates()`：1 行 `dirname(__DIR__, 2)` + 2 行注释；已降级为第三候选，见 A8） |

★ 这不是"不会用"，是**从来没有人系统地过一遍"编译器给了什么"**。
`08` 册把 18 条**约束**整理成表了，但**能力**那一面是空的 —— 本册补这一面。

★ 上面后四行是 `best_practice.md` 逐条对齐后的**现状盘点**：`global` 与无类型参数本仓已经
是干净的（`strict_types=1` + 全类型化的长期纪律奏效），所以 §8/§5 那两条对本仓**是零工作量**；
真正的缺口在 `__DIR__`（A8）与 callable 槽（§4）。**先把"已经做对的部分"数出来，才知道
该往哪儿使劲** —— 否则容易把力气花在已经解决的问题上。

---

## 2. 九条（A0 是前置开关，A1–A8 是增补；按价值排序）

### A0 前置：在命名空间里写 `#[Override]` 是**静默无效**的

> **这一条排最前，因为它不是"优化"，是"其它八条全部失效"的开关。**

官方 `compile-time-attributes.md` §命名空间规则 的原话：

> 编译期 Attribute 位于**根命名空间**，并完全遵循 PHP 的类名解析规则。
> 在全局命名空间中可以直接使用短名称……在其他命名空间中，可以使用完全限定名：
> `#[\Getter]` ……也可以通过 `use` 导入短名称。
> **只有最终解析到根命名空间内建 Attribute 的名称才有编译期语义。**
> 例如下面的 `Getter` 会解析为 `App\Model\Getter`，不会自动生成方法。

⇒ **`src/` 里 222 / 223 个文件都在命名空间里**（唯一例外：`src/Platform/Win32/ResizeHook.php`）。
在这些文件里写：

| 写法 | 结果 |
|---|---|
| `#[Override]` | 解析成 `Drift\Widget\Override` ⇒ **没有编译期语义，零效果，不报错** |
| `#[\Override]` | ✅ 正确 |
| `use \Override;` + `#[Override]` | ✅ 正确（多一行） |

★ **这与 MEMORY 里"`instanceof` 漏 `use` ⇒ 恒 false 不报错（咬过 3 次）"是同一个坑的第二次出现。**
形状完全一样：**名字解析错了，不报错，只是没效果。**

⇒ **本册所有代码示例一律写带反斜杠的形式**：`#[\Override]` / `#[\Getter]` / `#[\With]` /
`#[\Immutable]` / `#[\Hot]` / `#[\Cold]` / `#[\Constructor]` / `#[\MustUse]`。

⇒ **一条 lint（`07` 的 L2 级）**：`attribute-unqualified` ——
扫描内建注解名单（`Override`/`Getter`/`Setter`/`With`/`Immutable`/`Hot`/`Cold`/`MustUse`/
`Constructor`/`NoExport`/`MethodsFor`/`NotNull`/`NotEmpty`/`Validate`/`Printer`/`Arrayable`/`Native`），
**凡是不带 `\` 且本文件没有 `use \X;` 的，报 ERROR**。

★ 技术上可行：注解是**语法节点**（`T_ATTRIBUTE`），名字是字面量；而 `instanceof` 那条
要靠 `Project::symbols()` 反查 —— **`InstanceofMissingUse` 已经这么做了**，两条其实是同一个
套路（"短名解析后落空 ⇒ 报错"）。属性那条更简单：名字是字面量，**40 行就够**。

★★ **裁决：排期 —— 落点是 `09` §3 W0 第 8 步（A0+A1）的第一个动作**，`L2` 级，规则名 `attribute-unqualified`。
初稿判"不排期"，三条理由里有两条**站不住**，逐条修正：

| 初稿理由 | 修正 |
|---|---|
| 严重度低于 `instanceof` | **成立但不足以否决**：`instanceof` 漏 `use` 算出错误布尔值（灾难），漏 `\` 只是丢掉一条保护（不改变行为）。可"丢掉保护"正是 `09` 最怕的那类失败 —— **静默** |
| **会误报**（用户可以自己定义 `App\Getter`） | **不成立**：`SourceFile::resolveName()` 已经能把短名解析成 FQN，再查 `Project::symbol()` —— **解析到仓内真实类就跳过**。`InstanceofMissingUse` 用的正是这套（`visible()` 那个方法），零误报 |
| 一次性成本很低，`grep` 一条命令够 | **不成立**：`grep` 依赖人记得跑。而**本仓已经为同一个坑写过 lint**（`instanceof-missing-use`，2026-10-19，注释里记着"咬过 ≥3 次"）⇒ **同类问题同类处置**，否则是判据不一致 |

⇒ **成本实测**：1 个规则文件（40 行，照 `InstanceofMissingUse` 的形状）+ 1 个夹具
（`tools/Lint/fixtures/apps/AttributeBad.php`）+ `Linter::defaultRules()` 两行 + `selftest.php`
的 `$expected` 一条 = **四改**。规则本体只需要"内建名单 + `resolveName` 后不在符号表 ⇒ ERROR"。

⇒ **它与 A1 的先后**：lint **先上**，再批量标注 30–40 处。反过来做等于"先改 40 处，再装闸门"，
中间那 40 处没有保护。

⇒ **对计数的影响**：lint `15 → 16`（W0）→ `18`（W1 +2）→ **`19`**（W2 +1）。
`09` §5 是唯一口径，本册只声明增量。

★ 顺带纠正一个初稿的措辞：这条**不是**"用纪律代替机制"，而是"**机制优先于纪律**"——
本册其余八条也都是机制（编译期报错），只有它一开始被写成纪律，**属于自相矛盾**。

---

### A1 `#[\Override]`：把"改基类方法"从纪律升级为编译错误

> **价值最高的一条。它直接消掉 `09` 里最危险的那个静默失败点。**

**问题回顾**（`09` §3 W2 第 17 步）：`Element::settle()` 改 `final` 并拆出 `settlePass()`，
`ErrorBoundaryElement::settle()` 必须改名。漏改的后果：

- `settle()` 已是 `final` ⇒ 编译器不会报"覆写 final 方法"（名字不同）
- `settlePass()` 是 `protected` 且**非抽象** ⇒ 编译器不会报"抽象方法未实现"
- PHP 对**未被调用的** `protected` 方法零警告
- ⇒ 错误边界从"到节点"退化成"到整帧"，**不报错、不崩、测试可能全绿**

`07` §2.3 的处置是提议一个 20 行脚本 `tools/overrides.php`（"改之前先数覆写点"）。
**那是纪律，不是机制** —— 它依赖人记得跑。

`#[\Override]` 把这件事变成机制：

```php
// src/Widget/ErrorBoundaryElement.php
#[\Override]
protected function settlePass(): void      // ← 若父类没有同名方法 ⇒ 编译期报错
{
    try {
        parent::settlePass();
    } catch (\Throwable $e) {
        $this->capture($e);
    }
}
```

**收益的精确边界**（不要夸大）：

| 基类方法形态 | `#[\Override]` 的增量价值 |
|---|---|
| **抽象**（`performLayout` 21 / `intrinsicWidth` 15 / `intrinsicHeight` 15 / `paint` 22） | **零** —— 漏了本来就是"抽象方法未实现"编译错 |
| **有默认实现**（`adoptBoxes` 6 / `build` 14 / `settlePass` 1 / `hitTestSelf` / `overflow` …） | **决定性** —— 漏了原本是静默的 |

⇒ 只给"有默认实现"的那批标 `#[\Override]`，一次性约 **30–40 处**。
标错（父类真没这个方法）会编译错，所以**不会标多**。

**官方"硬性规则"里与本仓直接相关的四条**（`override.md` §硬性规则）：

| 规则 | 对本仓的含义 |
|---|---|
| 匹配父类的 **public 或 protected** 方法，也匹配抽象方法和接口方法 | ✅ `protected settlePass()` 在覆盖范围内 —— **`10` 的这一条成立** |
| **父类 private 方法不属于覆盖目标** | 本仓 `RenderBox` 的几个 `private` helper 不能标 |
| **`__construct()` 不属于匹配目标** | 本仓大量"子类构造转调父类"的地方**不能**用 `#[\Override]` 保护 |
| 只能用于方法，不接受参数，不能重复声明 | — |

⇒ 原先那条"待确认（tpc 是否覆盖 protected + 非抽象）"**已由文档回答，可以删掉**。

★ **顺带一个减法**：`tools/overrides.php` **可以不做了**。
`#[\Override]` 全覆盖之后，改基类方法名 ⇒ 编译器会把每一处覆写点按 `file:line` 报出来，
那就是这个脚本的输出，而且**不需要人记得跑**。
（`09` §3 W0 第 8 步删除，W0 从 10 步变 9 步。）

★ **额外收益**：`02` §7.2 M2-2 的 `paint()` → `paintContent()`（20 文件改名）
与 `03` §5.1 的 `settle()` → `settlePass()`（1 处）都在 `#[\Override]` 的覆盖范围内，
W5 与 W2 的"手改可能漏"风险归零。

**唯一的落地注意事项**：见 A0 —— 必须写 `#[\Override]`，不是 `#[Override]`。

---

### A2 `?T` 退化 `php::Var` 是**文档确认**的 ⇒ M0-2 取消，且 `Modifier` 必须改

**`08` §3.4 的 M0-2** 问"`?Obj` 是否退化 `Var`，用 grep 生成物判二值"。
官方文档已经给了答案：

> `null`/`callable`/`iterable`/联合/`?A`/交叉**在编译期退化为 `php::Var`**。

⇒ **M0-2 从"实测"降为"引用文档 + 一次 grep 确认"**（半天 → 10 分钟）。
`08` §3 的 M0 从三条变两条（M0-1 `readonly` 成本、M0-3 `mixed` 装箱）。

**这条立刻改掉 `02` 的一个设计**：

```php
// 02 §3.1 原写 —— 两个 ?Color 会把 Modifier 的两个字段打成 php::Var
private ?Color $background = null;
private ?Color $borderColor = null;

// 改成（与 03 §3.2 的 Cell 完全同一个模式：值 + bool）
private Color $background;          // 无背景时存 Color::TRANSPARENT 或 Color::BLACK 都行
private bool $hasBackground = false;
private Color $borderColor;
private bool $hasBorder = false;
```

★ **为什么这不是"多两个字段"而是"少一次 Variant 拆箱"**：
`Modifier` 在 `paint()` 里每个节点读一次 `hasVisual()` + 最多 3 个字段。
`Var` 槽位每次读要判类型再取值；`Color` + `bool` 是固定偏移直读。
**这与 `03` §3.2 给 `Cell` 用 `State $owner` + `bool $bound` 是同一条判据**
（"可空对象退化 `Var` ⇒ 用值 + bool 表达可选"），两册应写成同一句话。

★ 注意 `Color` 本身是 `final readonly class`（见 A3）—— `readonly` 与 `?` 是两件事，
`readonly` 是 Hook（慢），`?` 是类型擦除（变 `Var`）。**两个都要治，治法不同。**

#### A2.1 官方给的另一半修法：`toObject()` 类型接续（本仓记判据，不排期）

上面那条治的是"**可以重构**的可空值"（`?Color` → `Color` + `bool`）。但有一批 `?T` **重构不了** ——
没有合理的哨兵值：树指针（`Element::$parent`、`Application::$focus`）、回调槽（`?callable`）。
`best_practice.md` §6 给的修法是**在使用点把类型接回来**：

```php
$rs = $app->focusedElement();          // ?Element ⇒ php::Var
if ($rs !== null) {
    $o = $rs->toObject(Element::class); // ← 类型接续：之后是原生方法调用
    $o->someMethod();
}
// 反向：想显式保留动态行为
$any = $o->toAny();
```

★ **但本仓的实测结论是"记判据、不排期"**，理由不是"文档没说"，而是三条已经量过的数：

| 事实 | 数 |
|---|---|
| `toObject` 比动态分派便宜多少 | **0.72–0.83**（省 **19ns/节点**） |
| 每节点 `layout` 成本 | **5.7–12.4 µs** |
| ⇒ 占比 | **0.5–1%** |
| 副作用 | 给 Render 层加 `toObject` **定型**会触发 C6：18 个 `performLayout` 覆写被去虚化、静默走基类 |

⇒ **触发条件（写死，避免每次重开讨论）**：`layout` 段 > **1.5ms** **且** 该调用点每帧 > **100 次**
**且** 分派层已重写（C6 已解）。三条同时成立才动；否则 `toObject` 只当"知道有这么个东西"。

★ 这条与 `Modifier` 那条的区别要讲清：**`?Color` 是"能重构却没重构"（该改），
`?Element` 是"重构不了"（不改，只记修法）**。两者的处置不同，判据是同一条 ——
"可空对象退化成 `Var`"，只是出路不一样。

---

### A3 `readonly` **没有**零成本等价物：`#[\Immutable]` 不在同一层

> **★ 本节在 2026-10-21 21:57 被用户纠正过一次，原稿是错的。**
> 原稿写「`#[\Immutable]` 是 `readonly` 的零成本替代」，并把它标在**类**上：
> `#[\Immutable] final class BoxConstraints`。
> **这行不通** —— `#[\Immutable]` 只能放**实例方法**与**参数**，**不能放属性上，也不能放普通函数上**
> （`immutable.md` 原文：「把 `#[\Immutable]` 放在实例方法上，该方法中的 `$this` 会被视为不可变对象」；
> 「`#[\Immutable]` 不能放在普通函数声明上，因为普通函数没有 `$this`」）。
> 把它标在类上、或者指望它替代属性级的 `readonly`，**是两件不同层的事**。

**两者的层不同，这才是关键**：

| | `readonly` | `#[\Immutable]` |
|---|---|---|
| 挂在哪 | **属性**（`public readonly float $minWidth`） | **实例方法**（`$this` 只读）/ **参数**（含 `const &` 语义） |
| 保证什么 | **属性级**：构造之后谁都写不了，包括将来新加的方法 | **方法级**：标了的方法内写不了 `$this`；**没标的方法照样能写** |
| 怎么实现 | **运行时 Hook**（文档：密集访问慢 **10×+**） | **纯编译期检查**，零运行时成本 |
| 能否 `#[\Native]` | **不能** | 可以（方法上的注解） |
| 能否 `#[\With]` | **不能**（编译错） | 可以 |

⇒ 结论：**`readonly` 的"属性级保证"没有零成本替代**。只有三种取舍：

| 方案 | 保证强度 | 运行时成本 | 代码量 |
|---|---|---|---|
| **① `final readonly class` + `public` 属性**（现状 32 个类） | **属性级**（最强） | **高**（每次读都走 Hook） | 最短 |
| **② `final` + `private` + `#[\Getter]`** | **无**（靠"没人写 setter"的约定） | 类内零 / 类外=方法调用（见 A3.1） | 短 |
| **③ `final` + `private` + 手写 `#[\Immutable]` 读方法** | **方法级**（覆盖率决定强度） | 类内零 / 类外=方法调用（见 A3.1） | 每字段一个方法 |

**方案 ③ 的正确形状**：

```php
final class BoxConstraints
{
    private float $minWidth;          // 非 readonly ⇒ 无运行时 Hook

    #[\Immutable]                     // ← 方法上：本方法内 $this 只读
    public function minWidth(): float
    {
        return $this->minWidth;       // 读属性合法
    }

    public function __construct(float $minWidth, float $maxWidth)
    {
        $this->minWidth = $minWidth;  // 构造期写入合法（构造函数不能标 #[\Immutable]）
    }
}
```

★ 官方对 `#[\Immutable]` 方法列了 9 条禁止（`immutable.md` §编译器禁止的操作），与本仓相关的三条：

| 禁止 | 对本仓的含义 |
|---|---|
| 不能调用**未标 `#[\Immutable]`** 的确定对象方法 | ⇒ **与 `#[\Getter]` 互斥**（见下） |
| 不能把不可变对象存进可变属性/数组/global/static，不能直接 `return` 让它逃逸 | ⇒ `#[\Immutable]` 方法**不能 `return $this`** |
| **`clone` 的结果是可变的** | ⇒ `withX()` 里 `clone $this` 再写克隆字段**合法**（上一版的"待确认"由此解决） |

★ **一个会让方案 ③ 走不通的细节**：`#[\Getter]` 生成的 `getXxx()` 是**普通方法**，
注解只认手写方法 ⇒ **生成的方法标不了 `#[\Immutable]`**。
所以一个 `#[\Immutable]` 方法里调 `$this->getMinWidth()` 会**编译错**
（"不能在 immutable `$this` 上调用 mutable 方法"）。

⇒ **`#[\Getter]` 与"全方法标 `#[\Immutable]`"只能二选一**：

| 选 | 得到 | 失去 |
|---|---|---|
| `#[\Getter]` | 少写 32 个类的读方法 | 不可变保证 |
| 手写 `#[\Immutable]` 读方法 | 方法级不可变保证 | 每字段一个方法（32 类 × 2–7 字段） |

★ **方案 ③ 的保证强度 = 覆盖率** ⇒ 需要一条 lint（`07` 的 L2 级）：
`immutable-value-object` —— 白名单里的值对象类，**每个 public 实例方法必须标 `#[\Immutable]`**。
没有这条 lint，方案 ③ 就退化成方案 ②（漏标一个方法就等于开了一个写入口）。

#### A3.1 官方背书 —— 以及一条会推翻"零成本"说法的边界

`best_practice.md` §7 原话：

> **替代 `readonly`**：使用 `private` 属性，它在类外同样不可访问，但保留完整的优化能力；
> 需要对外暴露只读访问时再加 `#[Getter]`。

★ 上面是**原文引用**，官方示例写在全局命名空间里所以不带 `\`；**抄进 `src/` 必须写 `#[\Getter]`**
（A0）。本册自己的示例一律带 `\`。

官方示例（`Config`）里那句注释是本节的要害：

```php
public function total(int $n): int
{
    $t = 0;
    for ($i = 0; $i < $n; $i++) {
        $t += $this->port;   // 类内直接访问，不要用 getValue()
    }
    return $t;
}
```

⇒ **方案 ② 不是本册的取舍，是官方唯一认可的替代形状。** `02` §3.1 的 `Modifier` 与它逐字同形。

★★ **但官方紧接着的末段推翻了本册原稿的一句断言**：

> 把 Property Hook 改写成普通方法不会带来性能提升，两者都是函数调用；`#[Getter]` 生成的方法
> 调用同理。**真正的差别在于「属性访问」与「方法调用」。**

⇒ 本册原稿把方案 ②/③ 笼统写成"零成本"，**只在类内成立**。正确的分界：

| 读点位置 | 现状（`readonly`） | 改后（`private` + getter） | 收益 |
|---|---|---|---|
| **类内**（`$this->minWidth`） | 属性读 ⇒ **触发 Hook**（10×+） | 属性读 ⇒ **直读**（无 Hook） | **真收益** |
| **类外**（`$c->getMaxWidth()`） | 属性读 ⇒ Hook | **方法调用** | **≈ 0，甚至略负**（多一层调用 + 返回类型检查） |

⇒ **裁决要加一条前置动作**：动手前先数**类内读点 vs 类外读点**。几何类里
`BoxConstraints::constrain()/deflate()`、`Rect::intersect()/contains()` 是类内密集循环（每帧数千次）
⇒ 收益真实；而 `Size`/`Point` 基本只被外部读 ⇒ 改它们**只得到代码变长**。
**"字段少、读点密"不够，还要"读点在类内"。**

★ 附带确认：本仓 **Property Hooks 使用量 = 0**（`grep -rn 'get {' src/` 只命中一处 docblock），
所以官方 §7 的前半段（Hook 替代）对本仓**零工作量**，只有 `readonly` 那半段适用。

**裁决（不做无条件替换）**：

| 步 | 动作 |
|---|---|
| 1 | 读 `SurfaceProfile` 的 `layout` 段（`Frame.php:105` 已在记）。`build/m4.on` 打开，跑 300 帧 |
| 2a | `layout > 1.5ms` ⇒ 只对**几何 6 类**（`Size`/`Point`/`Rect`/`EdgeInsets`/`BoxConstraints`/`Scale`）做方案 ③，**且先数类内读点**（A3.1）：`BoxConstraints`/`Rect` 的类内循环是收益来源；`Size`/`Point` 若以类外读为主则**跳过**（改它只变长）。附带好处：它们也是 `#[\Native]` 的潜在落点 |
| 2b | 否则**维持 `readonly`**，并在 `08` 记一条"`readonly` 是已知的 10×+ 成本，未处理" |
| 3 | **新写的值对象**（`Modifier` 等）一律用方案 ②（`private` + `#[\Getter]`，**不用 `readonly`**）—— 它们没有迁移成本，且 `#[\With]` 需要非 readonly |

★ **`readonly` 的两个结构性代价（比性能更重要）**：

1. `#[\Native]` **不支持 `readonly`** ⇒ 只要还用 `readonly`，几何类永远不可能被 profiling 判定为"该上 `#[\Native]`"。
2. `#[\With]` **不支持 `readonly`** ⇒ 值对象永远没有 `withX()`（编译错，不是静默）。

⇒ 一句话：**`readonly` 是"用两个未来的门，换当下的一个保证"。** 写进 `08` 的约束表。

★ 记一个事实修正：`#[\Getter]` 生成的是 **`getXxx()`**，不是 `xxx()`。
所以方案 ② 的读点从 `$c->maxWidth` 变成 **`$c->getMaxWidth()`**（上一版写成 `$c->maxWidth()` 是错的）。
若嫌长，手写 `maxWidth()` 与 `#[\Getter]` 等价（都是编译期零成本），只是多写一行。

---

### A4 `#[\Hot]` / `#[\Cold]`：MSVC 下**安全忽略**（零风险、零收益）

`hot.md` 原文：

> 在 GCC 和 Clang 中，它会映射为 `__attribute__((hot))`。编译器可据此更积极地优化函数，
> 并根据工具链和目标平台调整代码布局。**其他不支持该能力的 C++ 编译器会安全地忽略此提示。**

⇒ 上一版写的"**很可能被忽略或直接报错**"是错的：**文档明写"安全地忽略"**，不会报错。

而本仓 `drift-build.php` 调的是 `cl.exe`（MSVC）⇒ **标了等于没标，但也不会有任何副作用**。

| 事实 | 结论 |
|---|---|
| MSVC 下无收益 | 不值得为它排期 |
| MSVC 下无风险 | 现在标上也不亏 |
| `windows.md` 说可用 `clang++`/`lld-link`/`llvm-rc` 替代 | **换 clang 后端的那天，这些标注立刻开始生效** |

**裁决：标，但不排期**（`W7` 顺手带上，不单独开一步）。

值得标的（一次到位，将来换 clang 就白赚）：

| 方向 | 函数 |
|---|---|
| `#[\Hot]` | `Frame::run` · `RenderBox::layout` · `RenderFlex::performLayout` · `Element::settlePass` · `DisplayList::replay` · `RasterCanvas::drawRect` |
| `#[\Cold]` | `Errors::report` · `Errors::fatalBox` · `RasterCanvas::unsupportedOp` · `FramePhase::assertOrder` · `LayoutSelfCheck::maybeCheck` |

★ 但 `hot.md` 自己有一句警告要照抄：**"务必以 profiling 数据为准，不要猜测式大量添加"**
（可能增加体积或破坏优化）。⇒ 上表是**按"每帧必走 / 只在出错时走"的结构判据**选的，
不是按 profiling 选的；真要精调，等换 clang 之后再用火焰图定。

---

### A5 `#[\With]`：`Modifier` 的 20 个 `withXxx()` 白送

`with.md` 原文（生成的方法形状，逐字）：

```php
// #[Getter, With] private string $name = '';
public function withName(string $name): static
{
    $clone = clone $this;
    $clone->name = $name;
    return $clone;
}
```

⇒ **正是 `02` §3.1 的 `Modifier` 需要的东西**：返回 `static`、克隆后写一个字段、原对象不动。

```php
final class Modifier
{
    #[\With] private float $padLeft = 0.0;
    #[\With] private float $padTop = 0.0;
    // ... 20 个字段，20 个 withXxx() 白送
}
```

★ **三条限制，都对本仓直接相关**：

| 限制 | 含义 |
|---|---|
| **不支持 `readonly` 属性**（编译错） | 与 A3 的方案 ② 配套：`Modifier` 的字段必须**非 readonly** |
| **不支持带 `get`/`set` Hook 的属性** | 本仓不用 Property Hook，无影响 |
| 命名空间里要写 `#[\With]` | 见 A0 |

★ **一个用法上的细节**：`#[\With]` 生成的是**单字段** `withPadLeft(float)`。
多字段的修饰器（`padding(float $all)` 要同时改四个边）**手写**才划算 —— 否则是 4 次 `clone`：

```php
// Widget::padding() 的正确写法：一次 clone
public function padding(float $all): static
{
    $c = clone $this;
    $c->mods = $this->mods->withPadding($all);   // Modifier::withPadding() 手写，内部一次 clone
    return $c;
}
```

⇒ `#[\With]` 省掉的是**20 个单字段方法**（约 100 行样板），多字段的 6–8 个方法仍然手写。

---

### A6 `#[\Constructor]`：只给"纯赋值"的构造用

文档："按属性声明顺序生成 `__construct()`"。

本仓可用的落点（纯赋值、无校验、无默认值计算）：

| 类 | 参数数 | 可否 |
|---|---|---|
| `RenderFlex` | 5 | 需确认是否有默认值计算 |
| `RenderScrollView` | 5 | ★ **不可** —— 有 `?float $virtualExtent` 与 `appliedOffset()` 的读时夹 |
| `RenderDock` | 1（`Overflow $policy`） | 可以，但省不了几行 |
| 几何 6 类 | 2–4 | 可以 |

★ **收益是"少写几十行"，不是性能**。且**顺序敏感**（属性声明顺序 = 参数顺序）⇒
一旦有人重排属性，构造签名就静默变了（调用点参数错位）。
⇒ **判据：只用在"参数顺序与语义顺序天然一致"的类上**（几何类符合），
`RenderBox` 家族不符合（构造参数带策略/默认值）。

⇒ 低优先，**可以不做**。写在这里是为了"下次有人问'为什么不用'时有答案"。

---

### A7 `#[\Native]` 的适用边界：本仓暂无可落点，但判据要写下来

文档的选择原则（§1 native-class 末尾）：

> **优先普通 class，仅在 profiling 确认对象布局/属性查找/动态分派是热点时，
> 再把边界清晰的内部数据结构改为 `#[\Native]`。**

"边界清晰"是关键。本仓的候选逐个判：

| 候选 | 能不能 `#[\Native]` | 为什么 |
|---|---|---|
| `Element` / `RenderBox` | **不能** | 要做树节点 ⇒ 要进 PHP array（`$children`）⇒ 硬红线 |
| `Geometry\*`（32 个值对象） | 理论可以 | 但要能进 `Rect[]`/`Glyph[]` 等数组 ⇒ **不能** |
| `Text\Glyph` | 不能 | `Paragraph::$glyphs` 是 array |
| `DisplayList::$buffer` | 不能 | 已经是 `array<int, float>`（数值平铺，比对象图更快） |
| `Canvas` 的两个实现 | 不能 | 要按 `Canvas` interface 传递 ⇒ 文档明写"Native Object 不能赋值/传递为 Interface 类型" |

⇒ **结论：`#[\Native]` 在本仓没有落点，而且原因是结构性的**（三棵树 + 数组持有）。
写下来免得再讨论。

★ 但记一条**未来**判据：如果哪天出现"不持有孩子、不进数组、按具体类型传递"的
内部数据结构（例如一个纯计算的贝塞尔求解器、一个 `PathFlattener`），
那才是 `#[\Native]` 的第一个合理落点。

---

### A8 `__DIR__` / `__FILE__` 在 AOT 下是**编译期**路径 —— `09` 的三个文件开关都踩这个坑

`best_practice.md` §3 原话：

> 由于 TypePHP 编译器是在编译阶段确定 `__FILE__` 和 `__DIR__` 魔术常量的值，因此最终的目录
> 是**编译时** `.php` 文件的路径，而不是运行时。因此虽然可以使用魔术常量，但它的结果与预期
> 可能不一致。建议使用 `getcwd()` 或者其他配置文件方式确定最终运行时的目录。

**对本仓的直接含义**：`09` 里有**三个文件开关**（W0 的 `build/layout.check`、W5 的
`build/frame.trace`、性能量具的 `build/m4.on`）。如果按 `__DIR__ . '/../../build/layout.check'`
拼，AOT 之后指向的是**编译机**的源码树 ——

| 场景 | 结果 |
|---|---|
| 在开发机上构建 + 运行（源码树还在原地） | 路径**恰好存在** ⇒ 看起来能用 |
| 打包分发 / 换机器 / 源码树移动 | 路径不存在 ⇒ **静默不生效** |
| 而这三个开关本来"关着的时候什么都看不出来" | ⇒ **两层静默叠加，只有真机才可能发现** |

⇒ 规则（写进 `08` §6 纪律第 14 条）：

| 用途 | 允许 | 禁止 |
|---|---|---|
| **运行时资源**（字体 / 配置 / 开关文件 / 日志落盘） | `getcwd()` 相对路径、环境变量、显式参数 | `__DIR__` / `__FILE__` |
| **编译期事实**（诊断里报告"这行代码在哪"） | `__FILE__` **正该用它** | — |

★ **本仓现状是"做对了"的**：`__DIR__` 全仓只有 1 处**代码**（`FontLocator::candidates():46`，另 2 行是注释），
且是**第三候选**、注释明写"`__DIR__` 在 AOT 下是构建期路径，找不到也无害"，前两个候选是
`getcwd()`。**不要"顺手优化"成 `__DIR__` 优先** —— 那会把一个已经避开的坑重新踩上。

★ 附带一条 Windows 侧的：`FontLocator` 用 `DIRECTORY_SEPARATOR` 拼路径，进 `is_file()` 没问题；
但若哪天把路径递给 Skia（`SkFILEStream` / `SkTypeface::MakeFromFile`），C 侧要 UTF-8，
**中文目录名会静默失败**（Skia 找不到就是找不到，不报错）。打包路径保持 ASCII 即可，不必改代码。

---

## 3. 一条"要测不要猜"：`replay()` 的接口分派

**这条不是增补，是一个必须测的未知数。**

`DisplayList::replay(Canvas $canvas)`（`:156`）的签名是**接口类型参数**，
循环体里每个 op 调一次 `$canvas->drawX()`：

```php
public function replay(Canvas $canvas): void
{
    for ($i = 0; $i < $this->length; $i++) {
        $op = $this->buffer[$i * self::SLOTS];
        if ($op === self::OP_SAVE)            { $canvas->save(); }
        elseif ($op === self::OP_DRAW_RECT)   { $canvas->drawRect(...); }
        // ... 19 个 op
    }
}
```

**文档的相关事实**：

> `Interface` 仍是普通 PHP Interface，**仅编译期检查**。

⇒ 接口类型的接收者拿不到 Native Call，只能走动态方法查找。
每帧的 op 数约 **2000**（`05` §1.4 的量级）⇒ **2000 次接口分派/帧**。

**收益不确定，所以先测**：

```bash
# 已有量具，不用新写：Frame.php:135 已经在记 paint 段
# build/m4.on 打开，跑 300 帧
grep "^paint" build/profile.txt
```

| `paint` 段读数 | 判断 | 动作 |
|---|---|---|
| < 0.8ms | 分派不是瓶颈 | **不做**，记进"不做"表 |
| 0.8–1.5ms | 可疑 | 在 `replay()` 里插一个计数器数 op 数，算"每 op 纳秒" |
| > 1.5ms 且每 op > 300ns | 分派占大头 | 见下 |

**若要做，形状是什么**（以及为什么很可能仍然不做）：

```php
public function replay(Canvas $canvas): void
{
    if ($canvas instanceof SkiaCanvas) {          // ← 每帧一次
        $this->replaySkia($canvas);
        return;
    }
    $this->replayRaster($canvas);                 // ← 具体类型 ⇒ 静态分派
}
private function replaySkia(SkiaCanvas $c): void { /* 19 个 op 的循环 */ }
private function replayRaster(RasterCanvas $c): void { /* 同样 19 个 op */ }
```

★ **这份代码是 19 个 op × 2 份，必须逐字节同序**。这正是 `05` §2.1 说的
"一强一弱"三种坏法的温床，也正是 `05` §2.5 刚加的 lint `backend-branch` 要**禁止**的形状。

⇒ **裁决：测出来 > 1.5ms 才做，且做的同时要加一条断言"两份 replay 的 op 序列逐字节相同"**
（用 `RecordingCanvas` 各录一遍比对）。否则宁可不做。

---

## 4. 明确不做的

| 提议 | 否决理由 |
|---|---|
| **把 `int` 句柄换成 `php::Box`** | 速度收益 ≈ 2000 次 × 8ns = **16µs/帧（0.5%）**，低于本仓"个位数百分比不做"的门槛。安全收益（类型校验）已被显式 `dispose()` + 幂等 destroy 覆盖。**触发条件**：出现第二个后端对象类型且句柄互传时再做 |
| **`std::map<int, SkiaSlot*>` 换 `std::vector`** | 稳态只有 1–2 个槽位，`find` 已经是一次比较。改了省不了 1µs |
| **`#[\MustUse]`** | 找不到合适落点：`Cell::setIfChanged(): bool` 的返回值**忽略是合法的**（大部分调用点不关心有没有变），标了会逼出无意义的 `if` |
| **`#[\Immutable]` 当类级"不可变"标记用** | 它只能放**实例方法与参数**上（`immutable.md`），**不能放属性、不能放普通函数**。把它标在类上是无效果的（且若类在命名空间里、名字又没带 `\`，连报错都没有 —— 见 A0） |
| **`NotNull` / `NotEmpty` / `Validate`** | 文档自己警告"每次调用执行，较昂贵"+"契约校验更适合放在输入边界一次性完成"。本仓已有 `Errors` + 断言，不需要再插一层运行时检查 |
| **`#[NoExport]`** | `-m bin` 模式不生成 stub，无消费者。真做 SDK（`-m lib`）时再说 |
| **`#[\Native]` 化任何现有类** | §2 A7：全部候选都撞"要进 PHP array"或"要按 interface 传递"两条硬红线 |
| **把 `readonly` 一次性全仓替换** | §2 A3：`#[\Immutable]` 给不了属性级保证（它是方法级/参数级注解），三种方案各有代价。先读 `layout` 段，> 1.5ms 才对几何 6 类做；否则维持现状 |
| **给 9 处无类型属性补类型**（`best_practice.md` §5） | 9 处**全是 callable 槽**（`DragTarget::$acceptHandler`、`Menu::$openHandler`、`PageHost/PageHostState/SubjectBuilder::$select`、`SubjectBuilder::$builder`、`Errors::$fatalBox`、`ResizeHookState::$pump`）。**PHP 禁止 `callable` 作属性类型** ⇒ 现在的无类型是**被迫**的；`?\Closure` 是 nullable ⇒ 照样退化 `Var`（A2），零收益；非空 `\Closure` + 静态 no-op 哨兵能定型，但这几处每帧调用 **0–2 次**（拖拽事件 / 致命框 / 壳侧回调）⇒ 不划算。**结论：保持无类型，但把这条写下来**，免得后来人"顺手补类型"白干一场 |
| **`vendor` 白名单编译 / `ignore` 部分文件**（`best_practice.md` §1） | 本仓无 `vendor` 依赖（`tools/autoload.php` 自管），N/A |
| **"继承动态类必须整链静态编译"**（`best_practice.md` §4） | 同上，无动态类可继承，N/A |
| **`toObject()` 类型接续铺开**（`best_practice.md` §6） | §2 A2.1：省 19ns/节点 ÷ layout 5.7–12.4µs = **0.5–1%**，且给 Render 层定型会触发 C6。**触发条件写死**：`layout > 1.5ms` 且该点每帧 > 100 次 且 C6 已解 |

---

## 5. 对 `09` 路线的影响

| 波次 | 变化 | 说明 |
|---|---|---|
| **W0** | 10 步 → **10 步**（删 1 加 1，第 8 步内含两个动作） | 删 `L6-3 overrides.php`；第 8 步改 **A0+A1**：**先**加 lint `attribute-unqualified`（40 行 + 夹具，**lint 15 → 16**），**再**批量标 `#[\Override]`（30–40 处）。顺序不能反 —— 漏 `\` 的注解**能编译通过** |
| **W0** | M0 三条 → **2 个计时 + 1 个 grep 观察** | `M0-2`（`?Obj` 退化）由文档回答 ⇒ 不再计时，只 grep 生成物确认 |
| **W0** | 纪律（非步骤）两条 | `A0` 的"内置注解必须写 `#[\X]`"进 `08` §6 第 13 条（**同时有 lint 兜，纪律是第二层**）；`A8` 的"`__DIR__` 不用于运行时路径"进第 14 条 |
| **W2** | 第 17 步风险降级 | `settlePass` 有 `#[\Override]` 兜 ⇒ 从"编译器兜不住的静默失效"变"编译错" |
| **W5** | 第 28 步风险降级 | `paint` → `paintContent` 的 22 个覆写点有 `#[\Override]` 兜（本来就是抽象方法，增量小，但顺带覆盖） |
| **W5** | `Modifier` 形状修正 | `?Color` → `Color` + `bool`（A2）；字段**非 `readonly`** + `#[\With]` 生成 20 个单字段 `withXxx()`（A3 方案 ② + A5）；**类内直读 `$this->x`，不走 getter**（A3.1）。★ **不给 `Modifier` 标类级 `#[\Immutable]`**（A3：它不是类级注解） |
| **W5** | 三个文件开关的路径写法 | A8：`build/layout.check` / `build/frame.trace` / `build/m4.on` 一律 `getcwd()` 相对，**禁 `__DIR__`**（否则打包后静默不生效） |
| **W7** | `L6-2` 的 `internal-leak` 方法级检查 | 不变（那是 `@internal` 的跨层检查，与 `#[\Override]` 正交） |
| **W7** | 顺手标 `#[\Hot]` / `#[\Cold]` | A4：MSVC 下**安全忽略**（零风险零收益），换 clang 后端那天自动生效。不单独开步 |
| **新** | 一个"读 profile"的动作 | `SurfaceProfile` 已有 `paint` 段（`Frame.php:135`），W0 或 W1 后跑一次 300 帧，决定 A3 与 §3 做不做 |

★ **W0 的出口判据加两条**：

```bash
# ① 所有"基类有默认实现"的覆写点都标了 #[\Override]，且没有漏反斜杠的
php tools/lint.php --list                      # 16 条（+ attribute-unqualified）
php tools/lint.php --selftest                  # 16/16 ok
grep -rnE '#\[\\?Override\]' src/ --include='*.php' | wc -l   # ≥ 30（★ 必须 `\\?`，否则数的是漏反斜杠的那批）
php tools/drift-build.php users                            # 成功（标错会编译错）

# ② 没有运行时路径依赖 __DIR__
grep -rn "__DIR__\|__FILE__" src/ --include=*.php          # 仅 FontLocator 3 行（1 处代码 + 2 处注释）
```

---

## 6. 决策表

| # | 决策 | 决定了什么 | 否了什么 | 推翻条件 |
|---|---|---|---|---|
| A0 | **加 lint `attribute-unqualified`（W0，lint 15→16）** | 漏反斜杠的内建注解 = `error`，机制不靠纪律 | 初稿"不做 lint、只写纪律"（**已推翻**：`resolveName` + 符号表可做到零误报，且本仓已有 `instanceof-missing-use` 先例） | 规则误报 ≥ 1 次且无法用符号表消掉 ⇒ 降为 warn |
| A1 | **给"基类有默认实现"的覆写标 `#[\Override]`** | 改基类方法 = 编译错 | `tools/overrides.php`（不做） | tpc 不支持 `protected` + 非抽象的组合 ⇒ 退回脚本 |
| A2 | **`?T` 退化 `Var` 按文档采信** | `Modifier` 用值 + `bool` | M0-2 实测（取消） | 生成物 grep 显示 `?Color` 未退化 ⇒ 恢复 `?Color` |
| A2.1 | **`toObject()` 接续记判据、不铺开** | 不可重构的 `?T`（树指针/回调槽）保持 `Var` | 全仓铺 `toObject` | `layout > 1.5ms` 且该点每帧 > 100 次 且 C6 已解 |
| A3 | **`readonly` 没有零成本等价物；`#[\Immutable]` 是方法级/参数级注解** | 新值对象用 `private` + `#[\Getter]`（无 `readonly`）；32 个旧类**维持现状**，除非 `layout` 段 > 1.5ms | 类级 `#[\Immutable]`（无效）· 全仓替换 | `layout` 段 > 1.5ms ⇒ 只对**类内读点密**的几何类做方案 ③（手写 `#[\Immutable]` 读方法 + lint `immutable-value-object`） |
| A3.1 | **"零成本"只在类内成立**（官方 §7 末段） | 动手前先数类内/类外读点；`Size`/`Point` 以类外读为主 ⇒ 跳过 | "改 getter 一定更快" | 实测类外 getter 调用 < 属性读 ⇒ 放宽 |
| A4 | **`#[\Hot]` / `#[\Cold]` 都标，但不排期** | 换 clang 后端即生效 | 单独开一步 | 实测证明标了反而变慢（体积/布局副作用）⇒ 撤 |
| A5 | **`Modifier` 用 `#[\With]`** | 20 个单字段 `withXxx()` 白送（约 100 行样板） | 手写 20 个 | 多字段修饰器仍手写（一次 clone，不是四次） |
| A6 | **`#[\Constructor]` 只给几何类** | 少写几十行 | `RenderBox` 家族 | 参数顺序被重排 ⇒ 撤掉 |
| A7 | **`#[\Native]` 本仓不适用** | 记下结构性原因 | 所有候选 | 出现"不进数组、按具体类型传"的内部数据结构 |
| A8 | **运行时路径一律 `getcwd()`/环境变量，禁 `__DIR__`** | 三个文件开关打包后仍生效 | `__DIR__` 相对拼路径 | tpc 支持运行期 `__DIR__`（当前文档明写是编译期） |
| §3 | **`replay()` 的接口分派先测** | 一个量具读数 | 盲目拆两份循环 | `paint` 段 > 1.5ms ⇒ 拆 + 加"两份序列逐字节同"的断言 |

---

## 7. 本册的既有裁决依据

| 规则 | 依据 |
|---|---|
| 先 `ls` 目录再 grep（避免"零命中"误判） | `00` §1.2 |
| 对"某能力有没有实现"下结论前先数用量 | 本册 §1（10 种注解全是 0 —— 这次是对的，因为有 `--include` 与逐项计数） |
| 抽象方法漏覆写靠编译器，非抽象方法漏覆写**没人管** | `03` §5.1 + 本册 A1 |
| 可空对象退化 `Var` ⇒ 用值 + `bool` | `03` §3.2（`Cell`）+ 本册 A2（`Modifier`） |
| `NAN` 哨兵优于 `?float` | `02` §3.2 + 本册 A2（同一条判据的第三个用例） |
| 个位数百分比不做 | `24` §5.3 + MEMORY「M1 的结论不能推成该去做」 |
| 不靠 `__destruct` 管原生资源 | `SkiaCanvas.php:136` 原注 + 文档"`unset` 不保证立即析构，仅两个安全点" |
| 拆分两后端循环会引出"两份必须逐字节同"的维护债 | `05` §2.1 三种坏法 + `05` §2.5 lint `backend-branch` |
| 同类静默失效优先做成 lint 而不是纪律 | `tools/Lint/Rules/InstanceofMissingUse.php` 文件头（"咬过 ≥3 次"）⇒ A0 的裁决依据 |
| `readonly` 的官方替代只有 `private` + `#[\Getter]` | `best_practice.md` §7（原文引用见 A3.1） |
| "属性访问"与"方法调用"才是真差别 | `best_practice.md` §7 末段 ⇒ A3.1 修正"零成本"的范围 |
| `__DIR__` / `__FILE__` 是编译期常量 | `best_practice.md` §3（原文引用见 A8） |
| 无类型 ⇒ `any`、`global` ⇒ `var` | `best_practice.md` §5/§8 ⇒ 本仓现状 0 / 0（§1 表），零工作量 |
| `toObject()` 可把 `Var` 接回具体类型 | `best_practice.md` §6 + MEMORY「`toObject` 0.72–0.83」⇒ A2.1 |
