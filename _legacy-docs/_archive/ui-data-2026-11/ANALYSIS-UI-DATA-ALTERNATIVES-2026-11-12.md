# 推演 · 放弃生成层之后，还有没有别的路 · 2026-11-12

> 状态：**只读推演，未改 `src/`**。基线 HEAD `5f26dfc`。
> 前提：用户已否决「构建期生成订阅代码」（见 `DESIGN-UI-DATA-FINAL-2026-11-12.md` 顶部 ⛔ 段）。
> 输入：六份旧稿的结论 + 本轮源码实测 + 成熟框架的坑检索。
> 目标：在不生成代码、不违反既有架构裁决的前提下，还有没有比现状更好的形状。
> ★ **最终评估见 `docs/PLAN-UI-DATA-RECOMMENDED-2026-11-12.md` 顶部 ⚠️ 裁决**：
> 该稿先把本文 §3 的「4 合 1」修正为「分区全标量 VM」、删掉 §4.2 的 `sameList()`，
> 再与现状逐项对比后**把整包判为不值得推进**。存活的只有两档：修 S1（真缺陷）、
> 表格线 `dead-field`；「多字段按一致性合并」降级为按需纪律，不铺开。
> 本文保留为推演证据，不再作为开工依据。

---

## 0. 结论（先读这一段）

**有，而且不在框架里，就在仓库里。**

答案叫「**视图模型切片**」：**一次 `select()` 把本次视图需要的全部值收进一个关联数组，
一次 `builder()` 造整页**。仓库里已经有 7 处同款写法，其中 `ResultCard` 的注释明记着
它正是用来修「漏订阅导致状态区说谎」这个事故的。

`UsersPage` 的类注释（`apps/users/src/Pages/UsersPage.php:35-40`）已经把纪律写死了：

> ⇒ 页面里**任何**读 Store 的地方都必须在某个 `select` 之内，否则它就是快照。

现状是用 **4 个 `select` 覆盖 4 个读点**；改成 **1 个 `select` 覆盖全部读点**之后，
`builder()` 里根本不出现 `$this->store` ⇒ **「读了却没进订阅」这个事故形态写不出来了**。
这是结构性收益，不是语法糖，而且**不需要生成代码、不需要运行期追踪、不新增类型、
不动 `BuildContext` / `Element` / widget 基类 / `drift-codegen`**。
框架侧只有一处改动：给 `SubjectBuilderState` 加逐元素门控（§4.2）。

代价两条，都有成熟框架实测依据，也都能量化：整页粒度（React Context 同款坑）、
视图模型必须逐元素门控（Redux `shallowEqual` 同款坑）。

---

## 1. 方案空间重新摊开

前面六份文档其实只在三族里打转。加上第四族，全空间是：

| 族 | 成熟框架 | 订阅怎么建立 | 要生成代码吗 | 动 `BuildContext`/Element 吗 | 结论 |
|---|---|---|---|---|---|
| ① 生成族 | Svelte / WinUI `x:Bind` | 构建期扫读点 → 生成包装 | **是** | 否 | **已否决**（用户裁决） |
| ② 追踪族 | Compose Snapshot / MobX / SolidJS / Vue 3 | 运行期读即订阅 | 否 | 是（追踪窗口） | 否决，§2.2 |
| ③ 登记族 | Flutter `InheritedWidget.of()` / React Context / WPF `DataContext` | `$ctx` 上一次调用同时读+登记 | 否 | 是 | 代价过高，§2.3 |
| ④ **映射族** | **Elm `view : Model → Html` / React-Redux `mapStateToProps` / 本仓现状** | **一次显式映射，值作为视图模型传入** | 否 | **否** | **可行，§3** |

关键区分：②是**隐式**追踪，③是**把订阅藏在取值背后**，④是**显式一次映射**。
前面几轮的盲点是把 `select()` 一直当成「单值切片」，没注意到它早就支持返回 `array`，
而且仓库里已经用它做过「多字段一次切」。

---

## 2. 前三亚族为什么死

### 2.1 生成族（已否决，不重复）

PHP 的 trait 只能在类定义处静态 `use`，没有编译单元接管 ⇒ 写 `use XxxView;` 时生成物
必然还不存在 ⇒ `php -l` / IDE 全红。Svelte / WinUI 能「源码零感知」的前提是编译器接管
整个编译单元、生成物不落在开发者写的文件里。**PHP 没有这个前提。**

