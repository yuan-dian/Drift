# 最终方案 · UI 数据订阅与表格契约（可实施版）· 2026-11-12

> ## ⛔ 已否决（2026-11-12 当日）
>
> **裁决：放弃「构建期生成订阅代码」这条主线。** 否决理由只有一条，但足以致命：
>
> > 在写的时候，还没有生成，`use` 就直接错误了。
>
> 即：页面上那行 `use \App\Users\Generated\StatusBarView;` 指向的 trait 必须**先跑一次
> 生成器**才存在，因此在「刚写完、还没跑 codegen」的时间段里，`php -l` / lint / IDE
> 全部报错。这不是工具链没配好，是 PHP 的硬限制 —— trait 只能在类定义处静态 `use`，
> 没有编译单元接管，也没有运行期注入（`class_alias` / `__call` 都不行）。
>
> 对照 Svelte / WinUI `x:Bind`：它们能「源码零感知」，前提是编译器**接管整个编译单元**，
> 生成物不进入开发者要写的那个文件。PHP 没有这个前提 ⇒ 本仓做不出同款 DX。
> 硬做的结果是：把「漏写订阅」的静默问题，换成「每次编辑都红」的阻塞问题，**更差**。
>
> **本文以下内容保留为判据与证据，不再作为开工依据。** 与生成层解耦、依然成立的
> 七条发现，见 §0.0「存活清单」（S1–S7）。
> 推演见 `docs/ANALYSIS-UI-DATA-ALTERNATIVES-2026-11-12.md`；
> **最终评估（整包未采纳，拆档裁决）见 `docs/PLAN-UI-DATA-RECOMMENDED-2026-11-12.md` 顶部**。

> 状态：~~最终方案~~ **已否决**，未落地任何代码。基线 HEAD `5f26dfc`。
> 本文曾取代 `DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` §11（v2.1）；
> 否决后，v2.1 与本稿的「生成层」部分一并作废，前几份文档降级为证据链。
> 代码块标「现状」的逐字取自仓库；其余为设计草案，**未编译**。

---

## 0.0 存活清单（与生成层解耦，仍然成立）

被否决的是「构建期生成」这条**实现路径**，不是下面这几条**事实**。它们全部有实测支撑，
与最终选哪条路无关：

| # | 发现 | 为什么与方案无关 |
|---|---|---|
| S1 | **`SourceQuery::readBody()` 不收构造器属性提升** ⇒ `dead-subscription` 对现代写法 0 条（最小仓库对照：提升 0 条 / 传统 1 条）。全仓 570 类中 181 个用提升、124 个属性完全不在表里 | 这是 `tools/Lint` 的**真缺陷**，任何路线都要修 |
| S2 | 真实订阅面是 **7 个文件 23 处**（watermark 三张 Card 15、`UsersPage` 4、`SignupPage` 3、两个 `StatusBar` 各 1），showcase 30+ 页面零订阅 | 决定任何方案的迁移量 |
| S3 | `select` 57 处里 apps 25 中有 2 处不是订阅入口（`ShowcaseRoot::$this->select()`、`UsersRoot::PageHost::select`），真实面 23 | 同上 |
| S4 | **不用推导读路径**：`deps` 只需把 `select` 表达式原样搬（换掉闭包参数），唯一盲区 `watermark/StatusBar::slice()` 因此消失 | 说明「语义推导」不是必需能力 |
| S5 | **不能引入 php-parser**：`vendor/` 在 `.gitignore:64`，`tools/autoload.php` 不消费 composer vendor ⇒ T0 门会 `Class not found` | token 级是硬约束 |
| S6 | 门控若做容器比较，必须**逐元素** `sameValue`（deps 元素可能是 `AsyncValue` / `StatusMessage`，数组 `===` 会退成身份比较） | 与生成层无关的独立改进 |
| S7 | 5 个待迁移文件的 `build()` 顶层**零副作用**（唯一 `reset()` 在 `onPressed` 回调里） | 迁移安全性前提 |

---

## 0. 一句话结论（原，已被 ⛔ 段否决）

**方向成立，但 v2.1 的迁移单位选错了。** 它按「一个页面一个 `BindingHost`」设计，
实际订阅点分布在 **7 个文件、23 处**，其中 15 处在 watermark 的三张 Card 里，
showcase 的 30+ 个页面**一个都不订阅**。按页面迁移会产出 30+ 个空 Binding，
并把本来「只重建一张 Card」退化成「重建整页」。

换成「**一个订阅宿主类一个 `ViewHost`**」后，粒度退化被限制在 widget 级；
再叠一条本轮新发现 —— **`deps()` 不需要推导读路径，只需原样搬运 `select` 表达式** ——
G0 的判死口从「能否做语义推导」降级为「能否做 token 区间搬运」，工期与风险同时下降一个量级。

三条裁决不变：**普通读 / 构建期生成订阅 / 框架内部有数据平面**。

另有一条与方案无关但必须排在它前面的现存缺陷：**`dead-subscription` 对构造器属性提升
是假绿的**（§1.7 实测 0 条）。它是坑 #4 的唯一防线，不修则本方案新加的 `dep-missing`
会一起空转 —— 所以它是 G-1，在 G0 之前。

---

## 1. 可实施性复核：v2.1 落不了的七处

本节全部是本轮实测，行号可复现。这是本次 review 的实际产出。

### 1.1 迁移单位错了：订阅点是 widget，不是页面

实测 `SubjectBuilder::make` 的分布（`grep -rn "SubjectBuilder::make" --include=*.php`，全仓 42 处）：

| 位置 | 处数 | 形态 |
|---|---:|---|
| `apps/watermark/src/Widgets/UploadCard.php` | 5 | `StatelessWidget`，构造 `(WatermarkStore, \Closure)` |
| `apps/watermark/src/Widgets/TaskCard.php` | 5 | `StatelessWidget`，构造 `(WatermarkStore)` |
| `apps/watermark/src/Widgets/ResultCard.php` | 4 | `StatelessWidget`，构造 `(WatermarkStore)` |
| `apps/users/src/Pages/UsersPage.php` | 4 | `StatelessWidget`，构造 `(UserStore)` |
| `apps/users/src/Pages/SignupPage.php` | 3 | `StatelessWidget`，构造 `(SignupForm, ?callable)` |
| `apps/watermark/src/Widgets/StatusBar.php` | 1 | **不是 widget 类**：静态工厂 `make(): Widget` |
| `apps/users/src/Widgets/StatusBar.php` | 1 | 同上 |
| **apps 合计** | **23** | |
| `src/` | 0 真实调用 | 3 处全是文档注释 |
| `tests/` | 16 | `SubjectBuilderTest` / `MessageValueGateTest` |

⇒ 两个直接后果：

1. **showcase 的 30+ 页面零订阅**。`TablePage` 是 `StatefulWidget`（`apps/showcase/src/Pages/TablePage.php:59`），
   行数据来自自己的 `TablePageState::$rows`，与 `Store` 无关。v2.1 的「所有 `build()` 默认被扫描」
   会为它们生成 30+ 个 `deps(): []` 的空壳。
