# 方案记录 · UI 数据订阅（分区视图模型切片）· 2026-11-12

> ## ⚠️ 推进裁决（2026-11-12 同日复核，优先于本文其余内容）
>
> **结论：整包不值得推进。** 与现状逐项对比后，形状改造的收益小于成本，
> 且 §2.2 的分区 A 在性能上是**净负**。本文保留为方案记录与证据链，
> **不再作为开工依据**。与形状无关、仍值得做的两件在下面「A 档」。
>
> | 项 | 现状（4 个独立切片） | 本方案（分区 VM） |
> |---|---|---|
> | 事故形态：`build()` 顶层裸读 Store | `dead-subscription` 守；全仓仅剩 1 处 `this->store->`，还是注释 | `vm-pure` 守的是 **`builder` 区间 —— 守错了层** |
> | 多字段一致性 | 已按需合并 4 处，注释写明原因 | 全仓统一形状；其余 17 处是单值切片套数组壳 |
> | 名字拼错 | `builder` 用形参，IDE 可跳转 / 重命名 | 引入字符串键 ⇒ 自造 `$vm['qery']` 风险，再造 `vm-key` 去防它 |
> | 输入一个字符的重建范围 | 只重建 `TextField` 一支 | 分区 A 重建整行（约 7 个 widget） |
> | 订阅者数 / 广播 | 4 | 3（少一次 `select` 求值） |
> | 新增成本 | 0 | 2 条 lint + 3 夹具 + `SourceQuery` 改动 + 7 文件迁移 + tutorial |
> | 框架改动 | 0 | 0（这条确实做到了） |
>
> **五条判死理由**
>
> 1. **事故已不在。** 2026-10-31 的按钮永久灰已修；`dead-subscription` 是守它的防线，
>    S1 只是这道防线自身对构造器属性提升假绿。修 S1 即恢复，不需要改书写形状。
> 2. **74% 是套壳。** apps 真订阅 23 处，返回 `array` 的只有 7 处（且其中 3 处本就是
>    `visible()` / `selected()` / `uploadedKeys()` 这类天然数组），其余 16 处是单值
>    （7 `string` / 4 `bool` / 3 `StatusMessage` / 1 `float` / 1 `AsyncValue` / 1 `mixed`）。
>    全仓 VM 化就是把单值形参换成单键数组，零收益。
> 3. **§2.2 违反本文自己的 C3。** `query` 每字符变、`running` 每次刷新变，
>    `setQuery()` 与 `AsyncCommand::absorb()` 都走 `notifyChanged()` ⇒ 同一次广播里
>    两者都被求值，但频率差一个量级。合成 VM 后每敲一个字符都要重算 `isRunning()`
>    并重建整行；现状 `running` 的 `===` 门控直接挡住、只重建 `TextField`。
>    `Element::reuseChild()` 恒真 ⇒ 这是真的重建工作量，**性能净负**，不是可后置的调用开销。
> 4. **两条判据一条自造问题、一条守错层。** 现状没有字符串键，就没有「键拼错」这个坑；
>    `vm-key` 是给方案自己引入的风险打补丁。事故读点发生在 `build()` 顶层而非
>    `builder` 内，`vm-pure` 扫不到它；且 §2.2 示例的 `data` 分支里
>    `SubjectBuilder::make($this->store)` 本身就带 `$this->store`，判据还要豁免嵌套订阅
>    ⇒ 实现复杂度被低估。
> 5. **成本收益比不成立。** 净收益是「每次广播少一次 `select` 求值」（4→3），
>    代价是 2 条规则 + 3 条夹具 + 一张属性表 + 7 个文件迁移 + 文档 / tutorial 维护。
>
> **方案里确实成立、值得保留的**（不因整包否决抹掉）：框架侧零改动、四族方案空间的
> 否定边界（§1）、「全标量 ⇒ `===` 天然值相等」的实测（§3）、C3 这条**判断何时该合并**
> 的原则、以及 S1 的正确修法（新增 `ctorParams()` 而非改 `readBody()`，§5.3）。
>
> | 档 | 内容 | 裁决 |
> |---|---|---|
> | **A · 做** | ① P0 修 S1 + 夹具补构造器提升 Bad 类（真缺陷，与形状无关）；② 表格线 `dead-field`：`field('nmae')` 现在只在 `src/Widget/TableColumn.php:362` 渲染期报，apps 有 12 处 `field()` 无构建期对账 | 独立、量小、收益确定 |
> | **B · 可选** | 把「一致性敏感的多字段读点必须进同一个 `select`」写成一条窄纪律（+ 可选 `select-consistency` 判据），**只覆盖已有 7 处 array**，不动单值切片。仓库已有 4 处正确先例与事故注释 | 按需，不铺开 |
> | **C · 不做** | 全仓 VM 化、`vm-key` / `vm-pure`、7 文件迁移、§6 的 P1–P4 | 净负 |
>
> ★ 真要优化性能，方向不是改形状：粗广播 O(订阅者) 与重建工作量都不可由编译器后置
> （§4.4），要么维持现状，要么走已登记的细粒度状态原语（B 档重启条件写在 §4.4）。
>
> ---
>
> 状态：~~唯一开工口径~~ **评估后未采纳**，未落地任何代码。基线 HEAD `5f26dfc`。
> 前八份 UI 数据文档仍为证据链（§11 逐份索引）。
> 唯一不变的否决：**不生成 PHP 源码**（2026-11-12 用户裁决，语言边界，见 §1.2）。
> 代码块中，标「现状」的逐字取自仓库；其余为设计草案，未编译、未跑 T1/T2。