### 2.2 追踪族：读即订阅（Compose / MobX / SolidJS）

框架依据（本轮检索）：

- SolidJS 官方文档：*「当一个 Signal 没有在追踪作用域内被访问时，它的更新不会触发系统更新」*。
- MobX 文档明列不反应的三种情形：**追踪函数外读取的值、异步代码块里读的可观察量**、
  「spawned from the function」的东西（`setTimeout` / `promise.then`）。
- SolidJS 的经典泄漏警告：*"computations created outside a `createRoot` or render will
  never be disposed"*，典型触发场景正是**事件回调里读响应式属性**，解法是手动 `untrack()`。
- SolidJS 组件**只运行一次**，靠 JSX 建立追踪作用域；本仓 Widget 每帧 `new` 一次
  （`Element::reuseChild()` 的 `widget !== newWidget` 恒真），没有对应的常驻作用域。

⇒ 这正是评审稿坑⑥「追踪窗口外读」。在本仓落地要：给 `StatelessElement::rebuild()`
开窗口（改所有控件共用的重建路径）、处理退订生命周期、给「回调里读」加 `untrack` 形状。
**代价换来的还是隐式行为**（读点分散在 build 里，看代码不知道订阅了什么），与用户
「太难理解」的反馈直接冲突。

### 2.3 登记族：`$ctx->watch($store)` —— 最像新方案的一条，重点说

Flutter 的 `dependOnInheritedWidgetOfExactType()` 设计确实漂亮：**一个调用同时干两件事** ——
返回数据、把当前 Element 登记为依赖者。还有个只找不登记的
`getElementForInheritedWidgetOfExactType()`（`read` vs `watch`），语义分得很干净。
照抄到本仓会是：

```php
public function build(BuildContext $ctx): Widget
{
    $ctx->watch($this->store);                       // 一行：登记，不查数据
    return TextField::make()->value($this->store->query());
}
```

它不违反 §18 第 ③ 条（不向上查找）、也不违反第 ② 条（`ctx` 不持有业务数据、
只是接收参数登记），源码自洽无需生成。**但有三道硬账**：

1. **要推翻两条有据裁决**。`BuildContext` 是 `final readonly class`，不带 Element 引用，
   构造点散在 `Application.php:450`、`OverlayManager.php:551` 和 8 个测试/夹具里 ——
   要回访当前 Element 就得改 `readonly` 语义和全部构造点。更要紧的是
   `State::watchSlot()` 的注释（`src/Widget/State.php:275-282`）明写「**`watch()` 只允许在 `initState()` 调
   （A4 防 build 期订阅）**」，而 `ctx->watch()` 恰恰发生在 build 期。
   `DEBT-2026-11-02.md:1190` 也记着 §18 第 ③ 条是明文禁令，重开需要证据。
2. **粒度退化，且退化在最高频的场景**。Flutter 的
   `updateShouldNotify()` 与 React Context 都按「整个值」通知；React 官方到 19
   仍**没有**内置的 context selector，`useContextSelector` 是社区方案，且它的文档
   承认「组件仍会进入渲染阶段，只是不 commit」。React 社区的共识是
   *"Context is for dependency injection of slowly-changing values"* ——
   而本仓 `UsersPage` 的搜索框是**每敲一个字符**就变，正是文档点名的
   *"typing feels laggy"* 反例。
3. **Flutter 自己的三个坑照样要背**：`initState` 里调 `of()` 抛异常（登记有作用域，
   出了 build 就得报错）；`updateShouldNotify` 漏比较某个字段 ⇒ 永不刷新（**正是坑 #4**）；
   同一个 build 的 context 找不到同级 Widget（需要 `Builder` 拆层）。

⇒ **不是做不到，是要推翻两条明文裁决 + 改共用重建路径 + 换来一个更差的粒度**。
这三条加起来，不如不动。

---

## 3. 第四族：一次映射（本轮主推）

### 3.1 框架依据

- **Elm**：`view : Model → Html msg`。整个 Model 一次性映射成视图，没有订阅样板、
  没有依赖数组、没有读点追踪。副作用全部走 `Cmd`，视图函数是纯的。
