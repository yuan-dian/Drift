# 设计方案 · UI 与业务数据契约 · 2026-11-11

> ★ **本文的「三本名册」形状与 P0a–P5 推进顺序已被取代**，保留作方案空间记录。
> 最新落地方案见 `docs/DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` **§11（v2）**：
> 用户授权允许破坏兼容性 ⇒ 改为「普通读 + 构建期生成订阅」，本稿形状不再采用。
> 状态：**设计稿**（未落地任何代码）· 基线 HEAD `5f26dfc` · 上游编译器 `D:/TypePHP/typephp/bin/tpc.php`（`bff46481`）
> 上游结论依据：评审稿 §1（现状）、§3（高信号问题）、§11（闭包之外）、§12（成熟框架），
> 以及 `docs/AOT-CONSTRAINTS.md` 的现行台账。
> 本文只回答一件事：**把评审结论变成一套能落地的形状**。与评审稿冲突处以本文为准。
> 代码块是**设计草案**，签名对齐现有类，但未编译。

---

## 0. 一句话

> **三处闭包，换成三本名册。**

| 位置 | 现在 | 换成的「名册」 | 名册里装什么 |
|---|---|---|---|
| 读 | `select(fn($s) => $s->visible())` | **`Projection`**（每 Store 一个 enum） | 切片的名字 + 该切片的版本号 |
| 行 | `keyOf(fn)` / `cell(fn)` / `copyText(fn)` | **`RowSpec`**（每行型一个 final 类） | 列名 → 文本、行 → 身份 |
| 写 | `onChanged(fn($x) => $store->setQuery($x))` | **`CommandName`**（每 handler 一个 enum） | 写动作的名字 |

三者的共同性质不是「都是对象」，而是**四条同时成立**：

1. **可枚举** —— `cases()` / `columns()` / 路由表，运行期能列出全部；
2. **静态可见** —— `token_get_all` 看得见，lint 有判据；
3. **AOT 可直调** —— 具名类方法 / enum 常量，不是 `php::Var` 里的闭包；
4. **漏一个会响** —— `match` 无 `default`、挂载期 `TableContract::assert()`、lint 变异牙检。

评审稿 §11 说「代码降级成数据」、§12 说「让编译器看见数据」——
本文把这两句落成同一条可执行判据：**凡是要跨进控件的值，先有一个名字。**

---

## 1. 五条设计公理

从评审稿 §6 与 §12.4 提炼，后面每个决定都可以回溯到其中一条：

| # | 公理 | 否证它的写法 |
|---|---|---|
| A1 | **判据落在生成物**，不落在源码长相 | 「这里看起来像闭包，所以慢」—— SwiftUI 也是闭包 |
| A2 | **闭包留在语法糖层，契约层不许有闭包** | 把 `select()` / `cell()` 从 API 上删掉（DX 归零） |
| A3 | **一份数一个名字** | 投影值存一份、版本号另存一份且能各自漂移 |
| A4 | **漏写必须响**：构建期（挂载 / 每次 build）或 **lint**；`match` 无 default 只是**运行期**兜底，不是编译期保证（lint 必须在 `gate` 里才有牙） | 「运行到那一态才炸」的 `fold` 缺键 |
| A5 | **新通道必须先证明旧通道覆盖不了** | 为「好看」再造一条并行数据流 |

---

## 2. 总体形状

```text
        ┌──────────── 读 ────────────┐
Store ──┤ projection(P::Visible)     ├──► Projected{value, version}
        │  ├ 值缓存 + 版本号（一份）  │        │
        │  └ 变化比较只在这里发生一次  │        │ version 变了才重建
        └────────────────────────────┘        ▼
                                        SubjectBuilder::project(P::Visible)
                                               │
        ┌──────────── 行 ────────────┐        ▼
RowSpec ┤ keyOf / textAt / columns   ├──► Table::of($spec, $rows)
        └────────────────────────────┘
        ┌──────────── 写 ────────────┐
enum ───┤ UserCommand::Refresh       ├──► CommandRouter::on(…)
        └────────────────────────────┘
```

三条通道**没有新增数据流**：`Subject` 仍是唯一广播源，`CommandRouter` 仍是唯一写入口，
`Table` 仍是唯一表格控件。新增的只是「每条通道上多了一个名字」。

---

## 3. 读侧：`Projection`

### 3.1 形状

