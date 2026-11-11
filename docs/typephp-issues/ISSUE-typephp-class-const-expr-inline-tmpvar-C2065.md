# TypePHP AOT 缺陷 Issue — 表达式求值的类常量在方法体内被内联 ⇒ 引用未声明临时量（C2065/C2593），整个应用编译不过

> **标题（可直接贴到 GitHub Issue title）**
> `[AOT codegen] 表达式求值的类常量被内联进获取方函数体，携带 parse 期临时量 ⇒ C2065/C2593，整个应用编译不过`
>
> **提交对象**：上游 TypePHP 编译器（AOT / codegen）
> **提交人**：Drift（TypePHP→C++17→AOT 的 Win32 GUI 框架）实测发现
> **实证日期**：2026-10-20（真机构建暴露）/ 2026-10-21（用父提交复现确认）
> **编译器**：`tpc` AOT v0.9.5，Windows + MSVC，`-O0`、`-m bin`、`c++17`
> **复现基线**：缺陷在 `06851fb5` 上复现；本仓 fork 已修复于 `1a9d77d1`（尚未提交上游 PR，补丁见 §9）
> **对照**：同一段源码用发行版 `php.exe` 跑，结果**完全正确** ⇒ 缺陷在 AOT 生成物，不在运行时语义。

---

## 1. 现象

```text
build/src/main.cc(21): error C2065: 'tmp_var_1': 未声明的标识符
build/src/main.cc(21): error C2593: 'operator |' is ambiguous
```

（具体错误号随 MSVC 版本略有差异；Drift bench 应用上表现为 `C2065`。）

- **本地 PHP 语义完全正确**：`public const ALL_MASK = CAP_A | CAP_B;` 与 `return self::ALL_MASK;` 分开写，PHP 合法。
- **AOT 把常量初值串直接内联进"获取方"的函数体**，而该串里含有 parse 阶段产生的 **materialize 临时量 `tmp_var_N`** —— 这些临时量的声明只存在于 `arrayExpr` 的 prelude 里、且**只随数组常量发射**，获取方作用域里根本没有它们。
- **整个应用编译不过**，报错指向生成的 `.cc`，PHP 源码里找不到任何对应物。

---

## 2. 最小复现

### 2.1 `src/main.php`

```php
<?php

namespace Probe {
    final class Caps
    {
        public const CAP_A = 1 << 0;
        public const CAP_B = 1 << 1;

        // 表达式求值的类常量
        public const ALL_MASK = self::CAP_A | self::CAP_B;

        public static function all(): int
        {
            return self::ALL_MASK;   // ← 在方法体内获取 ⇒ 触发
        }
    }
}

namespace {
    function main(): void
    {
        \file_put_contents(__DIR__ . '/out.log', (string) \Probe\Caps::all());
    }
}
```

### 2.2 `project.yml`

```yaml
name: const-tmp-probe
version: 0.1.0
mode: bin
platform: win32
php-version: "8.5"
optimize: 0
build-dir: build
cxx-std: c++17
sources:
  - src
```

```bash
tpc project.yml -O0 -m bin          # 生成 + 编译 ⇒ C2065
tpc project.yml -O0 -m bin --dry    # 只生成：成功，零诊断（这是它危险的原因）
```

---

## 3. 生成物证据

### 3.1 修复前（父提交 `06851fb5`，`--dry` 后的 `build/src/main.cc`）

```cpp
php::Int php_probe__caps__all(php::Object &this_) {
    php::Var tmp_var_0;

    tmp_var_0 = (((php::Var(tmp_var_0)) | (php::Var(tmp_var_1))));   // ← 第 21 行
    if (UNEXPECTED(!(tmp_var_0.isInt()))) {
        php::throwReturnTypeError(tmp_var_0, get_str(0), get_str(1), true);
    }
    return php::toInt(tmp_var_0);
}
```

**这一行同时中两枪**：

- `tmp_var_1` —— 在本函数里**从未声明**（全仓库 grep `tmp_var`，方法体内只有 `tmp_var_0` 的定义）⇒ `C2065`；
- `tmp_var_0` —— 在自己的初始化表达式里读自己 ⇒ 即使编译过也是未定义行为。

### 3.2 修复后（`1a9d77d1`，同一份源码）

