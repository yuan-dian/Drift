# Drift 设计方案 · 第二轮评审

评审对象：`DESIGN.md`（v1.0 + 探针 A–E 修订，1719 行）
评审方式：与第一轮同——对照**真实 schema / 源码 / 探针 / 方案自身前后文**逐条核验，不采信文档自述。
评审日期：2026-10-14（晚）
范围说明：**第一轮已处置项不重复**（那份见 `REVIEW.md`）。本文只列新发现，外加用户点名的 **§11 专项**。

---

## 摘要：这一轮暴露的是另一类问题

第一轮修的是"**事实错误 + 能力缺口**"（schema 写错、`Errors` 分级矛盾、桌面刚需缺位）。
这一轮暴露的是——**机制被命名了，但没有被定义**。

一句话：

> **§11 把"隔离"讲透了，把"交互"讲了一半。**

| 缝 | 机制完整度 |
|----|-----------|
| **值缝**（业务 → UI） | ✅ **完整**。`notify` → 订阅者 `setState` → 帧末重建 → 新 Widget → Element diff → 盒更新。每一环都有定义 |
| **动作缝**（UI → 业务） | ❌ **只有样例，没有机制**。`command('a.b')->arg(1)` + `handleCommand()` 全篇出现 **1 次**，没有注册表、没有派发链、没有"谁先处理"、没有 `arg` 的类型定义，且与术语表里的 `Intent` **不是同一个东西** |

**而且值缝有一个静默失效口**：`build()` 里读了 Subject 的值、但该子树不在任何订阅者内 ⇒ **永远不更新，且不报错**。
方案只在 `Root` 上加了 lint 判据（§12.2），页面级没有。这是 §21 A4（build 期订阅）的**镜像反模式**，反模式表里没有它。

---

# Part A —— 全案复审（新发现）

## A-P0 —— 会直接导致落地失败

### A-P0-1 §10.1 的"五个公共命名空间"与组合根**直接冲突**

§10.1 定义公共面 = `Drift\Widget` / `Drift\Layout` / `Drift\Theme` / `Drift\State` / `Drift\Rendering`，并写：
> lint 检查：`apps/` 不得引用任何 `@internal` 类型。

但方案自己给出的两个必写文件都在这个清单之外：

```php
// §5.1：apps/hello/bin/bootstrap-win32.php —— 组合根，2 行
$app = new HelloApp(new \Drift\Platform\Win32\Win32Platform());   // ← Drift\Platform\Win32 ∉ 五个

// §4.5：apps/<app>/src/<App>Application.php
class HelloApp extends Application { ... }                        // ← Drift\App ∉ 五个

// §12.2：Root 里
->center(PageHost::make()->source(...)->table($this->routeTable()))  // ← Drift\Routing ∉ 五个
```

⇒ **按方案自己的 lint，`bootstrap-win32.php` 第一行就违规**，`Root` 与 `Application` 子类同样违规。
这不是"清单少写几个"的问题——**组合根必须引用平台实现**，这是 §5.1"平台类名只出现在组合根"的**必然结果**。

**修法（二选一）**：
1. **把公共面按"角色"而不是"命名空间"划分**：`Drift\App`（应用骨架）/ `Drift\Platform\<Os>`（组合根专用）/ `Drift\Routing` 都进公共面；
2. 或者保留五个"**控件作者**命名空间"，另立一张"**应用作者**命名空间"表（`App` / `Platform\<Os>` / `Routing` / `Text\TextInputClient`），lint 分两张表判。

★ 与 §10.1 已有的 `seal()` 豁免（"`@internal` 判定以**标注**为准，不以命名空间为准"）是同一个问题的第二次出现 —— 说明**"命名空间 = 可见性"这个模型不成立**，应该改成"每个类型显式标 `@api` / `@internal`，命名空间只是目录"。

### A-P0-2 动作缝（命令）**没有机制定义**

全篇 `handleCommand` 出现 **1 次**（§11.4 的样例）。方案没有回答：

| 问题 | 现状 |
|------|------|
| 命令**注册**在哪？ | 未定义。样例暗示"页面实现 `handleCommand()`"，但没说是 Widget 的方法还是 State 的方法 |
| 谁**派发**？ | 未定义。`EventLoop`？`Element`？`Application`？ |
| **找不到 handler** 怎么办？ | 未定义。§16.1 有"死命令 lint"（构建期校验 handler 存在），但运行期语义没写 |
| 多个 handler 都想要同一个命令？ | 未定义。`handleCommand(): bool` 的返回值**暗示冒泡链**，但链的构造/顺序/终止条件全无 |
| `arg(int)` 为什么是 `int`？ | 只能传一个整数 ⇒ **传不了 string id / 复合参数**。而 §22 术语表说动作缝载体是 `Intent`（对象）⇒ 两套表示 |
| 跨树复用怎么实现？ | §11.4 裁决 2 说"跨树复用、一个 handler 管一列"，但样例是"页面就近接管"且改的是 `$this->result`（页面自己的属性）⇒ **局部 handler 与跨树复用互相排斥** |