```php
namespace Drift\State;

/** 投影名册的标记接口：各 Store 用 enum 实现（enum 可实现接口） */
interface Projection
{
}

/** 一次投影的结果：值 + 它自己的版本号（A3：一份数一个名字） */
final class Projected
{
    /**
     * @param mixed $value      投影值（`mixed` ⇒ AOT 下仍是 `php::Var`，见 §12.2 F4）
     * @param int   $version    投影自己的变化计数：**只在值真的变了**才 +1
     * @param int   $computedAt 算这个值时的 Store 版本（缓存有效性）
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int $version,
        public readonly int $computedAt,
    ) {
    }
}

// ★ 不 extends Versioned：Subject 已实现，重复声明会让人以为有第二个版本号来源（§12.3 F9）
interface Projectable
{
    public function projection(Projection $p): Projected;
}
```

Store 侧：

```php
enum UserProjection: string implements Projection
{
    case Query   = 'query';
    case Visible = 'visible';
    case Page    = 'page';
    case Running = 'running';
}

final class UserStore extends Subject implements Projectable
{
    /** @var array<string, Projected> */
    private array $projCache = [];

    public function projection(Projection $p): Projected
    {
        $key = $p->value;
        $cached = $this->projCache[$key] ?? null;

        // ★ 先查缓存：Store 版本没动 ⇒ 直接给上一次的实例（值身份稳定）
        if ($cached !== null && $cached->computedAt === $this->version()) {
            return $cached;
        }

        // ★ 再取值。**不能**写成 cached($p, $this->visible()) —— 实参在进函数前
        //   就算完，缓存短路失效，等于把 N 次计算从 SubjectBuilder 搬进 Store（§12.1 F1）
        $value = match ($p) {
            UserProjection::Query   => $this->query,
            UserProjection::Visible => $this->visible(),
            UserProjection::Page    => $this->page->value(),
            UserProjection::Running => $this->page->isRunning(),
        };  // ★ 无 default：漏一个 case ⇒ **运行期** UnhandledMatchError（非编译期，§12.2 F6）

        // ★ 值没变 ⇒ 复用旧值实例、版本不推（订阅者只比版本，O(1)）
        if ($cached !== null && self::sameProjection($cached->value, $value)) {
            return $this->projCache[$key] = new Projected($cached->value, $cached->version, $this->version());
        }

        return $this->projCache[$key] = new Projected(
            $value,
            $cached === null ? 1 : $cached->version + 1,
            $this->version(),
        );
    }
}
```

用法（`UsersPage` 的三个切片各一行）：

```php
SubjectBuilder::make($this->store)
    ->project(UserProjection::Running)
    ->builder(fn(bool $running): Widget => /* 原样 */);
```

### 3.2 为什么不是「把闭包包一层」

`Projection` 的收益**不在写法短**，在三个可验证的性质：

- **AOT**：`UserProjection::Visible` 是 enum 常量，`projection()` 是类方法
  ⇒ 消掉的是 **`select` 闭包的构造**（`php::newClosureWithParameters`）
  与**一次对闭包的动态派发**。
  ★ **值本身仍是 `mixed` ⇒ `php::Var`**，`builder()` 收到的还是 `mixed` ——
  收益是「少一次闭包构造 + 一次动态派发」，**不是**「值变成静态类型」。
  评审稿 §1.4 数过：`UsersPage.cc` 里 4 个闭包构造 + 7 处 `typephp_call_method_cached`，
  其中 `select` 的那几个是本节要消掉的。
  ★ `Projectable` 是接口，**多实现时同样去虚化失效**（与 `RowSpec` 同一个风险，见 §10 第 3 条）。
- **lint**：闭包体里读了什么，静态分析只能靠猜（`SubjectBuilder::select()` 的 docblock
  至今写着「框架编译期推导依赖的第一步」——那是注释里的计划）。`projection()` 的 `match`
  是**可数、可穷举**的，`projection-cases` lint 能直接判「少了一个 case」。
- **版本**：见 3.3。

### 3.3 门控：从「每个订阅者比一次值」到「投影比一次、订阅者比版本」

现在是：`notifyChanged()` ⇒ **每个** `SubjectBuilder` 各自跑一次 `select` 闭包 +
各自做一次 `===`（数组时是 O(n) 逐元素）。N 个订阅者 ⇒ N 次计算 + N 次比较。

改成：`notifyChanged()` ⇒ 投影缓存**惰性**算一次，用 `sameProjection()` 判变化，
**变了才** `version++`；订阅者只比 `version !== $seenVersion`（O(1)）。

