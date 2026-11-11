# 焦点通道：控件层怎么「把焦点交给别人」

> 2026-11-06 · **已实施，但 §5 的方案被改写了** —— 见 `DEBT` §64。
> 最终实现是「宿主记在 `Pipeline` 上」（本文 §3 的候选 6，当时没收进表），
> 不是这里推荐的候选 1。改写理由：候选 1 的三条"漏一处不报错"（`attach()` 与
> `mount()` 的先后 / 根被自己的 `mount()` 抹掉 / 浮层是第二棵根）**全部由传播路径
> 自己造出来**；记在管线上这三条不存在，且 `OverlayManager` 一行都不用改。
> 本文 §5–§11 保留原文作为决策记录，**不要照它实现**。
> 起因：DEBT §60 之后重排候选，发现四个候选都写着「依赖控件层 → `FocusManager` 的通道」，
> 而这条通道不存在。本文把「到底缺什么」拆开，给出一个方案与三个备选，并回答
> **值不值得为现有消费者做**。

## 1. 先把需求拆开：通道 ≠ 四个候选

上一轮把四件事都归到「通道不存在」，复核后只有一件真的需要通道：

| 候选 | 真实缺口 | 需要通道吗 |
|---|---|---|
| 表头排序的键盘入口 | 表头没有「光标行 = 第 −1 行」的键盘模型；表头格要可聚焦/可点 | **不需要** |
| `MenuBar` Alt+←→ | 模态状态容器（`Alt` 按住期间的方向键语义）+ 已有几何导航 | **不需要** |
| 树 `←` 跳父行 | 要「把焦点交给另一个 Element」 | **需要** |
| `Slider` 拖动放大拇指 | 拖动期几何归谁（纯指针） | **不需要** |

⇒ 通道只有一个消费者。这决定了方案必须**极简**：为四个候选造一套通用焦点模型，
就是 §40.1 判据①要防的「做了没人验的能力」。

同时说明：树 `←` 跳父行本身也不只是通道问题（见 §8），所以本方案给的是**通道**，
不是「树跳父行已可做」。

## 2. 四道硬约束（都有代码证据）

**① 分层是静态检查，不是口头约定。**
`tools/Lint/Rules/DependencyDirection.php` 的 `Widget` 允许集不含 `App`：

```php
'Widget' => ['Widget','Layout','State','Rendering','Text','Geometry','Input','Platform','Errors','Logging'],
```

文件级例外只有 4 个（`BuildContext`→Theme、`State`/`Button`/`ActivationContext`→Commands）。
⇒ `use Drift\App\FocusManager` 要么改这张表（产生 `App ⇄ Widget` 双向边），要么加例外文件。
两条都会把「一个值类型穿过」的既有缝扩成「整层可以反向依赖」。

**② `BuildContext` 是「解析后的值」，不是查找入口。**
它的 docblock 第 ③ 条写死「无向上查找 —— 无 `of()` / `find()` / `maybeOf()`」，
并且是 `final readonly`，全仓 24 处构造点、2 个派生点（`withWindow` / `withTheme`）。

**③ `keyboard()` 回调签名不能加参数。**
现状是 `(KeyEvent, Element)`，14 处声明里有单参也有双参。MEMORY 的铁律：
**AOT 校验实参个数，解释器不校验** —— 给回调多传一个参数，lint/phpunit/smoke 全绿，
真机 `Fatal: {closure}() expects exactly N arguments`。
⇒ 「把 `FocusRequest` 作为第三参数传进去」这条路直接封死，不是风格问题是 AOT 红线。

**④ `FocusManager::focus(Element)` 目前不校验目标。**
它直接 `leave()` / `enter()`；`indexOf()`（焦点链索引）已经存在但只在 `step()` 里用。
一旦控件能请求任意 Element，`skipFocus()` / `disabled()` 子树就绕过了。

## 3. 五个候选