- **React-Redux `mapStateToProps`**：一个函数从 store 收全部所需值，返回 props 对象；
  `connect` 用 `shallowEqual` **逐键比较**决定要不要重渲染。
- **Vue `computed`**：多个派生值合成一个值，比较的是值不是引用。
- **WPF MVVM**：ViewModel 一次性暴露所有属性，视图逐个绑定。

共同点：**数据映射和视图构造是两个明确的阶段**，中间那个「视图模型」是显式的值。
这正是本仓 `select()` → `builder()` 的现成结构，只是现状把它嵌套用了 4 次。

### 3.2 ★ 仓库里已经有 7 处，还留着事故现场注释

`select` 返回 `array` 的现存写法（实测）：

```
apps/users/src/Pages/SignupPage.php:58     [$f->name, $f->errorOf('name')]
apps/users/src/Pages/SignupPage.php:69     [$f->email, $f->errorOf('email')]
apps/users/src/Pages/UsersPage.php:131     $s->visible()
apps/watermark/src/Widgets/ResultCard.php:71  ['result'=>..., 'polling'=>...]
apps/watermark/src/Widgets/ResultCard.php:152
apps/watermark/src/Widgets/UploadCard.php:128 $s->selected()
apps/watermark/src/Widgets/UploadCard.php:199 $s->uploadedKeys()
```

两处注释把设计意图写死了：

`SignupPage.php:56`：
> 值 + 该字段错误一起切（**数组按值 `===` 门控，不再多挂一条订阅**）

`ResultCard.php:68-70`（事故复盘）：
> `select` 只盯 `lastResult()`，而「刚开始轮询」时它是 `null → null`（没变化）
> ⇒ builder 不重建 ⇒ 状态区停在「等待任务提交后自动查询结果…」，而下面
> `pollControl()` 已经冒出「停止轮询」按钮 —— **两句话自相矛盾**。

修法就是把 `polling` 一并塞进同一次 `select`。**这正是 2026-10-31 `UsersPage`
按钮永久灰的同族事故**（读了会变的值却没在订阅里读），而且仓库已经用「多字段一次切」
修过一次。前面六份文档都没把这条既有经验当设计输入。

### 3.3 `UsersPage` 代码对照

**现状**（4 处 `SubjectBuilder::make($this->store)`，嵌套 4 层，8 个闭包；
结构取自 `apps/users/src/Pages/UsersPage.php:71-135`）：

```php
final class UsersPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()->top(
            Column::make()->children([
                SubjectBuilder::make($this->store)
                    ->select(static fn(UserStore $s): string => $s->query())
                    ->builder(fn(string $q): Widget => TextField::make()...),
                SubjectBuilder::make($this->store)
                    ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
                    ->builder(fn(bool $running): Widget => Button::primary('刷新')...),
                SubjectBuilder::make($this->store)
                    ->select(static fn(UserStore $s): AsyncValue => $s->page()->value())
                    ->builder(fn(AsyncValue $v): Widget => $v->fold([...])),
                // 第 4 处还嵌在第 3 处的 fold 分支里：->select(fn => $s->visible())
            ])
        )->center(...);
    }
}
```

**视图模型切片**（1 处 `SubjectBuilder`，2 个闭包，`builder` 里零 `$this->store`）：

```php
final class UsersPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return SubjectBuilder::make($this->store)
            ->select(static fn(UserStore $s): array => [
                'query'   => $s->query(),                    // string
                'running' => $s->page()->isRunning(),       // bool
                // AsyncValue 是对象 ⇒ 投影成标量，别塞进视图模型（§4.2）
                'state'   => $s->page()->value()->stateName(),
                'message' => $s->page()->value()->message(),
                'rows'    => $s->visible(),                  // list<User>，见 §4.2
            ])
            ->builder(function (array $vm): Widget {
                return Dock::make()
                    ->top(Column::make()->children([
                        TextField::make()->value($vm['query'])...,
                        Button::primary('刷新')
                            ->enabled($vm['state'] !== 'loading')
                            ->command('users.refresh'),
                        // 四态展示改用 $vm['state'] / $vm['message'] 分支
                    ]))
                    ->center(/* 表格吃 $vm['rows']，沿用现状列定义 */);
            });
    }
}
```

**开发者心智模型一句话**：`select` 里列齐这次视图要用的值，`builder` 里只准读 `$vm`。

### 3.4 为什么这一条是结构性的