这正好兑现评审稿 §2 里那句「`version()` 这个 O(1) 的现成信号没被订阅侧用上」，
且不需要改 `Subject` 的广播语义（推模式、粗广播、无 dep-table 全部不变）。

### 3.4 `array<User>` 盲区的正解：比行身份，不比深相等

评审稿 §3.3 的盲区是：`select` 返回 `array<User>`，`===` 按对象身份比 ⇒
每次 `visible()` 重算出新数组 ⇒ 判不等 ⇒ 重建；而 `sameContent()` 只看顶层对象，
数组里的变化反而报不出来。两个方向都错。

`sameProjection()` 对 `list` 用**行身份比较**，不是深比较：

```php
private static function sameProjection(mixed $a, mixed $b): bool
{
    if (\is_array($a) && \is_array($b)) {
        if (\count($a) !== \count($b)) {
            return false;
        }
        foreach ($a as $i => $v) {
            // 标量按值；对象按身份（实体必须不可变，见 §11 失败模式 2）
            if ($v !== $b[$i]) {
                return false;
            }
        }

        return true;
    }

    return $a instanceof Equatable && $b instanceof Equatable
        ? $a->equals($b)
        : $a === $b;
}
```

三个后果，都是想要的：

- `visible()` 因不相干字段变化而重算，但元素还是同一批对象 ⇒ **不重建**（消掉抖动）；
- 真取到新一页（hydrate 出新对象）⇒ 身份不等 ⇒ 重建（正确）；
- `array<int>` / `array<string>` 这类标量行天然按值比，`SelectPurity` 放行 `array`
  的理由从「`===` 是同键序同值」修正为「**投影缓存保证身份稳定**」——
  旧理由对 `array<User>` 不成立，这是评审稿 §3.3 指出的原话。

### 3.5 兼容

`select(callable)` **保留**，`project(Projection)` 是第二通道（A5：先证明覆盖不了）。
迁移按切片逐个来，一个页面可以一半 `project`、一半 `select`，不要求一次改完。

`select()` 的 docblock 要改一句：现在写「框架编译期推导依赖的第一步（可 lint）」，
那是计划；改成「闭包体静态不可见 ⇒ 只有 `project()` 能进依赖名册」。

---

## 4. 行侧：`RowSpec`

### 4.1 形状（Qt `data(role)` 的 PHP 版）

```php
namespace Drift\Widget;

interface RowSpec
{
    /** @return list<string> 这一行型声明了哪些列（挂载期与 TableColumn 对账） */
    public function columns(): array;

    /** 行身份：跨排序稳定（评审稿 §3.4 / §66 的数据身份） */
    public function keyOf(mixed $row): string|int;

    /** 统一取值协议：列名 → 文本 */
    public function textAt(mixed $row, string $column): string;
}
```

应用侧：

```php
final class UserRows implements RowSpec
{
    public static function make(): self { return new self(); }

    public function columns(): array
    {
        return ['name', 'email', 'status'];
    }

    public function keyOf(mixed $row): string|int
    {
        return $row instanceof User ? $row->id : 0;
    }

    public function textAt(mixed $row, string $column): string
    {
        if (!$row instanceof User) {
            Errors::report('UserRows 收到非 User 行：' . \get_debug_type($row));
        }

        return match ($column) {
            'name'   => $row->name,
            'email'  => $row->email,
            'status' => $row->active ? '启用' : '停用',
        };
    }
}
```

控件侧：

```php
// ★ 列定义与行一起给：与 make() 的参数序一致，且列必须在构造期定好 ——
//   TableContract::assert() 在**每次 build** 时按构造期快照对账（§12.3 F5）
Table::of(UserRows::make(), [
    TableColumn::make('name', '姓名')->sortable(),
    TableColumn::make('email', '邮箱'),
], $rows);
```

### 4.2 与 `TableColumn` 的关系

| 现在 | 之后 | 说明 |
|---|---|---|
| `field('name')` | 保留 | 关联数组行的零成本路径，不强制上 spec |
| `cell(callable)` | **降为逃生口** | 富单元格（图标 / 进度条 / 按钮）仍用它；不参与 `textAt` 对账 |
| `copyText(callable)` | 保留 | `RowSpec` 的列默认取 `textAt()`，不必声明 |
| `keyOf(callable)` | **`Table::of()` 自带** | 二选一：给了 spec 就不许再 `->keyOf()`（同时给 ⇒ 挂载期报错） |

### 4.3 挂载期契约扩展（`TableContract::assert()`）

`TableContract` 是本仓比 Drift3 硬的地方（评审稿 §1.3），只加两条、不改既有四条：

