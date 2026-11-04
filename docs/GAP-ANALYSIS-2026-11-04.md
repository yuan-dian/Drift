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

## 10. 第四轮复核：窗口尺寸与响应式

### 10.1 上一轮的判断是错的

第三轮把"响应式"列为缺口时，依据是"`BuildContext` 读不到窗口尺寸"。复核后修正：
**字段一直有**（`$width` / `$height`，`attach()` 时用根约束填），缺的是**它不会更新**。

`repaintForResize()` 只跑布局 + 绘制，不 `settle()` 也不下发新 ctx ⇒ 无论怎样 resize，
`build()` 里读到的宽高永远是 `attach()` 那一刻的。**是"有但死了"，不是"没有"。**

### 10.2 代价（仓库自己的记录）

| 证据 | 原文 |
|---|---|
| `ShowcaseState::NAV_WIDTH` | 侧栏定宽 200 |
| `Bootstrap::MIN_WINDOW_WIDTH` | 420 ⇒ 内容区只剩 220 |
| `tools/narrow-audit.php` | 专门逐页审计"220 宽装不装得下" |
| 同文件 | 「被裁掉的右边**没有横向滚动条可以拿回来**」 |

实测（本轮）：内容区 220 宽时 **34 页里有 5 页装不下**。这不是理论缺口，是每次
把窗口拖到最小都会发生的事。

### 10.3 裁决：暴露**档位**，不暴露连续尺寸

三条理由，任一条都足够：

1. **频率**：拖动 resize 是高频路径（平台在模态尺寸循环里同步调回 PHP）。
   逐像素重建全树会卡 —— 而"档位不变 ⇒ 布局结论不变"，重排就够了。
2. **正确性**：在"档内不重建"的前提下，`build()` 看到的宽度必然滞后。
   把它暴露成响应式判据就是埋坑（今天已经埋过：`LazyListState` 拿它算虚拟化窗口）。
3. **对标**：Material 3 `WindowSizeClass`、CSS media query、Tailwind/Bootstrap 断点 ——
   主流都是**离散档做结构响应**；连续值交给布局层（`Expanded` / 比例），那本来就不需要 rebuild。

所以 `BuildContext` 只新增一个**永不过期**的值（`windowSize()`），宽度语义保持原样并注明时限。

### 10.4 落点

- `WindowSize` 枚举：`Compact < 600 / Medium < 1000 / Expanded < 1440 / Large`。
- `BuildContext::windowSize()`：构造参数可选，不给就按 `$width` 推 ⇒ 测试与审计只传宽即可。
- `Application::adoptWindowSize()`：**跨档才下发**（走现成的 `applyContext()` 链，
  根 + 浮层两棵树），档内直接返回。`frame()` 与 `repaintForResize()` 各调一次。
- 消费者：showcase 侧栏 Compact 档从 200 收成 56（`IconButton` 图标栏，标签仍进无障碍名）。

### 10.5 判据

| 用例 | 钉什么 |
|---|---|
| 断点 | 599/600/999/1000/1439/1440 六个边界，以及 `atLeast()` 是包含比较 |
| 推导 | 不给参数时按宽推；`withTheme()` 不动档位也不动宽高 |
| 跨档 | `repaintForResize` 跨越断点 ⇒ `build()` 重跑且拿到新档 + 新宽 |
| 档内 | 同档 resize ⇒ `build()` **不重跑**（宽度停在跨档那次） |
| 零视口 | 最小化（0×0）不改档 —— 不许被当成 Compact |
| 外壳 | 420 宽下侧栏不再有 `NavButton`、整棵外壳不裁剪；940 宽下 `NavButton` 还在 |
| 审计 | `narrow-audit.php`：内容区 364 宽 · **34 页 0 页装不下**（改造前 220 宽是 5 页） |

牙检：去掉"档内不下发"的门控 ⇒ `builds` 从 3 涨到 7，档内判据当场红；
强制不折叠 ⇒ 外壳判据当场红。

### 10.6 顺手修的

