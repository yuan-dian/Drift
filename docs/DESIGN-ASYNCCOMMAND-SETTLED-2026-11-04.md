# AsyncCommand 终结态订阅缝裁决：§27② watermark 复刻的更优方案（2026-11-04）

> 权威待办：`docs/DEBT-2026-11-02.md` §27 ② / §28.8。
> 方法：先量账 → 逐条核对既有裁决 → 五维评估「照旧清单开工是不是最优解」→
> 参考成熟 GUI 借机制、排结论 → 再动代码。
> 口径声明：本棒唯一真实消费者是 `apps/watermark`（复刻 `shuiyin.html` 的第一条业务闭环），
> 全部行数为 2026-11-04 仓库实测，不是估计。

---

## 0. 结论先行

| # | 结论 | 性质 |
|---|---|---|
| 1 | §27 ② 的「复刻」应用**已经存在**（`f9f1b21`），且 `JobRunner` / `equals()` / 两处真 bug 修复都已消费。**待办不是"再写一遍应用"** | 量账纠偏 |
| 2 | `ANALYSIS-watermark-dx-2026-11-02.md`「七、优化空间」的 P0/P1 清单**已腐烂**：一条判死、一条不立项、一条已落地、一条判死 | **文档反腐** |
| 3 | 真正缺口：`AsyncCommand` 把四态压成一次 `Subject` 广播，**"这次通知是不是结算"的信息只有它自己有**；应用侧 `hasData() \|\| hasError()` 只是近似，`cancel()` 回 `data` 会假阳性 | **正确性**，不只是 DX |
| 4 | **裁决：补一条终结态订阅缝 `subscribeSettledFor()`**，`emit()` 加一个 `bool`，`land()/fail()` 独占广播；复用 `Subject` 设施，零新状态机 | **本棒交付** |
| 5 | 兼容性：新增 API，不动 `AsyncValue` / `Subject` / `Future`，`subscribeFor()` 一字不改 | 克制 |

「可破坏兼容」是授权不是义务。这一棒**不需要**破坏任何东西：缺的是一个观察口，不是一次协议变更。

---

## 1. 事实底座

### 1.1 watermark 现状（11-02 量账 → 今天）

| 项 | 11-02 | 今天 | 谁改的 |
|---|---|---|---|
| `WatermarkStore` | 906 行 | **837 行** | `0a7b19d` `JobRunner` 消掉双路径 |
| `StatusBar` | 190 行手写订阅器 | **99 行** `SubjectBuilder` | `0c0508a` `equals()` |
| 双路径 `if ($host === null)` | 5 条链 × 2 套 | **1 处** `exec()` | `0a7b19d` |
| 真 bug | — | 2 处已修 | `f9f1b21` 接力标志、`462ffd6` 轮询双排 |

⇒ 「能力下沉」在 11-03/11-04 已经发生了一批，且**每一次都配了真消费者**。§27 ② 剩下要问的不是
"再补哪个控件"，而是「这批下沉之后，真应用里**还在咬人的**是什么」。

### 1.2 两次真 bug 是同一个成因

`AsyncCommand` 自己的类注释写着：

> `run()` 先 emit(loading) 再 emit(结果) ⇒ 订阅者每发起一次收两趟。

于是「只在**终结态**做副作用」这条纪律被写进注释，却没有机制：

```php
// absorbSign()（2026-11-02 修）
if ($value->hasData() || $value->hasError()) {   // ← 手写门控
    $relay = $this->uploadAfterSign;
    $this->uploadAfterSign = false;
}
// absorbPoll()（2026-11-03 修）
$settled = $value->hasData() || $value->hasError();
if ($this->polling && $settled) { $this->scheduleNextPoll(); }
```

两次事故的形状完全相同：**loading 那一趟把标志吃了 / 多排了一个定时器**，都不报错、只是行为错。
5 套 `absorb` 里 3 套各写一遍门控，两处 ★★ 大段注释专门解释「为什么不能无条件做」。
★ 这是"注释密度 20%"的典型来源：**代码本身无法表达约束，只能靠人写下来**。