**这一条与 A-P0-1 是同一类**：**声明了语义，没定义机制**。而它比 A-P0-1 更贵——命令是每个交互控件都要走的路。

### A-P0-3 §11.4 的样板**跑不起来**（缺订阅），而 §11.5 的样板**有真 bug**（缺 key）

**(a) §11.4 表单样板缺订阅**
```php
TextField::make('name')
    ->value((string) $form->get('name'))
    ->onChanged(fn(string $v) => $form->set('name', $v));
```
`$form->set(...)` 会 notify，但**谁因此重建**？这段代码没有外层 `SubjectBuilder`。
若它所在的页面是 `StatelessWidget` ⇒ **输入后值永远不回流到 `value()`，光标行为与显示都会错**。
§11.5 的样板**有** `SubjectBuilder`，§11.4 **没有** ⇒ 照抄 §11.4 的人得到静默坏页面。

**(b) §11.5 列表样板缺 key，且样例里就有"删除"按钮**
```php
ForEach::make()->items($store->rows(), fn(User $u): Widget => Row::make()->children([
    Text::make($u->name)->body(),
    Button::danger('删除')->command('user.del')->arg($u->id),
]))
```
`ForEach` 没有 key 约定（全篇 `keyBy` 出现 **0 次**）。按位置 diff，删掉第 2 行 ⇒ 第 3..n 行的 Element 全部"左移继承"前一行的 State。
对纯 `Text` 无害，但只要行内有**焦点 / 展开 / 滚动 / 编辑中**任一状态，用户就会看到"我删了 A，结果 B 的展开态跑到 C 上了"。
★ 这**不是假想**：方案自己在 §12.3 把"选中项 / 展开状态 / 草稿"列为必须跨回收存活的 UI 态——说明这些状态确实存在于列表项里。

**修法**：`ForEach` 强制 `->keyBy(fn($item) => $item->id)`（**无 keyBy 即构建期报错**，不是可选）。
G1 判据："编译器知道列表项有身份吗？"——**知道**（`fn(User $u)` 的参数类型就在签名里），所以这该是**必填项**，进 `make()`：
```php
ForEach::make(fn(User $u): int => $u->id)      // key 是必填，不是可选修饰
    ->items($store->rows(), fn(User $u): Widget => ...)
```

---

## A-P1 —— 语义未定 / 与自身矛盾

### A-P1-1 `Intent` 是**幽灵术语**（3 次出现，0 次使用）

| 位置 | 写法 |
|------|------|
| §11.1 分层图 | `视图层  三树 + 动作缝：Intent / 命令 / 局部闭包` |
| §16.1 表 | `死命令 / 未处理 Intent ⇒ lint R5` |
| §22 术语表 | `Intent \| UI→业务的意图对象（动作缝载体，可路由 / 可审计）` |
| §4.2 文件清单 | **没有 Intent 这个文件** |
| §11.4 样例 | 用的是 `command('form.submit')` + `handleCommand(string, int)` —— **字符串 + 整数，不是对象** |

⇒ 动作缝有**两个名字**（`Intent` / `command`），术语表定义的那个在代码里**不存在**。这正是 §21 A12（两套真相源）的形态，只是发生在动作缝上。
**修法**：二选一并删除另一个。建议**留 `command` 这个名字、把 `Intent` 删掉**——因为 §11.4 的 `handleCommand(): bool` 冒泡语义 + §16.1 的"死命令 lint"都是围绕字符串命令建的，而 `Intent` 对象带来的是"谁构造、谁携带参数、谁解构"三件新事（正是 §18 从 WPF 学"RoutedCommand"时说要避开的那部分）。

### A-P1-2 §5.1 `Platform::surface(object $window)` 违反同节纪律 1

```php
public function surface(object $window): ?SurfaceProvider;   // §5.1
```
同节纪律 1 原话："端口方法签名只出现 `int/float/string/bool/array` 与 Drift 值对象；OS 句柄是**不透明 int**，跨接口后不得解引用"。
`object` 是**最宽的类型**，等于把纪律 1 关掉了，且 lint 无从检查。
**修法**：改成 `surface(int $windowHandle): ?SurfaceProvider`（与 §5.1 自己的口径一致），或 `WindowHost $window`（明确是端口对象）。

