# Drift 设计方案 · 评审报告

评审对象：`DESIGN.md`（v1.0 + 2026-10-14 探针修订）
评审方式：对照**真实 schema / 真实探针源码 / 真实 GUI 仓配置**逐条核验，不采信文档自述。
评审日期：2026-10-14

> ★ **章节号已按 `DESIGN.md` v2.0 重排更新**（本文写于 v1.0 时期）。
>
> **处置状态（同日更新）**：**P0 全部已修、P1 全部已决、P2 全部已裁决** —— 落点见 `DESIGN.md §24`（P2 的 11 项裁决已散入 §5.1 / §9 / §12.3 / §12.4；原 §23 变更日志已移出，见 `HISTORY.md`）。
> 其中 P1-2（守卫成本）**已补测并推翻了原推断**：守卫可测量（+11%），不是噪声；裁决不变但依据换成 25× 比值（`DESIGN.md §15.3`）。
> **探针 E 补测（2026-10-14 晚）**：§17.2 的"协程后端"**因果被推翻**——让 PHP 离开 UI 线程靠 TSRM 而非协程，且零扩展（`std::thread` + `ts_resource(0)` 已证可用）。见 `DESIGN.md §17.2` 与 `HISTORY.md §1`（F5）。
> 仍未决的开放项见 `DESIGN.md §24.2`。

---

## 总评

**架构选型是对的，不需要动。** 三树 + 显式 Key diff + 无自动追踪 + 两阶段布局 + 确定性优先，这套骨架有 Flutter/GUI 仓双重实证，且 §18 的"借局部机制、不借范式"纪律执行得很克制。

**问题集中在"落地细节"和"能力边界"两处**：前者有 4 处会直接导致第一天就踩坑（事实错误 / 自相矛盾），后者是桌面框架的刚需缺口（全篇 0 次提及）。另外有 1 处"实测结论"其实是推断，与方案自己立的"先验后用"纪律不符。

按严重度分四级：**P0 会翻车 / P1 语义未定 / P2 能力缺口 / P3 文档一致性**。

---

## P0 —— 会导致落地失败（事实错误 / 自相矛盾）

### P0-1 §16.4 `project.yml` 与真实 schema 不符（5 处）

对照 `D:\TypePHP\GUI\apps\demo\project.yml`（真实可用配置）与 `TypePHP使用手册.md` §（构建配置，L187–243）：

| # | 方案写的 | 实际 | 后果 |
|---|---------|------|------|
| a | `build-dir: build` | 手册示例确实是 `build`，但 GUI 实测纪律是**每应用一个子目录**（`../../build/demo`） | 多应用共用一个 `build-dir` ⇒ 互相触发**全量重编**（GUI 注释原文："混用同一个目录会让两个应用互相触发全量重编"）。§4.5 的布局是**多应用**（`apps/<app>/`），必踩 |
| b | 无 `job:` | 手册示例 `job: 8`；GUI 用 `job: 16` | 丢掉构建并行度。而方案自己承认"AOT 20–50s 构建是唯一真实差距"（§16.3）——`job` 是唯一能直接砍这个数的旋钮 |
| c | 无 cwd 双基准说明 | GUI 文件头有一整段 ★★ 警告 | **最贵的一处**：`cxx-flags` / `ld-flags` **不经 tpc 路径解析**，相对**进程 cwd**；而 `sources` / `include-paths` / `link-paths` / `build-dir` 相对**yml 所在目录**。§4.5 的 `apps/<app>/project.yml` + 共享 `src/` 布局 ⇒ 前者必须逐字对齐调用目录、后者必须写 `../../`。方案一字未提，落地必错 |
| d | `link-libs: [user32, gdi32, ole32, imm32]` | 缺 `comdlg32` / `shell32` / `advapi32` | **§5.1 声明了 `dialog()` 与 `tray()` 端口**，但 `comdlg32`（文件对话框）、`shell32`（托盘）没链 ⇒ 声明了却实现不了（违反 G2）。`advapi32` 是 ICU 时区探测的硬依赖（GUI 实测 LNK2019） |
| e | `--no-console`（只当命令行开关） | yml 里是键：`no-console: true` | 表述不准。另：手册明确 `--no-console` 会**自动补 `/ENTRY:mainCRTStartup`**，方案未记 |