### 1.3 编号撞车（本仓待办会腐烂的又一例）

上一轮记录写过「§23/§24 已把能力下沉逐条核实过」，容易被读成「DX 分析「七、优化空间」 也消费完了」。**不是**：

| 编号体系 | 内容 | 状态 |
|---|---|---|
| 11-03 §23 的 P0-1 / P0-2 | `exec()` 统一选路 / `Image::url()` | 落地 / 判死 |
| **DX 分析「七、优化空间」 的 P0-1 / P0-2** | **状态订阅自动化 / 表单绑定 `bind()`+`Slot`** | **从未处理** |

两套编号同名不同物。本裁决 §2 逐条给出它们**今天**的判定，写死以免下次再被旧清单带偏。

---

## 2. 为什么不能照 DX 分析「七、优化空间」 的旧清单开工

| 建议 | 今天的判定 | 依据 |
|---|---|---|
| P0-1 状态订阅自动化（MobX 式自动依赖收集） | **不立项** | PHP 无 `Proxy`；AOT 下生成式 getter 在 `php.exe` 里不生效（§4.1 静默税）；`select` 闭包是 lint `dead-subscription` 推导依赖的**锚点**（§16.6.3），自动收集会拆掉这条闸门。`Computed` 已明确选「显式依赖 + 粗粒度版本」路线（`03` §4.6） |
| P0-2 表单绑定 `bind()` + `Slot` | **正式判死，不重开** | `DESIGN.md` §11.4.2 四层论据（2026-10-14，§23.2 O-16 方案 A′）：PHP 属性无引用语义 ⇒ 必须传 read+write 两个闭包 ⇒ 字段名仍出现 2 次、与手写等价、**净收益 0** 却多一个公共类型；§18 已把 Vue `v-model` / WPF `Binding` / SwiftUI 属性包装器**三次列入避开栏** |
| P1 Job / 值对象 codegen | **判死** | §24.6：`fromArray()` 有真校验与兜底，不是样板 |
| P1 双路径收敛 | **已落地** | §23 `JobRunner`，watermark 5 条链各剩一行 |
| `pollUntil` 原语 | **判死** | §24.1：1 个消费者 ⇒ 形状凭空设计；必须在 `AsyncCommand` 之上再造一层状态机；`Future` 递归链取消不可达 |
| `Image::url()` | **判死** | §23.2：撞 §18「`BuildContext` 无向上查找」；跨进程图片字节只能落临时文件 ⇒ 退化成现有 `DownloadResultsJob` |

⇒ **照旧清单开工 = 重开判死议题 + 重复建设**。§26.9「能力面没有该做而没做的」到今天仍成立。

---

## 3. 真正缺口：结算真相不在订阅者手上

`emit()` 是所有状态变更的唯一出口，共 6 处：

| 调用点 | 值 | 是结算吗 |
|---|---|---|
| `start()` | `loading()->carriedFrom(...)` | ✗ |
| `onProgress` | `withProgress(...)`（仍是 loading） | ✗ |
| `land()` ×2（替代 / 追加合并） | `data(...)` | ✓ |
| `fail()` | `error(...)->carriedFrom(...)` | ✓ |
| `cancel()` | **`data(旧值)` 或 `idle()`** | ✗ |

★ **决定性的一条**：`cancel()` 有旧值时构造的 `AsyncValue::data($old)`，与 `land()` 的
`AsyncValue::data($new)` 在订阅者眼里**形状完全相同**（`state=DATA`、`hasValue=true`）。
订阅者拿不到任何字段区分"请求成功了"和"用户不等了"。

所以 `hasData() || hasError()` 不是「啰嗦」，是**近似的**：

- `absorbSign()` 在这条假阳性上会消费 `$uploadAfterSign` 并**接力上传** —— 用户点了取消，却发起了一次上传；
- `absorbPoll()` 会**排下一轮定时器** —— 取消后轮询自己又转起来。