2. **订阅宿主的生命周期不同**。`StatelessWidget` 的 `build()` 只在造树时跑一次；
   `State` 的 `build()` 每次都跑。宿主形状必须两者都能容纳，而 v2.1 的
   `UsersPageBinding extends BindingHost` 只覆盖前者。

### 1.2 `select` 的 57 处口径仍不准

按 `->select(` 统计的是 57，但 apps 的 25 里有两处不是订阅入口：

- `apps/showcase/src/ShowcaseRoot.php:1` —— `$this->select($index)`，是导航索引的 setter；
- `apps/users/src/UsersRoot.php:1` —— `PageHost::make()->source($app)->select(...)`，
  是 `PageHost` 自己的同名 API（`PageHost` 已经是订阅宿主，不在本方案范围内）。

真实订阅入口 = **23**。破坏面表必须以 `SubjectBuilder::make` 为准，不能以 `->select(` 为准。

### 1.3 不需要读路径语义推导 —— `deps()` 可以原样搬表达式

v2.1 的 G0 判死口是「对真实 `build()` 复现读集」，隐含要求从 `$s->page()->isRunning()`
反推「读的是 `page` 的 `isRunning`」。**这一步根本不需要做。**

`select` 的实参是一个**求值即得切片值**的表达式。把它原样搬进 `deps()`，只替换闭包参数，
得到的就是同一个值、同一个比较语义：

```php
// 现状（apps/watermark/src/Widgets/StatusBar.php:45）
->select(static fn (Subject $s): StatusMessage => self::slice($s))

// 生成的 deps（原样搬运，`self::` 在 trait 里解析为使用它的类）
public function driftDeps(): array
{
    $s = $this->store;
    return [self::slice($s)];
}
```

⇒ 三个收益：

1. **唯一真盲区消失**。评审稿反复提到的 `watermark/StatusBar::slice()` 跨方法读点，
   在「搬表达式」口径下不是盲区 —— 表达式整体进 deps 即可。
   上一轮统计的 `36/37`（闭包内读点可提取率）因此**不再是本方案的可行性判据**。
2. **不需要类型推导**。`ClassTable` 没有方法签名表这个问题（评审稿 B6）不影响 dep 提取。
3. **语义等价可对账**：deps 元素数必须等于原 `select` 调用数，一一对应，机械可验。

### 1.4 门控比较必须逐元素 `sameValue`，不能退成数组 `===`

v2.1 花大篇幅论证「PHP 数组 `===` 是值比较」，结论对，但用错了地方：
`deps()` 返回的数组**元素本身可能是对象**。

实际样本：

| 位置 | 切片值类型 |
|---|---|
| `UsersPage` 第 3 处 | `AsyncValue`（对象） |
| `users/StatusBar` | `string` |
| `watermark/StatusBar` | `StatusMessage`（对象） |
| `TaskCard` 末处 | `StatusMessage`（对象） |

现状 `SubjectBuilderState::sameValue()`（`src/State/SubjectBuilder.php:321-328`）
的处理是：**两边都 `Equatable` 才按值比，否则 `===`**。如果 `ViewHost` 用数组 `===`
比较 deps，对象元素立刻退成身份比较 —— `StatusMessage` 若每次新建，整张 Card 每帧重建。

⇒ 门控必须是 `count` 相等 + 逐元素 `sameValue`。这与评审稿 A2 的方向相反：
不是「让行变 `Equatable`」，而是**门控对容器逐元素比**。

### 1.5 引入 AST 解析器会让 T0 门在新 clone 上崩

`vendor/` 在 `.gitignore:64`，`tools/autoload.php` **不消费 composer 的 vendor**，
而是自己读 `composer.json` 的 `autoload.psr-4`。`nikic/php-parser v5.9.0` 确实在
`vendor/` 里，但它是 `phpunit/php-code-coverage` 的传递依赖（`composer.lock` 的
`packages-dev`），不是本仓的显式依赖。

⇒ 任何 T0 门（`gate` 里每次提交都跑的那批）**不能依赖 php-parser**：
fresh clone 上 `composer install` 没跑，门就 `Class not found`，而那是「环境的错扮成判据的错」，
本仓在 `gate` 退出码上已经踩过一次。

⇒ `DepExtractor` 必须走**纯 token 级**，复用 `SourceFile` / `SourceQuery` / `ClassTable`。
好消息：这三个零件够用（§4.5）。

### 1.6 `ViewBinding` / `BindingHost` 对 `StatefulWidget` 不成立

`TablePage`（`StatefulWidget`）的 `build()` 在 `TablePageState` 里；`BindingHost` 包在
widget 外层时，`build()` 的调用方是 `TablePageState`，不是 `BindingHost`。
包装类无法替它接管 deps。

本文的解法（§2.2）：宿主是 `extends ViewHost` 的类本身，`StatefulWidget` 与
`StatelessWidget` 都能由 `ViewHost` 提供 `State`，因此**两者统一**。

### 1.7 `properties` 不收构造器属性提升 —— 本方案的核心判据会假绿

`SourceQuery::classes()` 返回的 `properties` 只在**类体顶层**遇到 `T_PUBLIC/T_PROTECTED/T_PRIVATE`
时收集（`tools/Lint/SourceQuery.php:655-664`），构造器参数里的属性提升
（`__construct(private readonly UserStore $store)`）**一个都不收**。

实测（`Project::scan` + `SourceQuery::classes`）：

| 类 | `properties` 实际值 | 真的属性 |
|---|---|---|
| `App\Users\Pages\UsersPage` | `[]` | `store: UserStore`（提升） |
| `App\Users\Pages\SignupPage` | `[]` | `form` / `submit`（提升） |
| `App\Watermark\Widgets\UploadCard` | `[]` | `store` / `pickFiles`（提升） |

全仓 `apps` + `src` 共 570 个类：`properties` 非空 223 个，用构造器提升的 181 个，
其中 **124 个类的属性完全不在表里**。

**后果不是理论风险，是判据假绿**。构造两个只差属性写法的最小仓库跑
`DeadSubscription::check()`：

```php
// 提升写法：build() 里读 $this->store->n()，类不 implements SubscribingWidget
public function __construct(private readonly S $store) { parent::__construct(); }
// ⇒ diagnostics = 0    ← 假绿

// 传统写法：同样的读点
private S $store;
// ⇒ diagnostics = 1    「死订阅：build() 里读了 $this->store…重建会静默丢订阅」
```

⇒ 两条处置，都必须做：

1. **`dead-subscription` 现在对提升写法是空转** —— 而它正是坑 #4（可用态漏刷新）的
   唯一防线，也就是 `UsersPage` 2026-10-31 那次事故的防线。这是独立缺陷，与本文方案无关，
   应单独修：`readBody()` 增收 `__construct` 的参数列表（`SourceQuery.php` 内，纯 token 级）。
2. **本方案的 `dep-missing`（§6）不能复用 `properties`**，必须走修好后的属性表，
   否则它和 `dead-subscription` 一起假绿 —— 那等于整个方案没有防线。

这一步排在 G0 之前（§9 的 G-1），因为它同时解锁两条判据。


---