1. `Table::of()` 与 `->keyOf()` 同时出现 ⇒ 报错（两个身份来源 = 必然漂移）；
2. 每个 `TableColumn` 的 `key` 必须出现在 `$spec->columns()` 里（除非该列用 `cell()`）
   ⇒ 拼错列名从「整列空白」变成挂载期 Fatal。

第 2 条正是评审稿 §12.3 坑 #1（WPF `{Binding Nmae}` 静默空白）在本仓的对应物。

### 4.4 AOT 收益怎么量（不预先声称）

`TableState` 每帧每行都会调一次 `keyOf`，这是**热路径**。换成 `final class` 方法后：

- 期望：目标函数（`TableState` 里调 `keyOf` / `textAt` 的位置）从
  `typephp_call_method_cached` 变成静态直调；
- 前提：**该应用内只有一个 `RowSpec` 实现**（上游 #184 的「接口形参且只有一个实现类」场景）；
  多实现时去虚化不成立，收益归零 —— 这是本方案唯一可能「白干」的地方，
  所以 P2 的验收必须先跑 A/B 再改代码（见 §9）。
- **不能声称**：性能提升。要说得先跑 `frame-tax-probe`，A/B 只证明形状。

---

## 5. 写侧：`CommandName`

### 5.1 形状（Elm `Msg` 的 PHP 版，零破坏）

```php
namespace Drift\Commands;

interface CommandName
{
    // ★ 不叫 value()：backed enum 已有只读属性 $value，解释器下同名合法（已验），
    //   但 AOT 生成器可能给属性生成同名访问器 —— 没测过就别赌（§12.3 F10）
    public function commandName(): string;
}
```

```php
enum UserCommand: string implements CommandName
{
    case Refresh = 'users.refresh';
    case Delete  = 'user.del';

    public function commandName(): string { return $this->value; }
}
```

控件与路由接受**联合类型**，旧写法一行不改：

```php
Button::primary('刷新')->command(UserCommand::Refresh);   // 新
Button::primary('刷新')->command('users.refresh');        // 旧，仍然可用

$router->on(UserCommand::Refresh, new UserCommands($users, $app));  // 新
$router->on('users.refresh', new UserCommands($users, $app));       // 旧
```

### 5.2 为什么不是「命令名改 enum」的破坏性迁移

评审稿 §9 的 Q2 挂着「`string` → `enum`，破坏面覆盖全仓」。本方案把它拆成两半：

- **收益**（拼错静默、穷举）由**双载 + 名册**拿到，不需要改调用点；
- **破坏**（删 `string` 重载）变成**可选的最后一步**，且只有全仓迁移完才做。

⇒ 破坏性窗口从「必须」降为「可选」，这是相对评审稿 §7 的一处修正。

### 5.3 `command-cases` lint 的输入从哪来

有了 `on(CommandName, handler)`，lint 能建出**静态可达的映射**：
enum case → handler 类。于是判据是：

> 路由到 `UserCommands` 的全部 enum case，必须在该类 `match ($name)` 里各有一个分支；
> `default => false` 允许存在（冒泡语义），但**不许吃掉已路由的 case**。

这比现在 `dead-command` 的「发出名 ↔ 注册名」对账多一层：后者看不到 handler 内部
`match`，注册了但漏一个 case 只落 DIAGNOSTIC（评审稿 §3.5 的原话）。

### 5.4 `onChanged` 怎么办：lint 取代破坏

评审稿 §7 ② 把「`onChanged` 收敛到命令名」列为**唯一建议的破坏性改动**。
本方案改成零破坏路线：

- 新增 `onChangeCommand(CommandName $name)`，`onChanged(callable)` 保留；
- 新增 `write-in-ui` lint：`build()` 体内的回写闭包（`onChanged` / `onTap` / `onSubmit` …）
  若直接调用 `Subject` 子类的**非 getter** 方法 ⇒ 报错。

`UsersPage` 现在那一行 `->onChanged(fn(string $x) => $this->store->setQuery($x))`
会被这条规则抓到 —— 所以它**不能一上来就是 error**（会红掉现有代码）。
灰度顺序：`--warn` 观察一个迭代 → 迁移到 `onChangeCommand` → 提升为 error。
破坏性窗口因此只剩「删旧重载」一步，且可以永远不做。

---

## 6. 合成：`AsyncView`

`UsersPage` 现在 4 层 `SubjectBuilder`（搜索框 / 刷新按钮 / 四态 / 列表）。
`AsyncView` 把它们收成 **1 层**，但它**不是新通道**——内部就是
`SubjectBuilder::project()` 的糖：