---

## 0. 一页结论（以下为被否决方案的原始论证，保留作记录）

### 0.1 推荐什么

**保留 `SubjectBuilder::make()->select()->builder()`，把每个 `select` 改成返回一个「全标量的字面量关联数组」（视图模型），`builder` 里只准读 `$vm`；按变化频率分区，不把整页合成一个 VM。**

一句话心智模型：**`select` 里列齐这块视图要用的值，`builder` 里只准读 `$vm`。**

| 维度 | 这条方案给出的东西 |
|---|---|
| **开发者体验** | 零新概念、零新类型、零生成步骤、零 `use`、零依赖数组。仍是熟悉的两个闭包；写错键由 `vm-key` 报「可用键：…」；`builder` 里没有 Store 可读 ⇒ 漏订阅写不出来 |
| **架构** | 读集只有一个入口（`select`），显式、可枚举、构建期可见；读侧不碰 `BuildContext`/`Element`/widget 基类；不引入运行期记账，不引入第二套状态原语 |
| **实现** | **`src/` 零改动**。只动 `tools/Lint`（3 处）+ 1 个 app 文件灰度；无新 AOT 形态，无需重跑 T1/T2；纯 token 级判据，不依赖 `ClassTable` 方法签名、不引入 php-parser |
| **性能** | 不新增分配、查表、闭包或虚调用；全标量 VM 直接吃现有 `===` 门控；订阅者数量**下降**（`UsersPage` 4 → 3），广播回调更少；分区把每次通知的重建范围锁在小子树；需量化一次，口径见 §4.4 |

### 0.2 与上一版（「4 个 select 合成 1 个整页 VM」）的差别

本轮把「合成 1 个」修正为**按变化频率分区**。三条硬理由，见 §2.3：

1. `AsyncValue::stateName()` 的类注释明令「不要拿它做分支，界面画法一律走 `fold()`」——上一版示例用 `$vm['state'] !== 'loading'` 做分支，违反本仓自己的纪律；`fold()` 要求 `AsyncValue` 对象在手。
2. `visible()` 返回低频大子树，混进高频字符输入的 VM 会把整页拖进重建；`Element::reuseChild()` 的 `widget !== newWidget` 恒真，这是**真重建工作量**，不是编译器能补的调用开销。
3. 分区后 VM 可以做到**全标量** ⇒ PHP 数组 `===` 天然值相等 ⇒ **框架零改动**（§3）。上一版为此要新增 `sameList()`，而它与现有 `SelectPurity` 判据的既有契约冲突。

### 0.3 明确不做

生成 PHP trait / 运行期读追踪 / `$ctx->watch` 登记 / 整套 Drift3 架构 / `dep-table` 自动订阅 / 行数据换成对象 Row / 收编 `onChanged(callable)`。否决依据见 §1、§9。

---

## 1. 为什么收敛到这里

### 1.1 四族方案空间（前八棒的最终状态）

| 族 | 代表框架 | 裁决 | 决定性理由 |
|---|---|---|---|
| ① **生成** | Svelte / WinUI `x:Bind` | **否决** | PHP 没有编译单元接管。trait 必须在类定义处静态 `use`，写 `use XxxView;` 时生成物必然还不存在 ⇒ 先红后写。`class_alias` / `__call` 都不行（`ViewHost` 已有默认实现） |
| ② **追踪**（读即订阅） | Compose / MobX / SolidJS / Vue 3 | **否决** | SolidJS 文档明写 tracking scope 外读不追踪、`createRoot` 外创建的计算永不释放、事件回调要 `untrack`；MobX 明列异步块内读不反应。且 SolidJS 组件只跑一次，本仓 Widget 每帧 `new`，没有常驻作用域 |
| ③ **登记**（`$ctx->watch`） | Flutter `InheritedWidget.of()` / React Context | **否决** | 要推翻 §18③ 与 A4「防 build 期订阅」；`BuildContext` 是 `final readonly` 且构造点散在 10 处；React 到 19 仍无官方 context selector，社区共识「Context 只适合低频变化值」，而搜索框是每字符高频 |
| ④ **映射**（显式一次映射） | Elm `view: Model→Html` / React-Redux `mapStateToProps` / **本仓现状** | **可行 ⇒ 本文** | 读集本来就只有一个入口，不需要「找」；前几轮的盲点是没解决**映射的粒度**，不是映射的形状 |