⇒ 这不是 DX 问题，是**信息缺失**：只有 `AsyncCommand` 知道 `land/fail` 被调用过。
把「只在终结态消费」从注释纪律变成框架契约，是本棒唯一站得住的「核心能力下沉」。

**消费面不是零**：`AsyncCommand::make` 出现在 `apps/users/UserStore`、
`apps/watermark/WatermarkStore`（5 个命令）、`src/Widget/State::asyncCommand()`。
它补的是通用机制的一个观察口，形状不由 watermark 单方面决定。

---

## 4. 五维评估：三个候选为什么选 D

| | A 继续手写门控 | B `AsyncValue::isSettled()` 标记 | **D 独立结算订阅缝** |
|---|---|---|---|
| **结构** | 真相留在知情者、订阅者反推 ⇒ 必然近似 | 把**事件元数据**塞进值对象；`value()` 随时可读，脱离事件语境后「上一次变更是不是结算」语义模糊 | 结算归 `AsyncCommand` 独占；仍在 `State` 层（§4.3 允许方向内）；**复用 `Subject`** 而非第二套订阅机制 |
| **实现** | 0 行 | 要过 `idle/loading/data/error/carriedFrom/withProgress` 全部构造点，且 `cancel()` 造 data 时须显式置 false（漏一处即假阳性） | `emit()` 加一个 `bool` + `land/fail` 三处传 `true` + 一个私有 `Subject`；约 30 行，**零新状态机、零新公共类型** |
| **扩展性** | 每加一个消费者多写一遍 | `AsyncValue` 公共面被一个只在广播瞬间有意义的状态污染 | `cancel`/`dispose`/旧请求作废天然不触发；将来叠 `when(data:,error:)` 不必改值类型 |
| **易用性（DX）** | 开发者必须先知道「`run()` 会先发一趟 loading」才知道要防守 | 仍要每次 `if ($v->isSettled())`，只是换了个条件 | 写「结果到了做什么」即可；`subscribeSettledFor` 与 `subscribeFor` 并列，owner 退订语义一致；两处 ★★ 注释整类删除 |
| **维护性** | 两次真 bug 已证伪 | 判据要覆盖 6 个构造点 | 判据钉一张**四态触发矩阵**，含 `cancel→data` 这个假阳性；漏改即红 |

**C（表单绑定 / 自动依赖收集）不进对比表**：§2 已判死 / 不立项，不在本棒选项内。

---

## 5. 更优方案：`subscribeSettledFor()`

```php
// 新增（@api），与 Subject::subscribeFor 并列
public function subscribeSettledFor(object $owner, callable $handler): Subscription;
//   handler(AsyncValue $value): void —— 只在 land()/fail() 后调用
```

实现要点：

1. **结算通道复用 `Subject`**：同文件 `@internal final class SettledSignal extends Subject {}`，
   由 `AsyncCommand` 组合持有。`Subscription::of(Subject, id)` / 异常隔离 / `unsubscribeAllOf`
   全部白送 —— 不再造第二套订阅袋（§20「同一问题两套答案」）。
2. **`emit(AsyncValue $next, bool $settled = false)`**：先 `notifyChanged()`（值订阅者跑，UI 状态更新），
   再在 `$settled` 时广播结算通道。顺序天然兑现 watermark 的「先切到『处理完成』，再启动下载」。
3. **`land()` 两处 + `fail()` 一处传 `true`**；`start()` / `onProgress` / `cancel()` 不传。
   旧请求作废的 `Future` 永不结算（`abandon()` 的硬契约）⇒ 也不会触发。
4. **不动** `AsyncValue`、`Subject`、`Future`、`subscribeFor()`、`value()`、`fold()`。

改造 watermark 后：

- `absorbSign` 的 `$relay` 门控、`absorbPoll` 的 `$settled` 门控、`absorbTask` 的「提交成功立刻查一次」
  触发条件全部移进结算订阅；