| 事故形态 | 现状 | 视图模型切片 |
|---|---|---|
| `build()` 里读了 Store 却没包进任何 `select` | 靠注释纪律 + `dead-subscription` 判据 | **`builder` 里没有 `$this->store` 可读** |
| 新增一个读点忘了加订阅（`ResultCard` 事故） | 极易发生 | **写不进 `builder`，只能写在 `select` 里** |
| 订阅切片互相矛盾（状态区说谎） | 4 个独立切片各比各的 | 同一个视图模型一次比对，状态天然一致 |

对比前面被否决的方案：生成族靠「构建期扫读点」、追踪族靠「运行期记读点」，
都是为了自动找出读集。而这里**读集本来就只有一个入口**（`select` 闭包），
不需要找 —— 只是把它从 4 个入口合成 1 个。

### 3.5 顺带白捡一条判据

`select` 返回的是**字面量关联数组**（`'query' => ...`），`builder` 里取值也是
**字面量下标**（`$vm['query']`）⇒ 两端都是 token 可见的字符串，可以静态对账：

- `$vm['qery']` ⇒ 报「视图模型没有这个键，可用键：query, running, state, message, rows」；
- `select` 里登记了却没在 `builder` 里用 ⇒ warn（多余读点）。

**这正是 WPF 头号坑「名字拼错静默」的解法**，而且两端都是字面量、不碰
`ClassTable` 的方法签名、不需要类型推导、不需要生成代码。比前面几轮设计的
`dead-field`（要手写 `FIELDS` 常量）更便宜。

---

## 4. 代价与排坑

### 4.1 粒度退化到整页（诚实承认）

现状：改 `query` 只重建 `TextField` 那支。改后：4 个切片合成 1 个 ⇒ 整页重建。
`Element::reuseChild()` 的 `widget !== newWidget` 恒真 ⇒ 是真重建，不是编译器
能补的调用开销。

成熟框架的结论一致：React Context 的所有 consumer 一起重渲染，
`React.memo` 挡不住 context 变化引发的 consumer 重渲染；社区给的缓解手段是
「拆 context」「收窄 Provider」「`memo` 子树」「用 selector 库」，而
`useContextSelector` 自己也承认组件仍会进渲染阶段。

**本仓的取舍**：用户前提是「性能后置，后续编译器优化即可享受」，所以不作为阻断，
但必须量化，不能只写「可接受」。

★ **没有现成命令直接给这个数，别假装有**：`tools/frame-tax-probe.php --check` 判的是
合成树 500 行的 6 条自洽判据（静止树的每帧固定税），
`tools/probe-frame-gates.php` 给的是真机 layout/paint 门控计数 ——
两者都不能回答「输入一个字符重建了多少节点」。V2 要配一次性 instrumentation
（`Element::rebuild()` 计数，临时、不入 git）+ 真机驱动，拿改前/改后同操作的两个数。
超标再考虑拆成 2 个 `SubjectBuilder`（头部 / 列表），**不是回到生成层**。

### 4.2 视图模型必须逐元素门控（Redux 同款坑）

Redux FAQ 的原文警告：`mapStateToProps` 里 `todos.filter(...)` 每次返回新数组 ⇒
浅相等检查永远失败 ⇒ **即使值没变也每次重渲染**；解法是 `shallowEqual` 逐键比较 +
reselect 记忆化。React-Redux 开发模式甚至会主动打印
*"Selector unknown returned a different result when called with the same parameters"*。

本仓对应风险：

- `SubjectBuilderState::sameValue()`（`src/State/SubjectBuilder.php:321`）目前只比**单个值**：
  `Equatable` 走 `equals()`，其余走 `===`。PHP 数组 `===` 是**递归逐键值**比较，
  对全标量数组正确（`SignupPage` 注释正是据此）；但**数组里含对象时退成身份比较**。
- `AsyncValue` 是 `final class`，**不 implements `Equatable`** ⇒ 塞进视图模型就白重建。
  它的 `stateName()` / `message()` / `code()` / `progressOr()` 全是标量，投影成标量即可。
- `visible()` 返回 `list<User>`（对象数组）。好消息是它来自
  `visibleComputed->get()`，`Computed` 只在依赖变时重算 ⇒ 同一批 `User` 对象被复用，
  身份稳定。但这属于**依赖实现细节的运气**，方案里要写成约束，不能当保证。

