# 设计方案 · UI 与业务数据契约（DX 优先 · 允许破坏兼容性）· 2026-11-11

> **已被取代（2026-11-12）**：开工口径见 `docs/DESIGN-UI-DATA-FINAL-2026-11-12.md`。
> 本文保留为证据链：§1–§4 的「迁移前现状写法」仍是可抄的现状样本，
> §11 的部分论证（六坑 → 结构性消除、可逆性分层）被最终方案继承。
> 最终方案改掉了本文 §11 的三处形状：迁移单位（页面 → 订阅宿主类）、生成物
> （`BindingHost` 包装 → `trait`）、deps 来源（推导读路径 → 原样搬 `select` 表达式），
> 逐条理由见最终方案 §1 与 §13。
>
> 状态：**设计稿 v2.1，未落地任何代码**。基线 HEAD `5f26dfc`。
> **§11 是本文的最终裁决。** §0–§4 保留 v1 的「迁移前现状写法」作
> 对照，§5–§10 的裁决已被 §11 取代（原文保留，不作为开工依据）。
> 同日另三份文档（现状评审、三本名册设计稿、四轮推演分析）保留为判据与证据链，
> 其中评审稿 §12 是 v2 的主要依据。
> 代码块中，标「现状」的逐字取自仓库；其余均为**设计草案，未编译、未落地**。
>
> **v2.1（2026-11-12）**：按 `docs/REVIEW-UI-DATA-DX-FIRST-2026-11-12.md` 修了五处形状
> —— 行退回标量数组（`readonly` 在 PHP 不提供值相等）、值对象降级成一句 `FIELDS` 常量、
> 生成物下沉成 `BindingHost`（`SubjectBuilder` 不是删掉）、写通道降 P2、
> G2 出口加重建节点数量化判据。每处的理由标在所在小节。

---

## 0. 最新方案是什么（v2）

> **23:3x 用户授权「可以有兼容性破坏」**：v1 第 1 条裁决「页面代码一行不改 /
> 零破坏是底线」**撤回**。当时把它当底线，是因为把 DX 优先窄化成了
> 「少写字 + 快反馈」；允许破坏后，可以按成熟框架的路线处理根因。见 §11.1–§11.3。

### 0.1 v2 三条裁决

1. **书写形状改为「普通读」，订阅由构建期生成。** 公共 `select()` / `builder()`
   删除，页面 `build()` 里直接读 Store；`drift-codegen` 扫 `build()` 的读路径，
   生成一个 `BindingHost` 子类（§11.5.5）。开发者在 `build()` 里**零新增书写物**；
   整张表要多写一行 `FIELDS` 字段清单 —— 那是数据、不是订阅样板。心智模型一句话：
   「`build()` 里读到的，就是订阅到的」。
2. **框架内部必须有数据平面**：字段名、行、列、身份都是构建期可见的数据，
   不是运行时才第一次知道的闭包。六个坑里 #1/#2/#3/#4/#6 结构性消除，
   #5 明确不做增量通知（§11.4）。
3. **性能仍交给后续编译器**，但「让框架看见数据」是**正确性主张**；反过来，
   **门控粒度变粗**（整页 vs 切片）不是编译器能补的那一类 —— 那是重建工作量，
   必须在 G2 出口量一次（§11.5.5 / §11.6）。

一句话：**v1 补的是「写错有人喊」，v2 补的是「结构上写不错」，但写对之后的代价变粗了。**

### 0.2 变与不变（v2）

| | 方案落地后 |
|---|---|
| 页面写法 | **变**：`select(fn)` / `builder(fn)` 从公共面消失，改成 `build()` 里普通读 |
| 行数据 | **变**：`array<User>` → `array<string,scalar>` + 一行 `FIELDS` 常量（**不是**值对象） |
| 列定义 / 行身份 | **变**：`keyOf(callable)` → `rowKey('字段名')`；`field()` 加 `dead-field` 对账；`cell(callable)` 保留为逃生口 |
| `Button::command('name')` | **不变**（名字 + `dead-command` 双向对账） |
| `onChanged` / `onSelectionChange` 写通道 | **不变**：本轮不动，登记 P2（§11.5.4） |
| `AsyncCommand` / `AsyncValue::fold()` 四态 | **不变** |
| 门控粒度 | **变粗**：整页一个订阅（代价，G2 出口量重建节点数） |
| `Subject` 粗广播 | **不变**；不做细粒度通知、不做运行期读记账 |
| 写错时 | 生成期 / `gate` 红 + 中文修法；生成物是静态 PHP，可打开看订阅了什么 |
| 框架改动面 | `drift-codegen` 新增「生成 PHP」一层 + `BindingHost`；`SubjectBuilder` 下沉，**不删** |

### 0.3 数据流（生成物是新增的静态层）

```text
                  ┌─── drift-codegen（构建期，新增）───────────────┐
                  │  扫 build() 读路径 → 生成 *Binding.php         │
                  │  事实源：token/AST；产物：静态 PHP，参与 lint/tpc│
                  └──────────────────┬────────────────────────────┘
                                     │ 生成的 deps()：方法调用结果，不是字符串表
Store (Subject) ──notifyChanged()──► BindingHost ──整页值门控──► build() ──► Widget
      ▲                              (State 持订阅)                        │
      └──────── AsyncCommand::run() ◄── Button::command('name') ◄─────────┘
                                          │
                                  Application::commands() 路由表
```

与 v1 的差别：v1 的三件仪器都在这条链**外面**只做观察；v2 把「框架看见数据」
做成链上的一层，但**它是构建期生成的静态代码，不是运行期记账**。
`BindingHost` 是现有 `SubjectBuilderState` 的收窄版 —— 订阅仍由 `State` 持有，
只是 `select` 不再由人写。

---

## 1. 开发者要怎么写（v1 迁移前现状）

> 以下 §1–§4 逐字取自 HEAD `5f26dfc`，是**迁移前**的写法，保留作对照。
> v2 的开发者写法见 **§11.5**（普通读 + 值对象行 + 声明式列）。

### 1.1 五个文件各自负责什么

| 文件 | 职责 |
|---|---|
| `apps/<app>/src/Store/XxxStore.php` | 持有数据与四态；写操作末尾 `notifyChanged()` |
| `apps/<app>/src/Pages/XxxPage.php` | 订阅读点、拼控件树；不持有会变的值 |
| `apps/<app>/src/Commands/XxxCommands.php` | 命令 handler；写回 Store |
| `apps/<app>/src/XxxApplication.php` | `commands()` 路由表：名字 → handler |
| `apps/<app>/bin/win32/Bootstrap.php` | 组合根：平台实例、任务宿主、建窗 |

**只记一条**：界面里任何「会变的值」，都必须来自某个 `select()` 的返回值。

### 1.2 Store：真实形状（现状）

```php
final class UserStore extends Subject
{
    /** @var list<User> */
    private array $rows = [];

    private AsyncCommand $page;
    private string $query = '';
    private Computed $visibleComputed;

    public function __construct(
        private UserSource $src,
        private ?TaskHost $host = null,
    ) {
        $this->page = AsyncCommand::make(function (int $pageNum): Future {
            return $this->fetch($pageNum);
        });

        // 命令换值 ⇒ Store 也变，页面只需订 Store 一个
        $this->page->subscribeFor($this, function (): void {
            $this->absorb();
        });

        $this->visibleComputed = new Computed(
            fn(): array => $this->query === ''
                ? $this->rows
                : array_values(array_filter(
                    $this->rows,
                    fn(User $u): bool => str_contains($u->name, $this->query),
                )),
            [$this],
        );
    }

    public function page(): AsyncCommand { return $this->page; }
    public function query(): string { return $this->query; }

    /** @return list<User> */
    public function visible(): array { return $this->visibleComputed->get(); }

    public function load(int $page = 1): void { $this->page->run($page); }

    public function setQuery(string $q): void
    {
        if ($q === $this->query) {
            return;
        }
        $this->query = $q;
        $this->notifyChanged();
    }
}
```

写法要点：