### A-P1-3 §22 术语表 TaskHost 仍是旧口径

```
| TaskHost | 后台任务端口；两条后端（协程 / 同步三态） |      ← 应为「线程池 / 同步三态」
```
探针 E 之后 §17.2 已改写，术语表漏改。

### A-P1-4 §19 M1 探针门的"线程创建"仍未标记已过

```
| 线程创建 + 跨线程队列 | TaskHost 只用后端 B（同步三态） |   ← 该行应标 ✅ 已过（探针 E）
```
同一张表里 fluent 微基准已标"已完成"，线程这条没有 ⇒ 读者会以为它还没验。

### A-P1-5 §11.5 用 string + `default` 的 `match`，与 §12.1 自己的建议矛盾

§12.1 第 2 条原话：
> ★ `match` 编译成跳转表；需要枚举时换 backed enum + **无 `default`** 的 `match`（漏一支 = `UnhandledMatchError`）。

§11.5 却写：
```php
match ($store->status()) {
    'loading' => ..., 'error' => ..., default => ...   // ← stringly-typed + 吞掉未列举值
}
```
且 §11.5 的 Store 里 `private string $status = 'idle'` —— **字符串状态机**。
⇒ 三态是**语义**（§15.2 纪律 4 的"哨兵三档定则"精神），应该走 backed enum：
```php
enum LoadStatus: int { case Idle=0; case Loading=1; case Ready=2; case Error=3; }
// 无 default 的 match ⇒ 新增一个状态时编译期/运行期立刻响
```
★ 这条同时修掉一个隐患：`default => rows` 意味着**任何未来新增的状态都会静默显示数据行**（正是 A7"状态语义只写进某个 else 分支"）。

### A-P1-6 §5.3 的线程断言**漏了 `Subject::notify()`**

§5.3 第 3 条：
> `setState()` / 树变更在非 UI 线程调用 ⇒ `Errors::report`（Fatal 级）

但线程池（§17.2.2）落地后，**最可能踩的路径不是"直接改树"，而是"在 worker 里改 Store 然后 notify"**：
```
worker 线程：$store->setRows($data); $store->notifyChanged();
   ⇒ 订阅者回调在 worker 线程上执行 ⇒ setState ⇒ 树变更 ⇒ 数据竞争
```
`notifyChanged()` 本身不是"树变更"，所以按现口径**不触发断言**，但它的**后果**是树变更。
**修法**：断言点放在 **`Subject::notifyChanged()` 入口**（"通知必须在 UI 线程发出"），而不只是 `setState()`。
这一条现在从"理论担忧"变成了"结构上很可能"——因为线程池已经证明可用（探针 E），M4 就会有人这么写。

### A-P1-7 §17.2.2 把"worker 能跑 PHP"当成已证 —— 探针 E 验的是**具名函数**，不是**闭包**

§17.2.2 的样例：
```php
public function submit(callable $job, callable $onDone): void
{
    \pool_submit($job, $onDone);   // C++ 侧执行 job
}
```
探针 E 验的是 **`php_p__thr_php_add` / `php_p__thr_php_concat`——编译出来的具名函数**。
**没有验过"把一个 PHP 闭包搬到 worker 线程上调用"**。闭包在 TypePHP 里是带捕获作用域的对象，跨线程调用可能要的不止 `ts_resource(0)`。
⇒ §17.2.2 应把这一条标 **⏳ 待验（M4 前的探针门）**，而不是当作已证。诚实的表述：
> 已证：**编译出来的具名函数**可在 TSRM 初始化后的 worker 线程上执行（含 Zend 分配，4 线程并发）。
> 未证：**闭包 / 捕获了对象作用域的 callable** 同样可行。

---

## A-P2 —— 能力 / 体验缺口

