# 测试体系：是否引入 PHPUnit、smoke 怎么拆（2026-10-28）

> 触发：`tools/smoke.php` 已 19407 行 / 1727 条断言，单文件、无分组、无过滤。
> 参照对象是同机上的 `D:\TypePHP\typephp`（PHP→C++ 编译器，同一个 AOT 目标）。

## 1. TypePHP 怎么分的（实测，不是印象）

它其实是**两层，且不是"单元 vs 集成"那么简单**：

| 位置 | 内容 | 谁来跑 |
|---|---|---|
| `phpunit/src/**/*Test.php` | 4 个 `TestCase`（Platform / Backend / Factory / CompilerBaseAdapter） | `vendor/bin/phpunit` |
| `phpunit/code/` | **1200 个被编译的 .php 素材**，按特性命名（`abstract-class-new.php`…） | 由 `BaseTest::compile($file)` 驱动 |
| `phpunit/bootstrap.php` | `BaseTest` 基类 + **在本仓源码上注册优先 autoload** | — |
| `tests/` | 7 个文件：`compiler/attribute/`、`windows/clang/`、`windows/smoke/` | 手工 / CI 脚本 |

两个可借鉴的点：

1. **`BaseTest` 只提供动词，素材是数据**。`compile($file)` / `exec($expected, $file)` 两个方法
   撑起 1200 个用例 —— 新用例 = 加一个素材文件，不改测试代码。
2. **bootstrap 里那句"prepend 一个锚定本 checkouts 的 autoload"**。注释写得很清楚：
   vendor 可能被多个 worktree 共享，composer 的 PSR-4 会解析到**别人的**源码树，
   于是"测试静默测了另一个仓"。本仓的结构风险不同（自建装载器、不生成
   `vendor/autoload.php`），但结论一致：**bootstrap 必须锚死本仓源码**。

★ 注意 `tests/` 那 7 个文件**不是** PHPUnit 的用例 —— 它是给 CI 的手工素材目录。
所以"PHPUnit + tests 两套"的真实关系是：**PHPUnit 管能断言的，`tests/` 管要跑起来的**。

## 2. Drift2 的现状（量化）

```
tools/smoke.php   19407 行   1727 条 check()   单进程顺序执行   48s / 上限 2G
tools/Smoke/**    13 个主题目录 + 100 多个夹具类   ← 夹具层**已经**分好了
```

按 `// ──` 分节统计（133 节）：

| 类别 | 条数 | 行数 | 判据 |
|---|---|---|---|
| 轻量（可纯单元） | **485** | 5502 | 整节不出现 `Application` / `->frame(` / `dispatchInput` / `runCase(` |
| 重量（要集成） | **1242** | 13533 | 至少出现一个 |

集成侧的真实密度：`dispatchInput` 285 处、`Application` 202 处、`->frame(` 214 处、
`runCase(` 56 处（子进程 abort 用例）。

**结论先行：能交给 PHPUnit 的只有约 28%，而且这个 28% 是上限**（分节级判据偏粗，
实际更少）。smoke 的定位本来就是**端到端验收网**，不是单元测试集合。

## 3. 能不能用 PHPUnit：能，且没有结构冲突（已实测）

| 疑虑 | 实测结果 |
|---|---|
| AOT 会不会把测试代码编进产物 | **不会**。`drift-build.php` 只哈希 `src/` + `apps/{app}/` + `cpp-src/`，`tools/` 完全不参与 |
| 会不会污染 `composer.json` 的"物理边界表" | 不会。`Drift\Dev\ → tools/` 已存在，`testsuite` 指向 `tools/Smoke/Unit` 即可 |
| PHP 8.5 能不能跑 PHPUnit | 能。PHPUnit 10.5.66 实跑通过（`OK (8 tests, 10 assertions)`，25ms / 10MB） |
| 要不要 `composer install` | **不要**。本机 `composer update` 在 "Loading composer repositories" 处 segfault（PHP 8.5 + composer 2.10）；而 `php -r file_get_contents('https://…')` 直接失败、`curl` 正常 ⇒ **PHP 缺 CA 配置，不是网络问题** |
| lint 会不会拦 | 不拦。新目录加入后 `582 个文件 · 0 error` |

**装法（已落地）**：`tools/phpunit.phar`（5.2 MB，curl 下载，`.gitignore` 排除 —— 与
`/cpp/`（141 MB Skia）同级别的"外部二进制不入库"）。

```bash
curl -sSL -o tools/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
php tools/phpunit.phar -c phpunit.xml
```