## 2. 最终架构

### 2.1 分层

```text
                     ┌─ drift-codegen（构建期）──────────────┐
                     │ 扫 extends ViewHost 的类              │
   AST-less token 分析 │ 抽 deps 表达式 → 生成 *View trait     │
                     └───────────────┬───────────────────────┘
                                     │ 静态 PHP（进 git，参与 lint/tpc）
                                     ▼
Store (Subject) ──notifyChanged()──► ViewHostState ──逐元素门控──► ViewHost::build()
      ▲                                    │                        │
      │                              watchSlot('deps')              │
      └──── AsyncCommand::run() ◄── Button::command('name') ◄───────┘
```

三层职责：

| 层 | 谁写 | 谁看 | 内容 |
|---|---|---|---|
| 书写形状 | 开发者 | 人 + lint | `build()` 里普通读，无订阅样板 |
| 数据平面 | `drift-codegen` 生成 | lint / tpc / 人 | `driftDeps()`：切片值清单 |
| 订阅机制 | 框架 `ViewHostState` | 无（内部） | 持有 `Subscription`、逐元素门控、`markDirty()` |

### 2.2 框架侧唯一新零件：`ViewHost`

```php
// src/State/ViewHost.php —— 设计草案
namespace Drift\State;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * 订阅宿主：`build()` 里普通读，订阅由生成的 `driftDeps()` 接上。
 *
 * ★ 为什么不是把订阅放进 `StatelessElement`：那要改所有控件共用的重建路径。
 *   这里只新增一个 StatefulWidget 子类，`Element` 一行不动。
 * ★ `build()` 会被多次调用（每次 deps 变化），这与 `StatelessWidget` 的
 *   「只在造树时跑一次」不同 —— 后者正是 `UsersPage` 按钮永久灰的根因。
 */
abstract class ViewHost extends StatefulWidget implements SubscribingWidget
{
    abstract public function build(BuildContext $ctx): Widget;

    /** @internal 被订阅的 Store；生成的 trait 覆盖。`null` ⇒ 本控件不订阅 */
    public function driftSource(): ?Subject
    {
        return null;
    }

    /**
     * 切片值清单。**上界**：多订阅、不漏订阅。
     * 每个元素 = 一个原 `select` 表达式的求值结果。
     *
     * @internal 生成的 trait 覆盖
     * @return list<mixed>
     */
    public function driftDeps(): array
    {
        return [];
    }

    public function createState(): State
    {
        return new ViewHostState();
    }
}
```

```php
// src/State/ViewHostState.php —— 设计草案
namespace Drift\State;

use Drift\Widget\BuildContext;
use Drift\Widget\SizedBox;
use Drift\Widget\State;
use Drift\Widget\Widget;

final class ViewHostState extends State
{
    /** @var list<mixed> 上次的值；`driftDeps()` 的返回恒为 list */
    private array $last = [];

    private ?Subject $bound = null;

    public function initState(): void
    {
        $widget = $this->host();
        if ($widget === null) {
            return;
        }

        $this->bind($widget);
        $this->last = $widget->driftDeps();
    }

    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        $host = $this->host();
        if ($host === null) {
            return;
        }

        $this->bind($host);
        // 父换了描述 ⇒ 基线跟着换，否则下一次 notify 会拿旧基线比出假变化。
        $this->last = $host->driftDeps();
    }

    /** 绑/重绑：同名槽先退旧再挂新，不随 update 累加 */
    private function bind(ViewHost $host): void
    {
        $source = $host->driftSource();
        if ($source === null) {
            return;
        }
        if ($source === $this->bound) {
            return; // 每次 update 都重绑会让订阅 id 漂移
        }

        $this->bound = $source;
        $this->watchSlot('deps', $source, function (): void {
            $this->onChanged();
        });
    }

    private function onChanged(): void
    {
        $host = $this->host();
        if ($host === null) {
            return;
        }

        $now = $host->driftDeps();
        if (self::sameList($now, $this->last)) {
            return;
        }

        $this->last = $now;
        $this->markDirty();
    }

    /** 逐元素比：对象元素走 `Equatable`，其余 `===`（与 SubjectBuilder 同一口径） */
    private static function sameList(array $a, array $b): bool
    {
        $n = \count($a);
        if ($n !== \count($b)) {
            return false;
        }

        for ($i = 0; $i < $n; $i++) {
            if (!ValueEquality::same($a[$i], $b[$i])) {
                return false;
            }
        }

        return true;
    }

    private function host(): ?ViewHost
    {
        $widget = $this->widget();
        if (!$widget instanceof ViewHost) {
            return null;
        }

        return $widget;
    }

    public function build(BuildContext $ctx): Widget
    {
        $host = $this->host();
        if ($host === null) {
            // 三目两支不同型（`SizedBox` vs `Widget`）在 AOT 下是 C2678，拆开写。
            return SizedBox::make()->size(0.0, 0.0);
        }

        return $host->build($ctx);
    }
}
```

`ValueEquality::same()` 是把 `SubjectBuilderState::sameValue()`（`src/State/SubjectBuilder.php:321`）
提出来共享，`SubjectBuilderState` 改成调用它 —— 门控语义只有一份，不新增第二条口径。

### 2.3 生成物：一个 trait，不是一个包装类

```php
// apps/users/src/Generated/UsersPageView.php —— drift-codegen 生成，勿手改
namespace App\Users\Generated;   // 按 app 根算，不按被生成类的子目录算

/**
 * 事实源：UsersPage::build() 的读路径（构建期扫描）。
 * 每条 = 一个原 SubjectBuilder::select() 的切片表达式，原样搬运。
 */
trait UsersPageView
{
    public function driftSource(): ?\Drift\State\Subject
    {
        return $this->store;
    }

    public function driftDeps(): array
    {
        $s = $this->store;

        return [
            $s->query(),
            $s->page()->isRunning(),
            $s->page()->value(),
            $s->visible(),
        ];
    }
}
```

两个格式约束，写死不给生成器自由度：

- **返回类型与基类同形**（`?\Drift\State\Subject` / `array`）：协变返回在 tpc 下未验，
  不赌。
- **deps 必须是 list**（键 `0..n-1`）：`ViewHostState::sameList()` 按整数下标取值。
  生成器用 `array_values` 语义产出，不做关联键。


生成物是 **trait 而不是包装类**，理由三条：

| | trait（本文） | 包装类（v2.1 的 `BindingHost`） |
|---|---|---|
| 拿构造参数 | 直接 `${this}->store`，**不用读构造签名** | 要枚举页面的全部构造参数（`UploadCard` 有两个，含 `\Closure`） |
| `StatefulWidget` 页面 | 同一个人口 | 需另写一套 State 版 |
| `self::` 调用 | 解析为使用它的类，`self::slice($s)` 照原样搬 | 解析为包装类 ⇒ 断链 |
| 创建点 | **零改动** | 每个 `routes()` 的 `Page::make()` 要换名 |

代价：页面类自身要多写一行 `use`，且 `extends` 从 `StatelessWidget` 改成 `ViewHost`。