| # | 缺口 | 为什么是缺口 |
|---|------|------------|
| A-P2-1 | **无"选择器 / 切片"原语** | 订阅粒度 = 整个 `Subject`。一个 Store 有 10 个字段 + 10 个 `SubjectBuilder` ⇒ 任一字段变，10 个全重建。§12.2 靠"每个切片自己是一个 Subject"（`statusLine()` / `currentPage()`）绕，但那让 **Store 必须暴露 N 个 Subject**，正是 §18 从 Flutter 学来的要避开的 "provider soup"。详见 Part B |
| A-P2-2 | **无派生值（computed）的位置** | `fullName = first + ' ' + last` 放哪？放 Store getter ⇒ 每次读重算、且订阅者无法只跟它；放页面 build ⇒ 每帧重算。§18 明说借了 Vue 的"计算缓存 + 版本跳过（**形状**）"，但 §11 里没有这个原语 |
| A-P2-3 | **表单双向绑定要手写两遍** | §11.4 每个字段：`make('name')` + `value($form->get('name'))` + `onChanged(fn($v) => $form->set('name',$v))` ⇒ 字段名出现 **3 次**。10 字段 = 30 个字符串字面量。而 §2.1 明写 **property hooks 可用**且是"受控属性的物理基础"——**能力在手却没用**。详见 Part B |
| A-P2-4 | **`ForEach` 无 key 约定** | 见 A-P0-3(b)。`keyBy` 全篇 0 次 |
| A-P2-5 | **页面参数走全局 store ⇒ 页面无法独立预览** | §12.1 第 5 条："页面参数走共享 store（`selectedId`），不从根逐层钻参数"。代价：① 导航离开后 `selectedId` 残留（回列表页时旧选中还在）；② 页面**依赖 Store 才能构造** ⇒ §12.2 宣称的"每页可独立预览"对**带参页面不成立**（预览要喂一个假 Store）。不是致命，但"附带收益"那句该加限定 |
| A-P2-6 | **三种"变了"的说法未消歧** | `Subject::notifyChanged()`（§11.5）/ `State::setState()`（§5.2）/ `Cell::setIfChanged()`（§11.3）。§11 从未说清何时用哪个。`Cell` 甚至**没有一处使用样例** |

---

## A-P3 —— 文档一致性

| # | 位置 | 问题 |
|---|------|------|
| 1 | §19 M2 | "首批控件过 **W1–W7** 契约检查" —— `W1` 全篇出现 1 次，`W2`–`W7` **0 次**，从未定义 |
| 2 | §16.2 / §16.1 | "R1–R12 移植" —— 出现 2 次，**从未枚举**。读者无法据此实现 `drift-lint` |
| 3 | §12.3 表 | "**滚动位置** 必须外置到 Store" 与 §12.1 第 4 条"`Offstage` **保留** State"张力：页面级滚动位置在 Offstage 下是保留在 State 的。应限定为"**被虚拟化回收的列表**的滚动位置" |
| 4 | §7 | 没说生产路径是否**总是**录制 `DisplayList`（§7.2 说"paint 的产物是命令序列"，§7.3 说 M1 是 Skia CPU 直绘）。若总是录制 ⇒ 每帧多一次间接；若只在 dev 录制 ⇒ **golden 与生产走两条路**（§20 的"量具自检"要防的正是这个）。需一句裁决 |
| 5 | §16.4 样板 | 缺 `platform: win32`（真实 yml 有）与 `ignore:`（§4.6 刚说要用它排除子目录） |
| 6 | §2.2 表 vs §16.5 陷阱 3 | **同一事实两处记**（"PHP 调桥接须写前导 `\`"）。见下方"自查"一节 |

---

## 自查：上一轮我自己的一处**过度声称**

我在 §16.5 新增"陷阱 3"、并在当时的 §23.6（现 `HISTORY.md §1`）写"**顺带抓到 1 个 tpc 陷阱**"，读起来像是新发现。**其实不是**——§2.2 的表里早就有一行：