- 派生值用 `Computed`，依赖给 `[$this]` 粗依赖 —— **换的是「改了依赖忘了清缓存」这一整类 bug 的结构性消失**。
- `AsyncCommand` 自己也是 `Subject`，Store 在**构造器**里订它一次，页面不必另开订阅通道。
- 写操作末尾必须 `notifyChanged()`；相等时提前返回，避免无意义广播。

**只记一条**：Store 只暴露读方法 + 写方法，不返回 `Widget`，也不认识页面。

### 1.3 页面：完整可抄版本（现状 API）

```php
final class UsersPage extends StatelessWidget
{
    public function __construct(private readonly UserStore $store)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(
                Column::make()->spacing(16)->padding(EdgeInsets::all(24.0))->children([
                    Text::make('用户目录')->title(),
                    Row::make()->spacing(8)->children([
                        // ① 搜索框：读 query()
                        SubjectBuilder::make($this->store)
                            ->select(static fn(UserStore $s): string => $s->query())
                            ->builder(fn(string $q): Widget => TextField::make()
                                ->value($q)
                                ->label('搜索')
                                ->onChanged(fn(string $x) => $this->store->setQuery($x))),

                        // ② 刷新按钮：读 isRunning()
                        SubjectBuilder::make($this->store)
                            ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
                            ->builder(fn(bool $running): Widget => Row::make()->children([
                                Button::primary('刷新')
                                    ->enabled(!$running)
                                    ->command('users.refresh'),
                            ])),

                        Spacer::make(),
                        Button::primary('新建用户')->command('nav.signup'),
                    ]),
                ])
            )
            ->center(
                // ③ 列表四态：读 page()->value()
                SubjectBuilder::make($this->store)
                    ->select(static fn(UserStore $s): AsyncValue => $s->page()->value())
                    ->builder(fn(AsyncValue $v): Widget => $v->fold([
                        'idle' => static fn(AsyncValue $v): Widget =>
                            Text::make('尚未加载')->caption(),

                        'loading' => static fn(AsyncValue $v): Widget =>
                            Spinner::make(),

                        'error' => static fn(AsyncValue $v): Widget =>
                            Row::make()->spacing(8)->children([
                                Text::make('加载失败')->error(),
                                Button::primary('重试')->command('users.refresh'),
                            ]),

                        'data' => fn(AsyncValue $v): Widget =>
                            // ④ 派生行：读 visible()，订阅点在 data 支这层
                            SubjectBuilder::make($this->store)
                                ->select(static fn(UserStore $s): array => $s->visible())
                                ->builder(static fn(array $rows): Widget => Each::make(
                                    static fn(User $u): int => $u->id,
                                )->items($rows, static fn(User $u): Widget => UserRow::make($u))),
                    ]))
            );
    }
}
```

四个读点对应四份订阅，各自独立门控：改搜索字不会让列表重建，刷新中不会让
搜索框重建。`Each::make()` 的 key 必填。

**只记一条**：`select()` 里只读**这一个切片需要的值**，读得越窄，重建越少。

### 1.4 加一个新的读切片：三步

假设要在头部显示「当前共 N 条」。**不改任何框架代码，不登记任何名字**：

```php
// ① Store 加读方法（若是派生值，用 Computed）
public function total(): int
{
    return count($this->rows);
}

// ② 页面加一个订阅点
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): int => $s->total())
    ->builder(static fn(int $n): Widget => Text::make("共 {$n} 条")->caption()),

// ③ 写完收工 —— 没有 ③
```

对比被否掉的三本名册形状，同一个需求要动 3–4 处：加 enum case、加 `match`
分支、页面改 `->project(...)`、必要时补挂期 `assert()`。本方案**只有 2 处**，
且都在开发者本来就要动的文件里。

### 1.5 异步四态：`fold()` 必须写全四支

```php
$v->fold([
    'idle'    => static fn(AsyncValue $v): Widget => ...,
    'loading' => static fn(AsyncValue $v): Widget => ...,
    'data'    => static fn(AsyncValue $v): Widget => ...,
    'error'   => static fn(AsyncValue $v): Widget => ...,
]);
```

`AsyncValue::fold()` 在运行期入口检查四个键，漏一支当场抛
`InvalidArgumentException`（文案直接指名缺哪支）。不需要 `match` 无 `default`，
也不需要另造名册。

**只记一条**：四支写全即可，穷尽性由 `fold()` 保证，不靠人记。

### 1.6 写操作：控件只给名字，落点在路由表

```php
// 页面：控件只写一个字符串名字
Button::primary('刷新')->command('users.refresh'),
Button::primary('新建用户')->command('nav.signup'),

// Application：名字 → handler，一张表
public function commands(): iterable
{
    $user = new UserCommands($this->users, $this->app);
    $nav  = new NavigationCommands($this->app);

    return [
        'users.refresh' => $user,
        'user.del'      => $user,
        'nav.signup'    => $nav,
    ];
}

// handler：一个 handle() 分派名字，写回 Store，Store 自己 notifyChanged()
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;

final class UserCommands implements CommandHandler
{
    public function __construct(
        private UserStore $users,
        private AppStore $app,
    ) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'users.refresh' => $this->refresh(),
            'user.del'      => $this->delete($arg),
            default => false,   // 继续冒泡给下一层 handler
        };
    }

    private function refresh(): bool
    {
        $this->users->load();   // 只发起；结果由 AsyncValue 四态回答
        return true;
    }

    private function delete(CommandArg $arg): bool
    {
        $this->users->remove($arg->i);
        return true;
    }
}
```

`dead-command` lint 已双向对账「控件用到的名字」与「路由表登记的名字」，
漏一边会红。本方案**不新增 `CommandName` enum**：现有字符串 + `handle()` 分派
+ lint 已经够，换成 enum 只是把一处书写变成两处。

### 1.7 局部 UI 态：用 `StatefulWidget`，不要混进 Store

```php
final class CounterStage extends StatefulWidget
{
    #[\Override]
    public function createState(): State { return new CounterState(); }
}

final class CounterState extends State
{
    private int $count = 0;

    #[\Override]
    public function build(BuildContext $ctx): Widget
    {
        return Row::make()->children([
            Text::make('count = ' . $this->count),
            Button::primary('+1')->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->count++;
                });
            }),
        ]);
    }
}
```

分工：**会变且属于业务的数据**放 Store + `select()`；**只属于这一块 UI 的瞬态**
（计数器、滚动位置、展开态）放 `State`。`setState()` 只标脏，重建在帧末。

`State::watch()` 是局部态订阅 Store 的另一种通道，只能在 `initState()` 里调；
`reactive-read` 会把它识别为合法订阅作用域（§2.3）。

### 1.8 表格：列定义用现行 `TableColumn`

```php
private static function columns(): array
{
    return [
        TableColumn::make('id', 'ID')
            ->field('id')
            ->width(TableColumnWidth::fixed(72.0))
            ->sortable()
            ->resizable(),

        TableColumn::make('name', '姓名')
            ->field('name')
            ->width(TableColumnWidth::flex(2.0))
            ->editable(),

        // 派生单元格走 cell()
        TableColumn::make('status', '状态')
            ->cell(static fn(array $row): Widget => Text::make(
                self::disabledRank((int) $row['id']) === 1 ? '停用' : '正常'
            )->caption())
            ->sortable(),
    ];
}

Table::make(self::columns(), $rows)
    ->keyOf(static fn(array $row): int => $row['id'])
    ->sort($this->sortState)                                   // TableSort 单值
    ->onSortChange(fn(TableSort $next) => $this->setState(...));
```

现行排序是 `sort(TableSort)` + `onSortChange(fn(TableSort): void)`，
**不是** `sortBy(string, bool)` + `onSortChange(fn(?string, bool))`。
`RowSpec` 暂缓：现有 `TableColumn` + `keyOf()` 已把行契约表达清楚，
换名册只会多一份要人工对齐的书写物。

### 1.9 组合根：平台与 UI 的唯一交界

```php
// apps/users/main.php
namespace {
    function main(): void { \App\Users\Bootstrap::run(); }
}

// Bootstrap::run()（节选）—— 平台与 UI 的唯一交界
$platform = new Win32Platform();
$taskHost = new ProcessTaskHost([$exe, '--drift-worker'], JobRegistry::make());

$set = new WindowSet(
    $platform,
    Paths::userConfig($platform->configDir(), 'users.window'),
    static function (): void { ResizeHookState::$pump = null; },
);

$set->open(
    'main',
    null,                       // 标题取 Application::title()
    900,
    640,
    static fn(): UsersApplication => new UsersApplication($src, $taskHost),
)->setMinSize(420, 320)->onSemantics();

$set->run();
```

