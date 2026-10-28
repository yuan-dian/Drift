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

## 4. 关键判断：**不要整体迁 PHPUnit**

三条硬理由：

**① 72% 是集成测试，PHPUnit 对它们是负收益。**
`dispatchInput` 真路由、`Application` 组装、帧时钟、55 个子进程 abort 用例 ——
PHPUnit 要么 `processIsolation`（每条起一个子进程，48s 会变成几分钟），
要么照样自己 `exec`。**换壳不减负，只是多一层继承**。

**② 一次性改写 1727 条断言，失败时分不清是"搬迁错"还是"语义错"。**
这不是假想：写 `ColumnWidthsTest` 的第一版就错了 2 条（夹具用了固定宽 100，
smoke 里是 60；override 是否计入 `used` 我算反了）。**迁移本身有出错率**，
而搬迁和改语义混在一步里，红的信号就废了。

**③ 真正的痛点不是"没用 PHPUnit"，是"19407 行不能只跑一段"。**
改 Table 要陪跑 1242 条无关断言。这个痛点 `--only=` 就能解，不需要 PHPUnit。

## 5. 方案：两条腿，各干各的

对齐 TypePHP 的分法，但按本仓的实际情况变形：

| 层 | 落点 | 跑法 | 负责什么 |
|---|---|---|---|
| **端到端验收**（主体，~1242 条） | `tools/Smoke/Suites/*.php` | `php tools/smoke.php [--only=table]` | 真路由 / 帧 / 应用 / 子进程 abort |
| **纯单元**（~485 条，逐步搬） | `tools/Smoke/Unit/**/*Test.php` | `php tools/phpunit.phar -c phpunit.xml` | 无状态算术、纯函数、数据结构 |
| **夹具**（已有） | `tools/Smoke/{App,Layout,Rendering,…}` | 两者共用 | 构造物 |

`tools/smoke.php` 从 19407 行降到约 250 行的 runner（解析 `--only` / `--list`、
按序跑 suite、汇总、退出码）。

### 拆分不是"切 133 个分节"

现有分节是**按开发时间顺序**排的，不是按主题。同一主题散在多处：

| 主题 | 现分节位置（行） | 跨度 |
|---|---|---|
| Table | 16610 / 17342 / 17555 / 17799 / 18027 / 18506 | 2800 行，中间夹着别的 |
| 布局 | 4493 / 4973 / 5009 / 12014 / 15851 | 散在 11000 行里 |
| 日志 | 16012 / 16188 | 相邻，罕见的好切点 |

所以拆分要**按主题重排**，不是按现有 `// ──` 顺序切。这也意味着**跨节变量依赖**
是主要风险 —— 现在是线性脚本，后面的节可能复用前面的变量。

## 6. 推进顺序（每步都有硬判据，都可回退）

| 步 | 动作 | 判据 | 风险 |
|---|---|---|---|
| **S0** ✅ | PHPUnit 可行性实证（`phpunit.xml` + `ColumnWidthsTest` + phar） | 8 tests 绿 / lint 582 文件 0 error | 无 |
| **S1** | 抽 `tools/Smoke/Support/`（工具函数）+ `tools/Smoke/Cases/`（55 个子进程 case，原 374–1420 行） | 1727 全绿 + golden 逐像素不变 | `runCase(__FILE__)` 要改成指向 Cases 入口 |
| **S2** | 抽 `tools/Smoke/Suites/Table.php`（2800 行，单一主题、刚写完最熟） | 全绿 + `--only=table` 能只跑这段 | 跨节变量：需现场重建夹具 |
| **S3** | 按主题抽剩余（BackendConformance / Layout / Widgets / Input / State / Overlay / Apps / Async / Theme / Animation / Logging / Semantics） | 每抽一个跑一次全绿 | 同上；顺序依赖会在这里暴露（这是好事） |
| **S4** | 把已抽出的纯单元（`ColumnWidths` / `ExtentWindow` / `TableRowPicker` / 布局算术）改成 TestCase，并从 smoke 删掉对应 check | 两套都绿，且条数对得上 | 只能搬 28%，别贪 |

★ S1–S3 是**纯搬迁**：一行断言都不改。S4 才动语义。这条界线不能混 ——
一旦混了，红了就不知道是哪一步的错。

## 7. 坑（写下来避免重踩）

- **`runCase(__FILE__, 'case')`** 依赖 `__FILE__` 就是入口脚本。抽 Cases 之后要么
  改成 `runCase(SMOKE_CASES, 'case')`，要么让每个 suite 文件自己可当 `--case=` 入口。
  前者简单，推荐前者。
- **`fresh($log)` 78 处 + `Errors` 单例**：拆开后 suite 之间不再自动隔离。
  runner 在每跑一个 suite 前后各 `fresh()` 一次即可，但**不要**在 suite 内部保留
  "靠上一节留下的日志"这种隐式契约。
- **`ini_set('memory_limit', '2048M')`**：全量 1727 条会串起几十个 `Application`，
  每个都持有字模 + 渲染树。拆成 suite 后**单 suite 跑可以要小得多**，但 runner
  统一跑时仍需 2G。
- **PHP 的 https 流不可用**（缺 CA）：任何 `file_get_contents('https://…')`
  在测试里都会失败。已有用例若依赖它，会从"网络抖动"变成"必失败"。
- **迁移的出错率不是 0**：实测第一版 `ColumnWidthsTest` 7 条里错 2 条。
  S4 每搬一组，期望值都要对着 smoke 的原断言重新核，不能凭记忆写。

## 8. 待裁决

1. S1 现在开工，还是先做别的（Table 还有 §19.9 的边界没做）？
2. `tools/phpunit.phar` 不入库（当前做法）是否可接受？CI/换机需要一条 curl。
3. S4 的边界：只搬 `Layout\*` 的纯算术，还是连"布局结果断言"（要构造 Widget 树、
   但不碰输入/帧）也搬？后者的判据更模糊。