```cpp
php::Int php_probe__caps__all(php::Object &this_) {
    php::Var tmp_var_0;

    tmp_var_0 = (php::constant(get_persistent_class(PersistentClassId{0}, get_str(0)), get_str(1)));
    if (UNEXPECTED(!(tmp_var_0.isInt()))) {
        php::throwReturnTypeError(tmp_var_0, get_str(2), get_str(3), true);
    }
    return php::toInt(tmp_var_0);
}
```

回落 Zend 类常量查表（`php::constant(ce, name)`），注册处声明的本就是求值器算出的字面量 ⇒ 正确且有转换期缓存。

---

## 4. 根因

`findNativeClassConst()`（`src/CompilerBase.php:3333`）在非数组（标量）常量场景下，把**常量初值的 C++ 串直接作为获取方的表达式**返回并被内联：

```php
if (!$constDef->codegenFinalized) { return false; }
$expr->setAttribute('nativeConst', $constDef);
return $constDef->value;      // ← value 串原样飞出类定义所在的作用域
```

而当初值是**表达式**时，parse 阶段为求值产生的 materialize 临时量（`tmp_var_N`）的声明与赋值**只写在 `arrayExpr` 的 prelude 里、只随数组常量一起发射**。于是：

- **常量定义处**：临时量声明齐备，代码正确；
- **获取方（另一个函数体）**：只拿到了 `value` 串，引用了一批本作用域不存在的局部量 ⇒ 编译失败。

`|`、`&`、算术、函数调用等凡产生 materialize 临时量的表达式都走这条路。

---

## 5. 触发判据

1. 类常量（或接口常量）的初值是**表达式**，且该表达式的求值产生了 `tmp_var_N`（如 `A | B`、位运算链）；
2. 该常量在**另一个作用域**（方法/函数体内）被获取 ⇒ 触发内联。

不触发的对照形态：常量初值是字面量/不含临时量的表达式 ⇒ 内联即安全；常量在**常量表达式**里被引用（如默认参数）也不走这条内联路径。

---

## 6. 真实案例（Drift）

`src/Rendering/CanvasCapabilities.php`：

```php
public const ALL_MASK = self::CAP_ELLIPSE | self::CAP_LINE
    | self::CAP_GRADIENT | self::CAP_DASH | self::CAP_CLIP_PATH
    | self::CAP_PATH | self::CAP_IMAGE | self::CAP_SHADOW | self::CAP_LAYER;
```

`src/Rendering/RecordingCanvas.php:74` 的 `capabilities()` 返回它 ⇒ 2026-10-20 的 bench 应用 AOT 构建炸 `C2065`。修复上车后同一构建转为 `✓ bench.exe`（7.6 MB）。

---

## 7. 为什么难抓

| 维度 | 情况 |
|---|---|
| 标准 PHP | 完全正确 ⇒ 解释模式 0 症状 |
| `--dry` 门（只生成不编译） | **生成照样成功、零诊断** —— 报错只会在真编译时出现 |
| 错误定位 | 报在生成的 `.cc`（如 `build/src/main.cc:21`），PHP 源码里无对应物 |
| 触发面 | "常量链"（`ALL = A | B`、`CORE` 又是叶子的 `|`）是很自然的位掩码/标志位写法，撞上概率高 |

另：**该仓现有 phpt 未覆盖"表达式常量在方法体内获取"这一形态**，所以之前一直没被发现。

---

## 8. 用户侧规避（可行但不該由用户承担）

把常量链**平铺成叶子常量的一次运算**，不让"常量引用常量"进编译进产物的表达式（Drift `CanvasCapabilities.php:56-64` 就是这么改的）。中间 mask 保留供人查阅，但**不进任何表达式**。
⇒ 能用，但这是让用户替编译器绕坑。

---

## 9. 修复（已按上游 PR 标准备好分支，待提交）

### 9.1 分支与提交

- 分支：`fix/class-const-expr-inline-tmpvars`（单提交，自 `origin/master` @ `06851fb5` 起）
- 提交：`fix(compiler): fall back to Zend lookup for expression-valued class constants`
  （提交信息体为英文、72 列换行 + `Validation:` 段，格式对齐仓内最近提交；代码注释同步改为英文，与原文件注释风格一致）
- 变更：3 文件 / +74 行 —— `src/CompilerBase.php`（本节的闸）+ 新增 PHPUnit codegen 回归测试 2 文件
- master 上原中文提交 `1a9d77d1` **已撤销**（master 现与 `origin/master` 齐平）

