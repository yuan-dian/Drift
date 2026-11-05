# 11 · 编译器约束与 AOT 坑

## 1. 一句话

TypePHP 的 AOT（PHP→C++17，tpc 编译器）有几条**只在真机构建可见**的硬坑：控制流 `switch` 会生成
不加 `php::toInt()` 的属性读 ⇒ C2440；`?:` 两支类型不同 ⇒ C2678；`#[Native]` 类不能做节点、
PHP 数组存不了 Native Object ⇒ 复合数据只能走打包字节串过桥；被继承类的字段必须 `protected`；
`instanceof` 漏 `use` 恒 false 不报错。这些坑在 `src/` 里已全部绕开，纪律固化在代码注释与
`docs/ISSUE-typephp-*.md`。

## 2. 已落地的 AOT 纪律（带证据）

| # | 坑 | 编译器事实 | 代码里的样子 |
|---|---|---|---|
| A1 | **`switch` 在控制流里零处** | tpc 给 `switch ($this->intProp)` 生成的属性读**不加 `php::toInt()`** ⇒ `php::Int tmp = this_.attr(...)` ⇒ **C2440**（Variant→Int）。解释器/smoke 全绿，只有 T2 炸 | `src/` 控制流 `switch` **0 处**（唯一 `switch` 是 `SemanticsRole` 枚举的 `case Switch = 'switch'`，`src/Input/SemanticsRole.php:60`）。分派一律 `int $kind` + `===` 链：`ToggleBase`（`src/Widget/ToggleBase.php:141`、`:163`）、`Curve`（`src/Widget/Curve.php:91` 注释明示 C2440）、`RenderToggle` |
| A2 | **`?:` 两支类型不同 ⇒ C2678** | `operator?:` 无重载，只有 T2 炸；解释器面完全看不出来 | `ToggleBase::refreshSemantics()`（`src/Widget/ToggleBase.php:157`、`:189`）把三元全拆成 if/else；注释写"两支类型不同（php::Var vs 字面量）⇒ C2678" |
| A3 | **`#[Native]` 不能做节点** | 容器嵌不了原生类属性、PHP array 存不了 Native Object ⇒ 节点无法持有孩子 | 节点全是普通 `class`；几何/路径/图像过桥走 `Path`（`src/Rendering/Path.php`）/ `ImageHandle`（`src/Rendering/ImageHandle.php`）的**字节串序列化**，不进 PHP 数组 |
| A4 | **被继承类的字段必须 `protected`** | tpc 按运行时类型查属性表，父类 `private` 不进子类属性表 ⇒ 子类实例访问动态建属性（值/语义全丢） | `RenderBox::$size`、`Element::$box`、`ToggleBase` 字段均 `protected`（`src/Widget/ToggleBase.php:37` 注释明示） |
| A5 | **闭包绝不进 `layout`/`paint` 热路径** | `callable` 属性在热路径上退化 `php::Var`；`Closure::bind` 不支持；Native 对象不能进数组 | 判据 = "这条交互要不要进重放即复现的日志"；常规交互用闭包但不进热路径（design-v7 L2） |
| A6 | **`instanceof` 漏 `use` ⇒ 恒 false 不报错** | tpc 比 PHP 严，未 import 的类名被当不存在 ⇒ `instanceof` 静默返回 false | 框架内所有 `instanceof` 都配齐 `use`；lint/记忆双重守 |
| A7 | **二进制串禁在循环里 `.=` 累加** | `pack('f')`/`chr()` 产物含 \0 与高位 ⇒ AOT 下写坏多字节串 ⇒ 过桥读到乱码 | 用数组 + `implode`（`Path`/`ImageHandle` 的字节组装） |

## 3. tpc 比 PHP 严的其它处（详见 ISSUE 文档）

- **可选参数排必选前 ⇒ Fatal**（PHP 8 允许、tpc 不允许）。
- **类常量与同类方法同名 ⇒ C2872**，**仅数组常量**触发（标量被 `findNativeClassConst` 内联不触发）——实测见 `docs/ISSUE-typephp-class-const-vs-method-C2872.md`。
- **命名空间内非限定函数不回退全局** ⇒ 必须 `\` 前缀（如 `\Closure::fromCallable`）。
- **无 `$argv`**；**AOT 只编译可达代码** ⇒ 扩用法面必须真机构建；增量缓存可能不重编 ⇒ grep 生成物确认。

## 4. 真机验证手段（AOT 专属问题只能靠它）

- 解释器面 + smoke 永远看不出 AOT 专属问题（A1/A2）；**只能 T2 真机构建**或真机探针。
- 真机探针：`tools/probe-grab.cc`（带 `--cursor` 真挪物理光标、`--mdrag` 每步 `SetCursorPos`）；
  量盒矩形用 `_build/scan_col.php` + `_build/p4-crop.php`；无头命中探针可反射读 `Application::$focus`
  直接验 `hitTest`/`activate`。
- 真机量出的"盒左边"要以 `absoluteOrigin()` 为准（像素扫描容易把标签/分节线当边框）。

## 5. 验证口径

```bash
# A1：控制流 switch 是不是真的 0 处
grep -rn "switch" src/ | grep -v "//" | grep -v "\*"      # 只应剩 SemanticsRole 的枚举 case
grep -n "case Switch = " src/Input/SemanticsRole.php

# A2：ToggleBase 用 === 链、拆三元
grep -n "=== RenderToggle::KIND\|if (\$this->on)" src/Widget/ToggleBase.php

# A4：被继承类字段 protected
grep -n "protected.*\$kind\|protected.*\$box\|protected.*\$size" src/Widget/ToggleBase.php src/Widget/Element.php src/Layout/RenderBox.php

# A3：复合数据走字节串
grep -n "implode\|pack(" src/Rendering/Path.php src/Rendering/ImageHandle.php
```
