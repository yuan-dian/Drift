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

**已落地的最小实证**：`phpunit.xml` + `tests/Unit/Layout/ColumnWidthsTest.php`
（8 tests / 10 assertions），覆盖 `ColumnWidths::resolve()` 的四层规则。
其中 7 条与 smoke 里的 7 条 `check()` 逐条对等 —— 用来量"迁过去值不值"。
（首版放在 `tools/Smoke/Unit/`，S0 之后统一挪到 `tests/Unit/`。）

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
| **S1** ✅ | 按**被测对象**（不是分节）搬 Table 的 9 个分节 | 每条与 smoke 原断言**条数对得上** |
| **S2** ✅ | 每搬完一个主题就从 `smoke.php` 删掉对应段 | smoke 条数 = 1731 − 已搬数，且全绿 |
| **S3** 🚧 | 其余主题照同一形状搬（**按被测对象切文件、按关注点切方法**，纯逻辑→Unit、特征链→Feature） | 每搬一个：phpunit 全绿 + smoke 精确对账 + lint 0 error |
| &nbsp;&nbsp;· 帧预算/挂载登记 ✅ | `tests/Feature/Frame/{BudgetTest,MountRegistryTest}`（共用 `tests/Support/Frame`） | smoke 1585 → 1575（−10） |
| &nbsp;&nbsp;· 窗口状态 ✅ | `tests/Feature/State/WindowStateTest` | 1575 → 1563（−12） |
| &nbsp;&nbsp;· 语义树 ✅ | `tests/Feature/Semantics/SemanticsTest`（$semLay 夹具保留在 smoke） | 1563 → 1548（−15） |
| &nbsp;&nbsp;· 表单控件(Checkbox/Radio/Toggle) ✅ | `tests/Feature/Widget/ToggleTest` | 1548 → 1535（−13） |
| &nbsp;&nbsp;· 分节原语(Divider/Progress) ✅ | `tests/Feature/Widget/DividerProgressTest` | 1535 → 1520（−15） |
| &nbsp;&nbsp;· 图标 ✅ | `tests/Feature/Widget/IconTest` | 1520 → 1515（−5） |
| &nbsp;&nbsp;· 动画 ✅ | `AnimationControllerTest`(Unit) + `AnimationChainTest`(Feature) + `FramePhaseTest`(Unit) | 1515 → 1492（−23） |
| &nbsp;&nbsp;· 结构控件(Column/Row/Padding/Center/Spacer/Expanded) ✅ | `tests/Unit/Widget/StructureControlsTest` | 1492 → 1476（−16） |
| &nbsp;&nbsp;· 布局原语(Flex/basis·shrink/alignSelf/Stack/RenderProxy) + 三树 Element ✅ | `tests/Unit/Layout/{FlexTest,StackTest,ProxyTest}` + `tests/Unit/Widget/ElementTreeTest` | 1476 → 1431（−45） |
| &nbsp;&nbsp;· 文本/列表/按钮/盒级动画/滚动条 ✅ | `tests/Unit/Widget/{TextTest,EachTest,ButtonTest,ScrollBarFadeTest}` + `tests/Unit/Layout/HoverAnimationTest` | 1431 → 1384（−47） |
| &nbsp;&nbsp;· 输入框 TextField（含编辑态） ✅ | `tests/Feature/Widget/TextFieldTest` | 1384 → 1358（−26） |
| &nbsp;&nbsp;· 输入层（按键模型/焦点路由/坐标链/IME） ✅ | `tests/Unit/Input/KeyModelTest` + `tests/Feature/Input/{FocusRoutingTest,CoordinateChainTest}` | 1358 → 1320（−38） |
| &nbsp;&nbsp;· 选区模型 / 选区渲染 ✅ | `tests/Unit/Widget/TextFieldSelectionTest` + `tests/Feature/Widget/TextFieldTest::testSelectionGeometryAndPaint` | 1320 → 1292（−28） |
| &nbsp;&nbsp;· 状态层（SubjectBuilder / Cell / Computed / RT-3 / settle 漂移） ✅ | `tests/Unit/State/{SubjectBuilderTest,CellTest,ComputedTest}` + `tests/Feature/State/{SubscriptionIsolationTest,SettleDriftTest}` | 1292 → 1264（−28） |
| &nbsp;&nbsp;· 窗口帧（resize / 零视口 / AppBar 窄窗 / 预览降级）+ 主题切换 ✅ | `tests/Feature/App/WindowFrameTest` + `tests/Feature/Theme/ThemeSwitchTest` | 1264 → 1253（−11） |
| &nbsp;&nbsp;· 错误边界三层兜底 + 加速键/编辑命令环/指针框选 ✅ | `tests/Unit/Input/AcceleratorTableTest` + `tests/Feature/Widget/ErrorBoundaryTest` + `tests/Feature/Input/{KeyRoutingPriorityTest,EditingCommandLoopTest,PointerSelectionTest}` | 1253 → 1214（−39） |
| &nbsp;&nbsp;· 命令 / 帧循环 / 路由表 / Offstage ✅ | `tests/Unit/{Commands/CommandTest,Routing/RouteTableTest}` + `tests/Feature/{App/FrameLoopTest,Widget/OffstageTest}` | 1214 → 1182（−32） |
| &nbsp;&nbsp;· 待搬 | 光标形状 / Spinner / 主题作用域 / 页宿主 / 组合根 / 语义推送 / 渲染图像 / ensureVisible / 虚拟列表锚点 / 浮层·模态·菜单族 / W8-2 控件族 / Table / LazyColumn / 日志 / 毛玻璃 | |
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