`open()` 还有两个可选闭包（建窗后还原几何前、表面就绪后首帧前），此处省略；
`Application` 由闭包造，**不接收窗口句柄**。

---

## 2. 写错了会怎样（E0 · `reactive-read`）

这是本方案**唯一改变开发者日常体验**的地方。

### 2.1 三类误用与报错

**误用 ①**：读点露在 `build()` 顶层（最常见）。

```php
// 错
Button::primary('刷新')->enabled(!$this->store->page()->isRunning()),
```

```text
apps/users/src/Pages/UsersPage.php:88  error  reactive-read       读点 $this->store->page()->isRunning() 不在订阅作用域内；Store 变化时本子树不会重建 ⇒ 该值停在首次建成那一刻。修法：包进 SubjectBuilder::select(...)，或改为构造期注入的不变值
```

**误用 ②**：想靠标记接口蒙混过关。

```php
// 错：现有 dead-subscription 会被这个空接口洗绿
final class UsersPage extends StatelessWidget implements SubscribingWidget
```

`reactive-read` **仍然报红**，因为判据看的是「读点在不在订阅作用域的 token 区间内」，
不看类实现了什么接口。

**误用 ③**：读点放进了 `builder()` 而不是 `select()`。

```php
// 错：builder 闭包里直接读 Store，拿到的仍是浇筑那一刻的值
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): string => $s->query())
    ->builder(fn(string $q): Widget => Text::make(
        $this->store->page()->isRunning() ? '刷新中' : $q
    )),
```

```text
apps/users/src/Pages/UsersPage.php:92  error  reactive-read       读点 $this->store->page()->isRunning() 位于 builder() 闭包内但不在任何 select() 内；builder 只在订阅值变化时重跑，该读点不会触发重跑。修法：把它并入 select() 的返回值（可返回数组/值对象）
```

输出格式沿用现有 `Diagnostic::format()`：`file:line  severity  rule  message`。

### 2.2 规则实现骨架（草案）

签名对齐真实的 `tools/Lint/Rule.php` 与 `Diagnostic.php`；
`readPoints()` / `insideSelectClosure` 是**要新增的接口，现在不存在**。

```php
final class ReactiveRead implements Rule
{
    public function name(): string { return 'reactive-read'; }

    /** 服务的公理 / 反模式编号，落代码时按 §16.6 表裁决 */
    public function serves(): string { return 'G2'; }

    public function summary(): string
    {
        return '读 Subject 派生值必须词法上落在 select() / watch() 订阅作用域内';
    }

    /** @return list<Diagnostic> */
    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            // 由 §8 的 dep-path 提取器提供：静态探针实测 37 处订阅闭包 36 处可提取
            foreach ($file->readPoints() as $hit) {
                if ($hit->insideSelectClosure || $hit->insideWatchedScope) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit->line,
                    $hit->insideBuilderClosure
                        ? '读点位于 builder() 闭包内但不在 select() 内 ⇒ 不会触发重跑。修法：并入 select() 的返回值'
                        : '读点不在订阅作用域内 ⇒ Store 变化时子树不会重建，该值停在首次建成那一刻。修法：包进 SubjectBuilder::select(...)，或改为构造期注入的不变值',
                );
            }
        }

        return $found;
    }
}
```

判据的期望值**不从 `SubscribingWidget` 推导**，只从「调用点是否在订阅闭包的
token 区间内」推导 —— 这是它不会被空接口洗绿的原因。

### 2.3 夹具与牙检（草案）

夹具放 `tools/Lint/fixtures/apps/`，期望值登记在 `tools/selftest.php` 的
`$expected` 数组，规则注册进 `Linter::defaultRules()`：

```php
// tools/Lint/fixtures/apps/ReactiveReadBad.php —— 该响
final class ReactiveReadBad extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Button::primary('刷新')->enabled(!$this->store->page()->isRunning());
    }
}

// tools/Lint/fixtures/apps/ReactiveReadMark.php —— 补空接口仍须响
final class ReactiveReadMark extends StatelessWidget implements SubscribingWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Button::primary('刷新')->enabled(!$this->store->page()->isRunning());
    }
}

// tools/Lint/fixtures/apps/ReactiveReadGood.php —— 不该响（不登记进 $expected）
final class ReactiveReadGood extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return SubjectBuilder::make($this->store)
            ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
            ->builder(static fn(bool $r): Widget => Button::primary('刷新')->enabled(!$r));
    }
}
```

```php
// tools/selftest.php · $expected 新增一项
'reactive-read' => [
    'apps/ReactiveReadBad.php:' . Diagnostic::ERROR,
    'apps/ReactiveReadMark.php:' . Diagnostic::ERROR,
],
```

```php
// tools/Lint/Linter.php · defaultRules() 新增一行
new ReactiveRead(),
```

**牙检是判死条件的前提**：只有证明规则能咬红（含「补空接口仍然红」），
后面「一个迭代零命中 ⇒ 整个方案停在这里」才成立。否则「零发现」可能只是
「规则没覆盖」，又是假绿。

### 2.4 接线与成本

- `gate` **不需要新增项**：现有 `lint`（`tools/lint.php`）和
  `selftest`（`tools/lint.php --selftest`）已经会跑新规则与夹具。
- 静态探针的 36/37 是现成零件（`SelectPurity::isClosure()`、
  `SourceQuery::matchBracket()` / `methodCalls()`、
  `DeadSubscription::subjectCalls()` 已有闭包切分与读点提取）。
- 唯一真盲区 `watermark/StatusBar` 的 `self::slice($s)` 需要一层跨函数展开；
  规则上线初期可把它列为显式豁免并登记，别悄悄放过。
- 可选的第二观察口：dev 影子断言，在 `build()` 期间记录真实读点，只断言
  「实际读 ⊆ 静态潜在读集上界」，不自动订阅、不进 AOT。它与 lint 不同源，
  能抓静态看不到的间接读。

---

## 3. 从哪抄（E1 · tutorial `DataStage`）

### 3.1 新增文件：`apps/tutorial/src/Stages/DataStage.php`（草案）

一个文件走完「加载 → 四态 → 搜索 → 命令回写」全环，是**唯一可抄样板**：

```php
<?php
declare(strict_types=1);

namespace App\Tutorial\Stages;

use Drift\Async\AsyncValue;
use Drift\State\SubjectBuilder;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

use App\Tutorial\Store\DemoStore;

final class DataStage extends StatelessWidget
{
    public function __construct(private readonly DemoStore $store)
    {
        parent::__construct();
    }

    public static function make(DemoStore $store): self
    {
        return new self($store);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(8)->children([
            Row::make()->spacing(8)->children([
                SubjectBuilder::make($this->store)
                    ->select(static fn(DemoStore $s): string => $s->query())
                    ->builder(fn(string $q): Widget => TextField::make()
                        ->value($q)
                        ->label('搜索')
                        ->onChanged(fn(string $x) => $this->store->setQuery($x))),

                Button::primary('刷新')->command('demo.refresh'),
            ]),

            SubjectBuilder::make($this->store)
                ->select(static fn(DemoStore $s): AsyncValue => $s->page()->value())
                ->builder(static fn(AsyncValue $v): Widget => $v->fold([
                    'idle'    => static fn(AsyncValue $v): Widget => Text::make('尚未加载')->caption(),
                    'loading' => static fn(AsyncValue $v): Widget => Spinner::make(),
                    'error'   => static fn(AsyncValue $v): Widget => Text::make('加载失败')->error(),
                    'data'    => static fn(AsyncValue $v): Widget => Text::make('已加载')->caption(),
                ])),
        ]);
    }
}
```

`DemoStore` 用最小内存数据源，不接网络、不接 worker，保证 tutorial 能独立跑。

### 3.2 注册三处（草案）