⇒ 落地要求：`SubjectBuilderState` 增加 `sameList()` 对视图模型**逐元素**走
`sameValue()`（复用现有 `Equatable` 语义），并加一条 lint：
`select` 闭包里出现 `new` / 返回非 `Equatable` 对象 ⇒ warn。这与存活清单 S6 是同一件事，
现在它有了 Redux 的实测依据。

### 4.3 其余五个坑的处置

| 坑 | 处置 |
|---|---|
| #1 名字拼错 | §3.5 字面量双向对账，中文带修法 |
| #2 新对象白重建 | §4.2 逐元素门控 + `vm-unstable` warn |
| #3 行身份用 index | 表格线独立：`rowKey('id')`，与本方案正交 |
| #4 可用态漏刷新 | **`builder` 里零 Store 读点** ⇒ 结构性消除；`dead-subscription` 继续守漏包 |
| #5 增量通知仪式 | 不做；`Subject` 粗广播不变 |
| #6 窗口外读 | 不做追踪；读点全在 `select` 闭包内，一眼可见 |

---

## 5. 落地清单（比前面任何一版都短）

| 步 | 做什么 | 出口判据 |
|---|---|---|
| **V0** | **修 S1**：`SourceQuery::readBody()` 增收 `__construct` 属性提升；夹具补一个提升写法的 Bad 类 | `selftest` 期望数 1 → 2（§8.1 ①），两种属性写法都有正向测试；全仓新增诊断逐条确认为真缺陷 |
| **V1** | `SubjectBuilderState` 加 `sameList()` 逐元素门控；`ValueEquality` 可复用也可不抽 | `SubjectBuilderTest` 16 例一条不改仍绿；新增「视图模型含 `Equatable` 对象且值相等 ⇒ 不重建」用例 |
| **V2** | `UsersPage` 单文件改写（4 处 → 1 处），`builder` 里零 `$this->store` | `gate` 全绿；临时计数拿到「搜索框输入一个字符」改前/改后两个重建节点数并记录 |
| **V3** | `vm-key` 判据 + 投影纯度扫描（§8.1 ②）+ 迁移其余 6 处（可选） | `$vm['qery']` 变异必红；`select` 里夹带 `->set()` 的注入夹具必红 |

**框架改动面：V1 一处 `src/State/SubjectBuilder.php`，V0 一处 `tools/Lint`。**
`BuildContext`、`Element`、widget 基类、`gate` 结构、`drift-codegen` 全部不动。

---

## 6. 诚实边界

- 本文**未改 `src/`、未新增 lint、未跑 T1/T2**；代码均为设计草案，未编译。
- §3.3 的 `Dock` / `Column` / `Button::primary()->enabled()->command()` /
  `TextField::make()->value()` 取自现状写法；四态分支的改写只示意结构，
  实际迁移要保留 `AsyncValue::fold()` 的穷尽保证（`UsersPage` 类注释第 33-35 行），
  不能真退化成手写 `match`。
- 「`builder` 里零 Store 读点」目前是**纪律**，不是编译期强制。要变成判据很容易
  （`builder` 闭包 token 区间内不得出现 `$this->` + Subject 属性），但那是 V3 之后的事；
  在此之前 `dead-subscription` 修好后仍能守住「整个页面忘了包 SubjectBuilder」。
- 整页重建的实际倍数**本轮没量**，两个实测值要等 V2 的临时计数才有。
- 视图模型是关联数组 ⇒ 键名是字符串，存在同名不同义的可能；V3 的字面量对账
  只能抓拼写，抓不了语义。这是与「名字体系」共生的老问题，不假装解决。

---

## 7. 给裁决的对比

| | 改动面 | 新概念 | 生成代码 | 结构性防漏订阅 | 粒度 |
|---|---|---|---|---|---|
| 什么都不做，只修 S1 | `tools/Lint` 一处 | 无 | 无 | 靠判据 | 切片级（不变） |
| **视图模型切片（本文）** | `src/State` 一处 + `tools/Lint` 一处 + app 改写 | **零**（复用已有 `select(): array`） | 无 | **`builder` 里读不到 Store** | 整页（可量化、可回拆） |
| 登记族 `$ctx->watch()` | `BuildContext` + `Element` + 10 处构造点 + 推翻 A4/§18③ | 登记作用域 / read-watch 区分 | 无 | 读+登记一步 | 整页且高频退化 |
| 生成族（已否决） | `drift-codegen` 新层 + trait + 判据 | 生成物 / `use` / 幂等 | **有** | 是 | 整页 |

