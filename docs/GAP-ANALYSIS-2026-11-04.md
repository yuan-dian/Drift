# 能力对照复核（2026-11-04）：成熟 GUI 框架视角

> 上一份能力对照是 `DEBT` §26（2026-11-03），结论是「能力面已收口，剩下全是零消费者」。
> 本稿不重抄那张表，只做三件事：**复核它过期/漏掉的**、**补一类它没覆盖的维度**、
> **给出这一棒的选择与设计**。
>
> 方法沿用本仓铁律：每条结论落到 `src/` 的 grep 或判据上，不采信文档快照
> —— §26.1 自己就记着上一份 GAP 文档腐烂了 7 条（其中 3 条是**反向**腐烂）。

---

## 0. 结论先行

| # | 结论 | 性质 |
|---|---|---|
| 1 | §26 的十条缺口仍成立；另发现一条**反向腐烂**：`GAP-10-26` 记「无滚动惯性」，实际 `ScrollMotion` 早有 glide / fling / friction | 记账纠错 |
| 2 | §26 量的是「**有没有**」，没量「**够不够用**」。控件库存齐了，但**单个控件的成熟度**仍有明显落差 | 新维度 |
| 3 | 最典型是 `TextField`：公开面只有 `value/label/placeholder/onChanged` 四项，**没有错误态、辅助文本、长度约束**；Flutter `TextField` 30+ 属性、Qt `QLineEdit` 有 `validator`/`maxLength`/`inputMask`、WPF 有 `ValidationRule` | 能力缺口 |
| 4 | 这个缺口**有真实消费者**：`apps/users` 注册页只能显示一条全局 `submitError`，**字段级错误无处可放** | 消费者信号 |
| 5 | 因此本棒做「TextField 字段装饰 + 长度约束」，其余缺口维持零消费者延后 | 裁决 |

---

## 1. 实证复核（2026-11-04）

| 能力 | 旧结论（GAP-10-26 / §26） | 实测（`src/` grep） | 判定 |
|---|---|---|---|
| 滚动惯性 | **缺口**（`grep inertia\|fling` = 0） | `src/Widget/ScrollMotion.php` 有 `glide` / `fling` / friction | **过期**（早已有） |
| 真实 TabBar | 缺（`TabView` 复用 `SegmentedControl`） | 同左；且 `TabView` **只有测试消费者**，无应用使用 | 成立，零消费者 |
| i18n / RTL | 缺 | `Directionality\|TextDirection\|RTL` = 0 | 成立，零消费者 |
| 混合模式 | 缺 | `BlendMode\|xfermode` = 0 | 成立，零消费者 |
| 手势族（长按/捏合） | 半成品（只有 tap/drag） | 同左 | 成立，桌面无消费者 |
| 装饰控件（Badge/Avatar/Breadcrumb/Pagination） | 缺 | 0 | 成立，零消费者 |
| 布局原语（`ConstrainedBox` / `IntrinsicWidth`） | 缺 | 内部固有尺寸在，公开控件无 | 成立，零消费者 |
| **TextField 装饰 / 约束** | **未覆盖** | `error\|helper\|maxLength\|obscure` 在 `TextField*` = 0 | **成立，有消费者** |
| 自动聚焦 | 未覆盖 | 0（`TableState` 注释明写「本仓无 `autoFocus`」） | **已做**（§9：机制缺的是"打开"那一半） |

★ 前七条都指向同一句话：**不是不会做，是没有消费者**。第八条不同 ——
它今天就在让业务应用付出代价（见 §2）。

---

## 2. 为什么是 TextField：消费者的真实形状

`apps/users/src/Pages/SignupPage.php` 现在这样显示错误：

```php
SubjectBuilder::make($this->form)
    ->select(static fn(SignupForm $f): string => $f->submitError)
    ->builder(static fn(string $err): Widget => $err === ''
        ? Row::make()
        : Text::make($err)->error()),
```

问题不在「有没有红字」，而在**红字的位置**：

- `SignupForm::validate()` 返回 `list<string>`（可能两条错误拼在一起），
  只能堆在表单底部 —— 用户得自己把「邮箱格式不正确」对应回那个输入框；