---

## 10. S1/S2 实际执行（2026-10-28 下半场）

### 10.1 ★★ 关键手法：**first-class callable 别名**

原计划是"把头部的闭包提到 `Fixtures`，然后把段里每个 `$tableBuild(...)` 改成
`Fixtures::build(...)`"。**不要那么做** —— 段里这类调用有几百处，逐个改名是
纯手工高危操作，而且改完读起来更啰嗦。

PHP 8.1 的 first-class callable 语法让搬迁退化成加 10 行头部：

```php
public function testXxx(): void
{
    $tableBuild = Fixtures::build(...);      // ← 别名，签名不变
    $tableMount = Fixtures::mount(...);
    $tableStateOf = Fixtures::stateOf(...);
    $tableBoxOf = Fixtures::boxOf(...);
    $tableFrame = Fixtures::frame(...);

    // 原段代码从这里开始，一个字都不用改：
    $el = $tableMount($tableBuild(10000));
    ...
}
```

★ 这条把"整段搬"从"逐处改名"变成"贴进来 + 头顶 10 行"，是本轮能一次搬完
8 个主题的前提。

### 10.2 ★★★ 分节**不是**测试边界（实测）

`// ──` 分节是按**开发时间顺序**排的，一节里经常混着几个主题：

| 分节 | 行数 | check | 实际内容 |
|---|---|---|---|
| ⑩b | 393 | 28 | 层缓存单元 3 + Table 回夹 1 + **ScrollView 4** + **LazyColumn 2** + Table 命中/斑马/滚动条 6 + Table 像素 5 + 首帧宽漂移 3 |
| 阶段 6 列虚拟化 | 213 | 19 | **ExtentWindow 单元 5 + ColumnWidths 单元 7** + 列虚拟化 7 |
| 选择 | 244 | 22 | **TableSelection 单元 3 + TableRowPicker 单元 11** + UI 8 |
| 排序 | 228 | 17 | **TableSort 单元 2** + UI 9 + 子进程 6 |
| 列宽拖拽 | 479 | 28 | 手柄/拖动 20 + **滚动条 7** + 子进程 2 |

⇒ 所以 S1 的口号从"8 个分节 → 8 个方法"改成**"按被测对象切文件、按关注点切方法"**。
判据是"改 X 只跑 X"，不是"一节一个方法"。

⚠️ 还有**隐式变量耦合**：`$tableHeaderCellsOf` 定义在排序节、被列宽拖拽节借用。
第一次删排序节时它跟着没了，smoke 当场 `Value of type null is not callable`。
**删任何一段前，先跑一遍"待删区定义的变量在保留区还有谁引用"**（脚本见 10.5）。

### 10.3 落地的文件（S1 收尾时的全貌）