`demo.refresh` 必须在路由表里有落点，否则 `dead-command` 会红。
同一个 `DemoStore` 实例由 `TutorialApplication` 持有，页面与 handler 共用：

```php
// apps/tutorial/src/TutorialApplication.php（节选）
final class TutorialApplication extends Application
{
    private DemoStore $demo;

    public function __construct()
    {
        parent::__construct();   // 漏掉会被 AppSubclassCtorParent 拦下
        $this->demo = DemoStore::make();
    }

    public function buildRoot(): Widget
    {
        return TutorialPage::make(
            function (int $ms, \Closure $cb): void { $this->after($ms, $cb); },
            $this->demo,
        );
    }

    public function commands(): iterable
    {
        return ['demo.refresh' => new DemoCommands($this->demo)];
    }
}

// apps/tutorial/src/Commands/DemoCommands.php
final class DemoCommands implements CommandHandler
{
    public function __construct(private DemoStore $demo) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'demo.refresh') {
            return false;
        }
        $this->demo->load();
        return true;
    }
}
```

```php
// apps/tutorial/src/TutorialPage.php · 构造加一个参数
public function __construct(
    private readonly \Closure $after,
    private readonly DemoStore $demo,
) {
    parent::__construct();
}

// make() 同步加参数；build() 的 children 数组末尾追加
DataStage::make($this->demo),
Divider::make(),
```

三处注册（Application 构造 / `buildRoot()` + `commands()` / TutorialPage）都在
参与编译的 `.php` 文件里，任何一处漏了都会由 lint 或 smoke 报红。

### 3.3 它为什么不会过期

| 覆盖 | 作用 |
|---|---|
| `php -l` / `lint` / `selftest` | 语法与 37 条 lint 规则 |
| `drift-codegen` | AOT 翻译门，API 形状变了当场红 |
| `smoke` | 组合根接线与命令路由对账 |
| `reactive-read`（E0） | 连教学样例自己漏订阅也会红 |

**关键在于它是 `.php` 文件，不是文档里的代码块。** 文档腐烂的第二个落点
（第一个是 AOT 约束台账）由此被结构性堵住。

---

## 4. 文档怎么写（E2）

### 4.1 前后对比

现状 `docs/DESIGN-TABLE-2026-10-26.md:230-234` 里那段「可直接抄」的样板：

```php
// 已漂移：现行 Table 没有这两个方法
Table::make()
    ->sortBy('name', ascending: true)
    ->onSortChange(fn(?string $key, bool $asc) => ...);
```

现行是 `->sort(TableSort $sort)` + `onSortChange(fn(TableSort $next): void)`。
这段代码至今没有被任何判据检查，新人照抄必然编译失败。

改后文档只留一句（示意，不写代码块）：

> 唯一可抄样板：tutorial 的 `DataStage`（链接指向该文件）；表格列定义见
> `TablePage::columns()`。本文不再复制可运行代码。

### 4.2 判据骨架（草案）

新增一条检查，扫根级文档里含 `::make(` 的 PHP 代码块，要求块内每个方法名
在 `api-doc-check` 的方法清单里存在，或该块显式标注 `@historical`：

```php
foreach (doc_php_blocks($root . '/docs') as $block) {
    if ($block->hasTag('@historical')) {
        continue;
    }
    foreach ($block->methodNames() as $name) {
        if (!ApiSurface::has($name)) {
            $missing[] = "{$block->file}:{$block->line}  `{$name}()` 不在现行 API 清单里";
        }
    }
}
```

与 `docref` 分工：`docref` 管链接与行号引用，这条管代码块内容。两者都进 `gate`。

---

## 5. 明确不做（以及何时重新评估）

> **v1 裁决，已被 §11 取代；以下原文仅作对照，不作为开工依据。**

| 不做 | 为什么 | 重新评估的条件 |
|---|---|---|
| `Projection` / `RowSpec` / `CommandName` 三本名册 | 一个读切片从 1 处变 3–4 处；名册与 `match` 两端手写，给 Store 加方法而两处不动时判据全绿 | E0 上线一个迭代后**持续命中**，且命中集中在「名字对不齐」而非「读点位置」 |
| `string` 常量 + 类常量表 | 比名册省，但仍要维护一份平行书写物；`select()` 闭包体里路径本来就在 | 同上，且证明常量表的 IDE 补全收益大于维护成本 |
| 独立的 dep-path 静态对账器 | 会与 `reactive-read` 形成两套同源判据；提取器直接作 E0 的零件即可 | 需要「读集上界」做别的用途时 |
| C2 自动追踪（读即订阅） | 页面省到 0 处，但 Store 要每个 getter 打点、`Subject` 通知要带 key、两个 Element 的 `rebuild()` 要补追踪窗口；依赖隐式、条件分支静默退订、调试看不到订阅了什么 | 先有可观测性配套（依赖图 / trace），且 E0 量出样板税是真实痛点 |
| 删掉或「可省略」`select()` | 零破坏是本方案的底线；`select()` 是显式降级口 | 不重新评估 |
| `bind()` 双向绑定 | 单向流已成立，控件只上报意图 | 不重新评估 |

成熟框架的对照结论（v1）：Compose / Vue / MobX / SolidJS 的「读即订阅」都配有
compiler metrics、devtools 或 `trace()`；React 的显式依赖数组配有
`exhaustive-deps`。**没有一家是「零书写 + 零诊断」**。v1 选的是
「显式书写 + 一条能咬红的 lint」，成本最低且可解释。

> **v2 修正（§11.4）**：这段只对照了「运行期自动追踪」一族，漏掉了
> Svelte / WinUI `x:Bind` / Vue 模板编译那一族 —— 它们靠**构建期生成**
> 同时做到「零书写」与「诊断可见」（生成物可读）。允许破坏兼容性后，
> 该族才是本仓该走的路线；「零诊断」不再是保留闭包的理由。

---

## 6. 落地顺序与判死条件

> **v1 裁决，已被 §11 取代；以下原文仅作对照，不作为开工依据。**

| 段 | 做什么 | 出口判据 |
|---|---|---|
| **E0** | `ReactiveRead` 规则 + 三个夹具 + `selftest` 期望登记 + `defaultRules()` 注册 + 一条盲区豁免登记 | 三条变异（裸读 / 补空接口 / builder 内裸读）全红；正确写法绿；全仓 `lint` 除登记豁免外 0 error |
| **E1** | 新增 `DemoStore` + `DataStage`，注册进 `TutorialPage` | `drift-codegen` 翻译通过、`smoke` 通过、`reactive-read` 对样例自身不报 |
| **E2** | 文档代码块方法名判据 + 清掉 `docs/` 里失效的可运行代码块 | 判据进 `gate`；`docref` 0 FAIL |
| **观察期** | 一个迭代，只记 E0 的命中数与命中分布 | 见下方判死条件 |
| **条件分支** | 命中集中在少数页面 ⇒ 只改那几页；命中普遍 ⇒ 才考虑第 5 节的候选 | 依据真实数据，不依据「24 处 select 看着烦」 |

### 判死条件

E0 必须先通过牙检（含「补空接口仍红」）证明**能咬红**。在此前提下：

- **一个迭代零命中** ⇒ 「漏订阅」不是真痛点 ⇒ **三名册、常量表、独立对账器、
  C2 全部不做**，本方案就此收口，只保留 lint + tutorial + 文档判据。省下整个重构。
- **有命中** ⇒ 命中就是真实样本，按分布决定局部修法还是全仓通道；仍不预先铺形状。

这条比「lint 零发现」强，因为牙检把「真没发生」与「规则没覆盖」区分开了。

---

## 7. 性能与后续编译器

> **v1 裁决，已被 §11 取代；以下原文仅作对照，不作为开工依据。**

**只后置调用开销，不后置信息密度。** `select(fn($s) => $s->page()->isRunning())`
保留了完整类型化的读路径，编译器将来有素材可优化；把它压成一个名字反而擦掉了素材。

同仓已经发生过一次「只换编译器、应用代码零改动就变快」：上游去虚化那批修复让
`typephp_call_method_cached` 从 1593 降到 1128（−29.2%），当天改动只落在 `docs/`。
所以性能不参与 API 形状裁决，且（v1 结论）**不上新形状 = 零铺开面 = 零迁移成本**，
将来真要改时最便宜。