> 桥接签名逐字一致，不一致静默失败；**PHP 调桥接须写前导 `\`** | GUI 仓 AGENTS.md §4

⇒ **事实早就在文档里**。探针 E 真正新增的只有三件：**机制**（decl 头注入依赖 `symbolCallInFile`→`symbolDeclInFile`）、**报错形态**（`C3861` 指向生成的 `.cc`）、**强制手段**（lint）。
已按此收窄措辞并与 §2.2 交叉引用。**这条留在这里当方法论提醒**：把"我撞上了"当成"文档没有"是很容易犯的错，而它恰好会让第二轮评审重复第一轮的工作。

---

# Part B —— §11 专项：隔离之后，数据到底怎么走

## B.1 三条真实通路（先看事实，再看声明）

```
                         ① 下行（业务 → UI）：机制完整 ✅
  ┌─────────────┐  notifyChanged()  ┌──────────────┐  setState()  ┌───────────────┐
  │ Store       │ ────────────────▶ │ 订阅者       │ ───────────▶ │ 脏 Element    │
  │ (Subject)   │  每订阅者 try/catch│ SubjectBuilder│   只标脏     │ （帧末 flush）│
  └─────────────┘   (§11.3 RT-3)    │ / PageHost   │              └───────┬───────┘
        ▲                           └──────────────┘                      │ build()
        │                                                                 ▼
        │                                                        新 Widget（不可变）
        │                                                                 │
        │  ┌──────────────────────────────────────────────────────────────┘
        │  │  Element diff (type,key) ⇒ 同类型 ⇒ createRenderObject() 重调（§13.2）
        │  │                          ⇒ 不同类型 ⇒ 换 Element（State 丢）
        │  ▼
        │  relayout（★ 全树，无脏边界，§13.3）→ paint → present
        │
        │  ② 上行（UI → 业务）：只有样例，没有机制 ❌
        │
  ┌─────┴──────────┐   command('a.b')   ┌───────────────────────────┐
  │ Button/TextField│ ─────────────────▶│ ??? 注册表 / 派发链 ???   │ ← A-P0-2：全无定义
  │ ->onChanged(闭包)│ ────────────────▶│ 业务方法（直调）          │
  └────────────────┘                    └───────────────────────────┘
        ▲
        │  ③ 静默失效口（值缝的反面）❌ 反模式表里没有
        │
  ┌─────┴──────────┐
  │ build() 里读    │  没有订阅者祖先 ⇒ 通知到达不了 ⇒ 永不更新，且不报错
  │ $store->rows()  │  （§12.2 的 lint 只覆盖 Root，页面级无判据）
  └────────────────┘
```

**三条结论**：
1. **下行是完整的**，而且设计得不错（订阅点下移 + 订阅袋 + 异常隔离 + 相等门控，四样都进原语）。
2. **上行是断的**。`handleCommand` 只有 1 处样例，没有机制。
3. **下行有一个静默失效口**，反模式表没有对应条目。

★ 另外注意图中一处**被低估的代价**：`relayout` 是**全树**（§13.3 明写"Render 层没有脏边界，`relayout()` 永远全树"，M5 才做）。
§11.5 宣称的收益是"订阅点下移 ⇒ **兄弟子树不重建**"——**这只在 build 层成立**。
**帧的真实成本 = 被重建的 build + 全树的 layout + 全树的 paint**。所以在 M5 之前，订阅点下移的收益会被全树 relayout 吃掉一大半。
§11.5 应加一句诚实边界（与 §11.7 对 `loading` 的诚实边界同款）。

## B.2 逐条核对：机制完整度

| 环节 | 方案是否定义 | 判 |
|------|------------|-----|
| 数据归谁 | ✅ §11.1"持有"、§11.4 裁决 1（表单值的家是 `FormData`） | 完整 |
| 规则在哪 | ✅ 业务层 Store，零 GUI 依赖 | 完整 |
| 谁发起变更 | ⚠️ 两条路（闭包 / 命令）**但没有选择规则** | 半 |
| 变更怎么传下去 | ✅ notify → 订阅者 → setState → 帧末重建 | 完整 |
| **谁来订阅** | ⚠️ 说"只允许专职订阅控件"，但 `SubjectBuilder` / `PageHost` / `StatusBar` / `Bound` 的关系与判据没给 | 半 |
| **订阅粒度** | ❌ 无切片原语（A-P2-1） | **缺** |
| 订阅生命周期 | ✅ §11.3 订阅袋 + dispose 全退 + mounted 守卫 | 完整 |
| 通知的异常隔离 | ✅ §11.3 RT-3 每订阅者 try/catch | 完整 |
| 通知的相等门控 | ⚠️ 只有 `Cell::setIfChanged`；`Subject::notifyChanged()` 无门控 | 半 |
| **动作怎么上行** | ❌ 无注册表 / 无派发链 / 无 arg 类型 / 与 `Intent` 不一致 | **缺** |
| 上行失败怎么反馈 | ⚠️ §11.4 写 `$this->result = ...`，但**没说这个 `$this` 是 Widget 还是 State**（若是 Widget，改已封存对象 ⇒ Fatal） | 半 |
| 异步怎么接 | ✅ §11.7 + §17（后端 B → A 零改动） | 完整 |
| **忘记订阅怎么发现** | ❌ 无 lint（只有 Root 有判据） | **缺** |

**4 缺 / 5 半 / 4 完整。**

## B.3 开发者实际要写什么（严格按 §11 复刻，数摩擦点）

### B.3.1 表单页（§11.4 的写法，补上它漏掉的订阅）

```php
final class SignupPage extends StatefulWidget
{
    public function __construct(private readonly SignupForm $form, private readonly UserSource $src) { parent::__construct(); }
    public function createState(): State { return new SignupPageState(); }
}

