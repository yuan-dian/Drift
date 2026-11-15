# P0-1 表单最后一公里：复核、裁决与落地（2026-11-15）

基线：`842a2d6`（P0-3 已落地）。本稿是**先量后判**的产物：不照 `REVIEW-CAPABILITY-2026-11-15.md §5`
的最小落点直接改，先在当天 HEAD 重 grep，再按结构 / 实现 / 扩展性 / DX / 维护性裁决。

---

## 1. 复核：四条 P0-1 落点只有两条是真的

| §5 写的落点 | HEAD 实测 | 判定 |
|---|---|---|
| 密码遮蔽 | `obscure` 全库 0 命中；`TextField.php` 字段只有 value/label/placeholder/error/helper/maxLength/回调 | **真缺口**，登录框写不出来 |
| 输入校验 | `TextField::error()` 受控展示 + `TextFieldValidationTest.php` + `apps/users/src/Store/SignupForm.php::validate()` 应用层规则 | **已足够**。docblock 明写「规则属业务状态」是有据裁决，塞进控件会造第二份真相 |
| 自动补全 | `src/Widget/ComboBox.php`：**输入过滤 + `overlayPanel` 候选浮层 + 回车取第一项 + 远程搜索换表**；`apps/showcase/src/Pages/ComboBoxPage.php` 有真消费者 | **已足够**。§3.4 用 `completer` 关键词 0 命中判「缺失」是**假阳性**——功能在，只是不叫这个名字 |
| IME 预编辑串 | `cpp-src/win32/drift_win32.cc:1291-1295` 明写「GCS_COMPSTR 不读：那条是自绘路线，litehtml-lab 自绘路线明写不照抄」；`TextInputClient` 那组组合区间接口正是该裁决留下的死接口 | **已裁决不做**。§5 把它列进 P0-1 是口径错误，重开就是踩坑 |

> 这一节是本轮最值钱的产出：**关键词 0 命中 ≠ 能力缺失**。`ComboBox` 与 `error()`
> 都是「有实现、但名字和清单里的词不一样」。清单会腐烂，动手前重 grep 不是形式主义。

于是 P0-1 从四项收敛成两项：**显示/值分离（密码遮蔽）** 与 **输入边界格式化**。

---

## 2. 密码遮蔽：为什么放在渲染盒，而不是 `TextField`

### 裁决

在 `RenderTextField` 里换**绘制源**，`TextField` 只多一个 `obscureText()` 声明。
`$this->text` 恒为原文；公开的字节偏移契约（构造参数、`caretRect()`、`selectionRect()`、
`offsetAt()`）也恒为**原文 UTF-8 字节偏移**。

### 为什么这是更优解

- **结构**：语义 `Value` 走 `TextField::value() → WidgetSemantics::semanticsValue() → SemanticsBuilder`，
  绘制走 `TextFieldState::build() → TextFieldBox → RenderTextField`。两条路本来就分开，
  遮蔽只需改后一条 —— 不需要第二份「原文」缓存，「遮蔽时把 UIA Value 也换成圆点」
  在结构上根本写不出来（判据的第一条变异正是手工模拟这种偷懒）。
- **实现**：掩码串是「每个原文码位一个 `•`」，`Utf8::decode()` 给出码位边界数组，
  `toDisplayOffset()` / `fromDisplayOffset()` 双向换算。中英混合串 `a中b` 的原文边界
  `[0,1,4,5]` 与绘制串边界 `[0,3,6,9]` 必须能真实往返，否则密码框里点一下就落错插入点。
- **扩展性**：`offsetAtX()` 的静态调用方（`TableState` 表格单元格编辑）传的是原文 seed，
  不受影响；渲染盒公开契约不变 ⇒ 现有消费者零改动。