```php
AsyncView::of($this->store)
    ->page(UserProjection::Page)       // 四态来源
    ->rows(UserProjection::Visible)    // data 支的行
    ->states(
        loading: static fn(): Widget => Spinner::make(),
        idle:    static fn(): Widget => Text::make('尚未加载')->caption(),
        error:   static fn(AsyncValue $v): Widget => ErrorBanner::make($v)->retry(UserCommand::Refresh),
    )
    ->data(fn(array $rows): Widget => Table::of(UserRows::make(), $rows)->columns(self::columns()));
```

门控 = `page.version + rows.version` 的和（两个投影各一个版本，不合成新值对象）。

`AsyncValue::fold(array)` **保留**：它已经在入口校验四个键齐全（A4 已满足一半）。
`states()` 用命名参数，拼错参数名会立刻 `Unknown named parameter`——
比 `fold` 的字符串键更早响，但不构成替代关系。

---

## 7. 判据层：四条 lint + 变异牙检

新方案的价值一半在 API，一半在**这些判据能咬红**。每条都要有变异用例：

| lint | 判据 | 咬红方式（必须真跑） | 破坏性 |
|---|---|---|---|
| `reactive-read` | `build()` 里对 `Subject` 子类的方法调用，必须**词法上**位于 `select` / `project` / `builder` 闭包作用域内 | 把 `$store->page()->isRunning()` 挪出 `select` ⇒ 红；**补一个空 `SubscribingWidget` 接口仍须红** | 无（灰度 warn） |
| `projection-cases` | `projection()` 的 `match` 覆盖其 enum 全部 case，且无 `default` | 删 `UserProjection::Visible` 分支 ⇒ 红 | 无 |
| `command-cases` | 路由到某 handler 的 enum case 必须在该 handler 的 `match` 里各有分支 | 删 `'user.del'` 分支 ⇒ 红 | 无 |
| `write-in-ui` | `build()` 回写闭包内不得直接调 `Subject` 子类的非 getter | 还原 `setQuery` 写法 ⇒ 红 | 无（灰度 warn） |

★ `reactive-read` 是这四条里**唯一**能覆盖评审稿 §3.1 那个真机 bug 的：
现有 `dead-subscription` 是标记接口判据，「裸读 + 补一个空接口」能洗绿；
本规则判的是**读点位置**，洗不掉。变异用例第 2 条就是专门防洗绿的。

★ 变异牙检的纪律（评审稿 §12.3 坑 #6 的反面）：每条规则落地时必须有一个
**故意漏记 / 故意挪位**的夹具，且必须观察到红；否则规则本身是绿壳。

★ **四条规则的工程量差一个数量级**：`projection-cases` / `command-cases` 是
「enum cases ↔ `match` 分支」的集合比较；`reactive-read` 要做**词法作用域**判定
（闭包体 span、链式调用、箭头函数、嵌套闭包），是全套里最贵、也最容易假阳的一条
⇒ P0 必须拆成 P0a / P0b（§9），否则 P0 变成长尾。

---

## 8. 与既有裁决的关系

| 既有裁决 | 本方案 | 说明 |
|---|---|---|
| §18 组合点注入、否决 `BuildContext::of()` | **不动** | `Projection` 是「怎么取」，不是「从哪拿」；作用域问题仍归组合根 |
| §19.2 自动订阅 / `bind()` 判「留」 | **不动** | 无 dep-table；`Projection` 是显式名册，不是自动追踪 |
| §20 外部 `setText` 清空 undo 栈 | **不动** | 不做双向绑定 |
| §66 Table 两套身份 | **不动** | `RowSpec::keyOf()` 只取代数据身份；视口序号 `->key($i)` 仍是 diff 用的第二套 |
| 评审稿 Q1（Element 作用域） | **不做** | `reactive-read` 零破坏覆盖同一个 bug |
| 评审稿 Q2（命令名 enum） | **降级** | 双载 + 名册拿到收益，删旧载变成可选 |
| 评审稿 Q3（`fold` → 接口四方法） | **不做** | `fold` 入口校验已够硬；`AsyncView::states()` 用命名参数补 DX |
| 评审稿 Q4（行身份收口） | **不动** | 见 §66 行；增量通知仍以它为前提 |

---

## 9. 迁移路径