> **v2 修正（§11.2）**：「性能后置」只适用于调用开销，不能推出「形状不动」。
> 闭包黑盒导致「运行时才第一次知道读了什么」，这是**信息可达性**而非快慢，
> 编译器补不回来。用户授权破坏兼容性后，形状该按 §11 改。

仍需在写代码时守住的硬约束（这些不是「慢」，是编不过或数值偏，编译器补不回来）：

- AOT 构建禁用的语句形态（如顶层 `Stmt_If`）、同一变量换类型赋值；
- `int / int` 静默整数除法；
- `mixed` / `callable` / 真实混合联合仍退化成 `php::Var`；
- 名字一旦铺开，改名就是源码迁移。

---

## 8. 验证清单

| 阶段 | 必跑 |
|---|---|
| E0 | `php tools/lint.php`、`php tools/lint.php --selftest`、`php tools/gate.php --fast` |
| E1 | `php tools/drift-codegen.php`、`php tools/smoke.php`、全量 `gate` |
| E2 | `php tools/docref.php`、文档判据、`git diff --check` |
| 收口 | 全量 `gate`（28 项）、T1 codegen、T2 `drift-build --app users` + `probe-window` |

E0 / E1 / E2 都只碰 `tools/` 与 `apps/tutorial/`，不改 `src/`，按 T0 档；
E1 若动到 tutorial 的构建，补一次 T1。

---

## 9. 与其他文档的关系

| 文档 | 关系 |
|---|---|
| `docs/REVIEW-UI-DATA-2026-11-11.md` | 现状评审。问题清单与证据链仍有效；其 §3.1 / §3.2 两条 P0 正是本文 E0 / E1 / E2 的来源 |
| `docs/DESIGN-UI-DATA-CONTRACT-2026-11-11.md` | 「三本名册」设计稿。**推进顺序与形状裁决已被本文取代**，保留作方案空间记录 |
| `docs/REVIEW-UI-DATA-CONTRACT-2026-11-11.md` | 四轮推演分析（§8–§11）。裁决依据、AOT 台账核对、代码对照仍有效；**结论以本文为准** |
| `docs/AOT-CONSTRAINTS.md` | 硬约束与性能阶梯的现行台账，本文 §7 的依据 |
| `docs/REVIEW-UI-DATA-2026-11-11.md` §11 / §12 | **v2 的主要依据**：方案空间（B/C 族）与六个跨框架坑；§11.4 逐条给出结构性消除 |

前三份文档顶部均已加一行指向本文，避免再按旧顺序开工。

---

## 10. 诚实边界

- 本文**未改 `src/`、未新增 lint、未碰 gate、未提交**；所有新代码均为设计草案。
- §1 的「现状」片段逐字取自 HEAD `5f26dfc`（`UsersPage`、`UserStore`、
  `TablePage::columns()`、`UsersApplication::commands()`），为聚焦形状略去了说明性注释。
- §2 的 `readPoints()` / `insideSelectClosure` / `insideBuilderScope` 等接口现不存在；
  `serves(): 'G2'` 是占位编号，落代码时按 §16.6 表裁决；CLI 输出里的行号是格式示意。
- `ReactiveRead` 的 36/37 可提取率是静态探针结果，不等于规则覆盖全部真误用。
- `DemoStore` / `DataStage` / `DemoCommands` / `TutorialApplication` 的改动均为草案，
  未验证编译；`api-doc-check` 方法清单的具体形状也未实现。
- 未跑 P1 前置实验、`bin --dry` A/B、T1 / T2；按 T0 档。
- v2（§11）同样未改 `src/`；所有 v2 代码均为设计草案，未编译。
- `drift-codegen` 生成 PHP 的通道仍未验证；`ViewBinding` 协议与「生成物进不进 git」
  两项已在 §11 按 v2.1 重裁（改用 `BindingHost`、生成物进 git），本行是 v1 时的记录。

---

## 11. v2：借鉴成熟框架坑的破坏性方案（最终裁决）

> 依据：用户反馈「最新方案感觉回头了，之前分析的成熟 GUI 框架的坑没有借鉴」，
> 并授权「可以有兼容性破坏」。本节取代 §5–§7 与 §10 的 v1 裁决。
> 证据来源：`docs/REVIEW-UI-DATA-2026-11-11.md` §11（方案空间）、§12（框架坑）；
> `docs/REVIEW-UI-DATA-CONTRACT-2026-11-11.md` §8（dep-path 探针）、§10.3（Element 窗口更正）。

### 11.1 v1 为什么是回头：三条实证

| # | 证据 | v1 做了什么 |
|---|---|---|
| 1 | 评审稿 §12.4 明写「**别因为字符串声明会拼错就退回闭包**：WPF 的答案是加 lint 再升级到强类型生成，不是把回调请回来」；还写「别照抄 Flutter 的闭包密度」 | v1 §0 把「页面代码一行不改 / `select()` 永久保留」立成底线，正是把回调请回来并锁死 |
| 2 | §12.3 列出六个跨框架重复出现的坑 | v1 只处理 #4（可用态漏刷新 ⇒ `reactive-read`）；#1 名字拼错、#2 新对象 / 门控、#3 行身份、#6 窗口外读全被归入「不做」或「条件分支」。**另有 90 处 `onChanged(callable)` 写通道闭包一次都没进方案** —— v2.1 把它登记为 P2，不在本轮破坏面内（§11.5.4），但至少不再假装它已是数据 |
| 3 | 分析稿 §9.3 自己立的决策表：「**不可后置**＝正确性可观测性，现在就要有判据」「**半可逆**＝信息密度，现在定」 | v1 §7 拿「只后置调用开销，不后置信息密度」当**不换形状**的理由，却把评审稿 §11.6 四条信息密度判据（`dead-field` / `cell-spec-exhaustive` / `projection-case` / AOT A/B）全部砍掉 |

**活体证据（本轮新查）**：`TableColumn::field(string $name)` 已在生产代码 11 处
（`apps/showcase/src/Pages/TablePage.php::columns()` 等），但 `textOf()` 只在
**运行期渲染到那一行**、`array_key_exists` 失败时才 `Errors::report`
（`src/Widget/TableColumn.php:372-380`）；`tools/Lint/Rules/` 里**没有 `dead-field`**。
也就是 WPF 头号坑「`{Binding Nmae}` 拼错不报错」在本仓是活着的，且更隐蔽：
构建期全绿、`php -l` 全绿，要真机起窗并滚到那一行才响。

⇒ v1 的「三件仪器」不是借鉴框架，而是**用 lint 补一个框架本来就该在构建期
看见的信息缺口**，同时把补缺口的通道封死。

### 11.2 「性能后置」推不出「形状不动」：混淆了两类主张

上一轮把 §12.1 那句共识——「让编译器／框架看见数据，别让运行时才第一次知道」
——当成了**性能主张**，于是用「性能可后置」把它一起撤掉。这是分类错误。

| | 内容 | 编译器能否事后补 |
|---|---|---|
| 调用开销 / 去虚化 / 分配 | L09 接口方法 1.96×、L10 闭包 2.04× | **能**：上游 #183–#186 只换编译器，`typephp_call_method_cached` −29.2%，`src/` 一行没动 |
| **运行时才知道读了什么** | `\Closure` 黑盒 ⇒ 漏订阅 / 名字拼错 / 快照读只能事后抓 | **不能**：这是源码形状决定的信息可达性，不是快慢 |

⇒ 允许破坏后，R1 → R2 → R3 这一跳该走，且**不受「性能可后置」影响**。
反过来，v2 的生成物是**类型化的静态 PHP**（`deps()` 返回方法调用的结果，不是字符串表），
信息密度与手写闭包**持平**，多出来的是两样编译器拿不到的东西：构建期可读、
开发者不用写。

> ★ 初稿写「生成物信息密度比闭包更高」，那是错判，已撤（评审稿 B2）：
> 同一组读点写成闭包还是写成生成的 `deps()`，tpc 看到的东西一样。
> 真正的增益是「人不用写」和「生成期能看见」，不是「素材更多」。

### 11.3 关键区分：书写形状 vs 框架内部数据平面