**这一行 `use` 删不掉，是 PHP 语言限制**：trait 必须在类定义处静态 `use`，没有运行期注入。
`class_alias` / `__call` 都不行 —— `ViewHost` 已有 `driftDeps()` 默认实现，不会走 `__call`。
因此它是**一次性手写、之后不再碰**的一行（全仓 7 个类，G3 的迁移脚本负责加）。

★ **漏写这一行是静默事故**：`ViewHost::driftSource()` 默认返 `null` ⇒ 不绑定 ⇒ 整页不刷新，
而 `dep-missing`（判据写的是「类不 `extends ViewHost`」）此时是**绿的**。这是第二个假绿，
判据见 §6 的 `vh-unbound`。

### 2.4 一个完整迁移后的文件（`apps/users/src/Widgets/StatusBar.php`）

```php
// 现状：不是 widget 类，是静态工厂返回 SubjectBuilder
final class StatusBar
{
    public static function make(AppStore $store): Widget
    {
        return SubjectBuilder::make($store)
            ->select(static fn (Subject $s): string => $s instanceof AppStore ? $s->statusLine() : '')
            ->builder(static fn (string $text): Widget => Row::make()->children([
                Text::make($text)->caption(),
                Spacer::make(),
            ]));
    }
}
```

```php
// 迁移后（设计草案）：类本身成为宿主，工厂只做一次 new
use App\Users\Generated\StatusBarView;
use Drift\State\ViewHost;

final class StatusBar extends ViewHost
{
    use StatusBarView;   // ← 生成物引入，唯一手写的新增行

    public function __construct(private readonly AppStore $store)
    {
        parent::__construct();
    }

    public static function make(AppStore $store): Widget
    {
        return new self($store);   // 调用点签名不变
    }

    public function build(BuildContext $ctx): Widget
    {
        $text = $this->store->statusLine();   // 普通读

        return Row::make()->children([
            Text::make($text)->caption(),
            Spacer::make(),
        ]);
    }
}
```

```php
// 生成物：原 select 表达式原样搬，`instanceof` 分支保留（冗余但语义等价）
namespace App\Users\Generated;

trait StatusBarView
{
    public function driftSource(): ?\Drift\State\Subject
    {
        return $this->store;
    }

    public function driftDeps(): array
    {
        $s = $this->store;

        return [$s instanceof \App\Users\Store\AppStore ? $s->statusLine() : ''];
    }
}
```

★ `instanceof` 那支是历史遗留（`select` 的形参声明成 `Subject`）。生成器不做语义简化，
原样搬；迁移时人工可以删（删了 lint 也过），但**生成器不允许自作主张**。

---

## 3. 开发者怎么写（DX）

### 3.1 三个角色各自要写什么

| 角色 | 迁移前 | 迁移后 |
|---|---|---|
| 写业务页面的开发者 | `SubjectBuilder::make($store)->select(fn)->builder(fn)` 三层嵌套，4 个切片 = 4 段样板 | `extends ViewHost` + `build()` 里普通读 |
| 写新页面的开发者 | 每读一个会变的值就欠一条订阅，漏了不报错 | 同上；漏了由 `dep-missing` 判据报 |
| 框架维护者 | — | 跑 `drift-codegen`，看生成物 diff |

### 3.2 迁移前后逐行对照（`UsersPage` 的搜索框）

现状（`apps/users/src/Pages/UsersPage.php:71-79`，逐字）：

```php
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): string => $s->query())
    ->builder(fn(string $q): Widget => TextField::make()
        ->value($q)
        ->label('搜索')
        ->placeholder('输入姓名')
        ->onChanged(fn(string $x) => $this->store->setQuery($x))),
```

迁移后：

```php
TextField::make()
    ->value($this->store->query())
    ->label('搜索')
    ->placeholder('输入姓名')
    ->onChanged(fn(string $x) => $this->store->setQuery($x)),
```

净变化：**−3 行样板，+0 行新概念**。四段样板变四块普通控件。

### 3.3 `build()` 里"读"的规则（写进 tutorial）

一条就够：

> **会变的值，在 `build()` 里读。** 不要在 `build()` 里存到字段，不要在回调里读。

对应的机械判据见 §6。

### 3.4 写错了会怎样

| 写错 | 反馈 | 层级 |
|---|---|---|
| `extends StatelessWidget` 但读 Store | `dep-missing`：`build() 读了 $this->store->query()，但类没 extends ViewHost ⇒ 这是浇筑时快照，之后永不更新` | lint |
| `extends ViewHost` 但 `driftSource()` 返回的 Store 里没有该读点 | 生成期：`deps 元素数 3 ≠ build() 读点 5` | codegen |
| 读点写在私有辅助方法里 | 生成期：`读点跨函数（self::helpers()），静态不可提取`；修法：搬进 build()，或 `@deps` 显式声明 | codegen |
| 读点写在 `onPressed` 回调里 | 同上（闭包体不扫描，与 Compose 坑 #6 同一条边界） | codegen |
| `field('nmae')` | `dead-field`（§7） | lint |
| 生成物手改了 | `build-stale-check` 第 10 条：重新生成有 diff | gate |

### 3.5 生成物可读，这是 Compose metrics 的替代

```bash
php tools/drift-codegen.php --emit-deps apps/users/src/Pages/UsersPage.php
# UsersPage → apps/users/src/Generated/UsersPageView.php
#   driftSource: $this->store  (UserStore)
#   deps[0]: $s->query()                  → string
#   deps[1]: $s->page()->isRunning()      → bool
#   deps[2]: $s->page()->value()          → AsyncValue (Equatable: yes)
#   deps[3]: $s->visible()                → array (O(n) compare)
```

`--emit-deps` 回答「这个页面到底订阅了什么」，不依赖运行期日志。
**Compose 靠 compiler metrics，Svelte 靠编译产物，本仓靠打开这个文件。**

### 3.6 迁移一个文件时的三步检查

`StatelessWidget::build()` 只跑一次，`ViewHost::build()` 每次 deps 变都跑
—— **`build()` 顶层的代码从「一次」变成「多次」**。这是迁移时唯一需要人看的语义变化。

三步：

1. **`build()` 顶层有没有副作用**（写 Store、调 `run()` / `submit()` / `reset()`）：

   ```bash
   awk '/public function build/,0' apps/watermark/src/Widgets/UploadCard.php \
     | grep -nE '\->(set|run|submit|reset|clear|add|remove|load|validate|toggle|bump)\('
   ```

   命中在 `onPressed` / `onChanged` 这类**回调闭包内**是正常的（不在 build 执行路径）；
   命中在顶层则必须先搬走。

   ★ 本轮已对 5 个有 `build()` 顶层的待迁移文件跑过（两个 `StatusBar` 是静态工厂，不适用）：
   **顶层零命中**，唯一的 `reset()` 在 `SignupPage` 的 `onPressed` 里。
   ⇒ 23 处的迁移面里没有这一类障碍。

2. **`deps` 与旧 `select` 一一对应**：数量相等、顺序按出现顺序、每条的表达式逐字可比。
   人工核对一次，之后由生成物 diff 守。