final class SignupPageState extends State
{
    private string $result = '';

    public function build(BuildContext $ctx): Widget
    {
        $form = $this->widget()->form;                    // ① 从 State 回到 Widget 取依赖
        return SubjectBuilder::make($form)->builder(fn(): Widget =>   // ② 必须自己想到要包这一层
            Column::make()->children([
                TextField::make('name')
                    ->label('姓名')
                    ->value((string) $form->get('name'))            // ③ 字符串键 + 显式转换
                    ->onChanged(fn(string $v) => $form->set('name', $v)),  // ④ 闭包 + 字符串键再写一遍
                TextField::make('email')
                    ->label('邮箱')
                    ->value((string) $form->get('email'))
                    ->onChanged(fn(string $v) => $form->set('email', $v)),
                Text::make($this->result)->error(),                 // ⑤ 错误反馈（State 态）
                Button::primary('提交')->command('form.submit'),
            ]));
    }

    public function handleCommand(string $name, int $arg = 0): bool   // ⑥ 这个方法在 State 还是 Widget？方案没说
    {
        if ($name !== 'form.submit') { return false; }
        $errs = $this->widget()->form->validate();
        if ($errs !== []) { $this->setState(fn() => $this->result = implode('；', $errs)); return true; }
        $this->widget()->src->submit('/api/signup', $this->widget()->form->toApiPayload());
        return true;
    }
}
```

**摩擦点计数（单字段）**：`'name'` 出现 **3 次** · 显式 `(string)` 转换 **1 次** · 手写闭包 **1 个** · 必须记得包 `SubjectBuilder` **1 处** · `$this->widget()->` 前缀 **4 次**。
**10 字段**：字符串字面量 **30 个**（每个都是 typo ⇒ 静默）、闭包 **10 个**、转换 **10 次**、`$this->widget()` **>20 次**。

### B.3.2 列表页（§11.5 的写法）

```php
SubjectBuilder::make($store)->builder(fn(): Widget =>
    match ($store->status()) {
        'loading' => Text::make('加载中…')->caption(),
        'error'   => Text::make('加载失败，点刷新重试')->error(),
        default   => ForEach::make()->items($store->rows(), fn(User $u): Widget =>   // ⑦ 无 key
            Row::make()->spacing(8)->children([
                Text::make($u->name)->body(),
                Button::danger('删除')->command('user.del')->arg($u->id),            // ⑧ arg 只能是 int
            ])),
    })
```

**摩擦点**：`default` 吞掉未列举状态（A-P1-5）· 无 key（A-P0-3b）· `arg` 只能传 int（A-P0-2）· `user.del` 的 handler 在哪没说（A-P0-2）。

## B.4 心智负担量化

**必须掌握的概念数（写一个"列表 + 搜索 + 删除"页）**：

| # | 概念 | 方案是否给出使用时机 |
|---|------|-------------------|
| 1 | `Subject`（业务层基类） | ✅ |
| 2 | `notifyChanged()` 何时调 | ⚠️ 只给了"改完调一次"，没给"同值要不要调" |
| 3 | `SubjectBuilder` 必须包在哪一层 | ⚠️ 样例给了，判据没给 |
| 4 | `State` / `StatefulWidget` / `createState` / `widget()` | ✅ |
| 5 | `State` 订阅袋 + dispose | ✅ |
| 6 | `command` + `arg` + `handleCommand` | ❌ 机制缺失 |
| 7 | 三态语义（string 约定） | ⚠️ 用 string，与 §12.1 矛盾 |
| 8 | `Cell` / `setIfChanged` | ❌ 无使用样例 |
| 9 | `Offstage` / `PageHost` / `RouteTable` | ✅ |
| 10 | Seal-on-Mount 纪律（别在 build 里改 widget） | ✅ |
| 11 | `Errors` 三级 | ✅ |
| 12 | `ForEach` 的 key | ❌ 无约定 |

**12 个概念里，3 个没有使用时机、1 个机制缺失。** 对比：Flutter 同规模页面需要 `StatefulWidget/State/setState/FutureBuilder/ListView.builder/key`（6 个），Vue 需要 `ref/computed/watch/v-for/:key`（5 个）。
⇒ **Drift 的概念数偏多**，而且多出来的部分**不是能力，是"要自己猜"**（订阅包在哪、handler 放哪、key 怎么给）。
**心智负担的公式**：`负担 = 概念数 × (1 − 文档给出的决策规则覆盖率)`。Drift 的**概念数**可接受，**覆盖率**是短板。

## B.5 六个具体改法（按优先级）

### 改法 1（必修）：`SubjectBuilder::select()` —— 订阅粒度

```php
SubjectBuilder::make($store)
    ->select(fn(UserStore $s): array => $s->rows())        // 只关心这一个切片
    ->builder(fn(array $rows): Widget => ...)