| # | 方案 | 形状 | 代价 | 结论 |
|---|---|---|---|---|
| 1 | **Element 持有 `FocusHost`** | `$self->requestFocus($target)` | 1 接口 + Element 2 方法 + Application 1 行 | **推荐** |
| 2 | `BuildContext` 注入 `FocusHost` | `$self->context()->focusHost()->requestFocus($t)` | 改 `@api` 类 + 24 处构造（可选参可省）+ 2 个派生点 | 备选 |
| 3 | `KeyResult` 带意图（`FocusParent` 等） | 回调返回值表达移焦 | 枚举加 case；但 `KeyResult` 在 `Drift\Input`，**不认识 `Widget\Element`**（Input 层允许集无 Widget）⇒ 要么破层要么加例外；且「目标是谁」表达不了 | 否决 |
| 4 | Core 级全局注册表 | `FocusBridge::request($el)` | 最小 | 否决（多实例串账） |
| 5 | 放宽分层，Widget 直接 `use FocusManager` | 最直接 | 改 lint 表 + 产生双向依赖 + Widget 单测要造 App 栈 | 否决 |

### 为什么否决 4

项目已经为「全局记账」付过一次学费：`Pipeline` 的记账字段必须**一个应用一份**，
四入口（`attach`/`frame`/`dispatchInput`/`dispatch`）都要切 —— 说明「静态全局 + 多实例」
在本仓是真实场景（测试并行、多窗口、preview 工具各建一个 `Application`）。
焦点请求走全局注册表，测试里两个应用会互相串。

### 为什么否决 5

`Activatable` / `EditableText` / `Scrollable` 这些 Widget 层接口的先例说明：
**契约可以由 Widget 定义、由 App 实现/消费**（方向 App→Widget，合法）。
反过来让 Widget 认识 App 类型，等于把这条边翻过来。

### 为什么 1 优于 2

两者语义都能成立，差别在「谁持有服务」：

- `BuildContext` 是**每次 build 传下去的值**，`OverlayManager` 会自己 `new BuildContext(...)`
  （`OverlayManager.php:551`）—— 那里不带 host ⇒ **浮层里的控件静默不能移焦**。
  这是典型的「漏一处不报错」。
- `Element` 的父链是唯一的、连续的，`mount()` 一处继承就覆盖整棵树（含浮层子树，
  只要浮层根也设了 host）。
- 也更贴成熟框架：见 §4。

## 4. 成熟框架对照

| 框架 | 移焦 API | 形态 |
|---|---|---|
| Web DOM | `element.focus()` | 元素实例方法 |
| WPF | `UIElement.Focus()` / `Keyboard.Focus(el)` | 元素方法 + 静态服务 |
| Qt | `QWidget::setFocus()` | 元素实例方法 |
| Flutter | `node.requestFocus()`；`FocusScope.of(context)` | 独立 node + 向上查找 |
| Win32 | `SetFocus(hwnd)` | 全局函数 + 句柄 |

4/5 是「元素方法」。Drift 没有独立的 `FocusNode` 对象（焦点按 `Element` 收集），
所以 `Element` 就是 node ⇒ 方案 1 与四种主流实现同形。
Flutter 的 `FocusScope.of(context)` 是本仓**明确否决**的那条（provider soup），不借鉴。

## 5. 推荐方案：`Element` 持有 `FocusHost`

### 5.1 接口（Widget 层，App 实现）

```php
// src/Widget/FocusHost.php
interface FocusHost
{
    /** 目标不在当前焦点链（skipFocus / disabled / 已卸载）⇒ 忽略 */
    public function requestFocus(Element $target): void;
}
```

只放一个方法。契约最小化的理由：**唯一的消费者只需要「交给谁」**，不需要读当前焦点、
不需要遍历链 —— 那些等第二个消费者出现再加（YAGNI，且每加一个方法都要有判据）。

### 5.2 `Element` 的三件事