v1 把两者混成一件事，用「不动书写形状」推出「框架内部也不动」，这才是回头的实质。

| 层 | 谁看得见 | 破坏性 | v2 裁决 |
|---|---|---|---|
| **书写形状** | 开发者 | 不可逆、DX 敏感 | **可以改**（用户授权）；目标是更少写、更难写错 |
| **框架内部数据平面** | lint / codegen / tpc | 开发者不可见，可逆 | **必须重做**：把闭包里的数据抽成构建期可见的事实 |

成熟框架动的都是第二层：Svelte 模板编译成 `if (changed.b) set_data(...)`、
WinUI `x:Bind` 生成强类型代码、Vue 模板编译成 render function、Compose Snapshot 记读。
开发者写的是**普通代码**，框架在编译期把「读了什么」变成数据。
本仓没有编译器插件（C3 不选），但有 `drift-codegen` 这道已存在的 T1 门 ⇒
可以把这一步做成**构建期生成**，而不是运行期记账。

#### 11.3.1 为什么可以先于 B 族做（回应评审稿 §11.4 的「最后做」）

评审稿 §11.4 对 C1 的原裁决是「最有长期价值**却该最后做**」，理由只有一条：
**B 族还没落地就先做 C1，等于给未验证的形状写生成器**。v2 初稿换了论据
（「本仓有 T1 门」）却没回应这条，属于选择性引用（评审稿 B5）。v2.1 补上：

| 评审稿的顾虑 | v2 的实际形状 | 判断 |
|---|---|---|
| 生成器会锁定未验证的**行/列协议** | 生成物不碰行、不碰列。行仍是 `array<string,scalar>`，列仍是 `TableColumn`，生成物只产出 `deps()` + `build()` 转发 | 顾虑不适用 |
| 生成器要等 B 有真实消费者 | `select` + `builder` 这个形状已有真实消费者：`apps` 里 9 个文件、25 处手写调用，生成的是同一个形状 | 消费者已有 |
| 形状会随 B2（`CellSpec`）/ B3（枚举投影名）变 | 这两族是**列与切片的富化**，与本层正交；它们落地后 `deps()` 不用改 | 正交 |

⇒ 可证伪的下界放在 G0：读集复现不了、或「漏一个读点」抓不出来，G2 就不开工。
B2 / B3 明确**不在本轮**。

### 11.4 六个坑 → 结构性消除（不再靠 lint 事后抓）

| §12.3 坑 | 成熟框架的教训 | v2 的结构性消除 |
|---|---|---|
| #1 名字拼错静默 | WPF `Binding` 只在 Output 打 trace；WinUI `x:Bind` 用编译期生成修掉 | 字段名集中在**一句 `FIELDS` 常量**里（字段 ⇒ 展示名），`field('x')` / `rowKey('x')` 的字符串两端不同源：一端常量、一端字面量 ⇒ `dead-field` 构建期对账，避开 §8 判定的同源漏洞。**不做类型推导**：`ClassTable` 只有类关系、没有方法签名表，跨方法推 `list<Row>` 是 `x:Bind` 级的活（评审稿 B6），本轮不走 |
| #2 selector 返回新对象 ⇒ 白重建 / 不重建 | Redux `useSelector` 文档警告、Flutter `Selector` 要求不可变 | 行保持 **`array<string,scalar>`**：PHP 数组的 `===` 是逐键 + 逐值 + 类型全等的**值比较**，天然稳定。初稿的 `final readonly class Row` 是错的 —— `readonly` 只约束「属性初始化后不可改」，**不改变比较语义**，对象 `===` 仍是身份比较，`array<Row>` 与 `array<User>` 同级不稳（评审稿 A2，已撤）。这条同时解 §3.3 的门控盲区 |
| #3 行身份用 index | Flutter `ValueKey` / Compose `key` / React `key` / Qt 代理索引 | 行身份从闭包改成**字段名**：`->rowKey('id')` 替代 `keyOf(fn($r): int => $r['id'])`。字段名与 #1 同一份对账 ⇒ 拼错构建期红；仍是字符串常量，AOT 可静态取值。§66 两套身份收口为一套（**不做增量通知**，Qt `begin/endInsertRows` 的坑继续避开） |
| #4 可用态漏刷新 ⇒ 按钮永久灰 | WPF `ICommand` 漏 `RaiseCanExecuteChanged()` | 订阅由生成物按 `build()` 读集的**上界**接上 ⇒ 漏订阅写不出来。**上界有前提**：读点必须在 `build()` 方法体内、或经 `deps()` 可达；跨函数读点由 G0 报「静态不可提取」并要求逃生口（§11.8），不静默放过 |
| #5 细粒度通知的仪式成本 | Qt `begin/endInsertRows` 必须成对、WPF 跨线程异常 | **不做增量通知**，`Subject` 粗广播不变（这一条 v1 判对了，保留） |
| #6 自动追踪「读在窗口外」 | Compose Snapshot 记不到 `remember{}` / lambda 里的读，靠 compiler metrics 查 | **不引入运行期追踪窗口**（§10.3 已证 Stateless 没有窗口、Store getter 不过 `Subject`）；静态提取给**读集上界**，天然免疫条件分支退订；生成物是静态 PHP 文件，开发者能打开看订阅了什么，替代 Compose 的 metrics |

★ 与 C2 的分界线：C2 在**运行期**记账（有窗口、有退订、调试不可见），v2 在
**构建期**提取（无窗口、只有上界、产物可读）。§10.3 撤回 C2 翻案的结论不变，
但撤回的理由不该是「零诊断」，而应是「运行期记账这条路线本身有坑」——
Svelte / WinUI / Vue 选的都是构建期，不是运行期。

### 11.5 开发者怎么写（v2，完整可抄形状）

#### 11.5.1 原状（逐字取自 `apps/users/src/Pages/UsersPage.php:89-94`）

```php
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
    ->builder(fn(bool $running): Widget => Row::make()->spacing(8)->children([
        Button::primary('刷新')->enabled(!$running)->command('users.refresh'),
    ])),
```

写错时只差一层括号（把读点露在 `build()` 顶层），`php -l` / lint 全绿、
真机起窗才发现按钮永久灰。

#### 11.5.2 v2 页面：普通读，零订阅样板

```php
// apps/users/src/Pages/UsersPage.php —— 设计草案，未编译、未落地
final class UsersPage extends StatelessWidget
{
    public function __construct(private readonly UserStore $store) {}

    public function build(BuildContext $ctx): Widget
    {
        $running = $this->store->page()->isRunning();
        $query   = $this->store->query();

        return Column::make()->spacing(8)->children([
            TextField::make()
                ->value($query)
                ->onChanged(fn(string $x): void => $this->store->setQuery($x)), // 写通道本轮不动
            Button::primary('刷新')->enabled(!$running)->command('users.refresh'),
            Table::make(self::columns(), $this->store->rows()),  // 列定义见 11.5.4
        ]);
    }
}
```

**开发者要在 `build()` 里新写的东西：零。** 读就是读，造控件就是造控件。

★ 「零新增书写物」这句要收窄（v2.1，评审稿 A3）：整张表仍要多写一句 `FIELDS`
常量（§11.5.3），那是**数据**、不是订阅样板，且只在这张表用 `field()` 时才需要。
初稿把它写成无条件成立，与自己的 11.5.3 打架。

写通道保留闭包（`onChanged(callable)` 90 处不动）：理由见 11.5.4，不是遗漏。

所有 `build()` 默认被扫描，不引入 `@view` 之类注解（AOT 未验属性解析）。
代码中 `Table::make()` / `TableColumn::make()` / `TextField::make()->value()` /
`Button::command()` 与现状逐字同形。

#### 11.5.3 v2 行：标量数组 + 一句字段清单（解掉 #2 / #3）

```php
// 设计草案：一句常量，不新增类层级
final class UserRows
{
    /** @var array<string, string> 字段 ⇒ 展示名；dead-field 的事实源 */
    public const FIELDS = [
        'id'     => 'ID',
        'name'   => '姓名',
        'email'  => '邮箱',
        'city'   => '城市',
        'status' => '状态',
    ];
}
```