①–③ 与 Drift3 整套架构（0/6 闸门、git 零提交、`buildInto()` 是未追认工作假设、渲染范式不同）的详证见 `ANALYSIS-UI-DATA-ALTERNATIVES-2026-11-12.md` §2、`ANALYSIS-ARCH-DRIFT3-VS-2026-11-12.md` §2/§7。

### 1.2 「兼容性可破坏」不能翻案

它只免掉「迁移旧调用点」一项。另外四项成本与兼容性无关：PHP 语言边界、渲染范式（`build(): Widget` + Element diff vs 立即模式节点句柄）、验证体系重做（本仓 25 项 gate + 真机 probe-window）、状态原语（本仓只有 `Subject` 粗广播，没有细粒度 Cell）。**主要成本在验证，不在迁移。**

### 1.3 前八棒留下的、本文继承的实测结论

- 真实迁移单位是 **widget 不是页面**：`SubjectBuilder::make` 全仓 42 处 = apps 23 处 / 7 个文件 + tests 16 + src 3 处文档注释；showcase 30+ 页面零订阅。
- `select` **早就支持返回 `array`**，仓库已有 7 处：`SignupPage` 2、`UsersPage` 1、`ResultCard` 2、`UploadCard` 2。`SignupPage` 注释写着「值 + 该字段错误一起切（数组按值 `===` 门控，不再多挂一条订阅）」；`ResultCard` 事故注释写着「`select` 只盯 `lastResult()`，轮询 `null → null` ⇒ 状态区说谎」，修法就是把 `polling` 并进同一次 `select`。**这就是 2026-10-31 `UsersPage` 按钮永久灰的同族事故，仓库已用「多字段一次切」修过一次。**
- `dead-subscription` 对构造器属性提升是**假绿**：`SourceQuery::readBody()` 有意跳过方法体（`tools/Lint/SourceQuery.php:570` 注释写明怕误读提升参数），`__construct(private readonly UserStore $store)` 一个都不进属性表。最小仓库对照：提升写法 0 条 / 传统写法 1 条。全仓 570 类里 181 个用提升、124 类属性完全不在表里。
- 自证夹具 `DeadSubscriptionFixture.php` 两个类都用传统属性声明 ⇒ 这个洞连 `selftest` 都抓不到。与 `SelectPurity` 2026-11-07 修 `static fn` 假绿是同一族：**夹具漏等价书写形态**。

---

## 2. 形状：分区视图模型切片

### 2.1 三条硬约束

| # | 约束 | 理由 |
|---|---|---|
| **C1** | 每个 `select` 返回**字面量关联数组**，值全部是标量（string / int / float / bool / null / enum） | 全标量 ⇒ 数组 `===` 是逐键值比较（§3 实测）⇒ 现有门控直接工作，框架零改动 |
| **C2** | `builder` 闭包体内**零 `$this->store` 裸读**；写通道（`setQuery()` 等）与 `->command('name')` 保留 | 「读了却没进订阅」结构性写不出来；这是坑 #4 的唯一机械防线 |
| **C3** | **按变化频率 + 子树大小分区**，一个 VM 的重建范围 ≈ 它变化时要重画的那块 UI | 控制重建工作量；避免把低频大列表拖进高频字符输入的重建路径 |

「分区」不是新概念：现状本来就是多个 `SubjectBuilder` 分区，本文只要求**每个分区内部把多字段合成一个全标量 VM**。`UsersPage` 从 4 个切片收敛到 3 个（1 个 VM + 2 个保持原状的稳定对象切片）。

### 2.2 代码对照：`UsersPage`

**现状**（`apps/users/src/Pages/UsersPage.php`，4 个 `SubjectBuilder`、8 个闭包，`builder` 里能直接读 `$this->store`；事故注释见该文件 35–40 行）：

```php
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): string => $s->query())
    ->builder(fn(string $q): Widget => TextField::make()->value($q)->...),

SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
    ->builder(function (bool $running): Widget { /* 刷新按钮 + Spinner */ }),

SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): AsyncValue => $s->page()->value())
    ->builder(fn(AsyncValue $v): Widget => $v->fold([...])),
// 第 4 处嵌在 fold 的 data 分支：->select(fn => $s->visible())
```