- `TextField` 没有错误态 ⇒ 输入框本身**不会变红**，视觉上无法定位；
- 加一个字段 = 再加四行 `SubjectBuilder` + 再想一次"错误放哪"。

成熟框架把这件事收在字段内部：Flutter 的 `InputDecoration.errorText`、
WPF 的 `Validation.ErrorTemplate`、Qt 的 `QValidator` + `QLineEdit::setStyleSheet`。
**框架给"错误槽 + 约束"，校验规则留应用**（见 §3 的否决项）。

---

## 3. 裁决：不做 Flutter 式 `Form` / `FormField`

这是一个**结构性**决定，写下来防重开：

1. **`BuildContext` 明令无向上查找** —— 类注释原文：「无 `of()` / `find()` /
   `maybeOf()`（§18 已否决 Flutter 的 provider soup）」。`Form.of(context)`
   与 `FormField` 的自动注册都建立在向上查找上 ⇒ **结构上被否决**，不是没做。
2. **校验规则是业务状态** —— 跨字段（密码确认）、提交时机（submit 后才报）、
   服务端回传的错误，都不是控件能决定的。本仓的落点是 `Subject`（§11.4.3），
   已有 `SignupForm` 这个正确形态。
3. **双真相源** —— 若控件再持一份 `computedError`，就会出现「屏幕显示的错误」
   与「业务判定能不能提交」两份答案。`TextField::error()` 保持**受控**：应用给什么就显示什么。

⇒ 框架只补**表现 + 约束**两件事，规则不动。

---

## 4. 设计：三段分工内的最小落点

本仓的字段链路是 `TextField`（声明）→ `TextFieldState`（组合）→
`TextFieldBox`（token→值）→ `RenderTextField`（几何/绘制）。

| 要加的东西 | 落点 | 为什么 |
|---|---|---|
| 错误行 / 辅助行 / 计数 | `TextFieldState::build()` 的 `Column` | label 已经在这里组合；**不必动 `RenderTextField` 的 14 个构造参数** |
| 错误红边 | `TextFieldBox::createRenderObject()` 换 `BoxStyle.borderColor` | Layout 层不碰 Theme（§4.3），token 解析仍只发生一次 |
| 长度约束 | `TextDraft` | 逐字键入（`TextKeymap`）、IME、粘贴**全部**收口在这里；放 State 会漏路 |

### 4.1 长度约束的两个判据

- **按码位截断**，不是字节：`Utf8::decode()` 取前 N 个码位再 `encode`
  —— 与「退格永不切半个汉字」同一条红线。
- **`setText()` 不截断**：那是外部受控值，应用是真相源。截断用户输入、
  不篡改应用给的值 —— 与浏览器 `input.value = x` 不触发 `maxlength` 同义。

---

## 5. API 形状（与既有链式风格一致）

```php
TextField::make()
    ->value($this->form->email)
    ->label('邮箱')
    ->helper('用于登录')      // 弱化色辅助行
    ->error($err)             // 非空 ⇒ 红边 + 错误行（抢占 helper 的位置）
    ->maxLength(64);          // 右下角 12/64；超出键入被截
```

`error('')` / `helper('')` = 不显示（空串不是"一条空错误"）。
`maxLength(null)` = 不限；负数按 0 处理。

---

## 6. 判据（不只断言"变量对了"）

| 用例 | 钉什么 |
|---|---|
| 组合 | 有 label + error ⇒ `Column[label, box, 提示行]`；error 抢占 helper |
| 颜色 | `invalid` ⇒ `boxStyle()->borderColor === Danger`；无错误时仍是 Border |
| 截断 | `maxLength(3)` 输入 5 个 ASCII ⇒ 3；输入「中文字符」`maxLength(2)` ⇒ 「中文」 |
| 选区 | 有选区时替换，可用额度 = `max - (len - selectedLen)` |
| 计数 | 文案为码位数 / 上限（不是字节数） |

---

## 7. 本轮不做（附理由，防重开）

- **`Form` / `FormField` / `autovalidateMode`**：见 §3。
- **`obscureText`（密码）**：没有消费者（注册页没有密码字段）；加它要动
  选区和 IME 的光标映射，等真有登录页再付。
- **`inputFormatters`**：先用 `onChanged` 里过滤即可；框架层过滤器要处理
  "过滤后与受控值不同步"，收益不足。