```
tests/Support/
├── SmokeTestCase.php            check() 转发 + flushChecks()
└── functions.php                全局 check() / findElement() / smokeContext()

tests/Unit/                      纯值对象：无 Element、无 Application
├── Input/TapTrackerTest.php     四条规则 + 三个断链边界
├── Layout/ColumnWidthsTest.php  四层分配规则（DataProvider）
├── Layout/ExtentWindowTest.php  列窗口累加 + extentOf
├── Text/TextDraftTest.php       字节数学：退格按码位 / 插入符吸附
├── Widget/TableSelectionTest.php 不可变 + int/string 分离
├── Widget/TableRowPickerTest.php 点击 / Ctrl / Shift / 反向 / 无锚点 / 单选 / 孔洞
└── Widget/TableSortTest.php     空态 / thenBy 移列到末尾

tests/Feature/
├── App/FrameTest.php            settle 该跳过 + 不该跳过（盒换代）
├── Rendering/LayerCacheTest.php 录 / 命中 / 同盒改 size 失效
└── Table/
    ├── Fixtures.php             夹具（原 $tableXxx 闭包）+ 像素/几何辅助
    ├── EditSession.php          编辑会话驱动：open / chord / type / tick + 树查询
    ├── SelectionSink.php        受控回写的承接者
    ├── SortSink.php             同上（带列序号）
    ├── ResizeSink.php           同上（拖动日志）
    ├── Phase1Test.php           虚拟行 + 列宽三层 + 行内容居中
    ├── ScrollTest.php           滚动不重建 + 帧不重排 + settle 跳过
    ├── ScrollBarTest.php        maxScroll 双轴 / 不串轴 / 贴底缘 / 真实拖动
    ├── ColumnWindowTest.php     列虚拟化（8 列 → 3 列 / 列位不漂）
    ├── ColumnResizeTest.php     手柄 / 拖动 / clamp / maxWidth 夹取 / 分割线像素
    ├── LayerCacheTest.php       行区边界命中 + 换窗失效
    ├── SelectionTest.php        点击语义送达 + 行态像素
    ├── SortTest.php             三态循环 + 箭头 + 列序号
    ├── KeyboardTest.php         单 Tab 位 / 方向键 / End / ensureVisible / 失焦
    ├── EditTest.php             进 / 提交 / 丢弃 / 失效（会话生命周期）
    ├── TapTest.php              单击落格 / 双击进编辑 / 插入符半边
    └── DragSelectTest.php       区间 / 反向 / 阈值 / 单选 / 自动滚
```

`SelectionSink` / `SortSink` / `ResizeSink` 单独成类是刻意的：受控表要"接住上报"，
而 PHP 的 `&$ref` 在返回类型和箭头函数里会被静默破坏（现象是断言永远 null）。
用一个一字段对象把身份固定住，读代码时一眼看出谁在接。

`EditSession` 同理是"值对象而不是四元组"：`[$app, $root, $state, $focus] = ...` 顺序
错了不报错，只会在解构出一堆 `null` 之后才崩 —— 而那正是要防的失败形态。它还把
**"按键后必须推帧"**收进 `chord()`（漏了不报错，只表现为"断言到了上一帧的值"）。

### 10.4 删旧的验证（S2）

每搬完一个主题就立刻从 smoke 删掉，然后跑全量。条数必须**精确对上**：

| 步骤 | smoke 条数 | 说明 |
|---|---|---|
| 起点 | 1731 | |
| 删 ⑨⑩ + 层缓存 + 4 批单元 | 1692 | −39 |
| 删列虚拟化 | 1685 | −7 |
| 删选择（留 2 条子进程） | 1678 | −7 |
| 删排序 + 行居中 | 1666 | −12 |
| 删列宽拖拽 + 滚动条（留 2 条） | 1640 | −26 |
| 删键盘光标格 + TextDraft + maxWidth | 1622 | −18 |
| 删编辑会话 + 点击链 + 拖动框选（留 4 条） | 1585 | −37 |

最后一批的 −37 拆得开：编辑会话 13 + `TapTracker` 9 + 指针侧 6 + 拖动框选 9。