| 阶段 | 内容 | 破坏性 | 前置 | 验收判据 |
|---|---|---|---|---|
| **P0a** | `projection-cases` / `command-cases` / `write-in-ui` + 变异夹具 | 无 | 无 | 每条规则至少一个变异红、一个正例绿；`gate --fast` 含它们 |
| **P0b** | `reactive-read`（最贵的一条：词法作用域判定） | 无 | P0a | 三条变异：挪出 `select` 红 / **补空 `SubscribingWidget` 仍红** / 放回绿；先 `--warn` 烧一个迭代 |
| **P1** | `Projection` / `Projected` / `project()`；`UserStore` 迁三个切片 | 无（`select` 并存） | P0a | `UsersPage` 的 `select` 闭包数 3 → 0；`projection-cases` 咬红；**enum 实现接口 + match 在 `bin` 下 AOT 夹具通过** |
| **P2** | `RowSpec` + `Table::of()`；`TableContract` 加两条 | 无（`keyOf` 并存） | P1 | `bin --dry` A/B：目标调用点 `typephp_call_method_cached` 归零；`--full` 重建 + 真机 `probe-window` 5/5 |
| **P3** | `AsyncView`；`UsersPage` 的**数据子树** 2 层 → 1 层（整页 4 → 3） | 无 | P1、P2 | 数据子树嵌套 2 → 1；整页 golden 零变化；**搜索框保持独立订阅**（理由见 §12.1 F2） |
| **P4** | `CommandName` enum + `on` / `command` 双载；`onChangeCommand` | 无 | P0a | `command-cases` 咬红；`write-in-ui` 提升为 error 后全仓零红 |
| **P5** | **不删任何 API**：`select()` / `cell()` / `onChanged(callable)` 永久保留为逃生口；只加「应用代码不写 `command('字面量')`」的 lint 政策 | 无 | P0a–P4 | 全仓应用代码零 `command('字面量')`；框架内部允许 |

★ **P1 有一个前置实验必须先做**：`enum X: string implements Projection` +
`match ($p)` 在 `bin` 口径下是否真的静态化。这不是「理论上应该」——
上游 #184 修的是「接口形参且只有一个实现类」，enum 实现接口是另一条路径，
**没测过就不许写进结论**。失败则退回「投影名用 `string` 常量 + 类常量表」，
代价是拼错不再被类型拦住，只能靠 lint。

---

## 10. 这个方案自己会怎么坏（失败模式）

写完方案先写它会怎么坏，比写完先写收益有用。

| # | 坏法 | 症状 | 防线 |
|---|---|---|---|
| 1 | 投影缓存与字段不同步：改了字段忘了 `notifyChanged()` | 版本不推 ⇒ **永不更新**，界面停在旧值 | `projection()` 只许读 `$this->` 字段；每个 setter 必须 `notifyChanged()`（既有纪律）；单测断「写入前后投影值不同」 |
| 2 | 原地改实体（`$user->name = 'x'`） | 行身份不变 ⇒ 判「没变」⇒ 不重建 | 实体必须不可变；`UserStore::remove()` 用 `array_filter` 重建数组是正例；可变实体必须显式 `touch()` |
| 3 | 应用里有多个 `RowSpec` / `Projectable` 实现 | 去虚化不成立 ⇒ P1 / P2 的 AOT 收益归零 | A/B **按应用**跑一次；多实现时只声称 lint 收益，不声称 AOT 收益 |
| 4 | `Projection` 名册膨胀成第二份 Store API | 一个 Store 几十个 case，与字段一一对应 | 一个 Store 的 case 数 **≤ 字段数**；超过 ⇒ 该拆 Store（同 `Computed` 深度上限的纪律） |
| 5 | `write-in-ui` 只抓直接调用，抓不到 `$fn()` 间接回写 | 以为覆盖了，其实漏 | 诚实边界写进规则 summary；间接回写靠 code review，不假装 lint 能管 |
| 6 | `AsyncView` 变成「什么都塞进去」的上帝控件 | 又出现 4 层嵌套的变体 | 只做「四态 + 一个行投影」；多一个能力就退回组合 `SubjectBuilder` |

---

## 11. 结论台账