另：`include:` 手册 L214 明确支持（"复用公共配置…被包含文件按序合并"），但 GUI 注释写"tpc 的 YAML **没有 `include`**（实测：被静默忽略 ⇒ `sources` 为空 ⇒ `Duplicate function main`）"。**手册与实测矛盾**。同理 `ignore`（手册说能排除）与 GUI 注释"sources 没有排除能力"也矛盾。
⇒ 这两条直接决定 §4.5 能否避免"N 个应用 N 份参数拷贝"（GUI 现在就是两份拷贝 + `yml-parity` 断言硬守）。**按方案自己的"先验后用"纪律，这是必须先写探针的两条**，方案却完全没提。

### P0-2 §12.1 与 §12.2 直接打架（路由表形态）

- §12.1 第 2 条：「路由表用 `match`（**不用闭包表** / class-string 表 / 嵌套数组配置）」
- §12.2 样板：`RouteTable::make()->route('buttons', fn(): Widget => ButtonsPage::make(...))` —— **正是闭包表**

同一节内隔 30 行自相矛盾。需要二选一，或明确"简单应用用 `match`、需要 devtools 列举/单测时用 `RouteTable`"是**同一决策的两个规模档**（而不是两套语义）。

### P0-3 §12.2 的 `Root` 读值但不订阅 ⇒ 样板跑不起来

```php
final class HelloRoot extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->title('Drift Demo')->children([...]))
            ->bottom(StatusBar::make()->text($this->store->statusLine()))  // ← 读值
            ->center(PageHost::make()->current($this->store->currentPage()) // ← 读值
                ->table($this->routeTable()))
```

`StatelessWidget` 不会因 `store` 变化而重建（§11.5：订阅只能落在 `SubjectBuilder`）。所以：
- **换页不会发生**（`currentPage()` 只被读一次）
- **状态栏永不更新**

§12.1 第 7 条自己说"`PageHost` 持当前页 Element"，但样板把它当**参数**传进去。⇒ 要么 `PageHost` 内部订阅 `currentPage` 的 Subject（那 §12.2 就不该传 `current()`），要么 `Root` 订阅（那违反 §11.5"订阅点下移"）。**样板与自己的架构不兼容。**

### P0-4 §11.5 / §11.7 的"骨架先可见"在 M1–M3 是**假的**

```php
public function load(int $page = 1): void
{
    $this->status = 'loading'; $this->notifyChanged();   // 骨架先可见   ← 注释
    $r = $this->src->fetchPage($page);                   // 同步阻塞
    ...
    $this->notifyChanged();                              // 一次通知，帧末一次重建
}
```

§5.2 规定"统一重建发生在帧末 `flush` 阶段"。两次 `notifyChanged()` 在**同一个调用栈内**，中间**没有任何一帧**能被渲染 ⇒ 单线程同步后端下 `loading` 态**永远画不出来**。

§11.7 又重复了同一个错误："M1–M3：同步 `DataSource` + 三态（`status='loading'` 先广播 ⇒ 界面不卡死到不可用）" —— 广播了但画不出来，界面照样卡到 `fetchPage()` 返回。

⇒ **三态（loading/error/ready）在 M1–M3 只在 `error` 一支真正生效**。这不是实现问题，是"同步后端 + 帧末统一 flush"的结构后果。要么承认（把 M1–M3 的三态定位为"错误态可见，加载态不可见"），要么 M1 就得有后台后端。

---

## P1 —— 语义未定 / 声明了但可能不生效（G2 违规）

### P1-1 `Errors::report` 的发布期语义自相矛盾

| 位置 | 写法 |
|------|------|
| §5.3 第 3 条 | "`Errors::report` + **中止**"（无条件） |
| §13.1 C6 | "`Errors::report`（**debug 中止**）" |