3. **`SubjectBuilder` 的 `builder` 闭包参数有没有被复用到 `build()` 顶层之外**
   （例如存进字段）。有则报出来，不在本轮自动化范围内。

第 1 步不做成 lint：静态判「方法调用是不是副作用」需要类型推导，成本远超收益。
运行时已有兜底 —— `State::markDirty()` 的 `E-FLUSH-CYCLE` 会在 build 期写自己的状态时
直接 `Errors::report`（`src/Widget/State.php:103-112`）。

---

## 4. 实现：`DepExtractor`（token 级，可实施）

### 4.1 落点

新建 `tools/Lint/DepExtractor.php`（`Drift\Dev\Lint\DepExtractor`），被
`drift-codegen` 的生成步骤调用，不单独进门。理由：它和生成是同一件事的两半，
分开会让「提取了但没生成」这种中间态存在。

### 4.2 输入与定位

```php
$project = Project::scan($root, [], ['src', 'apps']);   // 已有零件
$table   = ClassTable::fromProject($project);           // 已有零件

foreach ($table->all() as $fqn => $info) {
    if (!$table->descendsFrom($fqn, 'Drift\State\ViewHost')) {
        continue;                          // 只处理 extends ViewHost 的类
    }
    $file  = $project->fileFor($fqn);      // 需新增：FQN → SourceFile
    $build = $info['methods']['build'] ?? null;   // SourceQuery::classes() 已给区间
    ...
}
```

`SourceQuery::classes()` 返回的 `$info` 里，`methods`（`{start, end, line}`）够用；
**`properties` 不能用**（§1.7：不收构造器提升，`UsersPage` / `UploadCard` 实测为 `[]`）。

⇒ 新增一个零件 `CtorParams::of(SourceFile $file, array $classInfo): array<string, string>`
（属性名 ⇒ 类型 FQN），在 G-1 里与 `readBody()` 的属性表一起实现、一起测。

回答「`$this->store` 的类型是不是 `Subject` 子类」：

```php
$type = $ctorParams['store'] ?? null;          // UserStore
$isSubject = $type !== null
    && ($type === 'Drift\State\Subject' || $table->descendsFrom($type, 'Drift\State\Subject'));
```


### 4.3 提取规则：两种模式

**模式 A（G1，机械搬运）** —— 源是旧的 `SubjectBuilder` 链：

1. 在 `build()` 区间内找 `T_STRING('SubjectBuilder')` + `T_DOUBLE_COLON` + `T_STRING('make')`；
2. 取其 `(` 的配对 `)` 内首个实参 → 即 source 表达式（`$this->store`）；
3. 顺链找 `->select(`，取 `(` 配对 `)` 内的闭包；
4. 用 `SelectPurity::isClosure()`（`tools/Lint/Rules/SelectPurity.php:302`）确认是闭包；
5. `SourceQuery::matchBracket()` 定位闭包体的 `{`…`}`（箭头函数则到 `,` 或 `)`）；
6. 取闭包体表达式，把形参（`$s`）整体替换为 `$this-><prop>`；
7. 产出 `deps[i] ?= 替换后的表达式`。

这一步**不做语义理解**，只做「区间 + 标识符替换」。替换必须按 token 边界做（不能字符串替换，
否则 `$s` 会命中 `$store` 的前缀）。

**模式 B（G2，上界扫描）** —— 源是迁移后的 `build()`：

1. 在 `build()` 区间内找所有 `T_OBJECT_OPERATOR` 前接 `T_VARIABLE('$this')` + 属性名在
   `CtorParams` 里且类型是 `Subject` 子类的（**不是** `properties`，§1.7）；
2. 从该属性名往后读**链式后缀**（`->name` 与 `->name(args)` 交替），遇到不在链上的 token 停；
3. 向上找**表达式边界**：若整条链是某个调用的实参（`f(self::slice($this->store))`），
   扩到该实参边界（`SourceQuery::matchOpenBracket()`）；
4. 若边界落在闭包体内（`fn`/`function` 的 `{`…`}` 之间）⇒ **不提取，报跨函数**；
5. 产出 `deps[i] = 该表达式`。

**两条模式都只产「表达式文本」**，不建模类型、不推断返回值。这是本方案与
`x:Bind` 级类型推导的分界线：**WinUI 需要类型是因为它要生成强类型字段绑定；
本仓只需要一个能求值并比较的表达式列表。**

### 4.4 产出与报错

```php
final class DepContract
{
    /** @var array<string, array{source: string, deps: list<array{expr: string, line: int}>}> */
    public array $byClass;
}
```

三条硬报错（都带中文修法）：

| 触发 | 文案 |
|---|---|
| `extends ViewHost` 但 `build()` 里没有 `$this-><prop>` 读点 | `build() 没有读任何 Store：要么删掉 extends ViewHost，要么把读点搬进 build()` |
| 读点落在闭包 / 私有方法体内 | `读点跨函数（{name}），静态不可提取；搬进 build() 顶层，或在类上写 @deps {expr}` |
| `deps` 元素比上次生成的少 | `deps 少了 N 条（上个版本 4 / 现在 3）：build() 里有读点没进 deps` |

第三条的「上次」来自**进 git 的生成物**：diff 就是基线，不需要另存一份。
`--emit-deps` 只负责把清单打给人看，不承担基线职责。

### 4.5 零件复用表

| 需要 | 现成零件 | 位置 |
|---|---|---|
| 类表 + 层级查询 | `ClassTable::fromProject / descendsFrom` | `tools/Lint/ClassTable.php` |
| 类体 token 区间、方法区间 | `SourceQuery::classes()` | `tools/Lint/SourceQuery.php:383` |
| 属性类型（**不含构造器提升**，需 G-1 补） | `SourceQuery::classes()['properties']` | 同上 `:655` |
| 括号配对 | `SourceQuery::matchBracket / matchOpenBracket` | 同上 `:283` / `:324` |
| 闭包判定 | `SelectPurity::isClosure()` | `tools/Lint/Rules/SelectPurity.php:302` |
| `build()` 体内 `$this->prop->m()` 识别 | `DeadSubscription::subjectCalls()` | `tools/Lint/Rules/DeadSubscription.php:107` |
| 方法调用区间 | `SourceQuery::methodCalls()` | `tools/Lint/SourceQuery.php:245` |

⇒ **`DepExtractor` 是这些零件的编排，不是新写一套 token 分析**。这是 G0 工期可控的依据。

---

## 5. 生成层接线（与 `gate` 的三条硬约束）

`drift-codegen.php` 现在是**翻译门**（434 行），`collectSources()` 只枚举 `src/` + `apps/*/src`，
`writeProjectYml()` 只写 `project.yml`。新增生成通道必须落在它内部：

```php
// tools/drift-codegen.php，main 流程里 collectSources() 之前
emitViewTraits($root);            // ← 新增：先写生成物
$sources = collectSources($root);  // ← 原样：再枚举
```

三条硬约束，缺一条门就会红：

1. **生成在 `collectSources()` 之前、同一次进程内**。那道门要求
   `$prepared === count($expected)` 双向严格相等（`tools/drift-codegen.php:389-399`）；
   生成物落在 `apps/*/src/Generated/` 会被枚举到，先生成再枚举才不会两侧漂移。