```php
private ?FocusHost $focusHost = null;

public function mount(?Element $parent, BuildContext $context): void
{
    // ★ ① 走公开 getter（命名与 `keyHandlerValue()` 同形），不读 $parent->focusHost：
    //   跨实例读 private 在 AOT 下读成 null、不崩只静默（MEMORY 已记）。
    // ★ ② 只在有父时覆盖。根/浮层根的 parent 是 null，而宿主恰恰是 mount 前
    //   预先设好的 —— 无条件 `= $parent?->…` 会把根上的 host 抹成 null，
    //   整棵树静默失去移焦能力。
    if ($parent !== null) {
        $this->focusHost = $parent->focusHostValue();
    }
    ...
}

/** @internal Application::attach() / OverlayManager 给根设 */
public function attachFocusHost(FocusHost $host): void { $this->focusHost = $host; }

/** @internal 子节点 mount 时继承用 */
public function focusHostValue(): ?FocusHost { return $this->focusHost; }

/** 没有宿主（单测直接 mount 单控件）⇒ 静默无效，不崩 */
public function requestFocus(Element $target): void
{
    $host = $this->focusHost;
    if ($host !== null) {
        $host->requestFocus($target);
    }
}
```

**顺序很重要**：`Application::attach()` 必须**在 `mount()` 之前**设根宿主 ——
`mount()` 会 `rebuild()` 建整棵子树，子节点在那一刻继承父的 host；先 mount 后设，
子树全是 null（静默缺口）。配合上面 ② 的「有父才覆盖」，根宿主才不会被自己的
`mount()` 抹掉 —— 这两条合起来才成立，少一条都是「不报错、就是不能移焦」。

```php
$root = $this->buildRoot()->createElement();
$root->attachFocusHost($this);   // ← 必须在 mount 前
$root->mount(null, $context);
```

**浮层是第二个根**：`OverlayManager` 自己 mount 浮层树（parent = null），
必须同样 `attachFocusHost($this->host)`，否则菜单/弹窗里的控件不能移焦。

### 5.3 `Application implements FocusHost`

`Application` 已经 `implements TextEditingHost, InputHost, OverlayHost, PumpHostHost`，
再加一个 Widget 层接口，方向合法（App→Widget）：

```php
public function requestFocus(Element $target): void
{
    $this->focus->requestFocus($target);
}
```

### 5.4 `FocusManager` 的校验（安全网）

```php
public function requestFocus(Element $target): void
{
    if ($this->focused === $target) { return; }
    if ($this->indexOf($target) < 0) { return; }   // 不在链上：skipFocus / disabled / 已卸载
    $this->focus($target);
}
```

放进 `focus()` 还是新方法：选**新方法**。`focus()` 的内部调用方（`focusHit` /
`focusNext` / `step` / `firstFocusable`）都已经保证目标合法，给它们加一次 O(n) 查找
是把「调用方的不变量」变成「每次的运行时检查」。外面进来的请求才需要校验。

### 5.5 配套：`Element::findElement(callable)`

「交给谁」需要能**找到**目标。`Element` 现在只有 `findBox(callable)`（找盒），没有找元素。
补一个同形状的：

```php
public function findElement(callable $match): ?Element   // 先序，含自己
```

测试工具里已有等价的 `findElement()`（`tests/Support/functions.php:43`），
提进框架是「同一段逻辑第二次被写就合并」那条纪律的又一次应用。

> 注意：`findBox` 是 `@internal`。`findElement` 若只给控件内部用，同样标 `@internal`；
> 若要给应用层用（应用按 key 找控件），则要另审 —— 本方案先标 `@internal`。

## 6. 为什么不把目标「返回」给 `FocusManager`

考虑过让回调返回 `KeyResult::Focus($target)`：

- `KeyResult` 在 `Drift\Input`，`Input` 的允许集是 `Input/Geometry/Errors/Text/Layout/Logging`
  —— **没有 `Widget`**。让它认识 `Element` 必须破层或加第 5 个文件例外。
- 树跳父行的目标在回调**内部**才知道（要先按 `depth` 回溯行数据），
  「回调内直接调用」比「先算完再返回」自然。
- 返回值带载荷 ⇒ 每次按键构造一个对象；`KeyResult` 现在是零成本的纯枚举。

## 7. 判据（防假绿）