若按 §13.1 的口径，**release 构建下封存违规被静默吞掉** ⇒ §21 A11 声称"封存后写入 ⇒ **响亮失败**"是**空头承诺**，而且正好犯了方案自己列的 **A6（裸吞 ⇒ 缺陷永久静默）**。

⇒ 必须钉死：`Errors::report` 分几级（fatal / recoverable）？release 下各自行为？**§15.2 的 `mutate()` 守卫属于哪一级？** 这是 G2 的承重墙，不能含糊。

### P1-2 §15.3 的"守卫在噪声量级内"**不是实测**，是推断

方案原话："从 B 与 C 的差值（0.07 µs/节点…）可见，**守卫在噪声量级内**"。

但看探针源码 `probes/fluent_cost/probe.php` 的 `TextB`：

```php
public function title(): self { $this->role = 1; return $this; }   // ← 裸 set，没有 mutate()
```

**探针的 B 没有守卫**，且字段是 `public`，而 §15.2 的设计是 `private` + 每方法 `$this->mutate()`。⇒ 被测量的实现 ≠ 被裁决的实现。

这不是"结论错"，是"证据不支持结论"。按方案自己立的"先验后用"（§20 第 3 条）与"量具双向自检"，应**补测带守卫的 B**，或把这句话降级为"预期"。

### P1-3 §15.2 的样例不可编译 / 与 §12.2 不一致

1. `Widget` 基类**没有构造器**，但 §12.2 的 `HelloRoot` 调 `parent::__construct()`。
2. §15.2 的 `Text` 声明 `private string $content;`，`make()` 里 `new self($content)` —— 但**该类没有构造器**。样例缺构造器。

### P1-4 `@internal` 与 `final public seal()` 三者冲突

- §15.2 docblock 标 `@internal`
- §15.6 教用户显式调 `->seal()`
- §10.1 把 `Drift\Widget` 列为**公共命名空间**，§10.2 承诺"公共签名不悄悄变"（API 快照进 CI）

⇒ `seal()` 一旦是 `final public`，就**永久进入公共契约**。三者需对齐：要么 `seal()` 移出公共面（用 `@api` 反向标注豁免），要么承认它稳定。

### P1-5 `Text::$role = 0` 是 A2 风险（拿合法值当哨兵）

注释写 `// TextRole 哨兵：0 = 未设置`。但 `TextRole` 是 backed enum —— **若 `Body` 的 value 是 0，这里就是 §21 A2 的原样复现**（"拿合法取值当哨兵 ⇒ 安静地错"）。

同一段里 `$size = -1.0` 的理由写得很清楚（"★ 不用 0，0 是合法字号"），却对 `$role` 没给同样的论证。⇒ 必须钉死"`TextRole` 的 value 从 1 起"，并写成 lint/断言。

### P1-6 `Paragraph` 两套表示、两个所有者

| 位置 | 表示 |
|------|------|
| §7.1 `GpuContext::createParagraph(array $runs, float $maxWidth): int` | **int 句柄**（"段落槽"） |
| §8.1 `Paragraph` | **不可变排版结果 + 缓存键 `(text, style, maxWidth, scale)`** |

缓存归谁？谁负责 `release()`？§8 的六层栈里没有 `GpuContext`。⇒ 需要一个明确的所有权句。

---

## P2 —— 桌面框架的刚需缺口（全篇 0 次提及）

在 `DESIGN.md` 里逐词检索的结果：