行本身仍是 `array<string, scalar>`，`Table::make(array $columns, array $rows)` 的
现状签名不动：

```php
$rows = [
    ['id' => 1, 'name' => '张三', 'email' => 'z@a.cn', 'city' => '杭州', 'status' => 'active'],
    // …
];
```

**为什么不是 `final readonly class UserRow`**（初稿形状，已撤）：

| 判据 | `array<string,scalar>` | `readonly class UserRow` |
|---|---|---|
| `===` 语义 | 逐键 + 逐值 + 类型全等的**值比较** | **身份比较**（`readonly` 不改比较语义） |
| `field('name')` | 现成：`textOf()`（`TableColumn.php:357`）就按数组下标取 | 要动态属性（AOT 退化）或 `match` 取值（字段表回到两处手写） |
| `editable()` / `copyText()` | 零改动 | 四处连锁：`editableText():283`、`TableContract:168`、`TableState:954`、`textOf():361` 的 `is_array` 守卫 |
| AOT | 标量数组，无装箱 | 对象 + 属性访问 |
| 书写量 | 0（本来就是数组） | 每张表 +10 行 |

★ 这处是 v2 初稿最硬的事实错误：`readonly` 只约束「属性初始化后不可改」，
**不提供值相等**。两个内容相同的 `new UserRow(1,'a')` 用 `===` 比是 `false`，
所以「全标量值对象 ⇒ `===` 结构稳定」不成立，坑 #2 一个都没消除。

#### 11.5.4 v2 列与写通道：字段名进对账，写通道降 P2

```php
// 设计草案：行身份改字段名；cell(callable) 降为逃生口
Table::make([
    TableColumn::make('id',    'ID')->field('id')->sortable(),
    TableColumn::make('name',  '姓名')->field('name')->sortable()->editable(),
    TableColumn::make('email', '邮箱')->field('email'),
    TableColumn::make('status','状态')->cell(static fn(array $row): Widget => /* 派生值 */ ...),
], $rows)
   ->rowKey('id');          // ← 替代 keyOf(fn(array $r): int => $r['id'])
```

三个变化，都是「闭包 → 字段名」：

| 现状 | v2 | 为什么 |
|---|---|---|
| `->keyOf(fn(array $r): int => $r['id'])` | `->rowKey('id')` | 字段名可对账、AOT 可静态取值；闭包是黑盒 |
| `->field('id')` | 不变 | 本来就数据化，只是现在有 `dead-field` 守着 |
| `->cell(fn(array $r): Widget => …)` | 不变 | 派生值 / 富单元格的逃生口，全仓 3 处，不消灭 |

**写通道（`onChanged` / `onSelectionChange`）本轮不动，登记 P2。** 初稿把它列成
「与读通道一并收编」，v2.1 撤回，理由三条：

1. **粒度不匹配**：`command('users.refresh')` 是全局动作，字符串名与路由表天然对齐；
   `onChanged` 的作用域是**控件实例**，字符串名是**全局路由**。
2. **装不下行编辑的参数**：最需要它的是 `TableColumn::editable()` 的行内编辑，
   提交时要 `(哪一行, 哪一列, 新文本)` 三元组，而 `CommandArg` 只有 `i` / `s` 两槽；
   摊成三个参数就要改 `CommandHandler::handle(string, CommandArg)` —— 那是 83 处
   `command()` 的共同签名。
3. **热路径 + 收益低**：每按键一次路由查表 + `handle()` 分派，而现在是一次直接调用；
   换来的只是「拼错构建期红」，而 `onChanged` 拼错的后果本来就是「没反应」，
   不是 `field()` 拼错那种「整列空白还没人查」的难查错误。

⇒ 等 `Table` 行编辑的真实参数形状定下来再收编，不在 G1–G3 范围内。
`CommandArg` 本轮的形状因此不变（`none()` / `id(int)` / `key(string)`）。

#### 11.5.5 生成物：一个 `BindingHost`，不是协议空壳

初稿的 `ViewBinding::pageOf(UserStore $store): UsersPage` 有两个硬问题（评审稿 A5）：
**谁持有订阅？谁把 Store 递给绑定？** 页面是 `Application` 造的，不由绑定造；
Element 层也拿不到私有构造参数里的 Store（AOT 无反射）。
改成「生成一个 `StatefulWidget`」，两个问题一起消失：

```php
// apps/users/src/Generated/UsersPageBinding.php —— drift-codegen 生成，勿手改
final class UsersPageBinding extends BindingHost
{
    public function __construct(
        private readonly UserStore $store,
        private readonly UsersPage $page,
    ) {}

    /**
     * 事实源：UsersPage::build() 的读路径，**上界**（多订阅、不漏订阅）。
     * 返回方法调用的结果，不是字符串表 —— 信息密度与手写闭包相同。
     */
    public function deps(): array
    {
        $s = $this->store;

        return [$s->page()->isRunning(), $s->query(), $s->rows()];
    }

    public function view(BuildContext $ctx): Widget
    {
        return $this->page->build($ctx);
    }
}
```

`BindingHost` 是框架侧唯一的新零件（`StatefulWidget` + `SubscribingWidget`）：
内部就是现在的 `SubjectBuilderState` —— 一个 `State` 持有 `Subscription`、
比 `deps()` 的输出、不等才 `markDirty()`，`build()` 调子类的 `view()`。
`SubjectBuilder::select()` / `builder()` 从**公共面**移除（`@internal`），
app 代码不再出现它们。

★ `deps()` 的实现约束：里面只允许调**有缓存 / `Computed` 的读方法**。
若 `rows()` 每次都 `array_map` 出新数组，虽然标量数组 `===` 是值比较，
但每次比较都是 O(行数)，且生成物看不出来 —— 这条要在 G0 的读契约清单里标出来
（每个读点标注「是否走缓存」）。

★ **必须诚实写：`SubjectBuilder` 没有被删掉，它下沉成了 `BindingHost`。**
「删 `select()`」删的是公共 API，不是订阅机制本身 —— 订阅总得有个 `State` 持有，
而 `StatelessWidget` 没有 `State`、`StatelessElement::rebuild()` 中间没有钩子。
初稿写「删掉闭包入口」暗示框架里也没有等价物，那做不到。

★ **判据连锁**（`SubscribingWidget` 是空标记接口）：`dead-subscription` 的现行判据是
「`build()` 体内出现对 `Subject` 的方法调用 ⇒ 必须实现 `SubscribingWidget`」。
生成的 Binding 把读点放在 `deps()`、`build()` 只转发 ⇒ **旧判据抓不到它**。
G2 要同步把判据扩成「`build()` 或 `deps()` 体内」，否则这条 lint 对生成物是假绿。

**门控粒度是一次明确的取舍**（评审稿 B1），必须写进方案：

| | v1（现状） | v2 |
|---|---|---|
| 订阅数 | 4 个切片，各自门控 | 1 个，整页 |
| 改搜索字 | 只重建输入框那一支 | **整页重建** |
| 失效模式 | 漏订阅 ⇒ 永久旧值 | 不漏订阅，但粒度粗 |

这个代价属于**重建工作量**，不是调用开销 —— 编译器补不回来（上一轮 §9 把它归到
「不可后置」是对的）。`Element::reuseChild()` 里 `widget !== newWidget` 恒真
（`src/Widget/Element.php:589`），所以整树是**真的**全 update + rebuild。
⇒ G2 出口必须用量化判据（§11.6），不能留到 G4。

生成物参与 `php -l` / lint / `drift-codegen --dry` / tpc 翻译，
是 tpc 看得见的静态 PHP，不是 `php::Var` + 动态分派。
**Compose 靠 compiler metrics 回答「订阅了什么」，本仓靠打开这个文件。**

#### 11.5.6 写错了会怎样

| 写错 | v2 反馈（生成期 / `gate`） |
|---|---|
| `field('nmae')` | `dead-field`：`字段名拼错，UserRows::FIELDS 只有 id,name,email,city,status` |
| `rowKey('nmae')` | 同上（同一次对账，同一份 `FIELDS`） |
| 读点写在 `build()` 之外（辅助方法里读 Store） | 提取器报「读点跨函数，静态不可提取」⇒ 二选一：搬进 `build()`，或用逃生口（§11.8） |
| 把读点塞进 `onPressed` 回调 / 闭包里 | 同上（跨函数，报红） |
| `command('users.refersh')` | `dead-command` 已守，不变 |
| 忘记订阅（v1 的事故形态） | **写不出来**：没有 `select()` 可漏 |
| `onChanged` 写错 | 本轮不变（仍是闭包），拼错的表现是「运行时没反应」——已知的、登记在 P2 的缺口 |