```
- **相等门控落在 `select` 的返回值上**（`===`，与 §11.3 RT-4 同一机制，不引入第二套）。
- 一个 Store 可以喂 N 个 `select`，**不需要暴露 N 个 Subject** ⇒ 消灭 A-P2-1 与 §12.2 的 `statusLine()`/`currentPage()` 那种"方法名像 getter 实际返回 Subject"的歧义。
- **同时修掉一个隐藏问题**：§12.2 的 `->source($this->store->statusLine())` 依赖"每次调用返回**同一个** Subject 实例"，这一点方案没写、也无法 lint。`select` 把它变成纯函数式，没有实例身份问题。
- **可 lint（G1）**：`select` 闭包里出现的属性集合 = 这个订阅者真正依赖的字段集 ⇒ **编译期可生成依赖清单**（也顺带是 §16.1"远期编译期推导"的第一步）。

### 改法 2（必修）：动作缝给一个**最小机制**

```php
// 注册：应用级一次（组合根或 Application），可枚举、可 lint
$commands = CommandRouter::make()
    ->on('form.submit', SignupCommands::class)          // handler 是类，不是闭包（可测试、可列举）
    ->on('user.del',    UserCommands::class);

// 派发：从命中的 Element 向上冒泡（与 §9.1 的键盘/指针路由同族）
//   ① 目标 Element 的 State::handleCommand
//   ② 祖先 State（直到 Root）
//   ③ CommandRouter（应用级）
//   ④ 都没有 ⇒ Errors::report（Diagnostic 级）—— 不静默
interface CommandHandler { public function handle(string $name, CommandArg $arg): bool; }

// arg 不再是 int：
final readonly class CommandArg { public function __construct(public int $i = 0, public string $s = '') {} }
```
**为什么是"冒泡链 + 应用级注册表"而不是"只有页面 handler"**：
- §11.4 裁决 2 要的"**跨树复用**、一个 handler 管一列"⇒ 需要应用级注册表；
- §11.4 样例要的"**页面就近接管**"⇒ 需要 Element 冒泡；
- **两者都要**，所以是链，不是二选一。返回值 `bool` 的语义也就有了明确含义（"我处理了，停止冒泡"）——这是方案已经写出来但没解释的那个 `bool`。

### 改法 3（必修）：三态改 backed enum + 无 `default` 的 `match`
见 A-P1-5。顺带修掉 `default => rows` 的静默兜底。

### 改法 4（必修）：`ForEach` 的 key 进 `make()` 必填
见 A-P0-3(b)。

### 改法 5（应修）：表单绑定 —— 用上 property hooks + 引入 `Bound` 订阅控件

**第一步**：`FormData` 用 property hook 暴露受控字段（§2.1 已证可用）：
```php
final class SignupForm extends Subject
{
    public string $name  = '' { set (string $v) { $this->name = $v;  $this->notifyChanged(); } }
    public string $email = '' { set (string $v) { $this->email = $v; $this->notifyChanged(); } }
}
```
**第二步**：`TextField::bind()` 返回一个**订阅控件** `Bound`（与 `SubjectBuilder` 同族），而不是返回 `TextField`：
```php
// 页面里一行，取代"value + onChanged + 外层 SubjectBuilder"三件事
TextField::bind($form->name)->label('姓名')

// 等价展开（契约层，糖删掉后仍在）：
Bound::make($form->name, fn(string $v): Widget =>
    TextField::make('name')->label('姓名')->value($v)->onChanged(fn(string $x) => $form->name = $x))