- **DX**：`TextField::make()->obscureText()` 一行，不需要理解掩码与偏移的关系。
- **维护性**：知道「有遮蔽」这件事的只有 `RenderTextField` 一个文件；`TextFieldState` /
  `TextFieldBox` 只透传一个 bool。

### 反面方案（已否）

- 在 `TextField` 里存 `displayValue` 与 `value` 两份：undo / 受控值 / 语义值三处要同步，
  是典型的「一个模式 N 份手写」（§78 刚判过同类问题）。
- 直接把 `$this->text` 换成掩码串：字节偏移契约立刻破，指针框选与表格编辑一起错位。

---

## 3. 输入格式化：为什么落在 `TextDraft::commitInput()`

### 裁决

新增 `TextInputFormatter` 接口 + `TextEdit` 值对象 + `FilteringTextInputFormatter` 内置实现。
`TextDraft` 的 `insert()` / `replaceSelection()` 统一走新收口的 `commitInput()`：
先格式化，再入撤销栈。`TextField::inputFormatters()` 是声明面，
`EditableTextState::inputFormatters()` 是与 `maxLength()` 同款的子类钩子。

### 为什么这是更优解

- **结构**：`clampInput()`（长度上限）与 `undo()`/`redo()`、`setText()` 都在 `TextDraft`。
  格式化放在**用户输入唯一落点**，天然做到「外部受控值不被改写」和「撤销历史不被重排」——
  这两条是受控模型的红线，放在 Widget 或 State 层都会漏（键盘、粘贴、IME、UIA SetValue
  四条输入路径不是同一个入口）。
- **实现**：格式化后的整次输入只占一步撤销（快照记的是变换后的文本），
  不需要给 undo 栈加「格式化 delta」这种第二套机制。
- **扩展性**：接口收/发 `TextEdit(text, caret)`，应用可以写插入型变换（手机号分隔符、
  大写化）并自己修正 caret；内置的删除型实现用 `TextEdit::without()` 统一算
  「插入符之前被删掉多少」。
- **DX**：`->inputFormatters(new FilteringTextInputFormatter('/\s+/u'))`；
  真消费者是 `apps/users/src/Pages/SignupPage.php` 的邮箱字段（邮箱里不该有空白）。
- **维护性**：`commitInput()` 一处收口；`applyText()` 顺带把 caret 过 `snap()`，
  自定义格式化器返回越界/半字符偏移也不会撕裂状态。

### 反面方案（已否）

- 放进 `EditableTextState::settle()`：那时文本已变、通知已发，格式化会先给应用一个
  「脏值」再偷偷改掉，且撤销栈里存的是未格式化文本。
- 做成 `TextField::validator()` 之类的控件内校验：与 §1 的 `error()` 受控模型重复，
  把业务规则搬进框架。

---

## 4. 判据与变异自检

判据放在 `tests/Feature/Widget/TextFieldMaskTest.php` 与 `TextFieldFormatterTest.php`，
读的是**实际绘制命令流**（`RecordingCanvas` → `DisplayList::OP_DRAW_PARAGRAPH` → `Paragraph::$text`）、
**语义树**与**编辑状态机的公开行为**，不读 `obscure` / `formatters` 字段。

| 判据 | 盯什么 |
|---|---|
| 绘制串是 `•••`、不含原文字符 | 像素层（绘制命令，非配置） |
| 语义 `Value` 仍是 `abc` | 读屏 / 自动化拿真值 |
| 中英混合四个字节边界 `caretRect → offsetAt` 真实往返 | 偏移映射 |
| 空值仍显示 placeholder | 遮蔽不误伤占位符 |
| 粘贴含空格邮箱 → 文本与 `onChanged` 都是 `ab@c` | 格式化先于通知 |
| 插入符前的字符被删后 caret 不漂 | caret 修正 |
| `setText()` 不被格式化 / undo 一步退干净 | 受控模型与撤销栈 |
| `apps/users` 注册页真的接了格式化器 | 不是框架死代码 |