2. **生成物不能落 `_codegen/`**：`removeTree($buildDir, $work)` 每次删。
3. **生成物进 git**，并新增 `build-stale-check` 第 10 条判据：
   `emitViewTraits()` 跑一遍 ⇒ 与磁盘上的生成物逐字节相同。不进 git 则 fresh clone 的
   T0 档（`php -l` / lint）因缺 trait 而红，而 T0 是每次提交都跑的。

**幂等**：生成前先删本脚本产出的旧 trait（文件名带固定后缀 `*View.php` + 首行标记
`// @generated by drift-codegen`），不删用户文件。目录 `Generated/` 只允许含生成物，
由一条 lint 守（发现无标记文件 ⇒ 报错）。

### 5.1 生成物怎么来（开发者视角）

生成物**不是手写的，也不需要开发者维护内容**。完整链路：

```text
页面源文件（手写）                     apps/users/src/Widgets/StatusBar.php
   │  build() 方法体 = 唯一输入
   ▼
drift-codegen（构建期，token 扫描）    php tools/drift-codegen.php
   │  ① 找 extends ViewHost 的类
   │  ② 取 build() 的 token 区间
   │  ③ 扫区间内 $this-><属性> 且属性类型是 Subject 子类
   │  ④ 按 -> 链切分，每条链 = 一个 deps 元素
   ▼
生成物（静态 PHP，进 git）             apps/users/src/Generated/StatusBarView.php
   │  trait StatusBarView { driftSource(); driftDeps(); }
   ▼
页面类 use 它                          use \App\Users\Generated\StatusBarView;
```

**开发者要写的**：`extends ViewHost`（一次，替换 `StatelessWidget`）+ `use XxxView;`（一行）。
**开发者不用写的**：deps 的内容、名字登记、依赖数组、`select()` / `builder()` 闭包。
**不用手动跑 codegen**：它挂在 `drift-codegen` 门里，`gate` 每次跑都会重新生成；
`build-stale-check` 第 10 条守「磁盘上的生成物与重新生成的结果逐字节相同」。

★ 类名规则是确定的（`App\<App>\Generated\<类名>View`），但**不让开发者记它**：
`vh-unbound` 报错时直接把该加的那一行打出来。新页面首次写 `use XxxView;` 时 trait 还没生成
⇒ 直接 fatal，所以 **`gate` 的顺序必须是「先生成、再 `php -l`」**，否则第一个新页面必红。

---

## 6. 判据矩阵（六坑 → 判据 → 变异）

| 坑（§12.3） | 成熟框架教训 | 本方案判据 | 变异（必须红） |
|---|---|---|---|
| #1 名字拼错静默 | WPF `Binding Nmae` 不报错 | `dead-field`：`field('x')` ↔ `FIELDS` 常量（§7） | `field('nmae')` |
| #2 新值对象 ⇒ 白重建 | Redux `useSelector` 警告 | `dep-unstable`：deps 元素是对象且非 `Equatable` ⇒ warn（复用 `SelectPurity` 的 `IDENTITY_STABLE`） | deps 里塞 `new Foo()` |
| #3 行身份用 index | Flutter `ValueKey` / Qt 代理索引 | `rowKey('id')` 字段名，与 `FIELDS` 同一份对账 | `rowKey('nmae')` |
| #4 可用态漏刷新 | WPF 漏 `RaiseCanExecuteChanged` | `dep-missing`：`build()` 读 Subject 但类不 `extends ViewHost` | 把 `UsersPage` 改回 `StatelessWidget` |
| #4 漏写 `use`（同上，第二形态） | 同上 | `vh-unbound`：类 `extends ViewHost` 但未 `use` 生成 trait / trait 文件缺失 | 删掉 `StatusBar` 的 `use StatusBarView;` |
| #5 增量通知仪式 | Qt `begin/endInsertRows` 成对 | **不做**；`Subject` 粗广播不变 | — |
| #6 追踪窗口外读 | Compose 记不到 lambda 内读 | 闭包体内读点 ⇒ 生成期**报错**（不是静默漏） | 把读点塞进 `onPressed` |

新增四条判据的落点：

| 判据 | 类型 | 挂在哪 |
|---|---|---|
| `dep-missing` | lint 规则 `tools/Lint/Rules/DepMissing.php` | `gate` 的 `lint` 项（已有） |
| `vh-unbound` | lint 规则，可与 `dep-missing` 同一文件两个分支 | 同上 |
| `dep-unstable` | lint 规则，复用 `SelectPurity` 的稳定值登记 | 同上 |
| `dead-field` | lint 规则 `tools/Lint/Rules/DeadField.php` | 同上 |
| 生成物与输入一致 | 判据 | `build-stale-check` 第 10 条 |

★ `dep-missing` **必须等 G-1 修完才能写**：它的判据是「`build()` 里读了 `Subject` 属性」，
属性表不收构造器提升 ⇒ 它会和 `dead-subscription` 一起静默（§1.7 已实测 0 条）。

★ `vh-unbound` 是**纯静态判据**，不碰类型：类 `extends ViewHost` ⇒ 类体必须有一条
`use` 指向 `App\<App>\Generated\<类名>View`，且该文件存在并带 `// @generated` 标记。
两端都是字面量（类名 + 文件名），不需要 `ClassTable` 的方法签名，比 `dep-missing` 好写。
报错文案直接给出要加的那一行，不让开发者去猜类名规则。


---

## 7. 表格线（独立，不阻塞订阅线）

订阅线与表格线**正交**：`Table` 的 23 处订阅里一处都没有（showcase 全页零 `SubjectBuilder`）。
v2.1 把两条线捆在 G1/G2，是混乱来源。拆开后表格线可以单独做、单独回滚。

### 7.1 行形状不动

`Table::make(array $columns, array $rows)` 保持，行继续是 `array<string,scalar>`
（`field()` 只认数组，`TableColumn.php:361` 有守卫）。理由不是「数组 `===` 稳定」，
而是**不动就没有连锁面**：`textOf()` / `editableText()` / `copyText()` / `TableContract::collect()`
/ `TableState` 提交路径五处零改动。

### 7.2 `FIELDS` 常量 + `dead-field`

`FIELDS` 放在**使用 `field()` 的那个类里**，作为 `public const`：

```php
// apps/showcase/src/Pages/TablePage.php（设计草案，加在类体内）
final class TablePage extends StatefulWidget
{
    /** @var array<string, string> 字段 ⇒ 展示名 */
    public const FIELDS = [
        'id' => 'ID', 'name' => '姓名', 'email' => '邮箱',
        'city' => '城市', 'status' => '状态',
    ];

    // 现有 private static function columns(): ... 不动
}
```

`dead-field` 判据：**同一个类里**，`field('x')` 的字符串字面量必须 ∈ 本类 `FIELDS` 的键集。
`TableColumn` 的列定义就在 `self::columns()` 里（同文件同类），对账是两个字面量集合的差集，
不需要跨文件解析、不需要类型推导。