| 项 | 结论 |
|---|---|
| 核心手法 | **三处闭包 → 三本名册**（`Projection` / `RowSpec` / `CommandName`），共同性质是「可枚举 + 静态可见 + AOT 可直调 + 漏一个会响」 |
| 为什么不是 dep-table | 推模式广播不变；名册是**显式**的，不是自动追踪（§19.2 裁决不动） |
| 性价比最高的一步 | `reactive-read` lint：零破坏、直接覆盖 2026-10-31 真机 bug、且洗不掉；但**实现最贵**（词法作用域判定，§7 / §12.3 F8） |
| 最贵的一步（API 改动） | `RowSpec`：要改 `TableState` 热路径 + 每应用跑 AOT A/B |
| 破坏性窗口 | **没有**。P5 只加 lint 政策，API 一个不删（§12.1 F3） |
| 相对评审稿的修正 | ① `onChanged` 破坏 → 改 lint 灰度（§5.4）；② 命令名 enum 破坏 → 改双载（§5.2）；③ `array` 放行的理由改为「投影缓存保证身份稳定」（§3.4）；④ **取消 P5 删 API**，破坏性窗口归零（§12.1 F3） |
| 必须先测再写结论的 | enum 实现接口 + `match` 在 `bin` 口径下的静态化（P1 前置） |
| 验证口径 | 本文不落代码；每条 lint 落地时配变异牙检，每个 API 落地时按 T0/T1/T2 分级 |

---

## 附 · 本文对齐的现有签名

- 读：`Subject::notifyChanged()` / `Subject::subscribeFor()` / `SubjectBuilder::make()->select()->equals()->builder()` /
  `SubjectBuilderState::sameValue()` / `sameContent()` / `JITTER_ALERT` / `Computed::get()|version()` / `Versioned` / `Equatable`。
- 行：`Table::make()->keyOf()` / `TableColumn::make()->field()|cell()|copyText()` /
  `TableContract::assert()` / `TableContract::capabilities()` / `Each::make($keyFn)`。
- 写：`CommandRouter::make($parent)->on()->dispatch()` / `CommandHandler::handle(string, CommandArg): bool` /
  `Button::command(string)` / `CommandArg`。
- 异步：`AsyncValue::fold(array)` / `AsyncValue::stateName()` / `AsyncCommand::value()|isRunning()|run()|retry()`。
- 生成物：`apps/users/build` 的 `UsersPage.cc`（`php::newClosureWithParameters`、
  `typephp_call_method_cached`、`php_drift__state__subjectbuilder__select` 直调）。

---

## 12. 自审：12 个真问题与裁决（21:45）

> 审的对象是**本文**，不是代码。每条给「原稿 → 改成什么 → 为什么」，
> 并标类别：**真 bug** / **口径过宽** / **工程代价**。
> 形状类假设已用解释器验过（enum 实现接口、`value()` 与 `$value` 同名、无 `default` 的
> `match` 抛 `UnhandledMatchError`）；AOT 口径一律未验，标「待 A/B」。

### 12.1 必须改的三条（原稿会直接坏）

**F1 · `cached($p, $this->visible())` 是及早求值 —— 缓存短路失效。**
PHP 实参在进函数前就算完 ⇒ 每个订阅者每次通知仍跑一次 `visible()`，
只是把 N 次计算从 `SubjectBuilder` 搬进了 `Store`，比现状还多一层对象。
⇒ 改成「先查缓存有效性，再 `match` 取值」（§3.1 已改）。

**F2 · P3「4 层 → 1 层」与重建粒度自相矛盾。**
`UsersPage` 的注释写着「搜索框改字不会让列表重建」—— 4 层拆分就是为了这个。
合成一个 gate 后，改搜索词会重建整棵子树（含 `TextField`），而 §20 记着
「外部 `setText` 清空 undo 栈」⇒ 有真实回归风险。
⇒ 改成 **4 → 3**（搜索框、刷新按钮各留一个，`AsyncView` 吃掉四态 + 列表）；
要 4 → 2 必须先证明「同一 gate 下 `TextField` 重建不丢 undo」。

**F3 · P5 删 `select()` / `cell()` / `onChanged(callable)` 与 A2 自相矛盾。**
A2 说「闭包留在语法糖层」，而 `select` 是复杂派生的唯一逃生口、
`cell` 是富单元格的唯一出口。删它们不是「收口」，是把 DX 砍掉换一个本来就能用 lint 拿到的性质。
⇒ **API 一个都不删**，P5 降级为「应用代码不写 `command('字面量')`」的 lint 政策。
⇒ 本方案**没有破坏性窗口**。这比上一版「唯一破坏窗口」是改进，不是退让。

### 12.2 口径过宽的四条（结论对，话说过头）

**F4 · AOT 收益被说大了。**
`Projected::$value` 是 `mixed` ⇒ 值仍是 `php::Var`；消掉的只是
「`select` 闭包构造 + 一次动态派发」。而且 `Projectable` 是**接口**，
多实现时去虚化同样失效 —— 原稿只对 `RowSpec` 提了这条，`Projection` 漏了。