变异自检（`_build/mutate-mask.php`、`_build/mutate-formatter.php`）共 **8 组全真红**、
恢复后复绿：`no-mask` / `mask-semantic-value` / `no-offset-map` / `mask-placeholder` /
`no-format` / `format-external-value` / `no-caret-fix` / `no-real-consumer`。

---

## 5. P0-2 的设计裁决（下一轮落地，本轮不动 C++）

§7 原写「P0-1 → P0-2 同轮避免两遍接线」。**本轮复核后调整为分轮**，理由：
P0-1 最终**一行 `SemanticsNode` 都没碰**（语义 Value 本来就是受控值），
P0-2 要独立改 `uia.cc` 的 provider 架构、重编 AOT、跑真机 UIA 探针。
分轮不会重复接线，反而避免「PHP 数据面已推、C++ 还没消费」的中间态进闸门。

> **事后订正（2026-11-15，P0-2 已落地）**：下面 1 / 3 / 4 三条经复核**否决**，
> 2 条的「按 key diff」也改为「`key` 优先、`automationId` 兜底」。
> 以 `docs/DESIGN-UIA-P0-2-2026-11-15.md` 与 `DEBT §81` 为准，别照本节开工。

下一轮的既定裁决（避免再照 §5 的最小落点堆 if，**已被上方订正覆盖**）：

1. **能力位替代 ControlType if 链**。`uia.cc:simplePattern()` 现在按 `ControlType` 硬分派 6 种；
   继续加 4 种会变成「一个模式 N 份手写」。改为在 `SemanticsNode` 上声明标量 `patternMask`，
   `toArrays()` → `php_drift_win_uia_push()` → `UiaNode` 一条链，C++ 按 mask 分派。
   与 §65「按状态而不是按控制类型分派 SelectionItem」是同一条哲学。
2. **属性变更事件按 key diff**。`driftUiaApply()` 已有 `oldKeys` / `oldSelect` 快照机制，
   扩成比对 name / value / enabled / focusable 后 `UiaRaiseAutomationPropertyChangedEvent`。
   别用「整树重推」冒充事件 —— 读屏客户端订阅的是事件，不是快照。
3. **`ITextProvider` 做薄实现**。语义树只有整串 `value`，没有字符级信息；
   `DocumentRange` 覆盖整串、`GetText(-1)` 返回 `value`、
   `GetBoundingRectangles` 返回节点矩形、`Selection` 返回空 range。
   官方明确 `IValueProvider` 与 `ITextProvider` 应**并存**，且 `TextChanged` 与
   `AutomationPropertyChanged` 两个事件**都要**支持，别只补前者。
4. **`IGridProvider` 需要节点带行列**。`SemanticsNode` 补 `gridRow/gridCol/rowCount/colCount`，
   由 `TableState` 填；与 §78「不拆类」的裁决不冲突，但新能力别再堆进同一方法簇。
5. **判据扩订阅**。`tools/probe-uia-state.py:285-325` 目前只订阅 20012，
   纯 ctypes 外部客户端要能请求 `TextPattern` / `GridPattern` 并**收到**属性变更事件；
   变异「删掉一个 pattern 分派」/「删掉 raise」各红一条。

---

## 6. 诚实边界

- 自动补全的「自由文本 + 任意候选」仍以 `ComboBox` 的 one-of-N 形态提供；
  没有做 WinUI `AutoSuggestBox` 那种「候选只是建议、值可任意」的独立控件 —— 目前零消费者。
- 密码遮蔽只覆盖 `TextField`（单行）；`TextArea` 不需要遮蔽，故未改。
- 内置格式化器只有删除型 `FilteringTextInputFormatter`；插入型（分隔符 / 大小写）
  由应用实现 `TextInputFormatter` 接口，框架不预先设计签名。
- IME 预编辑串、应用级校验规则、P0-2 的四个 provider 与属性事件本轮**均未做**。