`narrow-audit.php` 的夹具给 `\Closure` 参数一律塞 `static function (): void {}`。
异步页会立刻 `$fetch()->onProgress(...)` ⇒ `Call to a member function onProgress() on null`。
这个缺陷一直被"220 宽下早早溢出"掩盖着，侧栏收窄后页面能排进去了才暴露。
改为返回 `Future::pending()`（审计只量布局，不需要数据落地）。

### 10.7 遗留

`$ctx->width/$height` 在**档内**仍是陈旧的（`TableState` / `LazyListState` 拿它算虚拟化窗口，
靠 `windowCovers()` 自愈，代价是一帧）。真正的"读布局期真实约束"是 `LayoutBuilder`
那一类能力，与"档位"正交，另议。

---

## 11. 第五轮复核：单行输入框的 `Enter` 是死键

### 11.1 实证：三环路由都没人接

推荐顺序第③项是 `TextField::onSubmitted`（`TextArea` 有而它没有）。复核先钉死
"今天到底发生了什么"，而不是照抄"少了个回调"：

| 证据 | 原文 / 实测 |
|---|---|
| `TextAreaState::interceptKey()` | `Ctrl+Enter` ⇒ `submittedHandler()`；裸 `Enter` ⇒ 插 `\n` |
| `TextFieldState::handleKey()` | 走 `EditableTextState` ⇒ `TextKeymap` 对 `Enter` 返回 `null`，`Esc` 分支也不匹配 ⇒ **返回 `false`** |
| `TextKeymap` 类注释 | 「收口键（`Enter`/`Tab`/`Esc`）故意不在这里：它们问的是"这次编辑怎么收场"」 |
| `InputRouter::dispatch()` | ① 焦点链 → ② 加速键 → ③ `activateFocused()`（`Enter`/`Space` 激活**焦点按钮**） |
| `activateFocusedWidget()` | 焦点是 `TextField`，不是 `Activatable` ⇒ 直接 `return` |

⇒ 焦点在单行输入框里按 `Enter`：三环全部放行，什么也不发生。仓库里的绕路是
`UsersApplication::accelerators()` 把 `Ctrl+S` 绑到 `form.submit` —— 注册页要提交，
只能点按钮或按一个与"表单"无关的组合键。

### 11.2 对照成熟框架：两种流派

| 框架 | 做法 | 归属 |
|---|---|---|
| HTML | `<input>` 里 `Enter` 隐式提交 `<form>` | **容器**（隐含默认按钮） |
| WPF / WinForms | `Button.IsDefault` / `Form.AcceptButton` | **容器** |
| Qt | `QLineEdit::returnPressed` | **字段** |
| Flutter | `TextField.onSubmitted` + `textInputAction` | **字段** |
| GTK | `GtkEntry::activate` | **字段** |

容器派的前提是"表单/默认按钮"这个作用域对象。本仓 §3 已结构性否决 `Form.of()`
（`BuildContext` 无向上查找、校验规则属业务状态、避免双真相源）⇒ 只剩**字段派**。
这与 `ScrollController` / `autoFocus` 前两轮的结论同形：**没有向上查找，能力只能靠
显式注入或声明 + 裁决**。

### 11.3 裁决

| 决策 | 理由 |
|---|---|
| `onSubmitted(callable)`，签名 `fn(string $text) => void` | 与 `TextArea::onSubmitted()` 同形；收**当前草稿**（不是受控值），与多行一致 |
| 只认**裸** `Enter` | 带修饰键的 `Enter` 归应用加速键 / 组合命令。`TextArea` 用 `Ctrl+Enter` 是因为它的裸 `Enter` 已被换行占了，语义不同，不强行对齐 |
| **没接 handler ⇒ 不吞键** | 硬兼容约束：`ComboBox` 内嵌的 `TextField` 没有提交回调，`activateFocused()` 靠这个放行去开面板（见 `ComboBoxState::keyHandler()` 的类注释）；应用也可能把 `Enter` 绑成加速键。吞了不报错，只是"键盘打不开下拉框" |
| 提交不写回、不改文本 | 回写只有 `onChanged` 一条路（受控）；提交是"这段编辑怎么收场"，不是一次编辑 |
| 命令派发**不进控件** | `Widget` 层够不到 `CommandRouter`（§4.3）。应用把 `dispatch('form.submit')` 包成闭包，从组合根经 `UsersRoot::submitHandler()` 注入 —— 与 `menuOpener()` / `windowDrag()` 同一条路。否则"回车提交"会复制一份校验/序列化/回跳 |