★ 第一次算"应该减 39"时对不上（以为 40），原因是有一条 `check(` 是单行写法，
统计脚本的 `^\s*check\(` 漏算 —— **条数对账要用 smoke 自己的汇总行，不要靠静态计数**。

### 10.5 变异自检（本轮 8 组，全红）

| 变异 | 抓到它的断言 |
|---|---|
| `ExtentWindow` 前置偏移改成 `$offset` | 2 条（缓冲带 + "偏移落在段中间"） |
| `TableRowPicker` Ctrl 也挪锚点 | 1 条（Ctrl 不动锚点） |
| `TableRowPicker` 区间改 `withAll`（累加） | 2 条（反向拖 + 区间外旧选中被丢） |
| `TableSelection::norm()` 去掉 `i:`/`s:` 前缀 | 2 条（int/string 分离） |
| `TapTracker::chains()` 去掉 `$elapsed >= 0.0` | 1 条（时间倒退 ⇒ 断链） |
| `Application::dispatchInput()` 不标注 `clickCount` | 2 条（双击进编辑 + 插入符半边） |
| `TableState::didChangeFocus()` 失焦不提交 | 1 条（失焦 ⇒ 提交） |
| —— | |

★★ 前两组**第一次变异只被 1 条抓到**，说明用例区分度不足，补了两条才有 2 条：
- `偏移 300` 那条的 `offset === leading`，区分不出"累加"与"直接拿偏移"
  ⇒ 补 `偏移 250（落在段中间）⇒ 前置是段起点 150`。
- 正向 Shift 那条 `current` 是空集，`of` 与 `withAll` 结果相同
  ⇒ 补 `current = ['x','y']` 的"区间外旧选中被丢掉"。

★ 这就是"变异自检要测它的**区分度**，不只是'有没有红'"。

★★ 另一类区分度问题：**反向断言没有独立杀伤力**。`TapTest` 里"慢双击不进编辑"、
"双击不可编辑列不进编辑"在"完全不标注 `clickCount`"的变异下**照样绿** —— 它们断言
"没有发生某事"，而坏实现下恰好什么都不会发生。它们的价值靠正向那条
（`testDoubleClickEntersEdit`）撑着。
⇒ 记法：凡是"X 不该发生"的用例，同一主题下必须有"X 该发生"的那条，否则整组假绿。
这类用例在变异自检里红 1 条是**对的**（它只属于一条规则），红 0 条才是警号。

### 10.6 留在 smoke 的部分及理由

**子进程用例**（`runCase`，约 12 条 Table 相关）**不搬**：

它们的判据是"子进程以退出码 3 结束 + 日志文本里有关键词"。PHPUnit 里跑要么
`processIsolation`（每条约 0.3s，比 smoke 还慢），要么自己 `proc_open` 管进程。
更关键的是：它们测的是 `Errors` 的上报链路与挂载期校验，**与"改 Table 只跑 Table"
无关** —— 改一列排序语义不会让它们红。

⇒ smoke 里保留一小块"Table 的响亮失败"，并在注释里指明其余断言已迁到哪里
（见 `tools/smoke.php` 的"Table 排序：三条响亮失败"段）。

### 10.7 共享夹具下沉到 `tests/Support`（S3 第一批起的新结构）

S1 把 Table 的局部闭包 `$tableXxx` 提升成 `Fixtures.php`。S3 撞到**两个跨段复用**
的全局助手，沿用"下沉成单一来源"的思路，但落点不同：

- **`spinCanvas(RenderBox, w, h): RasterCanvas`** —— 控件像素断言的取色器。
  加到 `tests/Support/functions.php`（全局助手落点，与 `check`/`smokeContext` 同处）。
  smoke 仍保留**自有**一份同名全局函数（两者互不 `require`，运行时不相遇）。
- **`Semantics::element(Widget): Element`** —— 挂载 + 布局一棵树、返回已布局 Element，
  是 smoke 里 `$semLay` 闭包的同款。做成 `tests/Support/Semantics.php`
  （`Drift\Dev\Tests\Support\Semantics`，PSR-4 自动加载）。smoke 仍保留自有 `$semLay`，
  因为下方 Toggle/Switch/PageIndicator/Icon/SegmentedControl/Slider 的语义断言还在
  smoke 里，删了会崩。

