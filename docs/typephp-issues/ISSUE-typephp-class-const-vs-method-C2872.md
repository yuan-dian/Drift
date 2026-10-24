# TypePHP AOT 缺陷 Issue — 类常量与同类方法同名 ⇒ `error C2872: ambiguous symbol`，整个应用编译不过

> **标题（可直接贴到 GitHub Issue title）**
> `[AOT codegen] 类常量与同类方法同名（大小写不敏感）⇒ 生成 C++ 符号冲突 ⇒ error C2872，整个应用编译不过`
>
> **提交对象**：上游 TypePHP 编译器（AOT / codegen）
> **提交人**：Drift2（TypePHP→C++17→AOT 的 Win32 GUI 框架）实测发现
> **实证日期**：2026-10-21（复现）/ 2026-10-15（首次真机暴露）
> **编译器**：`tpc` AOT v0.9.5（`D:/TypePHP/typephp` @ `1a9d77d1`），Windows + MSVC，`-O0`、`-m bin`、`c++17`
> **对照**：同一段源码用发行版 `php.exe` 跑，结果**完全正确** ⇒ 缺陷在 AOT 生成物，不在运行时语义。

---

## 1. 现象

```text
.../php_const_bug_src_main_8f46395db2_decl.h(15): error C2872: 'php_probe__fontdata__latin': ambiguous symbol
```

- **本地 PHP 语义完全正确**：`private const LATIN` 与 `public static function latin()` 同一类内共存，PHP 合法且行为正常。
- **AOT 生成的头文件里出现两个同名实体**（一个函数、一个变量），且分布在**不同的命名空间**，再叠加生成头末尾的 `using namespace typephp_project_<项目名>;` ⇒ 任何包含该头的翻译单元一用到这个名字就 C2872。
- **整个应用编译不过**，且**没有任何诊断指向用户源码**——用户在自己的 PHP 里根本找不到这个标识符。

---

## 2. 最小复现

### 2.1 `src/main.php`

```php
<?php

namespace Probe {
    class FontData
    {
        // private const 与同类方法同名（大小写不同即可）
        private const LATIN = [65, 66];

        public static function latin(): int
        {
            return self::LATIN[0];
        }
    }
}

namespace {
    function main(): void
    {
        \file_put_contents(__DIR__ . '/out.log', (string) \Probe\FontData::latin());
    }
}
```

### 2.2 `project.yml`

```yaml
name: const-bug
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
tpc project.yml -O0 -m bin          # 生成 + 编译 ⇒ C2872
tpc project.yml -O0 -m bin --dry    # 只生成：成功，零诊断（这是它危险的原因）
```

---

## 3. 生成物证据（`--dry` 后的明文 `.cc`）

`build/include/php_const_bug_src_main_8f46395db2_decl.h`：

```cpp
extern php::Int php_probe__fontdata__latin(php::Object &this_);   // ← 第 8 行：方法 latin()
extern void php_main();
#include <phpx.h>
...
namespace typephp_project_const_bug {
extern php::Var php_probe__fontdata__latin;                        // ← 第 15 行：常量 LATIN（数组）
}  // namespace typephp_project_const_bug
using namespace typephp_project_const_bug;                         // ← 第 18 行：注入全局作用域
```

`build/src/main.cc`（定义，全局作用域）：

```cpp
php::Int php_probe__fontdata__latin(php::Object &this_) {   // ← 第 18 行：方法定义
    ...
    tmp_var_0 = (php_probe__fontdata__latin.item(0LL, false));   // ← 第 21 行：这里取常量 ⇒ 歧义点
```

`build/init/php_const_bug_src_main_8f46395db2_class_constants.cc`：

```cpp
namespace typephp_project_const_bug {
zend_never_inline void typephp_request_init_class_constant_949db33225ad9a59b579() {
do {
php_probe__fontdata__latin = php::Array{ php::Var(65LL), php::Var(66LL) };   // ← 第 22 行
php::updateConstant("Probe\\FontData", "LATIN", ...);
} while(0);
```

`build/extension-const_bug.cc`（变量定义，位于 `typephp_project_const_bug` 命名空间内）：

```cpp
namespace typephp_project_const_bug {
...
php::Var php_probe__fontdata__latin;   // ← 第 146 行
```

**同一个不限定名字 `php_probe__fontdata__latin`：**

| 实体 | 种类 | 所在作用域 |
|---|---|---|
| 方法 `latin()` | 函数（`php::Int(php::Object&)`） | 全局命名空间（`main.cc:18` 定义 / decl.h:8 声明） |
| 常量 `LATIN`（数组） | 变量（`php::Var`） | `namespace typephp_project_const_bug`（decl.h:15 / extension-const_bug.cc:146） |

再经 decl.h 末尾 `using namespace typephp_project_const_bug;`（decl.h:18）注入 ⇒ **非限定引用同时命中两个不同种类的实体 ⇒ C2872。**

---

## 4. 根因

1. **两侧命名都用同一个函数、都强制小写**：`CompilerBase::getNativeName()`（`src/CompilerBase.php:1594`）拼 `php_<ns>_<class>_<member>`，`<member>` 经 `Utils::escapeName()`（`src/Generator/Utils.php:144`）`strtolower()` ⇒ **PHP 层的类常量名与方法名统一被折叠成小写**。
   - 常量：`Translator` 为数组常量发全局变量，名字 = `PREFIX . getNativeName($constant->name, ns, class)`（`src/Translator.php:1590`，`build/src`、`extension-*.cc`、`init/*_class_constants.cc` 三处同源）。
   - 方法：`getNativeMethodName()`（`src/Translator.php:5118`）/ `CompilerBase.php:3330` ⇒ 同名拼接。