**F6 · A4 说「响在编译期」不成立。**
`match` 无 default 抛的是**运行期** `UnhandledMatchError`（已实测），
且只在**首次请求该投影**时抛。真正的编译期保证只有 lint，而 lint 只有进了 `gate` 才有牙
（项目纪律：闸门不在 `gate` 里 = 红了没人知道）。A4 已按此改写。

**F11 · 「`write-in-ui` 零破坏」不准确。**
它是**零 API 破坏 + 一次源码迁移**：现有 `setQuery` 写法会被判红，必须改。
灰度（`--warn` → 迁移 → error）是缓冲，不是「不用改」。

**F12 · `reactive-read` 有明确绕过路径。**
若把读藏进普通方法（`private function label(): string { return $this->store->query(); }`），
再在 `build()` 里调 `$this->label()`，词法判据看不到 `Subject` 方法调用。
⇒ 诚实边界：本规则覆盖「`build()` 体内直接的读」，**不覆盖**「读被包一层方法」。
后者要么靠「页面类只许有 `build()` 一个方法」的形态约束，要么接受漏。

### 12.3 形状与工程（不改结论，改做法）

**F5 · `Table::of($spec, $rows)->columns([...])` 需要新增可变 `columns()`，与现状冲突。**
`Table::make()` 是唯一入口，构造时 clone 列 + `array_values($rows)` 快照；
`TableContract::assert()` 在 `TableState::build()` 里**每次 build** 按 `columnList()` 对账。
⇒ `of()` 必须同样在构造期拿到列：`Table::of(RowSpec $spec, array $columns, array $rows)`。

**F7 · `RowSpec::columns()` 与 `TableColumn` 列表是两处列名真相。**
这是本方案**唯一新增的重复源**。不假装没有：靠挂载期对账守住（§4.3 第 2 条），
拼错从「整列空白」变成构建期 Fatal。若要消灭重复，只能把 title / width 也塞进 spec，
那会让业务类持有 UI 配置 —— 不划算。

**F8 · P0 四条 lint 难度差一个数量级。**
`projection-cases` / `command-cases` 是集合比较；`reactive-read` 要做词法作用域判定，
且最容易假阳。⇒ 拆 P0a / P0b（§9 已拆）。

**F9 · `Projected` 与 `Computed` 的关系没交代。**
**不能直接复用 `Computed` 的版本语义**：它的 `version++` 发生在「依赖变了就重算」，
等于每次 notify 都推版本 ⇒ 门控恒真。`Projected` 要的是「重算后相等就不算变化」，
多出来的是那一层**相等门控**，不是第二套缓存。若要 `Computed` 也变成「相等不推版本」，
那是 Core 语义变更，单独立项，别塞进 P1。
（另：`Projectable extends Versioned` 是冗余的 —— `Subject` 已实现，留着无害但会让人
以为有第二个版本号来源。可去掉。）

**F10 · `CommandName::value()` 与 backed enum 的 `$value` 属性同名。**
解释器下合法（已实测），但 AOT 生成器可能给属性生成同名访问器。
⇒ 改名 `commandName()`，代价为零。

### 12.4 裁决台账

| # | 类别 | 裁决 |
|---|---|---|
| F1 及早求值 | 真 bug | **已改** §3.1：先查缓存再取值 |
| F2 4→1 | 真 bug | **已改** §9：4 → 3，搜索框保持独立 |
| F3 P5 删 API | 真 bug（自相矛盾） | **已改** §9 / §11：不删，降为 lint 政策；破坏窗口归零 |
| F4 AOT 收益 | 口径 | **已改** §3.2 / §10：只声称「少一次闭包构造 + 一次派发」 |
| F6 编译期 | 口径 | **已改** §1 A4：运行期兜底 + lint 才是编译期 |
| F11 零破坏 | 口径 | 记为「零 API 破坏 + 一次源码迁移」 |
| F12 绕过路径 | 边界 | 写进 `reactive-read` 的诚实边界，不声称全覆盖 |
| F5 `of()` 签名 | 形状 | **已改** §4.1：三参数，构造期定列 |
| F7 列名重复源 | 工程 | 承认，靠挂载期对账守住 |
| F8 P0 长尾 | 工程 | **已拆** P0a / P0b |
| F9 `Computed` 关系 | 工程 | 不复用其版本语义；`Projectable extends Versioned` 可去 |
| F10 命名 | 工程 | **已改** `commandName()` |