- **i18n / RTL / BlendMode / 真 TabBar / 装饰控件**：零消费者，继续延后。

---

## 8. 第二轮复核：滚动可控性

### 8.1 换了扫法

上一轮按「控件有没有某项属性」扫（`TextField` 的 `error/helper/maxLength`），
本轮反过来：**扫仓库自己记着的"做不到"** —— 注释里承认的限制、靠脚本兜的审计、
用常量写死的尺寸。这类"绕路"比任何框架清单都可信，因为它已经付过代价。

### 8.2 三条实证（都是仓库原文）

| 位置 | 原文 | 含义 |
|---|---|---|
| `LoadMorePage` 类头 | 「「加载更多」是**按钮**，不是"滚到底自动加载"：`LazyColumn` 今天没有 `onEndReached`」 | **读不带**滚动位置 ⇒ 无限滚动只能靠按钮 |
| `ScrollView` 公开面 | 只有 `child()` / `vertical()` / `createState()` | **写不进**滚动位置 ⇒ 滚到底 / 回到顶部无入口 |
| `ScrollInputRouter::revealFocused()` | 唯一的滚动触发是**焦点** | 应用拿不到 State ⇒ 命令只能由框架代发 |

同类第二处：`TableState` 注释把「本仓无 `GlobalKey`、无 autoFocus」列为
"不把 `TextField` 放进虚拟化行"的理由之一 —— 同一个根因（**应用无法对某个
具体控件下达命令**）在两张不同的痛点上各出现过一次。

### 8.3 判定：不是缺控件，是缺一句柄

| 框架 | 读位置 | 写位置 |
|---|---|---|
| Flutter | `ScrollController.offset` + `ScrollNotification` | `jumpTo` / `animateTo` |
| Qt | `verticalScrollBar()->valueChanged` | `setValue()` |
| WPF | `ScrollViewer.ScrollChanged` | `ScrollToEnd()` |
| Web | `scrollTop` + `IntersectionObserver` | `scrollTo()` |
| **本仓** | 无 | 无 |

`Scrollable` 接口刻意只承诺「收到 Δ 怎么办」（`scrollBy`），不承诺「偏移存在哪」
—— 这条设计是对的（`ScrollView` 的偏移住 State、`Table` 的外置到 Store），
**不改它**。缺的是接口之外的一个**绑定句柄**：应用造一个 `ScrollController`，
交给 `ScrollView` / `LazyColumn`，之后既能读偏移也能命令它滚。

### 8.4 裁决与落点

| 决策 | 理由 |
|---|---|
| 新增 `ScrollController`（句柄）+ `ScrollMetrics`（State 侧契约） | 句柄是**声明层对象**，由 State 挂载/卸载时 `attach`/`detach`；不做 `GlobalKey`，符合 `BuildContext` 无向上查找的既有裁决 |
| `jumpTo()` 用 `scrollBy(Δ)` 表达，不新增绝对定位原语 | 夹取（下界 0、上界 `maxScroll`）已收口在 `ScrollLane::move()`；再加一条绝对路径等于把夹取写第二遍 |
| 偏移通知在 State 侧**去重**后再回调 | 滚动入口有五个（`scrollBy` / `scrollByAxis` / `glideByAxis` / `ensureVisible` / `tickFrame`），逐个判"有没有真动"容易漏；按值去重是幂等的，宁可多调 |
| 回调签名 `(float $offset, float $maxOffset)` | 「滚到底」的判据要两者相减，只给 offset 应用还得自己再摸一次盒 |

### 8.5 判据

| 用例 | 钉什么 |
|---|---|
| 绑定 | `controller->offset()` 在 mount 后跟随真实偏移 |
| 命令 | `jumpToBottom()` 后 `offset() === maxOffset()`，且界面真的滚了 |
| 回调 | 滚动 ⇒ `onChanged` 收到 `(offset, max)`；**同值不重复回调** |
| 上限 | `jumpTo(1e9)` 被盒夹到 `maxScroll`，不飞走 |
| 解绑 | State 卸载后 `offset()` 返回 0（不乱读死盒） |
| 无 controller | 不传 ⇒ 一切照旧（既有路径零改动） |

---

## 9. 第三轮复核：焦点作用域的"打开"那一半