- 两处 ★★ 大段 bug 注释删除（机制取代纪律）；
- 业务状态（`$polling` / `$uploadAfterSign` / `$lastResult`）**仍归应用**，不下沉 —— 这是业务决策不是机制。

★ 净代码量减得不多（约 30–40 行），交付的是**整类静默 bug 消失**，不是行数。

---

## 6. 成熟框架：借什么、排什么坑

**借鉴（终结态是一等缝，是共识）**：

| 框架 | 形态 | 借到的那一点 |
|---|---|---|
| JS `Promise` / Dart `Future` | `.then(onValue, onError)` | 终结态是**独立回调**；loading 根本不是一次通知 |
| Riverpod | `AsyncValue.when(loading:/error:/data:)` | 框架保证 data/error 互斥且只在结算 |
| Flutter | `FutureBuilder` 的 `ConnectionState.done` | 框架显式告诉订阅者「这一趟结束了」 |
| TanStack Query | `onSuccess` / `onError` 与 `onMutate` 分离 | 副作用钩子与状态广播分开 |

⇒ 共同点：**「结算」由发起方广播，不由订阅者从值里猜**。我们把它压成一次 `Subject` 广播时丢了这个缝。

**排坑（不照抄）**：

- **不抄 Vue `v-model` / WPF `Binding` / SwiftUI `@Binding`** —— §18 三次否决；三家分别靠模板编译改写 lvalue、
  运行期反射按字符串路径、`@propertyWrapper` 投影值，PHP 一个都没有（§11.4.2 横向对照表）。
- **不抄 MobX / Vue 3 `Proxy` 自动依赖收集** —— PHP 无 `Proxy`；本仓 AOT 三世界差异会把它变成静默税。
- **不抄"全量事件流 + 订阅者自己 `if`"** —— 那正是现状的问题。
- **不造 `pollUntil` / 步骤链 DSL** —— §24.1 三条结构性理由不变。
- **Flutter / React 恰恰没有绑定原语**（`TextEditingController` 是用户自持的显式对象，React 是 `value + onChange`）
  ⇒ 不提供 `bind()` 是回归主流，不是能力退化。

---

## 7. 验证计划与边界

**判据（新）**：四态触发矩阵 —— `idle → loading` 不触发、进度通知不触发、`land` 触发一次、
`fail` 触发一次、**`cancel()` 回 `data` 不触发**、`cancel()` 回 `idle` 不触发、`dispose()` 后结算不触发、
旧请求作废不触发、`Subscription::cancel()` 后不触发、`append` 合并分支只触发一次。

**端到端（watermark）**：接力上传 / 轮询排期 / 提交即查三条路径行为不变；并补
「loading 趟不接力、不排期」的回归判据（把两次真 bug 钉死）。

**变异**：至少 3 组，证明判据不是恒真绿壳（含"把 `cancel()` 也标成结算"这一组，
它必须报红 —— 这是 §3 那条正确性论据的护栏）。

**全链**：`php -l` / `lint` / `phpunit` / `smoke` / `docref` / `apidoc`（重生成 `API-SURFACE.md`）/
`gate --fast` + 完整 `gate` / `golden`。

**边界（诚实版）**：

- 本棒只补 `AsyncCommand` 的观察口，**不改四态语义、不改 `Future` 契约、不动布局/渲染**；
- `Future` 自己的 `.then/.catch` 本来就是终结态缝，不需要动；
- 结算缝**不替业务判断终态**（轮询何时停、签名何时算有效仍是应用的事）；
- watermark 的三段 `absorb` 仍是应用代码，只是不再承担"这一趟是不是结算"的猜测；
- 不因此重开 `bind()` / `pollUntil` / codegen / `Image::url()`。

**兼容性**：新增 `@api` 方法，旧订阅路径一字不改 ⇒ 五个应用无需迁移即可继续跑。