2. **两侧作用域不一致**：方法函数落在**全局命名空间**，数组常量变量落在 `typephp_project_<项目名>` 命名空间。
3. **生成头末尾 `using namespace typephp_project_const_bug;`** 把命名空间成员提到全局 ⇒ 名字查找歧义。
4. **数组常量走"全局 `php::Var` 变量"这条路，而标量常量走"内联字面量"那条路**（`findNativeClassConst()` `src/CompilerBase.php:3393-3414`：`Type::ARRAY` 返回符号名、其余返回已求值的 value 串）⇒ 见 §5，**只有数组常量会踩**。

---

## 5. 触发判据（已全仓扫过，Drift2 仓仅此一处）

同时满足两条才触发：

1. **同一个类内**，存在常量名与方法名**忽略大小写后相同**（如 `LATIN` vs `latin()`、`Mode` vs `mode()`；案例：`private const LATIN/CJK` vs `public static function latin()`）。
2. 该常量是**数组（`Type::ARRAY`）常量**。

### 5.1 对照实验（已做）

把最小复现里的 `private const LATIN = [65, 66];` 改成 `private const LATIN = 65;`（标量），重新 `--dry`：

```text
decl.h:8   extern php::Int php_probe__fontdata__latin(php::Object &this_);   ← 只剩方法一个符号
           （decl.h:15 的 extern php::Var 不再出现；class_constants.cc 不再定义全局变量）
```

⇒ **标量常量被内联，不产生 C++ 符号，不冲突。** 这也解释了同仓里 `= ButtonVariant::Default`（enum case 整体）等写法能过。

---

## 6. 为什么难抓（影响评估）

| 维度 | 情况 |
|---|---|
| 标准 PHP | 完全正确 ⇒ **解释模式 0 症状**，`php -l` / 本地运行全绿 |
| `--dry` 门（只生成不编译） | **抓到生成物但零诊断** —— 代码照样生成，`--dry` 报成功 |
| lint / 预览 | 完全看不到（纯 codegen 问题） |
| 真编译 | C2872，报在**生成头**（`*_decl.h`）而不是用户源码 ⇒ **错误信息不可定位**，用户会以为是编译器内部错误 |
| 覆盖面 | 只要用户代码里出现"常量/方法大小写折叠同名 + 数组常量"就中；命名习惯越贴近 `LATIN/CJK/Glyph` 这种全大写常量族，撞上 `strtolower` 后方法名的概率越高 |

---

## 7. 当前规避（Drift2 侧）

常量改名，语义零变化：`LATIN` → `LATIN_GLYPHS`、`CJK` → `CJK_GLYPHS`。
⇒ **规避可行但代价落在用户命名上**，且**编译器没有给出任何提示**说明"这两个名字不能共存"。

---

## 8. 建议修法（任选其一/组合）

1. **给数组常量一个独立的符号命名空间**（推荐，与仓里既有做法一致）：
   现在 `getNativeObjectPropertyCppName()` / `getNativeObjectInitializerName()` 都有"compiler-owned helper 不走 `php_*` 用户符号命名空间"的先例（见 `src/NativeClass/NativeClassSupportTrait.php:1899-1921` 注释）。把数组常量改成 `php_const__<ns>_<class>_<const.lower>` 之类**带前缀、不与方法名同构**的形式，两条路从源头不撞。
2. **编译期诊断兜底**：在成员名校验处（`validateNativeObjectMemberNames()`，`src/NativeClass/NativeClassSupportTrait.php:872` 一带）加一条规则——**常量名小写 ∩ 同类方法名小写 ⇒ fatalError**，给出可读提示（"常量 `X::LATIN` 与方法 `X::latin()` 在 AOT 生成物中同名；请改名或改用标量常量"）。这样即使不改进命名，也能把 C2872 变成一条人话。
3. **最小兜底**：`--dry` 时对这类符号冲突做一次静态自检并报警（现在 `--dry` 是哑的，纯生成不校验）。

---

## 附录 A：另一条同家族常量缺陷（已在我方 fork 修复，未合上游）

- **现象**：方法体内获取**表达式求值的类常量**（`const ALL_MASK = A | B | C`，再 `return self::ALL_MASK;`）⇒
  `findNativeClassConst()` 把常量初值的 C++ 串直接内联进获取方函数体，而该串里的 `tmp_var_N`（parse 期 materialize 临时量）只在 `arrayExpr` 的 prelude 里声明、且只随**数组常量**发射 ⇒ C2065 / C2593（Drift2 实证：`RecordingCanvas::capabilities` 返回 `CanvasCapabilities::ALL_MASK`）。
- **修复**（本地 commit `1a9d77d1`）：`value` 串含 `tmp_var_` 或 `arrayExpr` 非空时返回 false，回落既有 `php::constant(ce, name)` 查表路径（与 forward-ref 回落同型）。
- **注意**：上游 `phpt` 未覆盖"表达式常量体内获取"这一形态；建议一并补测试。

## 附录 B：复现物位置（Drift2 仓内，可直接 `--dry` 复核）

```
_build/const-bug-probe/            # 数组常量版（触发）
_build/const-bug-probe/scalar/     # 标量常量版（对照，不触发）
```

复现方式：`cd _build/const-bug-probe && php D:/TypePHP/typephp/bin/tpc.php --project project.yml --dry --compiler <任意假 cl.exe>`（`--dry` 仍需 `cl.exe` 可发现：`cp /c/Windows/System32/cmd.exe cl.exe` 即可）；随后 `grep -n "fontdata__latin" build/include/*decl.h` 看第 8/15/18 三行。

---

**关联 Drift2 内部记录**：`docs/_archive/changelog-v2.md` §24.1 #41（上游 tpc codegen 缺陷）。
