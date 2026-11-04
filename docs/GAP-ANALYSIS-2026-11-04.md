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
| 自动聚焦 | 未覆盖 | 0（`TableState` 注释明写「本仓无 `autoFocus`」） | 成立，低优先 |

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