**建议**：V0（修 S1）→ V1（逐元素门控）→ V2（`UsersPage` 单文件灰度并量一次）。
三步都不引入开发者要学的新东西，也不碰用户刚否决的那条路。

---

## 8. 与 Drift3「22 · UI 控件与业务隔离」交叉核对（2026-11-12 补）

读了 `22-UI控件与业务隔离.md` 与它的事实源 `40-UI控件语法与业务数据隔离推演.md`。
**结论：形状一条都不能照抄，判据层有三条能直接用。**

Drift3 是 `N4 零闭包` + 无全局 Store + `Cell` 细粒度 + 具名投影函数 + `dep-table` 自动订阅；
本仓是闭包 `select`/`builder` + `Subject` 粗广播 + 显式映射。两代在「隔离」上的做法相反，
且 Drift3 自己写明 `SubjectBuilder::make()->select(fn)->builder(fn)` 与本仓 N4 冲突、
「只能学位置、不能学写法」。照抄形状等于推翻现有 23 处订阅，或回到刚否决的生成层。

### 8.1 能直接用的三条

**① 自证纪律要升级：夹具必须覆盖「等价书写形态」，不只是「配了注入用例」。**

`22` §7 / R9 写「没有自证的 lint 是假绿灯」。本仓**有**自证体系
（`tools/selftest.php` 的夹具 + `gate` 的 `lint --selftest`），所以这条看上去已达标 ——
但 S1 正好从缝里漏了过去：`dead-subscription` 的夹具
`tools/Lint/fixtures/src/Widget/DeadSubscriptionFixture.php` 两个类都用
`private Cell $hovered;` **传统属性声明**，没有一个用构造器属性提升。
于是 `SourceQuery::readBody()` 不收提升写法这件事，正向测试一条都抓不到，
`gate --fast` 25 项照样全绿。

⇒ **V0 出口判据要改**：不是「修完后一次性脚本验出 0 条 → 1 条」，而是
在夹具里加一个 `__construct(private readonly Cell $hovered)` 的 Bad 类，
把 `tools/selftest.php` 里 `$expected['dead-subscription']` 从 1 条改成 2 条，
让这条洞**常驻**在门禁里。否则修完还会再退化成假绿。

本仓 `SubscriberMarkFixture.php` 的类注释已记过同族教训
（判据收紧时夹具没同步 ⇒ selftest 报「期望 2 条、实际 0 条」）。这是第二次，
原因从「规则改了」变成「等价书写形态漏了」，所以纪律要加一层。

**② 补一条「投影纯度」判据（`22` §10 R5 / R2）。**

R5 写「投影只做实体 → 标量，不查库、不算聚合」，R2 要求 `Cell::set()` 的调用点
只在编排层并配变异体。本仓上一轮只查过 5 个待迁移文件 `build()` **顶层**零副作用，
没查 `select` 闭包内部。本轮实测 7 个文件的 `select` 闭包内确实零写调用，
但那是**现状快照，不是判据**。

⇒ V3 的 `vm-key` 顺带扫 `select` 闭包 token 区间：出现 `->set()` / `->load()` /
`->run()` / `->save()` 等写方法即报红，配一个注入夹具。理由不是「方案引入新风险」
（`select` 本来就在每次通知时求值），而是合并成 1 个视图模型后，
一处副作用会拖累**所有字段**的同一次比对 —— 影响面被放大了。

**③ 反向印证：视图模型切片落在 Drift3 AOT 探针判定的最优形态上。**

`40` §3.3 实测：`buildRowsTemplate(RowTable $rows, int $n)`（数据走形参）
= 2 静 / 0 动 / 1 Var；把同一份数据放实例字段再 `build()` = 2 静 / 1 动 / 11 Var。
结论是「数据走形参，不进控件实例字段」。本仓 `builder(fn(array $vm): Widget => ...)`
正是数据走形参，`UsersPage` 现有的 `builder(static fn(array $rows) => Each::make(...))`
是活证据；视图模型切片还把 `build()` / `builder()` 里的 `$this->store` 读点一并清掉。