### 9.2 补丁（`src/CompilerBase.php`，`findNativeClassConst()` 的 value 内联前）

```diff
@@ -3400,6 +3400,16 @@ abstract class CompilerBase implements PropertyAccessContext
             if (!$constDef->codegenFinalized) {
                 return false;
             }
+            // A constant initializer that parse lowered into materialize
+            // temporaries (tmp_var_N) cannot be inlined: those temporaries are
+            // declared and assigned only in arrayExpr's prelude, which is
+            // emitted together with array constants. Inlining the value string
+            // into the fetching function references locals that do not exist
+            // there (C2065 / C2593). Fall back to the Zend class-constant
+            // lookup instead: it reads the already-evaluated literal that was
+            // registered for the constant and is cached in the convert phase.
+            if ($constDef->arrayExpr !== '' || str_contains($constDef->value, 'tmp_var_')) {
+                return false;
+            }
             $expr->setAttribute('nativeConst', $constDef);
             return $constDef->value;
         }
```

**为什么这样修**：`return false` 会走函数既有 fallthrough —— 即 `php::constant(ce, name)` 的 Zend 类常量查表（与 forward-ref 回落同型）。注册处（`Translator`）声明的常量值是求值器算出的**字面量**，查表既正确又带转换期缓存，不损失内联的意义（内联本来是为了省一次查表，而这里已经不可安全内联）。

**回归测试**（新增 2 文件，PHPUnit codegen 夹具，便宜、无需 MSVC）：

- `phpunit/code/class-constant-expression-inline.php`：同类里放一个字面量常量 `CAP_A = 1` 与一个表达式常量 `ALL_MASK = CAP_A | CAP_B`，两个方法分别读取。
- `phpunit/src/ClassConstantExpressionInlineTest.php`：断言**叶子常量仍被内联**（方法体是 `= (1L+);`、无 `php::constant(`），而**表达式常量走 Zend 查表**（方法体含 `php::constant(`、且不含 `php::Var(tmp_var_N)`）。

**验证（全部真跑）**：

1. 新测试在父提交 `06851fb5`（未修）上**失败**，失败正文就是 `tmp_var_0 = (((php::Var(tmp_var_0)) | (php::Var(tmp_var_1))))`；带闸后通过。
2. 拆掉闸（保持测试）再跑 ⇒ 同样失败 ⇒ 该测试**确实抓得住这个 bug**，不是摆设。
3. **完整 PHPUnit 套件**（排除 `Build` 目录与被 Linux-only `exit(1)` 中断的 `CompilerBaseApiTest`）：修复分支与 master 的失败清单**逐条一致**（各 143 条：37 errors / 106 failures / 111 warnings），唯一的差量就是新增的这 1 个测试 / 7 条断言 ⇒ **零回归**。
4. Drift bench 应用 AOT 构建从 `C2065` 转为 `✓ bench.exe`（7.6 MB）；Drift smoke 797/0。

**还需要上游做的两件事**：
1. **合并该 PR**（`fix/class-const-expr-inline-tmpvars`）；
2. （可选）若要 phpt 覆盖，可基于同一 fixture 再补一条 —— 那需要自举编译器；另外建议给 `--dry` 路径加一次"内联值串含 `tmp_var_`"的自检，让这类问题不等真编译就暴露。

---

## 附录：复现物位置（Drift 仓内，可自行复核 before/after）

```
_build/const-tmp-probe/     # 最小复现工程
```

复核方式（两行）：

```bash
cd _build/const-tmp-probe
php D:/TypePHP/typephp/bin/tpc.php --project project.yml --dry \
    --compiler <fakecl路径>/cl.exe      # --dry 仍需 cl.exe 可发现：cp system32/cmd.exe 即可
sed -n '17,26p' build/src/main.cc       # 看 tmp_var_0 自引用 / tmp_var_1 未声明
```

换 `D:/TypePHP/typephp` 的 HEAD 到 `06851fb5`（缺陷）与 `1a9d77d1`（已修）各跑一次，即可对照 §3.1 / §3.2。

---

**关联**：本仓另有一条同家族常量缺陷 issue —— 类常量与同类方法同名 ⇒ `C2872`（`docs/ISSUE-typephp-class-const-vs-method-C2872.md`）。
**关联 Drift 内部记录**：`docs/_archive/changelog-v2.md` §24.1 系列；`src/Rendering/CanvasCapabilities.php:56-64`（规避注释）。