**不做类型推导**：`field()` 的对账两端是「常量数组字面量」与「字符串字面量」，
都在一个 token 文件内 —— 这是 `dead-command` 的同款成本，不是 `x:Bind` 级（评审稿 B6 的顾虑不适用）。

### 7.3 `rowKey('id')` 替代 `keyOf(fn)`

```php
// 现状
Table::make(...)->keyOf(static fn(array $r): int => $r['id'])
// 草案
Table::make(...)->rowKey('id')
```

`rowKey(string)` 内部实现（`src/Widget/Table.php`，新增 12 行）：

```php
public function rowKey(string $field): static
{
    $this->mutate();
    $this->keyOf(static function (array $row) use ($field): mixed {
        if (!\array_key_exists($field, $row)) {
            return null;
        }

        return $row[$field];
    });

    return $this;
}
```

⇒ `keyOf` 保留为逃生口（对象行 / 复合键），`rowKey` 是推荐形状，两者共用同一条渲染路径。
破坏面 27 处 `keyOf` 里，apps 3 处迁到 `rowKey`，其余（src/tests）不动。

`rowKey('id')` 的字符串与 `field('x')` 走**同一条 `dead-field` 判据**（同一个 `FIELDS` 常量、
同一份差集），所以坑 #3 与坑 #1 共用一个防线，不新开规则。

---

## 8. 扩展性

### 8.1 新增一个订阅页面

```php
final class OrdersPage extends ViewHost          // 1. 换个基类
{
    use OrdersPageView;                          // 2. 生成器下次运行补上

    public function __construct(private readonly OrderStore $store) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget { /* 普通读 */ }
}
```

`drift-codegen` 下次运行自动产出 `OrdersPageView`。**没有配置、没有注解、没有登记表。**

### 8.2 新增一个 Store 读方法

读方法直接加；生成物下次运行把它纳入 deps。无需改任何契约 ——
与 v2.1 的「三本名册」相比，这是有意的减法：**能被扫描的，不登记**。

### 8.3 第三方 app / 新增 app

`collectSources()` 按 `apps/*/src` 通配，新 app 零配置纳入。
`Generated/` 目录随 app 走，`.gitignore` 不需要例外（生成物进 git）。

### 8.4 与 B2（`CellSpec`）/ B3（枚举投影名）的关系

正交，可后置：

- `CellSpec` 改的是「列怎么定义富单元格」，不碰 deps；
- 枚举投影名改的是「切片名怎么表达」，本方案的 deps 是表达式不是名字 ⇒ 不受影响。

⇒ v2.1 §11.3.1 那条「为什么可以先做」的论证简化成一句：
**本层不定义任何行/列/切片的书写契约，只消费 `select` 的表达式。形状变了，deps 不用改。**

### 8.5 多 Store 的控件

本轮的 23 处样本里，**每个宿主只订阅一个 Subject**。多 Store 的写法：

```php
public function driftSources(): array
{
    return ['store' => $this->store, 'form' => $this->form];
}
```

`ViewHostState` 在 `bind()` 里遍历挂多个槽。**本轮不实现**，等出现真实需求；
接口留 `driftSource(): ?Subject` 单数形态，将来加 `driftSources()` 并存。

---

## 9. 迁移顺序与回滚点

| 段 | 做什么 | 出口判据 | 回滚点 |
|---|---|---|---|
| **G-1** | **独立缺陷修复**：`SourceQuery::readBody()` 增收 `__construct` 的属性提升，新增 `CtorParams` | `dead-subscription` 对提升写法从 0 条变 1 条（§1.7 的最小仓库直接当夹具）；全仓 lint 新增诊断逐条确认为真缺陷；`selftest` 绿 | 恢复 `readBody()` |
| **G0** | `DepExtractor` + `--emit-deps`（只读，不落盘、不接运行） | ① 对 7 个文件 23 处产出 23 条 deps，与旧 `select` **一一对应**（人工核对一次）；② 变异：把 `TaskCard` 的 `$s->time()` 注释掉 ⇒ 生成 4 条，判据必须报「少了 1 条」；③ 变异：读点塞进闭包 ⇒ 报「跨函数」 | 无（纯新增工具） |
| **G1** | `ViewHost` + `ViewHostState` + `ValueEquality`（`src/` 新增三文件，`SubjectBuilderState` 改调用 `ValueEquality`） | `unit` / `feature` 全绿；`SubjectBuilderTest` 16 例一条不改仍绿 | 删三个文件 |
| **G2** | 单文件灰度：`users/StatusBar`（1 处，最简单）+ 生成接线（对 `gate` 的三条硬约束同时落） | `gate` 全绿；`build-stale-check` 第 10 条绿；T1 翻译过；T2 `probe-window` 0 FAIL；**变异：注释掉 `use StatusBarView;` ⇒ `vh-unbound` 必须红** | 该文件回退到 `SubjectBuilder` |
| **G3** | `UsersPage`（4 处，事故形态）+ `SignupPage`（3 处，数组切片） | **事故复现**：真机刷新时按钮不灰、搜索框改字列表不重建错位；`dep-missing` 变异红 | 逐文件回退 |
| **G4** | watermark 15 处（含 `self::slice` 跨方法、三元切片） | 同上 + `dep-unstable` 对 `StatusMessage` 不误报 | 逐文件回退 |
| **G5** | `SubjectBuilder::select/builder` 标 `@internal`，公共文档删除；tutorial 加 `ViewHost` 样例 | 全仓 apps 0 处公共调用；tests 16 处视 `internal-leak` 是否覆盖 `tests/` 决定「保留」或「一并改走 `ViewHost`」；tutorial 参与编译 | 恢复 `@api` |
| **T1** | 表格线：`FIELDS` + `dead-field`（**可与 G1–G5 并行**） | `field('nmae')` 变异红；`editable()` 提交路径零改动 | 删规则 |
| **T2** | `rowKey`（可选） | 27 处 `keyOf` 中 apps 3 处迁移 | 保留 `keyOf` |

**判死条件**（任一条成立即停在 G1，不进入 G2）：

1. `DepExtractor` 对 23 处产出不了「一一对应」（差一条都要查清，不是调阈值）；
2. `ViewHost` 让 `SubjectBuilderTest` 的任何一例变红且改不动；
3. T1 翻译报 `ViewHost` / `ViewHostState` 的形态不支持（`abstract` 基类 + `instanceof` 转型）。

第 3 条要在 G1 出口**就跑一次 T1**，不要等到 G2 —— 这是 v2.1 漏掉的顺序（它把 T1 放在 G2）。

---

## 10. 性能：门控粒度与量测口径

### 10.1 粒度退化的真实幅度

| | 现状 | 本方案 |
|---|---|---|
| `UsersPage` 搜索框改字 | 只重建 `TextField` 那支 | 重建整个 `UsersPage`（`Dock` → `ScrollView` → 列表） |
| `UploadCard` 上传进度变 | 只重建进度条那支 | 重建整个 `UploadCard` |
| `TaskCard` 改时间字段 | 只重建该 `TextField` | 重建整个 `TaskCard` |