### 9.1 现状盘点（同一机制的三件套）

焦点作用域本来就已经有两条腿：

| 件 | 状态 | 位置 |
|---|---|---|
| 打开时**设陷阱**（Tab 只在弹层内走） | 有 | `OverlayManager::focusTrapRoots()` |
| 关闭时**回锚**（焦点回到打开它的节点） | 有 | `pushOverlay()` 快照 / `removeOverlay()` 恢复 |
| 打开时**自动聚焦** | **无** | —— |

缺的那条腿不是"没有这个功能"，而是**同一个机制的另外两条已经在了**：模态打开时
陷阱把焦点链缩到弹层内，`collect()` 因此把陷阱外的旧焦点清成 `null` —— 键盘就
此**悬空**，用户必须按一次 Tab 才能开始打字。这条路径每次开对话框都要用户付一次
Tab，而 `Modal` 正是为此存在的。

### 9.2 对照成熟框架

| 框架 | 声明方式 | 语义 |
|---|---|---|
| Flutter | `Focus(autofocus: true)` | 作用域里**第一个**声明者，且仅当该作用域内没人有焦点时接管 |
| HTML | `<input autofocus>` / `<dialog>` | 同上（`<dialog>` 聚焦第一个可聚焦元素） |
| Qt | `QWidget::setFocus()` + 对话框 `setTabOrder` | 显示时由对话框把焦点给第一个输入控件 |
| **本仓** | `->focusOrder()` / `->skipFocus()` 已声明化 | 缺自动聚焦这一条 |

结论：**做成声明**（`->autoFocus()`），不做成命令。理由是本仓 `BuildContext`
明令无向上查找、控件也够不到 `FocusManager`，命令式 `requestFocus()` 必须配一个
句柄（像 `ScrollController` 那样）；而"打开就该聚焦我"根本不需要命令 —— 它是一次
**状态为空时的裁决**，交给每次重建焦点链的人（`FocusManager::collect()`）最省。

### 9.3 裁决

| 决策 | 理由 |
|---|---|
| 落在 `FocusNode`（与 `order` / `skip` 同一值对象） | 三者都是"焦点声明"，Widget 上仍只有一个 `?FocusNode` 字段 |
| 只在 `focused === null` 时接管 | 抢焦点会让用户正在操作的控件被弹走；模态那一支**不需要额外条件** —— 陷阱生效时旧焦点已在同一处被清空 |
| 候选只从 Tab 序里选 | 顺序即文档序，`skipFocus()` / `disabled()` 的子树自然出局（它们本来就不进序）。两个声明矛盾时以 skip 为准 |
| 只取**第一个** | 与 Flutter 一致；"最后一个赢"会让先声明的节点静默失效 |
| 不解释 `requestFocus()` | 命令式聚焦的真实消费者（`ComboBox` 方向键、`Table` 单元格）都还缺前置条件（浮层开关在控件侧够不着），先不发明 |

**已知边界**：自动聚焦不触发 `revealFocused()`（那条路只挂在**按键**驱动的焦点
变化上）。首屏偏移恒为 0、模态面板本就完整可见，真实影响为零；要补得给
`FocusManager` 加焦点变化监听，等有真实诉求再付。

### 9.4 落点与判据

- 框架：`FocusNode::$autoFocus` + `WidgetFocus::autoFocus()`；`FocusManager::collect()`
  在"焦点因离线被清空"之后扫描 Tab 序。
- 消费者：`apps/users` 注册页首个字段（进页面直接打字）；
  `apps/showcase` 反馈页新增"带输入的对话框"（陷阱 + 自动聚焦的完整形态）。

| 用例 | 钉什么 |
|---|---|
| 空焦点 | 声明者拿到焦点并进编辑态 |
| 两个声明 | 文档序第一个赢（不是最后一个） |
| 已聚焦 | 重收后焦点不动（不抢） |
| 模态 | 焦点落在对话框里，不落到背后的页面；关掉后由主树声明接手 |
| 失效边界 | `skipFocus()` / `disabled()` 子树里的声明不生效 |
| 端到端 | 真 `Application`：开带输入的模态 ⇒ 聚焦并进编辑态；关掉 ⇒ 回到锚点（恢复优先于自动聚焦） |