**推荐**（分区 A 合成一个全标量 VM；分区 B 保持 `fold()` 与列表切片）：

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

                    // 分区 A：高频、小子树、全标量
                    SubjectBuilder::make($this->store)
                        ->select(static fn(UserStore $s): array => [
                            'query'   => $s->query(),
                            'running' => $s->page()->isRunning(),
                        ])
                        ->builder(function (array $vm): Widget {
                            $items = [
                                TextField::make()
                                    ->value($vm['query'])
                                    ->label('搜索')
                                    ->placeholder('输入姓名')
                                    ->onChanged(fn(string $x) => $this->store->setQuery($x)),
                                Button::primary('刷新')
                                    ->enabled(!$vm['running'])
                                    ->command('users.refresh'),
                            ];
                            if ($vm['running']) {
                                $items[] = Spinner::make()->diameter(12.0);
                                $items[] = Text::make('刷新中…')->caption();
                            }
                            $items[] = Spacer::make();
                            $items[] = Button::primary('新建用户')->command('nav.signup');

                            return Row::make()->spacing(8)->children($items);
                        }),
                ])
            )
            ->center(
                ScrollView::make(
                    Column::make()->spacing(8)->padding(EdgeInsets::only(24.0, 0.0, 24.0, 24.0))->children([
                        // 分区 B：低频、大子树；fold() 要 AsyncValue 对象在手
                        SubjectBuilder::make($this->store)
                            ->select(static fn(UserStore $s): AsyncValue => $s->page()->value())
                            ->builder(fn(AsyncValue $v): Widget => $v->fold([
                                'loading' => static fn(AsyncValue $v): Widget => Spinner::make(),
                                'error' => static fn(AsyncValue $v): Widget => Row::make()->spacing(8)->children([
                                    Text::make('加载失败')->error(),
                                    Button::primary('重试')->command('users.refresh'),
                                ]),
                                'data' => fn(AsyncValue $v): Widget => SubjectBuilder::make($this->store)
                                    ->select(static fn(UserStore $s): array => $s->visible())
                                    ->builder(static fn(array $rows): Widget => Each::make(
                                        static fn(User $u): int => $u->id,
                                    )->items($rows, static fn(User $u): Widget => UserRow::make($u))),
                                'idle' => static fn(AsyncValue $v): Widget => Text::make('尚未加载')->caption(),
                            ])),
                    ])
                )
            );
    }
}
```

**开发者要写的**：一个 `select` 里列键、一个 `builder` 里读 `$vm`。**不用写**依赖数组、名字登记、生成步骤、`use`、`extends` 变更。`UsersPage` 仍是 `StatelessWidget`，页面创建点零改动。

### 2.3 为什么不是「4 合 1」

| 理由 | 证据 |
|---|---|
| **四态必须走 `fold()`** | `src/Async/AsyncValue.php:282` 注释：「不要拿它做分支（`if ($v->stateName() === 'data')`）：那是把穷尽性检查换成字符串比较，写错一个字母不报错。界面画法一律走 `fold()`」。上一版示例把四态投影成 `$vm['state']` 再做字符串分支，正好踩中 |
| **`AsyncValue` 不适合塞进 VM** | 它在 `SelectPurity::IDENTITY_STABLE` 里（同值复用同一实例），整体作 `select` 返回值是安全的；但数组元素含对象时 `===` 退成身份比较（§3 实测）。分区 B 保持整体返回，避开这条 |
| **粒度是可观测成本** | 4 合 1 后每输入一个字符重建整页 `Dock`（含 `ScrollView` 与全部行 widget）；分区 A 只重建头部 `Row`（约 7 个 widget）。`Element::reuseChild()` 的 `widget !== newWidget` 恒真 ⇒ 是真重建，不是「编译器以后能补的调用开销」 |

### 2.4 什么时候允许合成一个 VM

当一个分区内所有字段**变化频率同量级、子树小、且全是标量**时，合成一个 VM 是最优解。`SignupPage` 已有两处现成先例：

```php
// apps/users/src/Pages/SignupPage.php:58（现状，逐字）
->select(static fn(SignupForm $f): array => [$f->name, $f->errorOf('name')])
```

`ResultCard` 把 `lastResult` 与 `polling` 并进同一次 `select`，同样是这个形状。⇒ 本文不是把「4 合 1」判错，而是给它划了适用边界。

---

## 3. ★ 框架零改动：门控为什么天然成立

这是本轮相对前八棒最实质的简化，也是「实现」维度的主要收益。

**实测**（本轮 `php -r`）：

```text
纯标量数组 ===            : true    ['q'=>'ab','running'=>true] vs 同值
数组含每次 new 的对象 ===  : false
数组含同一个对象实例 ===   : true
键序不同 ===              : false
```

PHP 数组 `===` 是「同键序 + 同类型 + 逐值严格比较」。所以**只要 VM 全标量，现有 `SubjectBuilderState::sameValue()`（`src/State/SubjectBuilder.php:321`）的 `===` 分支就是正确的值门控，一次改动都不用做。**

而且仓库的判据已经把这个契约写死了 —— `tools/Lint/Rules/SelectPurity.php` 的类注释：

> `array` 的 `===` 是「同键序 + 同类型 + 同值」，稳定值语义 ⇒ 放行。
> ★ 「array 不该响」由 `SelectFixture::staticShape()` 的 Good 形态钉住。

⇒ 前几版方案里「给 `SubjectBuilderState` 加 `sameList()` 逐元素门控」这一步**应当删除**：

- 它没有必要：C1 保证 VM 全标量；
- 它会**改变既有门控语义**，与 `SelectPurity` 已放行 `array` 的裁决冲突；
- 它把「框架零改动」变成「动 `src/` 且要重跑 T1/T2」。

**C1 的真正作用就是让这一步消失。** 违规形态（数组里塞每次 `new` 的对象）由现有 `SelectPurity`（查返回类型与体内 `new`）+ 新增 `vm-pure`（查 `select` 区间）共同覆盖；`select/unstable` 运行期诊断继续兜底（注意：`sameContent()` 在 `src/State/SubjectBuilder.php:271` 明确排除了数组，所以数组里含对象时它**不会**报，C1 不能只靠诊断）。

补一句精确化：前几版写「`AsyncValue` 不 implements `Equatable` ⇒ 塞进视图模型必白重建」不够准。它在 `IDENTITY_STABLE` 登记里，整体作为 `select` 返回值是稳定的；只有「数组元素 + 实例每次都换」才退化。本文用分区 B 整体返回，两种情况都避开。

---

## 4. 四个维度逐条

### 4.1 开发者体验

| 项 | 结果 |
|---|---|
| 新概念 | **0**。仍是 `select` / `builder`，只是 `select` 从单值改成键值表 |
| 新增书写物 | **0**。不写 DTO、不写 `FIELDS` 常量、不写 `use`、不写注解、不写依赖数组 |
| 心智模型 | 一句话：`select` 里列齐这块视图要用的值，`builder` 里只准读 `$vm` |
| 编辑时序 | **不存在「先跑生成器才能写代码」**。任何时刻 `php -l` 都通过 —— 这是相对被否决方案的决定性差别 |
| 写错时 | `vm-key` 报「视图模型没有这个键 `qery`，可用键：query, running」；`vm-pure` 报「读点必须移到 `select`」。都是构建期中文带修法 |
| 迁移量 | 只有 7 个文件订阅 Store；showcase 30+ 页面一个都不用动 |

对照 WPF 头号坑「`Binding Path` 拼错只在 Output 打 trace」：`vm-key` 的两端都是**字面量**（`select` 数组键 / `$vm['...']` 下标），静态对账即可，不需要 `ClassTable` 方法签名、不需要类型推导、不需要手写 `FIELDS`。

### 4.2 架构

- **读集单一入口**：依赖是显式写出的，不是扫出来或记出来的 ⇒ 可枚举、可 code review、可静态对账。避开 Compose 的「追踪窗口外读」与 SolidJS 的 scope 生命周期坑。
- **单向通道不变**：读经 `select → $vm → builder`，写经 `->command('name')` / Store 写方法 / `AsyncCommand`。不引入双向绑定、事件总线、全局 Store 之外的新原语。
- **不碰框架契约**：`BuildContext` 仍是 `final readonly`，`Element` diff、`State::watchSlot()`、`Widget::mutate()/seal()`、`drift-codegen` 全部不动。
- **类型边界从注释升级为判据**：现状 `UsersPage` 类注释「页面里任何读 Store 的地方都必须在某个 `select` 之内」是纪律，靠人守；C2 + `vm-pure` 把它变成机械事实。这是相对 Drift3「类型边界可机检」差距的最小补法。
- **不新增抽象层**：没有 `ViewBinding` / `ViewHost` / `RowSpec` / `dep-table`。视图模型就是一个 PHP 数组，调试时 `var_dump` 就能看。

### 4.3 实现

| 落点 | 改动 |
|---|---|
| `src/` | **零改动** |
| `tools/Lint/SourceQuery.php` | 新增 `ctorParams()`，不改 `readBody()` 现有契约（§5.3） |
| `tools/Lint/Rules/` | 新增 `VmKey.php`、`VmPure.php`；`DeadSubscription` 合并属性来源 |
| `tools/Lint/fixtures/` | 三条夹具（含等价书写形态） |
| `apps/users/src/Pages/UsersPage.php` | 灰度改一个文件 |
| `drift-codegen` / gate 接线 | **不动**。两条新规则挂进已有的 `lint` 项与 `selftest` |

纯 token 级：`SourceQuery::classes()` 已给方法体区间、`matchBracket()` / `nextIndex()` / `prevIndex()` 现成；不碰 `ClassTable` 的方法签名表（那需要类型推导），不引入 php-parser（`vendor/` 在 `.gitignore`，php-parser 只是 phpunit 的传递依赖，T0 门依赖它会在 fresh clone 上 `Class not found`）。

无新 AOT 形态：字面量数组 + 闭包 `fn(array $vm)` 都是仓库现有形状（7 处 `select: array` + `builder(static fn(array $rows))` 已在跑）。

### 4.4 性能

**三项正收益、两项必须承认的边界、一项量化。**

正收益：

1. **订阅者数量下降**：`UsersPage` 4 → 3 ⇒ 每次 `Subject::notifyChanged()` 少一次回调求值。`Subject` 是粗广播（`src/State/Subject.php:48` 遍历全部 subscribers），这是 O(订阅者) 的直接减少。
2. **零新增热路径成本**：没有 `Projected` 分配、没有 `projCache` 查表、没有接口方法、没有额外闭包。`select` 每次通知仍只求值一次。
3. **分区锁住重建范围**：输入一个字符只重建分区 A 的 `Row`；分区 B 的 `AsyncValue` 是 `IDENTITY_STABLE`（同一值复用同一实例）⇒ `===` 门控挡住；`visible()` 来自 `Computed`（`UserStore.php:98`），依赖未变时复用同一批 `User` 对象 ⇒ 列表不会白重建。

必须承认的边界（「性能后置」 premise 的适用范围）：

| 类别 | 能否靠后续编译器补齐 | 本方案处置 |
|---|---|---|
| 调用开销、去虚化、分配、缓存 | **能**（上游 #183–#186 已实证 `src/` 零改动即得） | 不预先优化 |
| **通知粒度**（`Subject` 粗广播 O(订阅者)） | **不能** | 保留，登记为 B 档（下） |
| **重建工作量**（整树 vs 子树） | **不能** | 由 C3 分区从设计上控制，并量化 |

粗广播与细粒度状态原语是同一枚硬币的两面：本仓 `UserStore` 注释明写「依赖取整个 Store（粗依赖）」是**有意设计**；`src/State/` 只有 `Subject`/`SubjectBuilder`/`Computed`，没有 Cell。改成细粒度（每字段一个可订阅槽 ⇒ O(变化字段)）是**唯一编译器补不了的架构差距**，但按「性能后置」前提**登记不实施**。重启条件写死：Store 字段 > 20 且订阅者 > 20，或 T2 量出粗广播成为帧预算主因。

**量化口径（别假装有现成命令）**：`tools/frame-tax-probe.php --check` 判的是合成静止树的每帧固定税，`tools/probe-frame-gates.php` 给的是真机 layout/paint 门控计数 —— 两者都**不能**回答「输入一个字符重建多少节点」。P3 要配一次性 instrumentation（`Element::rebuild()` 计数，临时、不入 git）+ 真机驱动，拿改前/改后同操作的两个数。判据：**改后重建节点数不超过改前的 1.5 倍**；超了先调整分区边界（把 `running` 拆回独立切片），不回到生成层。

---

## 5. 三条判据（全部纯 token 级）

| 判据 | 管什么 | 落点 |
|---|---|---|
| `vm-key` | C1 的字面量键 ↔ `builder` 里 `$vm['...']` 双向对账 | 新规则 `tools/Lint/Rules/VmKey.php` |
| `vm-pure` | C2：`builder` 区间零裸读；`select` 区间零写调用 | 新规则 `tools/Lint/Rules/VmPure.php` |
| `dead-subscription` 修复 | 属性表补构造器提升（S1） | `SourceQuery` + `DeadSubscription` + 夹具 |

### 5.1 `vm-key`

按 `SubjectBuilder::make(...)` 链分组（用 `matchBracket()` 定界），收集 `select` 闭包里数组字面量的字符串键，与同一链 `builder` 闭包里 `$vm['...']` 的字面量下标做差集：

- 下标不在键集 ⇒ **ERROR**：`视图模型没有这个键 qery，可用键：query, running`；
- 键没被用 ⇒ **WARN**：多余读点（不阻断，但要显形）。

两端都是字面量，不碰 `ClassTable`，不需要类型推导。这比前几版设计的 `dead-field`（要手写 `FIELDS` 常量）更便宜，且源头是同一次 `select`，天然同源。

### 5.2 `vm-pure`

- **`builder` 区间**：出现 `$this-><属性>` 且该属性在 subscribable 类型表里，其后的方法名**不在写白名单**（`set*` / `run` / `load` / `reset` / `add*` / `remove*` / `save*` / `toggle*` / `clear*`）⇒ ERROR「读点必须移到 `select`，`builder` 里只准读 `$vm`」。写通道与 `->command()` 字符串照常放行。
- **`select` 区间**：出现上述白名单方法 ⇒ ERROR「投影里不许有副作用」。

理由不是「新风险」（`select` 本来每次通知都求值），而是合成 VM 后**一处副作用会拖累所有字段的同一次比对**。本轮实测 7 个订阅文件的 `select` 区间内零写调用、5 个待迁移文件 `build()` 顶层零副作用，但那是现状快照，不是判据 —— 要配注入夹具常驻。

### 5.3 P0：修 S1（唯一与订阅线无关的真缺陷）

`readBody()`（`tools/Lint/SourceQuery.php:570`）刻意跳过方法体以避免把提升参数误读成属性 —— **这个行为不要改**，改了会让别处误读。正确修法是**新增一张表**：

```php
// SourceQuery::classes() 的返回结构新增一项
'ctorParams' => ['store' => 'App\Users\Store\UserStore', ...],
```

`DeadSubscription::check()` 把 `$info['properties']` 与 `$info['ctorParams']` 合并后再交给 `subjectCalls()`。属性类型解析沿用现有 `resolveName()`，FQN 已足够喂 `ClassTable::subtypesOf()`。

**出口判据必须写在夹具里，不能只跑一次性脚本**：`DeadSubscriptionFixture.php` 增一个 `__construct(private readonly Cell $hovered)` 的 Bad 类，`$expected['dead-subscription']` 从 1 → 2；`selftest` 常驻门禁。这是本仓第二次因「夹具没覆盖等价书写形态」漏判（第一次是 `SelectPurity` 的 `static fn`），纪律上要按「新增一种合法写法就要补一条夹具」执行。

### 5.4 变异清单（每条都必须红）

| 变异 | 期望 |
|---|---|
| 把 `select` 里的 `'running'` 删掉、`builder` 仍读 `$vm['running']` | `vm-key` 红 |
| `$vm['query']` 改成 `$vm['qery']` | `vm-key` 红，文案带可用键列表 |
| `builder` 里加一句 `$this->store->query()` | `vm-pure` 红 |
| `select` 里加一句 `$s->setQuery('x')` | `vm-pure` 红 |
| 夹具里把传统属性声明换成构造器提升 | `dead-subscription` 仍报（`selftest` 期望 2 条） |

---

## 6. 落地序列

| 段 | 做什么 | 出口判据 | 回滚点 |
|---|---|---|---|
| **P0** | 修 S1：`ctorParams()` + `DeadSubscription` 合并 + 夹具补提升 Bad 类 | `selftest` 期望 1→2 且全绿；全仓新增诊断逐条确认为真缺陷 | 单独一个 `tools:` 提交，可整段 revert |
| **P1** | `vm-key` + `vm-pure` 两条规则 + 3 条夹具，挂 `lint`/`selftest` | §5.4 前四条变异全红；现有 23 处订阅零误报 | 摘掉规则注册 |
| **P2** | `UsersPage` 单文件灰度（§2.2 代码） | `gate --fast` 25 项全过；`docref` 0 FAIL；`vm-key`/`vm-pure` 全绿 | 该文件回退到 4 个独立切片 |
| **P3** | 临时 instrumentation 量「输入一个字符」的重建节点数，改前/改后两个数 | 改后 ≤ 改前 1.5 倍；量完移除 instrument，数字写进本文 §4.4 | 超标 ⇒ 先调分区边界，不回生成层 |
| **P4** | 其余 6 个文件按同形状迁移（可选，按收益排序） | 每条新 VM 都过 `vm-key`/`vm-pure`；tutorial 补一段可抄样例 | 逐文件回退 |

**P0 是前置**：`vm-pure` 判「`$this->store` 是不是 Store 属性」同样依赖属性表，不先修 S1 就会和 `dead-subscription` 一起空转。

**不需要重跑 T1/T2**：`src/` 零改动、无新 AOT 形态。但 P2 若改了 `UsersPage` 的闭包形状，建议跑一次 T1 翻译作为保守验证（不是判死条件）。

表格线（`dead-field` + `FIELDS`、`rowKey('id')`）与订阅线**完全解耦**，可并行、可做可不做，见 §7。

---

## 7. 表格线（可选并行，行形状完全不动）

这两条与订阅线无关，是 WPF 头号坑在本仓的活体证据：

| 项 | 现状 | 建议 |
|---|---|---|
| `field('nmae')` 拼错 | 只在运行期渲染到那一行才 `Errors::report`（`src/Widget/TableColumn.php:362`），构建期无 `dead-field` 规则 | `FIELDS` 常量 ↔ `field()` 字面量对账；两端都是字面量，不碰类型推导 |
| `keyOf(callable)` | 27 处，apps 3 处；身份靠闭包 | 新增 `rowKey(string $field)`（约 12 行），`keyOf` 保留作逃生口，共用同一渲染路径 |

**行数据形状不动**：`array<string,scalar>` 关联数组原样保留。此前设计的 `final readonly class Row` 已撤 —— `TableColumn::textOf()` 有 `is_array` 守卫（对象行直接报错），且 PHP 的 `readonly` 不提供值相等，对象 `===` 是身份比较，换过去没有收益。

---

## 8. 成熟框架六坑的处置

| # | 坑（跨框架复现） | 本方案 |
|---|---|---|
| 1 | **名字拼错静默**（WPF `Binding` 只在 Output 打 trace） | `vm-key` 字面量双向对账，构建期报 + 可用键列表 |
| 2 | **selector 返回新对象 ⇒ 白重建 / 不重建**（Redux FAQ 的 `filter()` 新数组、Flutter `Selector`） | C1 全标量 ⇒ `===` 天然值相等；`SelectPurity` 已有夹具钉住 `array` 放行；不新增比较逻辑 |
| 3 | **行身份用 index**（Flutter `ValueKey` / React `key` / Qt 代理索引） | 本仓 `Each::make(fn(User $u): int => $u->id)` 已强制 key（R4），不动 |
| 4 | **可用态漏刷新 ⇒ 按钮永久灰**（WPF 漏 `RaiseCanExecuteChanged`） | **C2 结构性消除**：`builder` 里没有 Store 可读，读点只能在 `select` 里 ⇒ 漏订阅写不出来。P0 顺带修掉这条防线自身的假绿 |
| 5 | **增量通知难做对**（Qt `begin/endInsertRows`） | **不做增量通知**，`Subject` 粗广播不变 |
| 6 | **追踪窗口外读不订阅**（Compose Snapshot / MobX 异步块 / SolidJS `untrack`） | **不做运行期追踪**，读集由 `select` 显式给出，没有窗口概念 |

外加一条本仓自有的坑：**判据同源空转**。`projection-cases` 两端手写、指纹漏 `.cc`、`readBody()` 漏提升属性、`vh-unbound` 漏 `use` —— 都是「判据两端来自同一份手写输入」。`vm-key` 的两端虽然都在同一文件，但一端是**定义**、一端是**使用**，差集才是对账；`selftest` 夹具必须覆盖等价书写形态。

---

## 9. 明确不做（防止回魂）

| 不做 | 一句话依据 |
|---|---|
| 生成 PHP trait / `ViewHost` / `ViewBinding` | 写 `use` 时生成物不存在 ⇒ 每次编辑都红。语言边界，不是设计取舍 |
| 运行期读追踪 / 自动订阅 | 没有常驻 scope；窗口外读、退订、事件回调四类坑，且 AOT 无反射 |
| `$ctx->watch` / `InheritedWidget.of()` | 要推翻 §18③ 与 A4；React 到 19 无 context selector，高频输入场景不合适 |
| 整套 Drift3（N4 零闭包 / `buildInto` / dep-table） | 六道闸门 0/6、git 零提交、渲染范式不同、验证重做成本远大于迁移成本 |
| dep-table 自动生成元数据 | 生成 JSON 不违反 PHP 边界，但前提是「状态以静态类型形参进入构建函数」，本仓 `build()` 读 `$this->store` 正是探针判为「编译期断裂」的形态；且其真实消费者只是 CI 漂移门禁 |
| 收编 `onChanged(callable)` → 字符串名 | 90 + 5 处，改动面远大于收益，与坑 #4 无关；保持 `callable` |
| 行换 `readonly class Row` / 换对象 DTO | `textOf()` 有 `is_array` 守卫；`readonly` 不提供值相等 |

---

## 10. 诚实边界

- 本文**未改 `src/`、未新增 lint、未碰 `gate`**；全部代码为设计草案，未编译、未跑 T1/T2。按 T0 档。
- 分区边界（哪些字段进同一个 VM）目前是**人工判断**，只有 C3 这条原则，没有自动判据。判错只影响重建范围（性能），不影响正确性。
- `vm-key` 按 `SubjectBuilder` 链分组的实现依赖 token 定界的准确性；复杂链式表达式（跨变量赋值、闭包抽成方法）会落到「不判」的诚实边界，不假报绿。
- `vm-pure` 的写白名单是命名约定，不是类型事实；Store 里若出现 `fetch()` 这类「读但名字像写」的方法需要登记。首版宁可漏判，不误报。
- 重建节点数的 1.5 倍阈值是**待验的工程判据**，不是实测结论；P3 量出来才有意义。
- 粗广播 O(订阅者) 的性能特征未变，B 档重启条件见 §4.4。
- 前八份文档全部未提交（`git status` 8 个 `??`）；本文同样未提交。

---

## 11. 证据链索引（前八份文档的存活结论）

| 文档 | 本文继承的部分 | 已被取代/否决的部分 |
|---|---|---|
| `REVIEW-UI-DATA-2026-11-11.md` | §12.3 六坑、§12.4「WPF 的答案不是把回调请回来」 | §11 的方案空间排序 |
| `DESIGN-UI-DATA-CONTRACT-2026-11-11.md` | 三本名册的判据同源分析 | `Projection`/`RowSpec` 形状 |
| `REVIEW-UI-DATA-CONTRACT-2026-11-11.md` | §1 撤 AOT 收益、§9 可逆性分层、§8 dep-path 36/37 | §10 DX 优先的自动追踪翻案 |
| `DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` §11（v2.1） | 破坏面实测 7 项 | 生成层、`readonly Row`、`ViewBinding` 全撤 |
| `REVIEW-UI-DATA-DX-FIRST-2026-11-12.md` | 五处落地冲突（`textOf` 守卫 / `readonly` 无值相等 / codegen 门接线 / 订阅载体 / 判据同源） | 12 条处置表的结论已并入本文 |
| `DESIGN-UI-DATA-FINAL-2026-11-12.md` | ⛔ 否决段 + §0.0 存活清单 S1–S7、7 文件 23 处迁移单位、7 条可实施性发现 | 整套 `ViewHost` + trait 生成方案（已否决） |
| `ANALYSIS-UI-DATA-ALTERNATIVES-2026-11-12.md` | 四族方案空间、`select: array` 7 处先例、事故注释、`vm-key` 白捡判据、§8 夹具等价形态 | §3 的「4 合 1」被本文 §2.3 修正为分区；V1 `sameList()` 被 §3 删除 |
| `ANALYSIS-ARCH-DRIFT3-VS-2026-11-12.md` | A/B/C 三层评级、三档清单（A 该做 / B 登记不实施 / C 不做）、编译器版本差、粗广播与 dep-table 同源 | 无 |

**净结论**：绕了八轮回到「保留 `select`/`builder`」，但不是空手 —— 拿到的是①确定的否定边界（PHP 下生成族不可行）、②一个真缺陷（S1 假绿）、③一个更好的形状（分区全标量 VM），以及④「框架零改动」这个此前所有版本都没做到的实现成本。