★ 判据：**凡是被 ≥2 个新测试用到的局部闭包/函数，下沉成 tests/Support 单一来源；
smoke 那一份保留**（它服务的是"还没搬走的后续段"，不是历史包袱）。这样未来
6 个语义测试各写 `Semantics::element(...)` 即可，不再各抄 7 行闭包。

★ `spinCanvas` 的 `Scale` 在 `Drift\Geometry`（不是 `Drift\Rendering`）—— 又踩一次
"命名空间别按目录推"（见 §9）。已在 functions.php 写全限定名，不再依赖 `use`。

### 10.8 S3 5~17 批落地的文件账

| 批 | 文件 | 断言 | smoke 对账 |
|---|---|---|---|
| 5 分节原语 | `tests/Feature/Widget/DividerProgressTest.php` | 15（Divider/Progress 全分支） | 1535 → 1520（−15） |
| 6 图标 | `tests/Feature/Widget/IconTest.php` | 5（挂载/正方形/画墨/枚举完整性/不进语义树） | 1520 → 1515（−5） |
| 7 动画 | `tests/Unit/Widget/AnimationControllerTest.php`（15）+ `tests/Feature/Animation/AnimationChainTest.php`（8）+ `tests/Unit/App/FramePhaseTest.php`（2） | 25 | 1515 → 1492（−23） |
| 8 结构控件 | `tests/Unit/Widget/StructureControlsTest.php` | 16（建盒/挂接/端到端 flex/Row 主轴/弹性子拿剩余/Padding/Center 三态/无 build/update 换盒） | 1492 → 1476（−16） |
| 9 布局原语+Element | `tests/Unit/Layout/FlexTest.php`（19）+ `StackTest.php`（11）+ `ProxyTest.php`（5）+ `tests/Unit/Widget/ElementTreeTest.php`（10） | 45 | 1476 → 1431（−45） |
| 10 文本/列表/按钮/盒级动画/滚动条 | `tests/Unit/Widget/TextTest.php`（12）+ `EachTest.php`（5）+ `ButtonTest.php`（17）+ `ScrollBarFadeTest.php`（7）+ `tests/Unit/Layout/HoverAnimationTest.php`（6） | 47 | 1431 → 1384（−47） |
| 11 输入框 | `tests/Feature/Widget/TextFieldTest.php` | 26（声明层/盒几何与换肤/编辑序列/多字节码位/插入符几何与墨/示例页无头出图） | 1384 → 1358（−26） |
| 12 输入层 | `tests/Unit/Input/KeyModelTest.php`（11）+ `tests/Feature/Input/FocusRoutingTest.php`（21）+ `CoordinateChainTest.php`（6） | 38（按键值语义/焦点路由/帧末挂接/坐标链/IME 定位） | 1358 → 1320（−38） |
| 13 选区 | `tests/Unit/Widget/TextFieldSelectionTest.php`（23）+ `tests/Feature/Widget/TextFieldTest::testSelectionGeometryAndPaint`（5） | 28（锚点模型/Shift 只挪活动端/键入与删除吃整段/多字节吸附/选区几何与墨） | 1320 → 1292（−28） |
| 14 状态层 | `tests/Unit/State/SubjectBuilderTest.php`（8）+ `CellTest.php`（7）+ `ComputedTest.php`（7）+ `tests/Feature/State/SubscriptionIsolationTest.php`（4）+ `SettleDriftTest.php`（1） | 28（select 门控/Cell 同值不标脏/Computed 缓存与嵌套/RT-3 隔离/settle 不漂移） | 1292 → 1264（−28） |
| 15 窗口帧+主题 | `tests/Feature/App/WindowFrameTest.php`（7）+ `tests/Feature/Theme/ThemeSwitchTest.php`（4） | 11（resize 只跑一帧·不读时钟 / 零视口跳过且根盒保旧尺寸 / AppBar 窄窗裁剪 / 预览期降级 / 主题递归下发） | 1264 → 1253（−11） |
| 16 错误边界+按键路由 | `tests/Unit/Input/AcceleratorTableTest.php`（4）+ `tests/Feature/Widget/ErrorBoundaryTest.php`（13）+ `tests/Feature/Input/KeyRoutingPriorityTest.php`（5）+ `EditingCommandLoopTest.php`（9）+ `PointerSelectionTest.php`（8） | 39（加速键值语义 / 构造·build·渲染三层兜底 / 第①环优先于焦点链 / 让位规则② / 编辑门控 ②③④ / 指针框选 + IBeam） | 1253 → 1214（−39） |
| 17 命令+帧循环+路由+Offstage | `tests/Unit/Commands/CommandTest.php`（8）+ `tests/Unit/Routing/RouteTableTest.php`（4）+ `tests/Feature/App/FrameLoopTest.php`（12）+ `tests/Feature/Widget/OffstageTest.php`（8） | 32（CommandArg 双槽 / 未注册进 Diagnostic / State 冒泡 / setState 延后到帧末 / 时间戳注入 / 脏边界 / layout→paint→present 有墨 / 帧外换盒仍重排 / 隐藏不 dispose·切回立即兑现·卸载才 dispose） | 1214 → 1182（−32） |