★ 最后一条是本轮的**结构收益**：`SignupPage` 两个字段共用同一个 `$submit` 闭包，
点击「提交」按钮与在任一字段按 `Enter` 走的是**同一个** `form.submit` 命令，
业务只有一份。若当初把"提交"做成控件自己的行为，这里必然出现第二条提交路径。

### 11.4 落点与判据

- 框架：`TextField::onSubmitted()` / `submittedHandler()`；`TextFieldState::interceptKey()`
  在 `TextKeymap` 之前认领裸 `Enter`（沿用 `EditableTextState` 已有的三段顺序，
  不新增路由环）。
- 消费者：`apps/users` 注册页两字段（真实业务、走命令链）；`apps/showcase` FormPage
  新演示节（搜索框回车回显）；`ComboBox` 保持零改动，由既有判据守放行语义。

| 用例 | 钉什么 |
|---|---|
| 提交 | 接了 handler ⇒ 吃掉 `Enter`，回调收到**含未回写那笔**的草稿文本 |
| 单行纪律 | `Enter` 不插换行、文本不变 |
| 无消费者 | 没接 handler ⇒ `Enter` 放行（`ComboBox` 开面板、`activateFocused()` 还活着） |
| 组合键 | `Ctrl/Alt/Shift+Enter` 三种都不提交、不消费 ⇒ 落给应用 |
| 端到端 | 真 `UsersApplication`：焦点在姓名框按 `Enter` ⇒ 校验通过 + 回跳列表页 + 播报，与按钮同链 |
| 回归 | `ComboBoxTest` 既有的「Enter 取第一项 / 开面板」全绿；`page/FormPage` 像素基线（内容变高 ⇒ 页面滚动条拇指短 32px） |

**牙检**（去掉声明或门控，判据必须红）：

1. `submittedHandler()` 恒返回 `null` ⇒ 单测提交判据红 + 真应用 smoke 红（`page=signup`）；
2. 去掉 `is(KeyChord::enter())` 门控 ⇒ 5 条判据齐红（文本键也触发提交，重复回调）；
3. 没接 handler 也 `return true` ⇒ 「放行给上层」判据红。

### 11.5 本轮量具

| 项 | 结果 |
|---|---|
| PHPUnit | **1322** 用例 / **4529** 断言（上轮 1321 / 4521） |
| lint | 978 文件 · 0 error · 0 warn |
| smoke | **473** 条（新增 1 条真应用判据） |
| golden | 整页 27 / 原语 9，全 OK；`page/FormPage` 基线更新 1 个文件 |
| narrow-audit | 34 页 · 0 页装不下 |
| docref | 85 ok / **17 FAIL** —— 全是历史文档的 `文件:行号` 越界，与本轮无关（见 §11.6） |

### 11.6 遗留

- **docref 17 条**：已连续四轮上报。建议照 `API-SURFACE.md` 的先例**去掉行号只留
  文件名**（行号每改一次代码就漂，闸门会被噪声淹没）；需用户裁决后单独一轮清理。
- **推荐顺序下一项 `LayoutBuilder`**：读布局期真实约束，与档位正交，能根治
  `LazyListState` / `TableState` 用陈旧 `$ctx->height` 的一帧代价。改动面在**外层
  布局协议**（build 先行、layout 后量的时序），值得单独一轮，别塞进控件小改里。
- 仍未做且零消费者：`obscureText` / `inputFormatters` / i18n / RTL / 真 TabBar
  （§7 的理由未变）。