| 能力 | 命中次数 | 判断 |
|------|---------|------|
| 快捷键 / accelerator | **0** | ❌ 桌面刚需。且它要在**焦点路由之前**拦截（与 §9 的 `KeyEvent` 路由是两套语义），现在没有位置 |
| 菜单栏 / 上下文菜单 | **0** | ❌ §5.1 的端口清单里没有 `menu()` |
| 多窗口 | **0** | ❌ §7.2 明写"`Application` 持 surface"（单数）。若是**刻意不做**，应像 §11.6 / §12.1 第 6 条那样**显式写"明确不做 + 依据"**，否则是默认漏洞 |
| 拖放（OS → 应用） | **0** | ❌ `drag` 仅 1 次命中且指手势；无 OS 级 drag&drop（`WM_DROPFILES` / `IDropTarget`） |
| 鼠标指针形状 cursor | **0** | ❌ 桌面基本体验（`IDC_IBEAM` / `IDC_SIZEALL` / 忙碌态） |
| 系统深色模式 / 高对比度 | **0** | ❌ 有 `Theme` 但**没有系统主题变更事件**入口（`DpiChanged` 有，`ThemeChanged` 没有） |
| 窗口状态持久化 | **0** | ❌ 位置 / 尺寸 / 最大化状态 |
| 焦点遍历顺序 | **0** | ⚠️ §9 写了"遍历策略（Tab 序 / 方向键 / scope）"但**没定义顺序规则**（文档序？显式 index？容器可否整体跳过？）。这是可用性 + 可访问性硬需求 |
| 列表虚拟化 | **1**（仅 §19 M5） | ⚠️ 且与 §12.1 第 4 条 `Offstage` 保状态、§15 Seal-on-Mount 的**交互未定义**：被回收的 Element 的 State 去哪？ |

**建议**：P2 里"不做"的（多窗口、OS 拖放）**明确写进"明确不做"清单**并给依据；"要做"的（快捷键、菜单、cursor、主题变更事件、焦点序）至少要在 §5.1 端口清单与 §9 路由里**留出位置**——否则 M2 写控件时才发现没有插槽，就得回头改 `Platform` 接口（而 §5.1 第 2 条的"端口拆小"正是为了防这个）。

---

## P3 —— 文档一致性

| # | 位置 | 问题 |
|---|------|------|
| 1 | §0 第 13 行 | 说 `architecture.html` 是"分层 / 事件循环 / 三树 / 双缝 / 语法**四图**"，实际已是 **6 图**（含 fluent 生命周期、实测裁决） |
| 2 | §16.1 表格末行 | 引"**必填 modifier 未调用** ⇒ lint（**§15.3**）"——§15.3 现在是实测裁决表，**没有**这条 lint 的定义，引用错位 |
| 3 | §19 M1 出口判据 | 写"语言探针门（**fluent 微基准已完成**）"，但 `typed_ternary` / `clone_with` 两组探针（§2.4 纪律 1）也已完成，M1 探针门表未同步 |
| 4 | §2.2 表格 | `类型不可变` 行仍引"手册 §9.5"，而该行的旧口径已在 v2.0 被证伪并改写 ⇒ 同表内新旧口径混排，读者易误读 |

---

## 值得肯定的三处

1. **§15.3 / §2.4 纪律 1 的"探针证伪手册"** —— 不靠权威文档、直接看生成的 C++，这是方案里最有价值的方法论动作。尤其 §15.2 那处**自我纠错**（`freeze(ownerId)` 误杀 ⇒ 删掉 owner 校验）值得保留在文档里当范例。
2. **§15.6 的否决记录** —— 把"为什么不加 `->build()`"连同**名字硬冲突**（`StatelessWidget::build($ctx)`）一起记下来，防重开。这是 §20 第 2 条"不留沉默的否决"的正面样板。
3. **§18 借鉴总表的"避开"列** —— 借了八家框架的**局部机制**而不借范式，边界守得很干净（尤其明确拒绝 Vue `reactive()` / WPF 绑定表达式 / HTML 流式布局）。

---

## 建议的处置顺序

| 优先级 | 动作 |
|--------|------|
| 1 | **P0-2 / P0-3 / P0-4** —— 改样板代码（自相矛盾与跑不通的样板会直接误导实现） |
| 2 | **P0-1** —— 用真实 `project.yml` schema 重写 §16.4；`include:` / `ignore` 两条**先写探针** |
| 3 | **P1-1** —— 钉死 `Errors::report` 分级与 release 语义（G2 承重墙） |
| 4 | **P1-2** —— 补测带守卫的 B（或降级表述） |
| 5 | **P2** —— 分"明确不做 / 留插槽"两栏补齐 |
| 6 | **P1-3 ~ P1-6 / P3** —— 文档修补 |