### 11.6 破坏面与迁移顺序

实测调用数（`grep -rc -e "->method(" --include=*.php apps src tests`，2026-11-12 复核）：

| 入口 | 全仓 | apps | v2 处理 |
|---|---:|---:|---|
| `select(` | 57 | 25 | 公共面删除，由生成物接替；**保留为逃生口**（§11.8） |
| `builder(` | 45 | 23 | 同上 |
| `keyOf(` | 27 | 3 | 改成 `rowKey('字段名')` |
| `cell(` | 3 | 1 | 不动（富单元格 / 派生值的逃生口） |
| `field(` | — | — | 不动；新增 `dead-field` 对账，`editable()` / `copyText()` 随之受益 |
| `onChanged(` | 90 | 43 | **本轮不动**，登记 P2（§11.5.4） |
| `onSelectionChange(` | 5 | 1 | 同上 |
| `command(` | 83 | 17 | **不动**（已是名字） |

★ 口径必须写清（初稿没写，评审稿 C4）：`select(` 的 57 = apps 25 + src 16 + tests 16，
其中 `src/Widget/Select.php` 的 10 处是**下拉控件**的同名方法，不属于要删的订阅入口。
所以「全仓 57」不等于「要改 57 处」，真实迁移面是 **apps 的 25 处**。
同理 `builder(` 的全仓 45 里有 22 处在 src/tests。

★ 初稿把 `field()` / `editable()` / `copyText()` 漏在表外，但它们是「行形状」的实际
消费者：初稿换对象行会连锁打断四处（评审稿 A1）。v2.1 行保持数组，这四处零改动，
所以表里补一行说明「不动」——**不动本身也是一条要登记的结论**。

`drift-codegen.php` 现状：`file_put_contents` 只写生成的 `project.yml`
（`:183-193`），**没有生成 PHP 源码的通道** ⇒ 要新建一层，不是拼零件。

★ 生成层的接线有三条硬约束（初稿漏了，评审稿 A4）：

1. **生成步骤必须在 `collectSources()` 之前、同一次进程内完成**。那道门要求
   「枚举数 = tpc 自己数到的数」双向严格相等（`tools/drift-codegen.php:397-399`）；
   生成物落在 `apps/users/src/` 下时，第一次运行不存在、第二次存在，
   两次都可能判红。⇒ 顺序是「generate → collectSources → tpc」。
2. **生成物不能落 `_codegen/`**：`removeTree($buildDir)` 每次删。
3. **进 git**，并配一条「生成物与输入一致」的判据（`build-stale-check` 第 10 条）。
   不进 git 则 fresh clone 上 T0 档（`php -l` / lint）会因为缺类而红，
   而 T0 是每次提交都跑的。代价是手改生成物要靠判据抓。

| 段 | 做什么 | 出口判据 |
|---|---|---|
| **G0** | 把 §8 探针正式化成 `DepExtractor`，产物只写**读契约清单**（不接运行） | ① 对真实 `UsersPage::build()` 复现读集（`page.isRunning` / `query` / `rows`）；② 变异牙检：把 `$s->query()` 挪进辅助方法 ⇒ 必须报「跨函数不可提取」；③ 裸读 / 补空接口 / builder 内裸读三条旧变异仍红 |
| **G1** | `dead-field` + `UserRows::FIELDS` + `rowKey('id')`，先改 1 个 app | `gate` 全绿；`TablePage` 列定义零拼错；`field('nmae')` / `rowKey('nmae')` 变异必须红；`editable()` 提交路径零改动 |
| **G2** | `drift-codegen` 新增生成 PHP 层 + `BindingHost`，`UsersPage` 灰度 | T1 翻译通过、T2 真机构建、`probe-window` 0 FAIL；生成物指纹纳入输入；**重建节点数判据**：改一个字符 ⇒ 重建节点数 ≤ 现状的 K 倍（K 现场量定，先留空） |
| **G3** | 其余页面与 tutorial `DataStage` 迁移，删公共 `select()` / `builder()` | 全仓 0 处公共入口，**除登记豁免的 N 处**（唯一真盲区 `watermark/StatusBar::slice()`）；tutorial 有可抄样例且参与编译 |
| **G4** | `bin --dry` A/B 只验「没变慢」（用户前提：性能后置） | 口径必须 `bin`，`lib` 不去虚化 |

**G0 是判死口**，但口径换了（初稿是「复现 36/37」，评审稿 C2）：36/37 是**闭包内**
读点的可提取率，v2 要扫的是**方法体**，样本不同。新判据是「对真实 `build()` 复现读集」
+「漏一个读点必须红」。若这两条都做不到 ⇒ 停在 G0/G1，不生成订阅表（回落到 v1 的三件仪器）。
这比 v1「零命中就不做」更硬：它验的是「框架能不能看见」，不是「开发者有没有写错」。

### 11.7 与 v1 三件仪器的关系：升级，不是丢掉

| v1 | v2 |
|---|---|
| E0 `reactive-read` lint | **升级成 G0 的 `DepExtractor`**：同一份 token 分析，从「报错」变成「生成读契约」 |
| E1 tutorial `DataStage` | 保留，样例改成 v2 形状（普通读 + 标量行 + `FIELDS` + `rowKey`），参与编译 |
| E2 文档代码块判据 | 保留，扩成「六坑 → 判据」对照表，防止再次丢失 |
| 判死条件「零命中 ⇒ 全不做」 | **撤下**：零命中只说明没写错，不说明框架看得见；改用 G0 牙检 |

### 11.8 诚实边界

- 本节**未改 `src/`、未新增 lint、未碰 `gate`**；所有代码均为设计草案，未编译。
- **`SubjectBuilder` 不删**：公共 `select()` / `builder()` 移除，机制下沉为 `BindingHost`
  （§11.5.5）。订阅必须由 `State` 持有，这一步绕不过去。
- **`select()` 保留作逃生口**（v2.1 裁决，替代初稿的「二者择一待裁决」）：给静态提取
  不到的读点用，唯一真盲区是 `watermark/StatusBar::slice($s)`。G3 的「0 处」因此
  带豁免名单，不是绝对零 —— 初稿 §11.8 与 G3 判据互斥，已改。
- `DepExtractor` 的 36/37 是**闭包内**读点的探针结果，不是 `build()` 方法体的；
  G0 要用后者重测。36/37 只作为「零件可用」的证据，不作为 v2 的可行性证据。
- **`dead-field` 不做类型推导**：`tools/Lint/ClassTable.php` 只有类关系（133 行），
  没有方法签名表与返回类型 ⇒ 跨方法推 `list<UserRow>` 是 `x:Bind` 级的活。
  本轮走「`FIELDS` 常量 ↔ 字面量」两端对账，代价是表要显式声明字段清单。
- **生成物进 git**，配一条「生成物与输入一致」的判据；不进 git 会让 fresh clone 的
  T0 档（`php -l` / lint）因为缺类而红。IDE 跳转断链 / 调试栈跳到生成代码，
  两条维护面仍未验。
- **门控粒度是整页**，不是切片：改一个字符会重建整页（§11.5.5）。这是明确取舍，
  G2 出口必须量重建节点数，不能等 G4。
- AOT 硬约束照旧：生成物必须避开 `mixed` / 顶层语句 / `switch` / `int/int` /
  双引号串里裸 `$`（`docs/AOT-CONSTRAINTS.md` §1.2–1.3）。
- 不做增量通知、不做运行期读记账、不做双向绑定；`Subject` 粗广播不变。
- `BindingHost` / `DepExtractor` / `CellSpec` 本仓现无；`onChanged(name)` 不在本轮
  （登记 P2）；`CommandArg` 本轮形状不变。
- 未跑 T1 / T2 / `bin --dry` A/B；按 T0 档。