| 判据 | 钉什么 | 变异（撤掉后必须红） |
|---|---|---|
| 正常请求 ⇒ 焦点过去 | 通道通 | 删 `Application::requestFocus` 委托 ⇒ 红 |
| 请求 `skipFocus` 子树里的元素 ⇒ 不动 | 校验有效 | 删 `indexOf` 检查 ⇒ 红（焦点跑了） |
| 请求已卸载元素 ⇒ 不崩、不动 | 生命周期 | 同上 |
| 没有宿主 ⇒ 静默无效 | 单测/工具场景 | 删 `null` 判断 ⇒ 崩 |
| 浮层里的控件能移焦 | 第二个根不漏 | 删 `OverlayManager` 的 `attachFocusHost` ⇒ 红 |
| 重建后的新 Element 仍能移焦 | 继承点正确 | 把 `mount` 里的继承删掉 ⇒ 红 |
| 根宿主不被自己的 `mount()` 抹掉 | `if ($parent !== null)` | 改成无条件赋值 ⇒ 整棵树不能移焦 |

★ 「焦点过去」不能只看 `focused() === $target`：还要判**焦点环要了一帧**
（`focus()` 里已 `scheduleFrame()`）—— 否则真机上「焦点变了但环没画」。

## 8. 消费者：树 `←` 跳父行的完整成本

通道只是第一段。真做这条需求还要：

1. **找父行**：`TreeRow` 有 `depth` 没有 `parentId` ⇒ 在扁平行列表里向上找第一个
   `depth - 1` 的行（不需要改数据结构）。
2. **找父行的 Element**：`LazyColumn` 行是虚拟化的，父行可能不在窗口里 ⇒
   先 `ensureVisible`（`LazyListState::ensureVisible(Rect)` 存在，但需要目标的 Rect，
   意味着要先算行索引 → 矩形）。
3. **roving 语义**：树的「活动行」= 选中行（`activeRowId`）。跳父行要不要同时改选中？
   —— 这是产品语义，不是通道能决定的。
4. **折叠态**：父行折叠时它自己不显示，`←` 应「跳到最近的可见祖先」。

⇒ 树 `←` 跳父行 ≈ 通道 ×1 + 可见性 ×1 + 语义裁决 ×1。**通道不是它的大头。**

## 9. 实施顺序（若裁决做）

1. `src/Widget/FocusHost.php` + `Element`（字段 / 继承 / `requestFocus` / `findElement`）
2. `Application implements FocusHost` + `attach()` 在 mount 前设根
3. `OverlayManager` 浮层根同样设
4. `FocusManager::requestFocus()` 校验
5. 单测：假 host（Widget 层，不需要 `Application`）+ 真 `Application` 两条路径
6. **T2 必跑**：新增接口 + 接口动态派发 + 新增类 ⇒ 在 `dev≠AOT` 清单里

## 10. 风险

- **AOT 接口派发**：`?FocusHost` 属性 + `$host->requestFocus()` 是新的动态派发形状。
  项目有接口先例（`RenderSurface` / `SurfaceProvider`），但都是构造注入；
  「元素树传播 + 可空接口属性」要在真机确认。**这是一次 T2 的必查项。**
- **继承顺序**：先 mount 后设 host ⇒ 子树全 null，且**不报错**。判据必须覆盖。
- **浮层第二根**：漏设 ⇒ 菜单里不能移焦，同样不报错。判据必须覆盖。
- **`requestFocus` 命名**：与 `FocusManager::focus` 的区别是「请求」（可能被拒）
  vs「设置」（直接生效）。Web 的 `focus()` 是请求语义，这里叫 `requestFocus` 更明确。

## 11. 需要裁决的三件事

1. **做不做通道**：只有一个消费者（树跳父行），且它不是这个消费者的大头。
   按 §40.1 判据①（有真实消费者），**建议先不做**，把表头排序键盘入口做掉
   （它有 `showcase` 5 个可排序列的真实消费者，且不需要通道）。
2. 若做：方案 1（Element 持有 `FocusHost`）还是方案 2（`BuildContext` 注入）。
3. 若做：`FocusHost` 只放 `requestFocus` 一个方法，还是同时给 `focusedElement()`。
   建议只放一个 —— 等第二个消费者。