**已落地的最小实证**：`phpunit.xml` + `tools/Smoke/Unit/ColumnWidthsTest.php`
（8 tests / 10 assertions），覆盖 `ColumnWidths::resolve()` 的四层规则。
其中 7 条与 smoke 里的 7 条 `check()` 逐条对等 —— 用来量"迁过去值不值"。

## 4. 关键判断：不"重写成纯单元"，但让 PHPUnit 当运行器

三条硬理由：

**① 72% 是集成测试，"重写成纯单元"对它们是负收益。**
`dispatchInput` 真路由、`Application` 组装、帧时钟、55 个子进程 abort 用例 ——
要重写成无状态单测，得给每个用例造一套假宿主，那是**重写语义**而不是搬家。
（★ 但仍可以让 PHPUnit 跑它们：不做 `processIsolation`，就是同一个进程里的方法调用。）

**② 一次性改写 1727 条断言，失败时分不清是"搬迁错"还是"语义错"。**
这不是假想：写 `ColumnWidthsTest` 的第一版就错了 2 条（夹具用了固定宽 100，
smoke 里是 60；override 是否计入 `used` 我算反了）。**迁移本身有出错率**，
而搬迁和改语义混在一步里，红的信号就废了。

**③ 真正的痛点不是"没用 PHPUnit"，是"19407 行不能只跑一段"。**
改 Table 要陪跑 1242 条无关断言 —— 解法是"按主题分组 + 过滤"，
而 `--testsuite` / `--filter` 正好是 PHPUnit 的标准做法，不必自造一套 `--only=`。

## 5. 目标：标准结构 + 能只跑一处（2026-10-28 用户澄清）

> "可以实现一个可以复用的测试用例，验证可快速的验证，不需要每次全量跑，
> 同时尽量按照 PHP 的标准建立测试，这样别人在使用的时候可以少一些心智成本。"

所以判据不是"总耗时降到多少"，而是两条：

1. **改 Table 就只跑 Table**（不是 48 秒的陪跑）；
2. **PHP 开发者打开仓库就能看懂**：`tests/`、`*Test.php`、`phpunit.xml`、
   `vendor/bin/phpunit` —— 全是 PHPUnit 的默认约定，不需要先读本仓的规矩。

## 6. 落地的结构

```
tests/
├── Support/
│   ├── bootstrap.php        # PHPUnit bootstrap（require tools/autoload.php）
│   ├── functions.php        # 全局 check() / smokeContext() / findElement()
│   └── SmokeTestCase.php    # 基类：把 check() 桥到 PHPUnit 断言
├── Unit/                    # 纯单元：无状态算术、纯函数、数据结构
│   └── Layout/ColumnWidthsTest.php
└── Feature/                 # 场景：构造控件树 / 真输入路由 / 推帧
    └── Table/
        ├── Fixtures.php     # 该主题的夹具（原 smoke 里的 $tableXxx 闭包）
        └── Phase1Test.php   # 一个测试方法 = 一个关注点
```

`phpunit.xml` 两个 testsuite：`unit`（秒级，改什么都能全跑）、`feature`（按类过滤）。

### 6.1 关键机制：`check()` 转发，段代码一行不改

1727 条断言不能逐条改写成 `assertX()` —— 那是巨型重构，且红了分不清是搬迁错还是语义错。
所以保留全局 `check()`，让它转发给"当前活动的 TestCase"：

```php
function check(string $name, bool $ok, string $detail = ''): void {
    $case = SmokeTestCase::currentCase();
    if ($case !== null) { $case->recordCheck($name, $ok, $detail); return; }
    printf(...);   // 不在 TestCase 里（单跑段脚本调 bug）退回打印
}
```

于是**搬迁 = 把代码整段放进方法体**，`check(...)` 一个字都不用动。

### 6.2 为什么 `SmokeTestCase` 不让它"失败即停"

PHPUnit 默认第一条断言失败就中止方法。GUI 断言不适用：一个布局 bug 会连带 10 条红
（`offsetY` 错 → 对齐错 → 墨迹错），失败即停只能看到第一条；修完再跑，又只看得到第 2 条。

所以 `check()` 只记录，`flushChecks()` 在方法末尾把全部失败一次性抛给 PHPUnit：

```
1) Drift\Dev\Tests\Feature\Table\Phase1Test::testVirtualRowsHeaderAndColumnWidths
1 / 9 条失败：
  - Table · 10000 行只造一屏 + 缓冲（行区 208 / 行高 40 / 缓冲 200 ⇒ 11 行）  —— created=11 / 树内=11
```