★★ 第 17 批的**方法论发现**（值得在 S4 之前记住）：源码里 `Frame::needsSettle()` 的注释宣称
"拆掉 `boxEpoch` 判据 ⇒ 帧外直调 `Element::update()` 那条红"，**实测不成立** ——
`testOutOfFrameBoxSwapStillLaysOut` 只验**根盒** `hasSize()`，settle 跳过之后 layout 段仍会
重排，根盒当然有尺寸。要抓 `needsSettle` 的 ② 必须换成"根 = Column，帧外 `update()` 子节点，
再验祖先持有的**子盒**是新的"。

★ 结论：**注释里的变异承诺不是判据，只有变异自检跑出来的红才是。** 与本批其余五组
（拆 layout 的 boxEpoch / 未注册不上报 / 隐藏仍在 children / 切回不 settle）对照，
只有这一条是"注释说了但量具没守住"的。按 S3 纯搬迁纪律先记录、留 S4 补强。

★ 第 16 批的结构决策：**同一段的判据按"被测对象"分家，不按"段号"**。`㉝` 一段里其实装了
五个对象：`AcceleratorTable`（值对象 → Unit）、`ErrorBoundary`+`RenderErrorBoundary`（一个类
两个面 → 同一个 Feature 文件）、`Application` 的按键路由（第①环 vs 焦点链）、`TextEditingBridge`
的编辑命令环、`TextFieldState` 的指针侧。后三者共用 `AccelApplication`/`EditApplication` 夹具，
但判据互不参照（路由优先级 / 门控回落 / 手势生命周期），拆开才能一眼看出"哪一层坏了"。

★ 第 16 批同时把 `inkCount()` 下沉到 `tests/Support/functions.php` 并删掉 `TextFieldTest`
的私有副本 —— 第四个使用者出现时就必须收成单一来源，否则两份实现会各自漂移。

★ 第 16 批的坑：`FakeClipboard` 与 `EditApplication` 同类文件、PSR-4 加载不到第二个类。
smoke 里能跑是因为它先 `new EditApplication()`（顺带定义），换到测试里 `new FakeClipboard()`
先求值就 `class not found`。修法是**拆文件**（`tools/Smoke/App/FakeClipboard.php`），
不是调整调用顺序 —— 顺序依赖是定时炸弹。

★ 第 12 批的切法按"依赖强度"分三层：纯值对象与声明（`KeyChord`/`KeyEvent`/`FocusNode`
+ Widget 侧 `focusNode()`）→ Unit；需要真 Element 树与布局（`FocusManager` 路由、命中→焦点、
`Element::settle()` 的 `adoptBoxes()`）→ Feature；需要 `Application` + 光栅取墨
（坐标链、`imeCaretRect()`）→ 另一个 Feature 文件。

★ 第 12 批的坑（第二次踩"段间借变量"，这次更隐蔽）：`㉔ 输入层` 段造的树
（`$inRoot`/`$inBox`/`$inFirstState`/`$inContext`）被**几千行之后**的
"帧末重建 / skipFocus / focusOrder / claimKeys" 段借用（原 5215~5271）。
泄漏检查脚本一跑就报 4 个真泄漏 —— 正确处置不是"补回变量"，而是**把借用段一并搬走**
（它本来就是输入层判据）。判据：泄漏报告里的"使用点行号"落到哪个主题段，就把它一起收进来。