**这是重建工作量的退化，不是调用开销**，编译器补不回来（`Element::reuseChild()` 的
`widget !== newWidget` 恒真，整树真的 update + rebuild）。

但作用域是 **widget 级**，不是 v2.1 的整页级：`UploadCard` 这层之下才重建，
它的父 `Dock` / `Column` / 兄弟卡片不动。

### 10.2 量测口径（G2 出口就量，不等 G4）

用现成工具：

```bash
php tools/frame-tax-probe.php --check    # 每帧固定税（gate 的 tick 项同一条）
php tools/frame-tax-probe.php --scale    # 12k 节点尺子
php tools/probe-frame-gates.php          # 门控计数
```

判据：**同一操作（搜索框输入一个字符）的重建节点数，V2 不超过 V1 的 K 倍。**

K 的初值取 **3**，来源是结构估算而不是实测：`UploadCard` 现状 5 个切片，合并成 1 个
⇒ 最坏情况从「5 个切片各触发一次小重建」变成「1 次触发整个 Card」，比值上界约等于
「Card 子树节点数 / 最大切片子树节点数」，样本里预计落在 2–3。

★ **这个 3 是待验证的估算，不是基准线**。G2 出口必须先把 V1 的基线数量出来，
K 以基线的实际比值为准；估算与实测不符时以实测为准，并更新本节。
量出来超了就先做 §10.3 的分区，不硬扛。


### 10.3 如果量测超标：分区逃生口

生成器不改 `build()` 方法体（那是重写，量级完全不同）。逃生口是手写：

```php
// 只为超大页面的热点区域保留
return Column::make()->children([
    HotList::make($this->store->rows())->key(...),   // 这一支独立订阅
    ...
]);
```

`SubjectBuilder` 标 `@internal` 后仍可用，配合一条豁免注释
`@internal-use-allowed: 列表 12k 行，整卡重建超预算`。G5 的「0 处」判据带这份豁免名单。

---

## 11. 明确不做

| 不做 | 理由 |
|---|---|
| 运行期读记账（Compose Snapshot 路线） | 有窗口、有退订、调试不可见；`REVIEW-UI-DATA-CONTRACT-2026-11-11.md` §10.3 已证 `StatelessElement::rebuild()` 没有窗口 |
| 增量通知（Qt `begin/endInsertRows`） | 成对纪律 + 越界异常，收益不抵仪式成本 |
| 生成分区（改 `build()` 方法体） | 需要真正的 AST 重写 + AOT 约束检查，量级是另一个方案 |
| `onChanged(callable) → 名字` 收编 | 作用域不匹配（控件实例 vs 全局路由）、`CommandArg` 装不下行编辑三元组、热路径。登记 P2 |
| `readonly class Row` | `readonly` 不改比较语义；且会连锁打断 `field()` / `editable()` / `copyText()` |
| 类型推导（`x:Bind` 级） | `ClassTable` 无方法签名表；本方案不需要 |
| `@view` 注解驱动扫描 | AOT 属性解析未验；`extends ViewHost` 就是标记 |

---

## 12. 诚实边界

- 本文**未改 `src/`、未新增 lint、未碰 `gate`**；全部代码为设计草案，未编译、未跑 T1/T2。
- §1 的七条是本轮实测（`grep` / 源码阅读 / 两个最小仓库跑规则），但 `ViewHost` 的 AOT
  可行性**未验**：`abstract` 基类 + `instanceof` 转型 + `?Subject` 返回类型三者组合是
  本方案唯一的未知数，G1 出口必须跑一次 T1 才能继续。
- §1.7 的假绿是**独立于本方案的现存缺陷**，`gate` 现在全绿不代表 `dead-subscription` 有牙。
  修它（G-1）会新增一批诊断，需要逐条判真伪 —— 那批诊断里可能有真缺陷。
- `deps` 的 O(n) 比较成本：`$s->visible()` 返回数组，每次 notify 都做一次全表逐元素比。
  现状 `select` 也是每帧跑，成本相同；但合并后**一次 notify 比 4 个切片**，总数不变。
  这与「整卡重建」是两笔账，别混。
- `drift-codegen --emit-deps` 与 `emitViewTraits` 都要读 `SourceFile`，
  这会让 `drift-codegen` 首次依赖 `tools/Lint/` 的类 —— 目前两者互不引用。
  这是新增的依赖方向，需在 `dependency-direction` 判据里登记。
- 生成物进 git 的代价：代码评审要看过生成 diff；IDE 跳到生成代码时栈会更长一层。
  两条维护面未验。
- 表格线的 `FIELDS` 常量是**手写**的（一行数组字面量），不生成。
  这是「零新增书写物」的唯一例外，如实记账。
- `SubjectBuilder` 不删除，只降 `@internal`。订阅必须有 `State` 持有，
  `ViewHostState` 就是那个 `State` —— 说「删掉订阅机制」是错的。

---

## 13. 对前五份文档的裁决

| 文档 | 裁决 |
|---|---|
| `REVIEW-UI-DATA-2026-11-11.md`（四维度评审 + §12 六坑） | **保留为坑的索引**。§12.3 六坑仍是本文 §6 的骨架 |
| `DESIGN-UI-DATA-CONTRACT-2026-11-11.md`（三本名册） | **作废**。能被扫描的不登记 |
| `REVIEW-UI-DATA-CONTRACT-2026-11-11.md`（五轮推演） | 保留为证据链。§9 可逆性分层、§10.3 Element 窗口更正仍有效 |
| `DESIGN-UI-DATA-DX-FIRST-2026-11-11.md`（v1 + v2.1） | **§11 被本文取代**。§1–§4 的「迁移前现状」仍有参考价值 |
| `REVIEW-UI-DATA-DX-FIRST-2026-11-12.md`（v2 评审） | 保留。其中 A1/A2/A5/B1/B6 五条在本文有独立处置（§1.4 / §1.6 / §2.3 / §10 / §7.2） |

本文与 v2.1 的净差异，一页说完：

| 项 | v2.1 | 本文 | 为什么 |
|---|---|---|---|
| 迁移单位 | 页面 | **订阅宿主类** | 23 处的实际分布（§1.1） |
| 生成物 | `UsersPageBinding extends BindingHost` | **`trait XxxView`** | 不用读构造签名、`self::` 不断链、创建点零改动 |
| deps 来源 | 推导读路径 | **原样搬 `select` 表达式** | 唯一盲区消失，G0 判死口降级（§1.3） |
| 门控 | 数组 `===` | **逐元素 `sameValue`** | deps 元素可能是对象（§1.4） |
| 行形状 | `array<string,scalar>` + `FIELDS` + `rowKey` | **行不动**，`FIELDS` 独立成表格线 | 两条线正交，不互相阻塞 |
| 扫描触发 | 所有 `build()` 默认扫 | **`extends ViewHost` 即标记** | 否则 30+ 个 showcase 页面产出空壳 |
| T1 验证时机 | G2 | **G1 出口就跑** | `ViewHost` 的 AOT 形态是根本未知数 |
| 前置 | 无 | **G-1 修属性表假绿** | 否则 `dep-missing` 与 `dead-subscription` 一起空转（§1.7） |