```
**收益**：字段名出现 **1 次**（且是**属性**不是字符串 ⇒ 拼错是编译错误）· 闭包 **0 个** · `(string)` 转换 **0 次** · 不用记得包 `SubjectBuilder`。

**⚠️ 这里撞上方案自己的一条纪律**（值得记下来）：§10.2 第 6 条"**糖只转发**——删掉全部糖，框架契约与测试原样完整"。
`bind()` **不是纯转发**（它引入了"订阅"这一新行为），所以按现纪律它**不允许存在**。
**两条出路**：
1. 承认存在**第二类糖——"复合糖"**（引入新行为但**可由契约原语组合出来**），把 §10.2 第 6 条改成"糖只转发**或只组合**；删掉糖，契约与测试仍完整（只是更啰嗦）"；
2. 或不动纪律，把 `bind()` 定位成 **`Bound` 这个契约原语的变体工厂**（§15.4 的"变体工厂 = `make()` 的薄壳别名"）——这样它**是**纯转发，只是转发到 `Bound` 而不是 `TextField`。
**推荐第 2 条**：纪律不动，`Bound` 进 `Drift\State`（与 `SubjectBuilder` 同族，都是"专职订阅者"），`TextField::bind()` 只是它的别名。

### 改法 6（必修）：新增反模式 **A16** + 对应 lint

> **A16 | `build()` 里读 Subject 的值，但该子树不在任何订阅者内 ⇒ 静默不更新**
> 后果：改了数据界面不动，且不报错（最难查的一类）
> 正确做法：把读值的那层包进 `SubjectBuilder` / `Bound`；**lint**：`build()` 体内出现对 `Subject` 类型字段的方法调用（或 `select` 之外的值读取）⇒ 该 `build()` 必须是订阅控件或位于订阅控件内

★ 这条与 §21 A4（build 期订阅）是**同一枚硬币的两面**：A4 是"订了但订错时机"，A16 是"该订没订"。两个都要有。

## B.6 §11 与其它章节的交互漏洞

| # | 交互 | 漏洞 |
|---|------|------|
| 1 | §11 × §13.3 | §11.5 宣称"兄弟子树不重建"，但 relayout **全树** ⇒ M5 前收益被吃掉一大半。应加诚实边界 |
| 2 | §11 × §15 | 订阅者控件（`SubjectBuilder`）也是 Widget ⇒ 受 Seal-on-Mount 约束。若开发者在 `build()` 里把已建好的 `SubjectBuilder` 存进字段复用，**闭包会陈旧**（捕获的是旧 `$store` 快照）。方案没提这条纪律 |
| 3 | §11 × §12.3 | 虚拟化 dispose 列表项 State ⇒ 若订阅袋挂在项 State 上，回收时必须可靠退订。§12.3 只说了"跨回收状态外置"，**没说订阅袋怎么处理** |
| 4 | §11 × §17 | 线程池落地后，`notifyChanged()` 可能在 worker 线程发出（A-P1-6）。§11 与 §17 都没接这条 |
| 5 | §11 × §10.2 第 5 条 | "订阅责任只能落在专职订阅控件上" —— 但**没有定义"专职订阅控件"的判据**（是标记？是基类？是命名约定？）。改法 5 的 `Bound` 需要这个判据才能进 lint |

---

# 建议处置顺序

| 优先级 | 动作 | 为什么排这个位置 |
|--------|------|----------------|
| 1 | **A-P0-2 动作缝机制**（改法 2） | 每个交互控件都要走；现在等于没设计 |
| 2 | **A-P0-3 两个样板修正 + 改法 4/6**（key / A16） | 静默 bug，且样例就在文档里 |
| 3 | **改法 1 `select`**（A-P2-1） | 决定 §12.2 的 store 形态，越晚改越贵 |
| 4 | **A-P0-1 公共面按标注而非命名空间** | 决定 lint 的骨架，M0 就要定 |
| 5 | **A-P1-5 / A-P1-6 / A-P1-7**（enum 三态 / notify 断言 / 闭包⏳） | 三条都是"改一句话"的便宜修正 |
| 6 | **改法 5 `Bound` + property hooks**（A-P2-3） | 心智负担的最大单点，但依赖 §10.2 第 6 条的裁决 |
| 7 | **A-P1-1 ~ A-P1-4 / A-P3 全表** | 文档修补 |

---

## 值得肯定的四处

1. **§11.3 的"四件事"**（订阅袋 / 异常隔离 / 相等门控 / 盒同步）把 GUI 仓的运行期补丁**升成了原语**——这是第一轮 P2 里唯一被完整接住的类别，且每一件都指得出 GUI 仓的原始 bug。
2. **§11.5 的 `loading` 诚实边界**（"单线程同步后端下 `loading` 态画不出来"）——**主动承认自己的样例画不出来**，这是 G2"绝不静默"用在自己身上的正面样板。
3. **§12.3 的三档状态保留表**（保留 / 回收 / 丢弃）+ "跨回收状态外置"纪律——把虚拟化这个 M5 才做的功能与 M2 就定的 `State` 语义**提前对齐**了，避免回头改设计。
4. **§15.2 的 `mutate()` 单一守卫落点**——"不是每方法各写一遍守卫（一个口径一个落点）"，这正是 §11 目前缺的东西（动作缝有 1 个落点、订阅粒度有 0 个落点）。**§11 应该照 §15 的样子做一遍。**