⚠️ 口径必须写死：那是 tpc 0.10.0 / 另一套代码 / 探针替身的读数，
**只能当方向旁证，不能当本仓的性能证据**。与本仓既有纪律一致
（「数数不是对账」、绝对 µs 不可跨会话对照，只有倍率是结构量）。

顺带：`22` §4 的全标量 DTO（`UserRows` 只有 `int`/`string` 与标量读取器，
R6「DTO 长出方法 = 实体漏进 UI 层」）与本文 §4.2 的 `AsyncValue` 必须投影成标量、
`sameList()` 逐元素门控，是**同一条纪律在两代项目里的两次表述**，
Redux FAQ 的 `filter()` 新数组坑是第三次。三方独立指向同一处 ⇒ 这条约束可信度足够高。

### 8.2 看着像、但本仓不成立的三条（别拿来论证）

| Drift3 结论 | 本仓为什么不适用 |
|---|---|
| §5 / `40` §4.3「业务类型进构建签名 ⇒ `dep-table` 退化成全量重绘；隔离越干净自动订阅越可靠」 | 本仓 `select(static fn(UserStore $s) ...)` 确实把业务类型放进签名，但**本仓没有 `dep-table` 自动推导**（依赖是显式写出的），不存在「扫描器遇到动态分派」这件事。Drift3 能判 `resolvable=YES`，代价是先付出整套投影层 + DTO + 编排层 + `U-J1/U-J2` 类型白名单 lint。照抄这条因果等于走回生成层 —— **`dep-table` 就是刚否决那条路的另一个名字**，反过来给否决裁决补了一条跨代依据 |
| §10 R7「`match` 加 `default` = 静默失败，必须无 `default`」 | 本仓 `AppCommands` / `UserCommands` 的 `handle()` 都有 `default => false`，但 `CommandRouter::dispatch()` 在「链上没人接」时会 `Errors::report(...DIAGNOSTIC)`，且 `dead-command` 是静态双向对账 + selftest 两条夹具 ⇒ **不静默**。`default` 在这里是路由协商语义，不是兜底吞掉。名字撞上了，机理不同。（本仓有一处措辞漂移值得顺手核：类注释写「不靠 `handle()` 返回 `false` 去协商」，`dispatch()` 注释与 `UserCommands` 的 `// ★ 继续冒泡` 却按协商写；语义上解析在前、调用在后，不是 bug，但会误导后来人） |
| `40` §1.3 P5 把 `CommandRouter` 的注册/派发行列为「声明了但无消费者」的坑 | 那几行在本仓**正是修法**（重复注册当场 `Errors::report`、无人接 DIAGNOSTIC）。Drift3 记的是坑的位置，不是坑的现状。⇒ 跨仓引用必须核当前代码，不能照抄结论；这与本轮反复出现的「判据全绿但能力是假的 / 口径要写明」是同一族 |

### 8.3 对落地清单的影响

形状不变，§5 两步的出口判据各补一点：

| 步 | 原判据 | 补充后 |
|---|---|---|
| **V0** | 修 `readBody()` 后提升写法从 0 条变 1 条 | 夹具新增提升写法 Bad 类，`selftest` 期望数 1 → 2；`dead-subscription` 从此对两种属性写法都有正向测试 |
| **V1** | `sameList()` + 一个含 `Equatable` 对象的用例 | 不变（§8.1 ③ 给了跨代依据，但不改判据） |
| **V3** | `vm-key` 字面量对账，`$vm['qery']` 变异必红 | 追加投影纯度扫描（§8.1 ②）+ 一个「`select` 里写 `set()`」的注入夹具 |

★ 本节**没有产生新方案**：`22` 的 `dep-table` 自动订阅属于已否决的生成族，
`40` §1.1 也把「响应式自动追踪」列为两次判死的不迁移项。
第六族推演（本文 §3）与主推结论不变。

> 后续从**架构 / 扩展性**角度、并加回「兼容性可破坏」前提的第七族推演，见
> `docs/ANALYSIS-ARCH-DRIFT3-VS-2026-11-12.md`。那一轮把 `dep-table` 单独判了一次
> （生成元数据不受 PHP 语言边界约束，但另有三条判死），并纠了 §8.1 ③ 引用的
> AOT 读数是 tpc 0.10.0 的旧口径。本文结论不变。