★ 第 9 批的结构决策（可扩展性的落点）：**镜像生产类布局**——`src/Layout/{RenderFlex,
RenderStack,RenderProxy}` → `tests/Unit/Layout/{FlexTest,StackTest,ProxyTest}`；
`src/Widget/Element` → `tests/Unit/Widget/ElementTreeTest`。一类一文件，找起来凭类名即可。
夹具**不复制**：`Drift\Dev\Smoke\{Layout\ConstraintSpy,SolidBox,LayoutCountBox,Tree\BranchWidget,...}`
已被 `composer.json` 的 `Drift\Dev\ → tools/` 自动加载（bootstrap 就是 `tools/autoload.php`），
测试里 `use` 即可，单一来源。只有**真缺的全局助手**（`expectBox` / `countTree`）下沉到
`tests/Support/functions.php`（带 `function_exists` 守卫，与 `check`/`smokeContext` 同风格）。

★ 第 10 批印证"一段不等于一个主题"：`⑲ Text` 之后紧挨着的 `㉑ Each` / `㉒ Button` /
盒级 hover / 滚动条淡出 / `IconButton` 是**五个不同被测对象**，按类切五个文件。
`ButtonTest` 收编 `NavButton` 与 `IconButton`（三者是同一"按钮族"的三条设计线，判据互相
参照：居中 vs 左对齐 vs 正方形；拆开要重复夹具）。`HoverAnimationTest` 放 `Unit/Layout/`
因为被测的是 `RenderObject::hoverProgress`（时间由 `tickAnimations(ms)` 手动推进，
不需要 `Application::frame()`）。

★ 第 10 批的坑：`tests/Support/functions.php` **没有 namespace**（`check`/`smokeContext`/
`spinCanvas` 都在全局）。测试类里写 `use function Drift\Dev\Tests\Support\spinCanvas;`
会 fatal（`Call to undefined function`）。**不要写 `use function`**——PHP 在命名空间内找不到
函数会自动回退全局，直接调 `spinCanvas(...)` 即可。

★ 第 10 批的隐式变量耦合：`⑲ Text` 段定义的 `$darkContext` 被后面 `㉓ TextField` 段
（`$darkField->mount(null, $darkContext)`）**跨段借用**。删段后该变量悬空 ⇒ 必须在
TextField 段头补回一行同名构造。这类"段间借变量"grep 单个变量名查不出来（会撞见后段
自己的同名重新赋值），要用脚本对比"待删区定义集 × 删除点之后的首次使用是否早于重新赋值"。

★ 第 9 批的坑：独立的 `Overflow::Clip` 断言**夹在子进程失败路径块中间**（`flexreport` 与
`shrinkoverflow` 两条 `runCase` 之间），删段时不能整块删（会连带删掉子进程判据），要
**只摘出那 10 行**（注释 + 4 行构造 + check）。子进程判据（C5 Report / shrink 溢出 /
flex scroll / flex 值 / C6，10 条）按规则**留 smoke**：`runCase(__FILE__, …)` 依赖
`__FILE__` 是 smoke 入口，搬进 tests/ 需显式改指 `tools/smoke.php`，且它们测的是
`Errors` 上报链路而非布局本身。

★ 动画批再次印证"按关注点切文件"：同一 `㊹` 段里，纯算术（Curve/Controller）
不依赖帧驱动 → 放 Unit 层；"帧真的重建了"必须用真实跑帧夹具应用验 → 放 Feature 层。
这不是过度拆分，而是让"纯逻辑失败"和"链断了"在报告里一眼分开。

★ 删 `㊹` 段时，帧阶段顺序那条依赖 `getcwd().'/build/frame.trace.out'`
落盘 + `Errors::recentDiagnostics()` 环形缓冲。断言用**前后条数差**而非 `Errors::reset()`，
避免清掉同进程里别的用例依赖的 `$fatalBox`（与 smoke 同款理由）。在 PHPUnit 全量跑时
cwd 是项目根，落盘路径与 smoke 一致，✅ 通过。