比原来的 `FAIL <名> —— detail` 多两个信息：**失败条数 / 总条数**、以及**精确到方法的行号**。
断言计数照常交给 `addToAssertionCount()`，PHPUnit 的统计、`--testdox`、JUnit XML 都是真的。

★ `flushChecks()` 里有一条 `assertGreaterThan(0, $total, '这个测试方法一条断言都没跑')` ——
防止"段没搬进来 / require 静默失败"变成绿着的空壳。

### 6.3 夹具上提到类（这才是"可复用"的落点）

原来 `$tableRowsOf` / `$tableStateOf` / `$tableBoxOf` 这批闭包是**脚本变量**，
被九个分节共用。留在脚本里意味着"任何一段想单独跑，都得把前面几百行一起带上"。

现在提成 `tests/Feature/Table/Fixtures.php` 的静态方法（`rows()` / `build()` / `mount()` /
`pump()` / `stateOf()` / `boxOf()` / `rowsInWindow()` / `kidsOf()` / `rowElements()` /
`widthsOf()` / `rowBoxOf()`），每个测试方法自建夹具、互不依赖。

## 7. 实测结果

| 项 | 结果 |
|---|---|
| `phpunit --testsuite=unit` | 8 tests / 10 assertions / **31 ms** |
| `phpunit --filter=Phase1` | 1 test / 10 assertions / **88 ms** |
| `phpunit`（两个套件） | 9 tests / 20 assertions / **92 ms** |
| 变异自检（行数期望 11 → 12） | 红，报 `1 / 9 条失败` + 断言名 + `created=11 / 树内=11` |
| lint（`tests/` 已纳入扫描） | **588 文件 / 0 error** |
| gate `--fast` | 5 项 / **7.4s** |

## 8. 迁移顺序（每步都有硬判据）

| 步 | 动作 | 判据 |
|---|---|---|
| **S0** ✅ | 建 `tests/` 骨架 + `ColumnWidths` 纯单元 + `Table/Phase1` 场景 | 20 断言绿 / lint 0 error |
| **S1** | 把 Table 剩余 8 个分节搬成 8 个测试方法（阶段 1 已做） | 每条与 smoke 原断言**条数对得上** |
| **S2** | 从 `tools/smoke.php` 删掉已搬走的段 | smoke 条数 = 1731 − 已搬数，且全绿 |
| **S3** | 其余主题（布局 / 控件 / 输入 / 状态 / 浮层 / 应用 / 异步 / 主题 / 动画 / 日志 / 语义）照同一形状搬 | 每搬一个跑一次全绿 |
| **S4** | 纯单元（485 条里的）改成标准 `TestCase`：`assertSame` + `#[DataProvider]` | `--testsuite=unit` 覆盖它们 |

★ S1–S3 是**纯搬迁**（断言一行不改，只改"在哪个文件哪个方法里"）。
S4 才动断言写法。混淆了，红信号就废了。

★ S2 之前，同一批断言会在两处跑（smoke 和 PHPUnit）—— 这是有意的：
搬迁正确性由"两边都绿"互相印证，删旧的是最后一步。

## 9. 坑

- **`phpunit.xml` 的注释里不能有 `--`**（XML 规范）。写 `phpunit -c ... --filter=X`
  作示例会直接让整个配置加载失败，报 "Comment must not contain '--'"，且不说是哪一行。
- **`runCase(__FILE__, 'case')`** 依赖 `__FILE__` 就是入口脚本。段搬到 `tests/` 之后，
  子进程用例要显式指向 `tools/smoke.php`，不能再用 `__FILE__`。
- **`fresh($log)` + `Errors` 单例**：PHPUnit 里每个方法之间不自动隔离，
  用到日志的测试方法要在开头自己 `fresh()`。
- **命名空间别猜**：`BuildContext` 在 `Drift\Widget`、`Axis`/`BoxConstraints`/`Size` 在
  `Drift\Geometry`（不是 `Drift\Layout`）。抄 `tools/smoke.php` 的 `use` 块，别按目录名推。
- **`tests/` 要加进 lint 的 `DEFAULT_DIRS`**，否则新目录不受任何规则约束
  （而且 `isToolchain()` 要把 `tests/` 算进去，否则 nano-hygiene 一类"产物纪律"会误报）。
- **迁移的出错率不是 0**：手写 `ColumnWidthsTest` 第一版 7 条里错 2 条
  （夹具固定宽用了 100 而 smoke 是 60；override 是否计入 `used` 算反了）。
  搬断言必须对着原断言逐行核。
