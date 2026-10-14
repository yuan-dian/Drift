# Drift —— TypePHP 声明式 GUI 框架 · 设计方案

> 状态：**方案 v1.0（提案）** · 本文是 Drift 的架构裁决文档，覆盖用户提出的 **15 个设计重点**。
>
> **输入来源**（全部实读，非推测）：
> - `D:\TypePHP\typephp\TypePHP使用手册.md`（803 行，能力边界权威）
> - `D:\TypePHP\GUI`（160+ 提交的实验仓：三树 / 布局 V3 / 平台端口 / 45 控件 / R1–R12 lint / RT-1..RT-4 红线 / 六轮评审）
> - `D:\TypePHP\typephp\examples`：`qt-taskboard` / `android-native` / `tetris-win32` / `win32-hello` / `swoole` / `property-hook` / `attributes`
> - `D:\TypePHP\markdown-editor`（真实交付的单 exe 应用）
> - `D:\TypePHP\Drift`（本框架的既有设计稿 v1.3 / V2 / V3 / 工效方案 / 数据缝方案）
>
> **本文相对既有 Drift 文档的两个新裁决**（既有文档在此处自相矛盾或悬空）：
> 1. **控件写法**：既有 `DESIGN.md §9.6` 判"类构造器是契约权威"，`WIDGET-ERGO-PLAN.md` 判"fluent-first 链式"——两者冲突。本文给出**唯一裁决**（§15）并解决其背后的真问题：**链式 `->title()` 与 Widget 不可变性矛盾**（`mutate+return $this` 会复活 GUI 仓 RT-2/A9 那类静默缺陷）。方案是 **Seal-on-Mount**，并用**实测微基准**否掉了 `clone-with` 与 `->build()` 两条替代路线（§15.3 / §15.6）。
> 2. **异步**：既有文档把异步列为"M4+ 待验"。本文给出**可落地的线程/消息模型**与两条后端（**C++ 线程池** / 同步三态），并明确各自的物理前提（§17）。★ **2026-10-14 探针 E 已验**：`std::thread` 可用、跨线程队列可用、**worker 线程跑分配型 PHP 亦可用（前置条件 `ts_resource(0)`）**，且**不需要 Swoole/Swow 等任何扩展**（§17.2.1）。
>
> **纪律**：每条"不能"必须能在手册 §9 找到依据；每条"能"必须能在 `examples/` 找到实证；只推理未实测的点标 **⏳ 待验**。

---

## §0 一页结论：15 个重点的裁决

| # | 重点 | 一句话裁决 |
|---|------|-----------|
| 1 | **PSR-4 代码布局** | `Drift\` → `src/`，**目录即分层**；应用 `App\<X>\` → `apps/<x>/src/`；依赖方向由 lint 强制（§4） |
| 2 | **平台边界 + 事件循环 + 线程** | 平台是**能力端口集合**（只传原语）；**循环归属平台、派发归属框架**；UI **单线程亲和**，后台结果以事件回贴（§5） |
| 3 | **坐标系 / DPI / 分数缩放** | 逻辑单位（DIP）与物理像素两套空间，**只在表面边界量化一次**；布局全程 float；文本永远在设备分辨率塑形（§6） |
| 4 | **渲染表面 + GPU 上下文** | `RenderSurface` + `GpuContext` 后端无关；`Canvas` 是**接口**（Skia / 软光栅 / 录制器三实现）；绘制录制成 **DisplayList（数据）**（§7） |
| 5 | **文本与字体栈** | 六层：FontManager → FontCollection → TextShaper → TextLayout → Paragraph → TextMetricsProvider；**测量实现唯一、双后端互校**（§8） |
| 6 | **输入 / 手势 / IME / 无障碍** | **键盘**：`AcceleratorTable`（焦点之前）→ IME 组合期让位 → 焦点链；**指针**：一次命中**三方消费**（事件路由 / 语义 / **光标形状**）；`GestureArena` 竞争消歧；IME 走 `TextInputClient`；无障碍 = **数据化 SemanticsTree**（§9） |
| 7 | **公开 API 稳定** | **三个面分开**：物理边界（composer PSR-4 前缀）/ 架构分层（命名空间 + §4.3 lint）/ **公共契约面（`@api` 标注，默认 `@internal`）**；**API 表面快照进 CI**；"要么不提供、要么必须生效"（§10） |
| 8 | **UI 与业务隔离 + 数据交互** | **两条缝**：值缝（数据→UI，`select` 切片订阅）+ 动作缝（UI→业务，**`CommandRouter` + Element 冒泡链**）；`Subject` + `SubjectBuilder` / `Bound` + `SubscribingWidget`；State 只持纯 UI 态；订阅生命周期/异常隔离/相等门控**做进设计**（§11） |
| 9 | **多页面组合** | 一页一文件；`match` 路由表；`Dock` 外壳 + 内容槽；`Offstage` 保状态切换；页面间**首选共享数据源**；**Root 只做组合，绝不写布局**（§12） |
| 10 | **工具链** | **编译器知道 ⇒ 构建期做**；drift-lint / drift-preview（无头）/ drift-devtools / drift-conformance / golden 五件套（§16） |
| 11 | **布局/渲染确定性** | 布局 = 纯函数（树, 约束, 主题, **注入的文本度量**）；渲染 = 纯函数 → DisplayList 可重放；**不读环境时钟**（§14） |
| 12 | **布局契约** | 两阶段 `layout(constraints)→size` + `paint`；无界 = `+inf`（**永不拿 0 当哨兵**）；每容器声明溢出策略；违反 ⇒ 响亮失败（§13） |
| 13 | **架构图** | 见 §3 与 `architecture.html`（**六图**：分层 / 事件循环+线程 / 三树+帧生命周期 / 双缝 / fluent 封存生命周期 / fluent 实测裁决） |
| 14 | **控件写法（链式，IDE 联想）** | **fluent-first + 挂载即封存**：`Text::make('Hello Drift')->title()`；`make()` 只吃必填项；链式方法 `set + return $this`；**不加 `->build()`**（与 `StatelessWidget::build($ctx)` 冲突，见 §15.6）；**也不加 `->seal()`**（收益≈0，§15.6.1）；封存后写入 ⇒ 响亮失败（Fatal 级）。实测：设计真实形态 **0.92 µs/节点**，clone-with 贵 **3.4×**（§15.3） |
| 15 | **协程（Swoole/Swow）** | UI 线程**不跑协程**；异步收进 `TaskHost` 端口：**线程池后端**（C++ `std::thread` + `ts_resource(0)`，**已实测可用**）/ **同步三态后端**（永远可用）。★ 协程**不需要**：要的是"别占 UI 线程"，线程直接给（§17.2.1） |

---

## §1 定位与三条设计公理

### 1.1 定位

Drift 是**用 PHP 写界面、经 TypePHP AOT 编译为原生桌面应用**的声明式 GUI 框架：

- 开发者写声明式 Widget 树（PHP 源码，UTF-8）；
- 框架在运行期维护三棵树（Widget / Element / RenderObject），自持布局与绘制；
- 构建期工具链（lint / preview / conformance）承担"早期失败"职责；
- 产物是**单 exe、无运行时**的桌面应用。

**与 `D:\TypePHP\GUI` 的关系**：GUI 是 Drift 的**实验前身**。它的 160+ 提交沉淀了"哪些决策被实测证实、哪些被证伪"。Drift 不改其架构选型（三树 / 显式 Key diff / 无自动追踪**全对**），只做三件事：

1. **PSR-4 化**（GUI 用平铺 `namespace GUI;` + classmap，分层无法静态检查）；
2. **把补丁升为设计**（RT-1..RT-4、C-1 从"后来补的"变成"第一天就在结构里的"）；
3. **平台 / 渲染 / 文本三层边界显式化**（GUI 项目里它们还是硬连线）。

### 1.2 三条公理（仲裁一切分歧）

| 公理 | 内容 | 反例（GUI 仓实测） |
|------|------|-------------------|
| **G1 编译器优先** | 这件事编译器知道吗？知道 ⇒ 构建期做 | 死命令、key 唯一性、handler 存在性都可构建期校验，GUI 却放运行期 |
| **G2 绝不静默** | 每个机制要么生效、要么**响亮失败**；静默早返回 = 黑洞 | `Container::$constraints` 声明了从未被读；主按钮禁用仍是满色；`set()` 无门控白重建 |
| **G3 确定性优先** | 布局/渲染是**纯函数**：可重放、可对账、可快照 | 布局全程位置无关；绘制录制成 DisplayList；时间必须注入不能读环境时钟 |

**G2 的落地定义：`Errors::report` 分三级**（★ 上一版 §5.3 与 §13.1 口径不一致，此处统一，全篇以此为准）：

| 级别 | 语义 | debug | release | 用于 |
|------|------|-------|---------|------|
| **Fatal** | 框架不变式被破坏，继续跑必然得到错误结果 | 写日志 + **中止** | 写日志 + **中止** | 非 UI 线程改树（§5.3）；约束违反（C6）；封存后修改 Widget（§15.2） |
| **Recoverable** | 契约被违反但可安全降级 | 写日志 + **中止** | 写日志 + 降级继续 | 订阅者在 build 期注册；同值广播（§11.3） |
| **Diagnostic** | 值得知道，不是错误 | 写日志 | 写日志 | 布局普查发现溢出、性能阈值超限 |

★ **判据**：一条检查若在 release 下被静默吞掉，它就**不配出现在 §21 反模式表的"正确做法"列**——因为那等于承诺了"响亮失败"却没兑现（正好是 A6 裸吞）。§15.2 的 `mutate()` 守卫定为 **Fatal**（release 也中止）：挂载后改 Widget 会让树状态与描述不一致，属于"继续跑必然错"。

---

## §2 TypePHP 能力边界（实证锚定 —— 一切设计的前提）

写任何设计前必须先钉死这张表，否则会像 Drift v2 那样把设计建立在**不存在的能力**上（v2 曾假设"编译期 `#[Signal]` 注解可自动重写 set hook"，作者确认不存在）。

### 2.1 能用（附实证）

| 能力 | 实证 | 对 Drift 的含义 |
|------|------|----------------|
| 闭包 / 箭头函数 / `use` 值捕获 | `examples/closure.php`、`fn.php` | `fn(int $i): Widget` 映射直接可用 |
| 命名参数 / `readonly` / backed enum | PHP 8 常规语法 | 契约层枚举化、不可变值对象 |
| **property hooks（set/get）** | `examples/property-hook.php` | 受控属性的物理基础（`Store` 基类封装） |
| 运行时反射（Attribute 读取） | `examples/attributes/test.php` | devtools / 树转储；**不在渲染热路径用**（G1） |
| 协程（Swoole/Swow，需扩展） | `examples/swoole/co.php` | ⚠️ **降级为无关**（§17.2.4）：TaskHost 要的是"别占 UI 线程"，实测用 **C++ 线程池 + `ts_resource(0)`** 即可，零扩展。协程留作"UI 线程上复用 IO 等待"的备选 |
| **C++ 线程（`std::thread` + `mutex` + `atomic`）** | **探针 E（`probes/taskhost_thread/`，2026-10-14）** | **TaskHost 后端 A 的物理基础**；worker 跑 PHP 的前置条件 = `ts_resource(0)`（§17.2.1） |
| ~~生成器 `yield`~~ | `examples/yield.php` | ❌ **不作为候选**（§16.6.5）：`yield` / `Generator` 会被 `--nano` 编译期拒绝（C++17 无栈切换 API）；为保留将来切 nano 的可选性，**Drift 源码禁用生成器**，懒序列改用显式的"分页/窗口"数据结构 |
| C++ 互操作（`php_` 前缀 + `.stub.php`） | `qt-taskboard` / `tetris-win32` / `android-native` | 平台桥接的唯一形态 |
| `php::Box`（C++ 对象交 PHP GC） | `examples/native`、`tetris-win32/BOX_USAGE_GUIDE.md` | 窗口/表面句柄跨边界传递 |
| `--no-console`（PE 子系统 = GUI） | 手册 §6、`win32-hello` | 交付无黑框 exe |
| `-m lib` 共享库 + 自动 `.stub.php` | 手册 §4 | 框架以库形态分发，应用薄壳链接 |

### 2.2 不能用 / 有坑（必须绕）

| 限制 | 出处 | Drift 对策 |
|------|------|-----------|
| `$$` / `extract()` / 内嵌 HTML / 游离代码 | 手册 §9.2 / §9.3 | 不做模板文件、不做动态拼接 |
| `Closure::call/bind/bindTo` | 手册 §9.7 | 排除 hooks 式设计；回调一律显式传参 |
| 对 property hook 属性取引用 | 手册 §9.2 | 不取引用 |
| **类型不可变**（声明后不能改类型） | 手册 §9.5 | 哨兵值取代可空；可空走显式 `?T` |
| **类型化属性参与运算 ⇒ 类型降级** | ~~手册 §9.5/§9.15（C2440）~~ **2026-10-14 探针证伪** | **不再报错**（见 §2.2.1）。真实代价是**装箱 + 动态分派**，不是编译失败——热路径读进类型化局部变量即可 |
| 多字节 UTF-8 不能用增长累加器（`$s = $s . …`）⇒ 堆损坏，崩在无关代码 | GUI 仓 AGENTS.md §4 | 文本拼接一律 `implode` |
| 全局只放 POD 指针，不放有析构对象 | GUI 仓 Contract.md §5.1 | 平台句柄是**不透明 int** |
| 子类覆写 `protected` 钩子可能被**去虚化 ⇒ 静默不跑** | GUI 仓 AGENTS.md §4 | 语义写成**字段/构造参数**，不靠虚方法 |
| 桥接签名逐字一致，不一致**静默失败**；PHP 调桥接须写前导 `\` | GUI 仓 AGENTS.md §4 | lint 校验 stub ↔ C++ 符号一致 |
| 协程入口必须 `catch (\Throwable)`，否则异常越过 Zend 回调边界 ⇒ 进程中止 | 手册 §9.14 | 写进 `TaskHost` 实现纪律 |
| 构建 20–50s（C++ 编译） | GUI 仓实测 | 迭代回路是硬成本 ⇒ 无头 preview 是投资不是消费 |

### 2.2.1 实测修正：类型化属性的真实代价（2026-10-14 探针）

手册 §9.5/§9.15 与 GUI 仓 AGENTS.md §4 都记着"类型化属性读进三元/混合运算 ⇒ 编译器推成 `php::Variant` ⇒ C2440，且报错指向生成的 `.cc`"。**本次用探针复核，这条已不成立**（探针源码 `probes/typed_ternary/probe.php`，`tpc --dry` 生成 C++ 后逐函数核对）：

```cpp
// 探针：$s->size >= 0.0 ? $s->size : $fallback      （Style::size 是 readonly float）
php::Float php_resolve_ternary(php::Object s, php::Float fallback) {
    php::Var tmp_var_0; php::Var tmp_var_1; php::Float tmp_var_2 = 0;
    tmp_var_2 = (php::toFloat([&]() -> php::Float {
        tmp_var_0 = s.attr(get_persistent_prop(...), php::AttrMode::Get);   // ← 读出来是 php::Var
        tmp_var_1 = php::toBool(((tmp_var_0) >= (0.0)));
        tmp_var_0.unset();
        if (php::toBool(tmp_var_1)) {
            return s.attr(...);                                             // ← 同一个属性又读了一次
        } else { return fallback; }
    }()));
    return tmp_var_2;
}
```

全文件 `grep -c Variant` = **0**，五个探针函数（三元 / 混合算术 / 嵌套三元 / `match` / 纯算术）**返回类型全部保真**（`php::Float` / `php::Str`）。结论分两层：

| 维度 | 结论 |
|------|------|
| **正确性** | ✅ **没问题**。不再降级、不再 C2440。旧记录已过时，不要再据此规避写法 |
| **性能特征** | ⚠️ 仍有代价：类型化属性**不编译成原生字段直读**，而是走 `s.attr(...)` 取回 `php::Var`（16 字节带标签值）+ `php::toFloat/toInt` 拆箱；且三元条件里**同一属性读两次**（若有 property hook，get 钩子跑两次） |
| **链式调用的额外代价** | ⚠️ 链式中间结果落在 `php::Var` 临时变量里 ⇒ 后续修饰调用退化为 `typephp_call_method_cached`（有缓存，但非直接调用）；写成**类型化局部变量**的等价代码能恢复静态解析（实测快 ~14%） |

**对 Drift 的含义（写进编码纪律，不是"坑清单"）**：

1. **布局/绘制热路径**：属性读**先落类型化局部变量**再参与运算 —— 恢复原生直读 + 静态分派。
2. **哨兵值（`accent: -1`）的理由变了**：它不再是"绕开编译器降级的必需手段"，而是"区分未设置 vs 合法值"的**语义**手段（`0` 是合法尺寸/位置，不能当"未设置"）。§6.2 反模式 1 仍然成立，但依据换成语义清晰性。
3. **不必为这条改设计**：探针证明写法自由；只有性能敏感处才需要局部变量纪律。

### 2.3 一句话心智模型

> **写 PHP；只在 §2.2 清单处受限。** 其余"框架特性"（响应式、异步、声明式）都是**设计选择**，不是 AOT 能力门——把两者混为一谈会导致错误归因（Drift v1/v2 各犯一次）。

---

## §3 架构总览（重点 13）

### 3.1 分层图

```
┌──────────────────────────────────────────────────────────────────────┐
│  apps/<app>/       应用层：Page 页面 · Store 业务 · Root 组合          │
│                    ★ 业务层不 import 任何 Drift 类型（除 State/Widget） │
├──────────────────────────────────────────────────────────────────────┤
│  Drift\Routing    多页面：Page · RouteTable · PageHost · Offstage      │
│  Drift\Theme      主题：Theme · ThemeData · token · ThemeScope         │
├──────────────────────────────────────────────────────────────────────┤
│  Drift\Widget     公共 API ①：Widget · StatefulWidget · Element · State│
│  Drift\Layout     公共 API ②：RenderObject · RenderBox · 布局契约      │
│  Drift\State      公共 API ③：Subject · Subscription · SubjectBuilder  │
│  Drift\Input      事件 · 手势 · 焦点 · IME · 语义(无障碍)              │
├──────────────────────────────────────────────────────────────────────┤
│  Drift\Rendering  渲染表面 · GPU 上下文 · Canvas(接口) · DisplayList   │
│  Drift\Text       字体栈 · 排版 · 度量提供者 · IME 协议                │
│  Drift\Geometry   坐标 · DPI/Scale · BoxConstraints                   │
├──────────────────────────────────────────────────────────────────────┤
│  Drift\Platform   平台端口：窗口 · 表面 · 输入源 · 定时器 · 屏幕 · 壳服务│
├──────────────────────────────────────────────────────────────────────┤
│  Drift\App        Application · EventLoop(派发) · FrameClock · TaskHost│
│  Drift\Errors     错误出口（"绝不静默"的物质载体）                     │
└──────────────────────────────────────────────────────────────────────┘
```

### 3.2 三树（数据流）

```
Widget（不可变描述）          业务/页面 build() 的产物
   │ createElement()
   ▼
Element（身份 + State 宿主 + 调和）   (type,key) diff · 脏边界
   │ createRenderObject()
   ▼
RenderObject（几何 + 绘制）     layout(BoxConstraints) → Size → paint(Canvas)
```

### 3.3 一帧的生命周期

```
平台泵 pump()（阻塞等待，不空转）
   │ 规范化：OS MSG → Drift Event
   ▼
EventLoop 入队 → 路由（命中测试 → 焦点 → GestureArena → 目标 Element）
   ▼
帧末 flush：脏 Element 重建 → relayout → paint(录制 DisplayList) → surface.present()
```

> 四张图的完整可视化（分层 / 事件循环 / 三树 / 数据双缝）见同目录 **`architecture.html`**。

---

## §4 PSR-4 代码布局（重点 1）

### 4.1 裁决

框架一个前缀 `Drift\` → `src/`；每个应用一个前缀 `App\<X>\` → `apps/<x>/src/`；工具链 `Drift\Dev\` → `tools/`（由工具入口自行注册 autoload，**不进 composer autoload**）。

```jsonc
// composer.json
{
  "autoload": {
    "psr-4": {
      "Drift\\":      "src/",
      "Drift\\Dev\\": "tools/",
      // ★ 每个应用一条（前缀 → 目录），与 §4.5 的 apps/<app>/src/ 对齐
      "App\\Hello\\": "apps/hello/src/",
      "App\\Demo\\":  "apps/demo/src/"
    }
  }
}
```

★ **这张表不只是 autoload 配置，它就是 §10.1.1 的"物理边界表"** —— 谁在 `Drift\` 前缀下 = 框架包，其余 = 业务。§16.5.3 的 `scan-autoload-drift` 拿它与 `project.yml` 的 `sources` 对账。

★ **`App\` 前缀是必需的，不是风格选择**：桥接 stub 函数（`thr_*` / `win_*` / `platform_*`）**全部声明在全局命名空间**（§16.4.1 陷阱 3）。若业务代码也在全局命名空间，业务函数就会与 stub 混在同一空间里 —— 而 §16.4.1 已经证明"命名空间内的调用点与 stub 声明的全局符号对不上会导致依赖链断掉"。⇒ **业务必须有前缀。**

### 4.2 目录即分层

| 目录 | 命名空间 | 文件 |
|------|---------|------|
| `src/App/` | `Drift\App` | `Application.php` · `EventLoop.php` · `FrameClock.php` · `TaskHost.php` · **`WindowStateStore.php`** |
| `src/Platform/` | `Drift\Platform` | `Platform.php` · `WindowHost.php` · `SurfaceProvider.php` · `InputSource.php` · `TimerHost.php` · `Screen.php` · `Clipboard.php` · `Dialog.php` · `Tray.php` |
| `src/Platform/Win32/` | `Drift\Platform\Win32` | `Win32Platform.php` · `Win32WindowHost.php` · …（首个实现） |
| `src/Geometry/` | `Drift\Geometry` | `Point` · `Size` · `Rect` · `EdgeInsets` · `Scale` · `BoxConstraints` |
| `src/Rendering/` | `Drift\Rendering` | `RenderSurface` · `GpuContext` · `Canvas` · `DisplayList` · `Paint` · `SkiaCanvas` · `RasterCanvas` · `RecordingCanvas` |
| `src/Text/` | `Drift\Text` | `FontManager` · `FontCollection` · `FontFace` · `TextShaper` · `TextLayout` · `Paragraph` · `TextMetricsProvider` · `TextStyle` · `TextInputClient` |
| `src/Layout/` | `Drift\Layout` | `RenderObject` · `RenderBox` · 各 `Render*` 族 |
| `src/Widget/` | `Drift\Widget` | `Widget` · `StatelessWidget` · `StatefulWidget` · `Element` · `State` · `BuildContext` · `Key` |
| `src/Input/` | `Drift\Input` | `PointerEvent` · `KeyEvent` · `GestureArena` · `FocusNode` · `SemanticsTree` · **`AcceleratorTable`** · **`CursorShape`** · **`ThemeChangedEvent`** |
| `src/State/` | `Drift\State` | `Subject` · `Subscription` · `SubjectBuilder` · `Cell` · **`Bound`** · **`SubscribingWidget`**（接口，裁决 D-06 / D-10） |
| `src/Commands/` | `Drift\Commands` | **`CommandRouter`** · **`CommandHandler`**（接口）· **`CommandArg`**（动作缝，裁决 D-01） |
| `src/Theme/` | `Drift\Theme` | `Theme` · `ThemeData` · `ThemeScope` · `ColorToken` · `MetricToken` |
| `src/Routing/` | `Drift\Routing` | `Page` · `RouteTable` · `PageHost` · `Offstage` |
| `src/Errors.php` | `Drift\Errors` | 错误出口 |

### 4.3 依赖规则（lint 强制，违反即报错）

| 层 | 可以依赖 | 不许依赖 |
|----|---------|---------|
| `Geometry` | `Errors` | 一切 |
| `Platform` | `Geometry` / `Errors` | 上层任何东西 |
| `Text` | `Geometry` | Widget / Rendering / Platform |
| `Rendering` | `Geometry` / `Text` / `Errors` | Widget / Platform（表面由 App 注入） |
| `Input` | `Geometry` / `Errors`；**协议例外**：`Text\TextInputClient` 与 `Layout\SemanticsConfiguration` 可被引用，但仅限类型签名 | Widget / Rendering |
| `Widget` / `Layout` / `State` | 下面全部 | Routing / Theme / Commands / App |
| `Commands` | Geometry / Errors / **State**（只做类型签名） | Widget / Layout / Rendering / Platform |
| `Theme` / `Routing` | Widget / Layout / State / Text / Geometry | App |
| `App` | 全部 | apps |
| `apps` | Drift **公共 API**（标 `@api` 的） | Drift 内部实现（`@internal`，§10.1.4；有豁免标注 ⇒ warn） |

★ 与 GUI 仓的差别：GUI 用平铺 `namespace GUI;` + classmap，分层检查**没有抓手**（lint 的类索引必须递归 glob）；PSR-4 之后"谁能依赖谁"第一次变得**可静态检查**。

### 4.4 三条执行纪律

1. **一个类一个文件，文件名 = 类名**。GUI 仓的坑"只有 trait 的文件不产出翻译单元（`cl` 收到空文件名）"在 Drift 的对策：**trait 不单独成文件**，或所在文件必须同时含一个具体类 —— 写进 lint。
2. **新文件不需要改构建配置**：`tpc` 的 `sources` 列目录且递归（GUI 仓已验证）；只有**新增一个应用**才加一行 `project.yml`。
3. **目录移动不需要改 `use`**：PSR-4 下命名空间绑定路径；lint 有一条 `namespace-mismatch` 规则兜底。

### 4.5 应用工程形态

```
apps/<app>/
  project.yml        # tpc 构建配置（defines 注入 ALL_DECL 头名）
  main.php           # 入口（bin 模式硬要求：全局唯一 main()）
  bin/
    bootstrap-win32.php   # 组合根：平台名只出现在这里
    bootstrap-macos.php
  src/
    <App>Application.php  # extends Application：只写 buildRoot() + title()
    <App>Root.php         # 外壳 + 路由：只做组合
    pages/                # 一页一文件
    store/                # 业务：普通 PHP 类，零 GUI 依赖
```

#### 4.5.1 共享构建参数：用 `include:`（★ 已实测可用）

上一版以为 `include:` 不可用（沿用 GUI 仓的过时注释），只能"N 应用 N 份参数拷贝 + parity 断言硬守"。**探针证明 `include:` 可用**（§16.4.1）⇒ 改成共享：

```
build/
  base.yml               # 共享：cxx-std / cxx-flags / ld-flags / link-paths / include-paths / defines / optimize / job
apps/<app>/
  project.yml            # 只写：include: ../../build/base.yml + 本应用特有项（name / build-dir / sources 增量）
```

**★ 三条硬约束（实测得出，必须遵守）**：

1. ❗ **被包含文件里的路径按"使用者"的视角解析**——相对**顶层 `project.yml` 所在目录**，**不是** base 自己的目录。
   ⇒ 共享 base **只有在所有使用方同目录深度时才成立**。`apps/<app>/project.yml` 全部同深度 ⇒ base 里写 `../../src`、`../../cpp-src` 对每个 app 都成立。**这是这套用法能work的唯一前提**，新增一个不同深度的应用会直接破。
2. **列表整体替换，不深合并**；标量由**当前文件覆盖**。
   ⇒ base 里给了 `sources: [...]`，应用里再写 `sources: [...]` 会**完全替换**（不是追加）；要增量就得把完整列表写进应用，或让 base 不给 `sources`。
3. ⚠️ **base 路径写错 ⇒ tpc 崩溃**（`Uncaught TypeError ... getAbsolutePath()`，见 §16.4.1），不是"路径不存在"的干净错误。
   ⇒ 引入 `include:` 的同时**必须配一个路径存在性检查**（lint 或构建前置脚本），否则第一个踩的人会去查编译器 bug。

**配套**：`ignore:` 已实测可用 ⇒ 共享 `src/` 扫描时可排除子目录（如 `src/platform/macos` 在 Windows 构建里）。

**这条纪律取代了 GUI 仓的 `yml-parity` 断言**——参数不再是两份拷贝，就不需要比对器了。但要新增"base 路径存在性"检查（约束 3）。

---

## §5 平台抽象边界 + 事件循环归属 + 线程模型（重点 2）

### 5.1 边界：平台是能力端口的集合，不是上帝接口

```php
namespace Drift\Platform;

interface Platform
{
    public function screen(): Screen;                          // DPI / 工作区 / 显示器枚举
    public function window(): WindowHost;                      // 窗口生命周期
    public function surface(int $windowHandle): ?SurfaceProvider;  // 渲染表面（不支持 ⇒ null）
    public function input(): InputSource;                      // 原始事件泵
    public function timers(): TimerHost;                       // 定时器
    public function clipboard(): ?Clipboard;                   // 壳服务：不支持 ⇒ null
    public function dialog(): ?Dialog;
    public function tray(): ?Tray;
    // ★ 裁决（§23.2）：这里【不】加 menu() —— 菜单是自绘控件，不是平台能力（见下）
    // ★ 裁决（§23.2）：dropTarget() 为 M5+ 预留位（OS 级拖放需 OLE，明确不做，见下）
}
```

**三条端口边界裁决（评审新增）**：

1. ★ **菜单不进 `Platform`**（裁决 §23.2-2）：原生菜单栏（`HMENU` + `SetMenu`）与自绘界面**风格割裂**（GUI 仓 manifest 教训：原生对话框退回 Win95 外观时与 Skia 界面并排非常突兀）。⇒ **菜单 = 普通 Widget**（`MenuBar` / `Menu` / `MenuItem` 全走 §15 链式写法，放在 `Dock::top` 里），**零平台端口成本**。这也与 §18 的纪律一致（Aria 的"避开"列正是"把渲染外包给原生工具包"）。
   - **原生菜单栏：明确不做**。理由：风格割裂 + 无法与自绘主题统一 + 违反 §18。
2. ★ **鼠标指针形状进 `WindowHost`**（不是新端口）：它是**窗口级**状态（`SetCursor` 作用于 HWND），且与 `setCapture()` 同族。
3. ★ **`ThemeChanged` 复用现有事件通道**（不新增端口方法）：`screen()` 已有；Win32 侧把 `WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`）规范化成 `ThemeChanged` 事件即可（与 `DpiChanged` 同路，见 §9.2）。

**四条边界纪律**（GUI 仓 M2 教训直接继承）：

1. **只传原语**：端口方法签名只出现 `int/float/string/bool/array` 与 Drift 值对象；OS 句柄（HWND / NSWindow…）是**不透明 int**，跨接口后不得解引用（GUI 仓 Contract.md §5.1：全局只放 POD 指针）。
   - ★ **本条的自我遵守记录（第二轮评审修正）**：上一版 `surface(object $window)` 写的是 `object` —— 那是**最宽的类型**，等于把本条关掉，且 lint 无从检查。已按本条口径改为 `surface(int $windowHandle)`。**句柄的解引用只允许发生在 `Drift\Platform\<Os>\` 内部**（`Win32Platform::surface()` 里 `reinterpret_cast<HWND>($windowHandle)`），这正是"不透明 int"的含义：跨过接口就只是一串数字。
2. **端口拆小**：剪贴板 / 托盘 / 对话框**不进主接口**——跨平台语义差异大，强迫每个实现全量方法会让大量方法沦为抛异常的桩。独立端口让"不支持"在**类型层面**表达清楚（返回 `null`）。
3. **"生"与"活"分开**：`createWindow()` 管创建，`WindowHost` 管运行期控制（标题 / 位置 / IME 插入符 / 指针捕获 / 定时器）。
4. **平台实现放 `Drift\Platform\<Os>\`**；`Application` 主循环一行不改。

**应用与平台的边界**：平台类名只出现在**每平台引导文件**（组合根）。共享代码（页面 / 控件 / store / Application 子类）**零平台引用**；跨平台 = 同一份应用源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。

```php
// apps/hello/bin/bootstrap-win32.php —— 组合根，2 行
$app = new HelloApp(new \Drift\Platform\Win32\Win32Platform());
$app->run();
```

### 5.2 事件循环归属：循环属于平台，派发属于框架

```
OS 消息泵（WindowHost::pump，阻塞等待，不空转）
   │  规范化：Win32 MSG → Drift Event（Pointer / Key / Resize / Dpi / Close / Timer / Shell…）
   ▼
EventLoop（Drift\App）—— 拥有派发，不拥有等待
   ├─ 入队：派发中产生的事件（setState、动画起停、回贴的后台结果）进 next-tick 队列
   ├─ 路由：命中测试 → 焦点 → GestureArena → 目标 Element
   └─ 帧末：flush 脏 Element → layout → paint → surface.present()
```

**三条裁决**：

1. **框架绝不自建循环**：`pump()` 由平台阻塞等待下一条消息（事件驱动，不空转）；帧时钟 `FrameClock` 也由平台驱动（VSync / `WM_TIMER`），Drift 只消费帧时间戳。
   - 实证：`tetris-win32/main.php` 的 `while { win_peek_message(); handleKey(); render(); usleep(16000); }` —— **手动泵 + 帧睡眠**就是现实形态。
2. **时间注入**：动画相位 = f(帧时间戳)，**不读环境时钟**——这是 G3 确定性的物质基础（golden 测试可以喂固定帧序列）。
3. **重入安全**：事件处理中途的 `setState()` 只标脏；统一重建发生在帧末 `flush` 阶段；派发中产生的回调入队下一 tick ⇒ "build 期 setState 触发重入"这类问题**结构上不存在**（回调里连点十次只重建一次）。

### 5.3 线程模型：UI 单线程亲和 + 结果回贴

```
UI 线程（唯一）：  全部树操作 / 布局 / 绘制 / 事件派发 / setState
后台（TaskHost）： 文件 IO · 网络 · 计算 —— ★ 只碰业务数据，不碰任何 Widget/Element
回贴（marshal）：  后台完成 ⇒ postEvent 入 UI 线程队列 ⇒ 在 UI 线程改 Store ⇒ 通知 → 重建
```

**裁决**：

1. **框架核心无锁**。单线程亲和是 Win32 消息模型的自然延伸，也是 AOT 现实下的安全默认。实证：GUI 仓全仓 `grep` 零多线程 / 零 Fiber / 零协程——这不是能力缺失，是**刻意选择**。
2. **`TaskHost` 是平台能力**（C++ 线程池）。✅ **已实测（探针 E，§17.2.1）**：`std::thread` 可建（31–60µs 返回，不阻塞）、`mutex`+`deque` 跨线程队列可用、`std::atomic` 可用；**worker 线程跑分配型 PHP 亦可用，前置条件 = 入口先 `ts_resource(0)`**，4 线程并发已过。**M1 仍先做单线程 + 同步 IO 占位**（后端 B），线程池作为独立端口实现可后补。**纪律：先验后用**——新构造必须先写探针跑通再进 `src/`（§7 验收第 3 条）。
3. **断言点有两个（★ 裁决 `D-08` 补齐第二个）**：
   - **① `Subject::notifyChanged()` 入口** —— "**通知必须在 UI 线程发出**"；
   - **② `State::setState()` / 树变更** —— 非 UI 线程调用 ⇒ 同上。
   两处都是 `Errors::report`（**Fatal 级**，release 也中止，§1.2）（G2：线程错用是静默数据竞争的源头，必须响）。

   > **★ 为什么必须有第 ① 处**（上一版只有 ②）：
   > 线程池（§17.2.2）落地后，**最可能踩的路径不是"直接改树"，而是"在 worker 里改 Store 然后 notify"**：
   > ```
   > worker 线程：$store->setRows($data); $store->notifyChanged();
   >    ⇒ 订阅者回调在 worker 线程上执行 ⇒ setState ⇒ 树变更 ⇒ 数据竞争
   > ```
   > `notifyChanged()` **本身不是**"树变更"，所以按上一版口径**不触发断言**。而它有两个后果：
   > - **订阅者是控件** ⇒ 最终会在 `setState` 处响，但**错误在更深的栈上被报出来**（栈里看不到"哪个 notify 引起的"）；
   > - **订阅者不是控件**（纯业务订阅者，比如日志 / 缓存失效） ⇒ **它不会 `setState`** ⇒ **worker 线程上跑业务回调，无任何断言**，而业务回调可能改别的 Store ⇒ **数据竞争完全静默**。这是上一版口径的**真漏口**。
   >
   > **成本**：一次整数比较（缓存的 UI 线程 id vs 当前线程 id）⇒ **可忽略**。
   > **与 §11.1"业务层零 GUI 依赖"不冲突**：`Subject` 依赖的是 `Drift\State` **内部**的 `ThreadAffinity::assertUi()` 静态方法（不是 `Platform`）⇒ 业务层仍然不知道 GUI 是什么。
   > **落地时机**：**与 M4 的线程池同时落地**（M1–M3 单线程，该断言永远成立 ⇒ 是纯开销）。

### 5.4 窗口状态持久化（★ 评审新增）

**裁决：放 `Drift\App`，不进 `Platform`。**

理由：它**不需要任何平台能力**——`WindowHost` 已有位置/尺寸/最大化状态的读写（没有就补 getter），而"存到哪、什么时候存"是**应用生命周期**的事，不是平台的事。放进 `Platform` 会破坏 §5.1 第 2 条"端口只放平台能力"的纪律。

```php
namespace Drift\App;

final class WindowStateStore          // 启动时读回，退出时写回
{
    public function __construct(private string $path) {}   // 用户目录下的 kv 文件
    public function restore(WindowHost $w): void;          // 无记录 ⇒ 用默认值，不报错
    public function persist(WindowHost $w): void;          // 退出时一次
}
```

**三条纪律**：

1. **只在启动 / 退出各一次同步 IO**（不在帧内）⇒ **不需要 `TaskHost`**。
2. **恢复失败必须静默降级到默认值**，不报错——这是少数**允许静默**的场合（显示器拔了、分辨率变了、配置文件被删，都不该让应用起不来）。★ 这是 G2 的**显式例外**，写进文档以免被 lint 误判。
3. **存储后端可换**（文件 / 注册表 / 平台偏好），但**不进 `Platform` 端口**——因为换它不影响"谁能依赖谁"的分层，只影响一个 `Drift\App` 内部实现。

---

## §6 坐标系 / DPI / 分数缩放模型（重点 3）

### 6.1 两套坐标系，一条量化边界

```
逻辑单位（DIP, density-independent pixel）
  布局 · 控件 · 主题 · 约束 —— 只说逻辑，全程 float
        │  唯一的量化点：表面边界
        ▼
物理像素（device pixel）
  RenderSurface · Canvas · Platform —— 才说物理
```

| 件 | 内容 |
|----|------|
| `Scale` 值对象 | `dpr = dpi / 96.0`（float）；1.0 / 1.25 / 1.5 / 2.0 / 2.5 都合法 |
| 量化规则 | `physical = round(logical * dpr)`；位置 round、尺寸 round、**矩形不错开半像素**；量化只有这一个函数，全框架复用 |
| 文本 | 排版在逻辑空间，**字形在设备分辨率塑形与光栅**（永远不按逻辑分辨率放大——模糊的根源） |
| `TextScale` | 用户字号偏好，**只乘文本度量**，不动布局几何（与 dpr 正交的两轴） |
| Per-monitor DPI | `Screen::scale` 变化 ⇒ `DpiChanged` 事件 ⇒ **触发 relayout，不重建窗口**；窗口跨屏拖动 = scale 变更 = 布局事件 |
| 分数缩放 | 布局全程 float，**不在中间步骤取整**；取整只发生在 ① 表面量化 ② 文本行高（按 FontMetrics 归一后取整） |

### 6.2 反模式（三条都有实测出处，写进 lint / 文档防复发）

1. ❌ **拿 0 当"无界/未知"哨兵**——GUI 仓三次踩坑（`0..maxH` 松上界被当"可用高"吃满；合法值当哨兵 ⇒ 安静地错）。**无界 = `+inf`（`INF`）**。
2. ❌ **构建期魔法宽度阈值**（"宽度 < 891 就折叠"）——阈值必然腐烂（改一个标签就破）。★ **正解不是"更好的阈值"，是让阈值问对对象**：判据从"屏幕宽"升级为"**本容器约束宽**"（CSS 容器查询思路）+ 布局期行为 ⇒ 阈值腐烂在结构上被消灭。
3. ❌ **在控件里直接读 `dpi` 做分支**——缩放是**表面属性**，控件看到的是逻辑约束；`$ctx->width` 是逻辑宽，物理换算只在绘制边界发生。

---

## §7 渲染表面抽象 + GPU 上下文（重点 4）

### 7.1 两个接口

```php
namespace Drift\Rendering;

interface RenderSurface            // 每窗口一个；抽象 swapchain / present
{
    public function resize(int $w, int $h, float $dpr): void;
    public function beginFrame(float $timestampMs): Canvas;   // 返回帧画布
    public function endFrame(): void;                          // present
}

interface GpuContext               // 设备/队列所有权；后端无关
{
    public function uploadBitmap(string $rgba, int $w, int $h): int;  // ⇒ 纹理句柄
    public function release(int $handle): void;                       // 释放 GPU 侧资源
}
```

> ★ **裁决（§23.3-3）：`createParagraph` 已从 `GpuContext` 摘除。**
> 上一版在这里放了 `createParagraph(...): int`（"段落槽"），与 §8.1 的 `Paragraph`（不可变排版结果 + 缓存键）**构成两套表示、两个所有者**。裁决：
> - **`Paragraph` 归 `Drift\Text`**，由 `TextLayout::layout(...): Paragraph` 生产，是**值对象**（不是句柄）。
> - **缓存归 `TextMetricsProvider` 实现内部**（§8.1 的注入点），键就是 `Paragraph` 的缓存键 `(text, style, maxWidth, scale)`。
> - Skia 后端若需要缓存 `skia::textlayout::Paragraph` 对象，那是 `SkiaCanvas` / `SkiaTextMetricsProvider` 的**私有实现细节**，**不上公共接口**。
> - 理由：`GpuContext` 的职责是"设备/队列所有权"（§7.1 原文），`uploadBitmap` 是 GPU 资源（合理）；**排版不是 GPU 的事**。放进去等于让文本栈的所有权被渲染层绑架。

### 7.2 四个裁决

1. **`Canvas` 从 `final class` 改为接口**。GUI 仓原话："绘制层硬依赖（`Canvas` 是 `final class`，直连 `\gfx_*`）⇒ 换绘制后端要先抽象"。Drift 第一天就是接口，**三个实现**：
   - **`RecordingCanvas`** —— **生产路径的入口**：把绘制命令录成 `DisplayList`（见第 2 条裁决）；
   - `SkiaCanvas` —— **`DisplayList` 的回放器**（转发 Skia 桥接；GUI 仓实测 `SkCanvas::MakeRasterDirectN32` 画到离屏 bitmap，`SetDIBitsToDevice` 贴到 HWND）；
   - `RasterCanvas` —— **软光栅**：无头预览、CI、测试（无 OS 依赖 ⇒ §14 确定性的物质基础）。
   > ★ **上一版把"生产路径"标在 `SkiaCanvas` 上，与第 2 条裁决矛盾**（若 `paint()` 的产物**是**命令序列，那生产路径的入口只能是录制器）。已按第 2 条改正（裁决 `D-11`）。
2. **DisplayList = 绘制命令即数据**。`paint()` 的产物不是"立即画到屏上"，而是录制成命令序列，由 surface 执行。收益：可重放（golden）、可快照（devtools 传输）、可跨进程（将来的 Inspector）、为多线程光栅铺路。★ 这是 G3 在渲染侧的落点。
   > **★ 裁决（`D-11`）：`DisplayList` 总是录制，生产路径也走它。**
   > **理由**（三条）：
   > ① **§14.1 第 2 条已经承诺**"渲染是纯函数 ⇒ **录制成 `DisplayList`** ⇒ 可重放、可 golden 比对" —— 只在 dev 录制会让这条承诺**在生产上不成立**；
   > ② **§20 第 1 条"量具双向自检"的核心就是"量具与生产走同一条路"** —— 只在 dev 录制 ⇒ golden 通过 ≠ 生产正确；
   > ③ **成本可控**：§18 已记 GUI 仓的教训是"**每帧全画**"（不取 Flutter 的 `shouldRepaint`）⇒ 本来就没有"跳过绘制"的优化可损失；`DisplayList` 只是把"直接画"变成"先记录再画"，多出的成本是**内存 + 一次函数调用转发**。
   > **★ 配套契约（必须，否则反而变慢）**：**缓冲复用 —— 每帧零分配**（复用同一块 `DisplayList` 缓冲，不 `new`）。一条命令约 16–32 字节，一帧几千条 ⇒ 几十 KB/帧。
   > **★ 与 §7.3 的分期不冲突**：M1 = "录制 + 立即回放"；M5 的脏矩形 = "按需重放"（同一份 `DisplayList`，只是不再全量重放）。
3. **坐标约定不变**（GUI 仓验证过）：父级在绘制子盒前已 translate 到盒原点，`paint` 内部以 (0,0) 为盒左上角作画；`save/restore` 语义、`pushClip/popClip` 配对纪律原样继承。
4. **表面与应用解耦**：`SurfaceProvider` 由平台按窗口给出；`Application` 持 surface，`RenderObject` 树不认识 surface——测试里手搓 `RasterCanvas` 就能跑完整渲染。

### 7.3 GPU 上下文：诚实的分期

| 阶段 | 后端 | 依据 |
|------|------|------|
| **M1** | Skia **CPU 光栅** + 位块传送（BitBlt / SetDIBits） | GUI 仓实测路径，已验证可跑通并出图 |
| **M4+** | 可选 D3D11 / OpenGL / Vulkan 后端 | ⏳ 待验。GUI 仓 `project.yml` 虽链接了 `d3d12.lib/opengl32.lib`，但**代码未用 `GrDirectContext`**——GPU 路径是**未开采的性能上限**，不是已证伪的方向 |

> **诚实结论**：CPU 光栅是当前**性能天花板**（GUI 仓实测结论）。`GpuContext` 接口第一天就存在，但 M1 的实现可以是"直通 CPU"的空实现——**接口先行、后端后补**，避免重演 GUI 仓"硬连线 ⇒ 换后端要先抽象"。

---

## §8 文本与字体栈（重点 5 —— 跨平台最大坑）

### 8.1 六层结构，每层一个可替换接口

```
FontManager          face 注册：内存字节 / 文件 / 系统族枚举
   ↓
FontCollection       逻辑家族（sans/serif/mono + 别名）→ 回退链（拉丁 → CJK → emoji）
   ↓
TextShaper           run（文本+样式+家族）→ glyph 序列（advance / offset / cluster）
   ↓
TextLayout           断行（greedy + 测量回调）/ 对齐 / 行高 / ellipsize
   ↓
Paragraph            不可变排版结果 + 缓存键 (text, style, maxWidth, scale)
   ↓
TextMetricsProvider  ★ 布局确定性注入点：Widget 层问度量，不问平台
```

### 8.2 七个坑，逐个给答案

| # | 坑 | Drift 的答案 |
|---|----|-------------|
| 1 | 家族名跨平台解析不一致（"Microsoft YaHei" vs "PingFang SC"） | **逻辑家族**：`FontFamily::sans()/serif()/mono()` + 别名；平台字体配置负责落地名 |
| 2 | DPI 缩放下文本模糊 | 设备分辨率塑形（§6） |
| 3 | 行度量不一致（ascent/descent/lineGap/unitsPerEm 各平台各 face 不同） | `FontMetrics` 归一化值对象；行高 = `lineGap + (ascent+descent) × lineHeight`，**策略显式**，不隐式信任平台默认 |
| 4 | hinting / 亚像素定位 | 字形原点量化到设备像素（可配 `TextRendering::hinted()`）；亚像素 AA 开合是显式策略 |
| 5 | 彩色 emoji / CBDT | face 能力声明（`FontFace::hasColorGlyphs()`）；回退链按能力选 face |
| 6 | 测量不确定 ⇒ 布局不确定 | **一套测量实现，两个后端**（SkParagraph 真测量 + 确定性参考实现），conformance 互校；`TextMetricsProvider` 注入，测试可喂固定度量 |
| 7 | Unicode：光标移动按 grapheme cluster；bidi；IME 组合区间 | `Utf8` 工具 + 光标唯一入口 `indexAt(x,y)`；IME 组合区间由 `TextInputClient` 协议承载 |

### 8.3 两个实战教训（GUI 仓血账）

1. **字体初始化空指针 ⇒ 启动崩溃，且会被误判为"无控制台子系统的问题"**。GUI 仓早期 `--no-console` 启动报 `0xC0000005`，根源是 `ensureFont()` 里字体句柄为空。手册 §6 原话："**无控制台程序的启动崩溃常掩盖真正的空指针/初始化错误，先排查自身初始化路径**"。
   → Drift 对策：`FontManager` 初始化**必须**在任何绘制前完成并断言非空（G2），失败走 `Errors::report` 而非崩。
2. **ICU 是运行期硬依赖**。GUI 仓用 `SkUnicodes::ICU::Make()` 做断行/字素/双向，**运行期硬依赖 `icudtl.dat`（778 KB），缺失即 `check(fUnicode)` abort**。
   → Drift 对策（三条，按优先级）：① 把 ICU 数据经 `embedded-files` 或 PE 资源**打包进 exe**（手册 §10），交付仍是单文件；② 若体积不可接受，退"内置简体中文断行 + 拉丁"的最小实现（放弃 bidi）；③ 中文断行规则（禁则）单独实现，不依赖 ICU 的完整 UAX #14。

### 8.4 IME 协议（`TextInputClient`）

编辑器实现协议（`setEditingState` / 组合区间 / **插入符矩形上报** / 撤销回写），平台 IME 桥接：

- Win32：IMM32 组字 + **系统插入符登记**。GUI 仓实测：第三方输入法（搜狗 / QQ 拼音）靠 `GetGUIThreadInfo` 取插入符矩形定位候选窗，**自绘窗口不登记 ⇒ 候选框永不出现**。
- IME 定位一律用**光标行**几何；焦点判据用 `focusedElementId`（不用可能过期的副本）。

---

## §9 输入 / 手势 / IME / 无障碍模型（重点 6）

### 9.1 路由链（★ 评审修订：键盘多一环，命中测试改为一次命中多方消费）

```
Platform 原始事件（PointerEvent / KeyEvent / ImeEvent / ScrollEvent / ThemeChanged）
   │
   ├─ KeyEvent ──▶ ① AcceleratorTable（应用级快捷键，★ 在焦点之前）
   │                  │ 未命中
   │                  ▼
   │              ② IME 组合中？⇒ 交给 TextInputClient（组合期不吃快捷键）
   │                  │ 否
   │                  ▼
   │              ③ 焦点链（FocusNode → 目标 Element）
   │
   └─ PointerEvent ─▶ 命中测试（RenderObject 树，从前到后）
                        │ 一次命中，多方消费：
                        ├─ 事件路由 → GestureArena → 目标 Element
                        ├─ 语义（SemanticsTree 高亮 / 朗读目标）
                        └─ 光标形状（WindowHost::setCursor）★ 新增
```

| 件 | 裁决 |
|----|------|
| **命中测试** | 从根 RenderObject 深度优先，`hitTest(x,y)` 返回最深的命中盒；**禁用盒仍参与并吞掉事件**（absorb——禁用 ≠ 穿透，点禁用按钮不应落到背后的控件上）；显式穿透只有 `IgnorePointer`；`Offstage` 直接返回 null。★ **一次命中，三方消费**：事件路由 / 语义 / 光标形状（不重复走树） |
| **手势** | `GestureArena`：多个识别器可同时竞争（拖动中的 tap 让位），胜出者独占直到手势结束；识别器是**无状态的判定函数 + arena 成员资格**，不是每控件一份定时器 |
| **指针捕获** | 按下即捕获（Win32 只把鼠标消息发给光标下的窗口 ⇒ 不捕获则按下态永不释放、拖拽永不结束）；`WindowHost::setCapture()` |
| **光标形状** ★新增 | `RenderObject` 声明 `cursor`（`CursorShape` backed enum：`Default`/`IBeam`/`PointingHand`/`SizeAll`/`SizeNS`/`SizeWE`/`Wait`/`NotAllowed`）；`EventLoop` 在 `WM_SETCURSOR` 时问**命中链**，取最深声明者 ⇒ `WindowHost::setCursor()`。**不给控件开自绘光标的口子**（自绘光标要自己画、自己跟帧，得不偿失） |
| **快捷键 / 加速键** ★新增 | `Drift\Input\AcceleratorTable`，挂在 `Application` 上（**应用级数据，不是平台能力** ⇒ 零新端口）。`EventLoop` 在派发 `KeyEvent` **之前**查表（顺序见 §9.1）。冲突处理两条：① **IME 组合期不吃快捷键**（否则中文输入会误触）；② **编辑命令优先**：`TextInputClient` 可声明独占键集（`Ctrl+A/C/V/X/Z`），命中时让位给输入框 |
| **菜单** ★新增 | **自绘控件**（`MenuBar` / `Menu` / `MenuItem` / `ContextMenu`），零平台端口。原生菜单栏**明确不做**（理由见 §5.1）。上下文菜单走右键手势（GestureArena 已支持） |
| **焦点** | `FocusNode`；焦点是**渲染态**（不放业务值） |
| **焦点遍历顺序** ★新增 | 默认 = **文档序（深度优先）**，与 Flutter / Qt 一致。容器可 `->skipFocus()` 整体跳过（如 AppBar 工具按钮组，**显式**，不隐式）。需定制时用 `->focusOrder(int)`，同一父容器内**稳定排序**。★ **lint 判据**：同一容器内混用"有 `focusOrder`"与"无 `focusOrder`"的子节点 ⇒ 报错（否则隐式相对顺序有歧义，属 G2） |
| **IME** | `TextInputClient` 协议（§8.4）；caret / anchor / 撤销栈归属 State 的渲染态域（GUI 仓 V1 结案结论：外部不可能知道"用户点到哪、按了几个左箭头"） |
| **无障碍** | `SemanticsTree` 从 RenderObject 的 `SemanticsConfiguration` 生成：角色 / 标签 / 动作 / 取值 / 状态；**树是数据** ⇒ 可序列化 ⇒ 探针可断言。Windows UIA 桥接列为 M5+ |

### 9.2 系统环境变更（★ 评审新增）

`DpiChanged` 与 `ThemeChanged` **走同一条通道**，因为它们的后果相同：**环境变了 ⇒ 全树重建**（不是 relayout——主题变了字号/颜色都会变）。

| 事件 | 平台来源 | 框架响应 |
|------|---------|---------|
| `DpiChanged` | `WM_DPICHANGED` | 更新 `Screen::scale` ⇒ 全树重建（§6） |
| **`ThemeChanged`** | `WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`） | 更新 `Screen::systemTheme()`（`Light`/`Dark`）⇒ 全树重建 |
| **高对比度** | `SystemParametersInfo(SPI_GETHIGHCONTRAST)` | 并入 `ThemeData`（作为 token 的覆盖层） |

> 上一版只有 `DpiChanged`、没有 `ThemeChanged` ⇒ **跟不了系统深色模式**。补这一条不需要新端口，只是把已有的 `screen()` 通道多规范化一个事件。

> **P3 陷阱**：语义**不能**靠覆写 `protected` 钩子实现（子类覆写可能被去虚化 ⇒ **静默不跑**）。语义写成**字段/构造参数**——GUI 仓的 `SemanticsBuilder` 已是这个形态。

---

## §10 公开 API 稳定（重点 7）

> **★ 本章在 2026-10-14 第二轮评审后重写（裁决 `D-02`）。**
> 原版是"**五个公共命名空间** + lint 检查 `apps/` 不得引用清单外类型"，与**组合根直接冲突** ——
> 方案自己给的 `bootstrap-win32.php` 第一行 `new \Drift\Platform\Win32\Win32Platform()` 就在清单外，
> `Drift\App\Application`、`Drift\Routing\RouteTable` 同样在外 ⇒ **按方案自己的 lint，样板代码违规**。
> 根因不是"清单少写几个"，而是：**用"命名空间"表达了"可见性"，而这两个面根本不重合。**

### 10.1 三个面，三种表达手段（★ 重写）

一个 SDK 有**三个不同的"面"**。它们**互不重合**，而且**各自只有一种可行的表达手段** —— 混用必然出错：

| 面 | 定义 | 唯一可行的表达手段 | 能强制吗 |
|----|------|-----------------|---------|
| **① 物理边界** | 哪些文件属于框架包 | **composer PSR-4 前缀**（`Drift\` → `src/`） | ✅ 工具强制（autoload / 文件扫描） |
| **② 架构分层** | 包内**谁能依赖谁** | **命名空间 + §4.3 依赖规则 lint** | ✅ lint 强制 |
| **③ 公共契约面** | **承诺不破**的签名 | **`@api` 标注** + `api-surface.json` 快照 | ✅ lint 强制 |

**上一版把 ①（命名空间）当成 ③（契约面）来用。** 这在 PHP 里必然失败，三条硬理由：

1. **组合根必须引用平台实现** —— 这是 §5.1"平台类名只出现在**每平台引导文件**"的**必然结果**。⇒ `Drift\Platform\Win32` **必须**对应用可见。不是"清单漏了"，是**清单这条路本身走不通**。
2. **PHP 没有"包内私有"** —— `@internal` 是**文档约定**，不是语言强制（`internal` 关键字是 **namespace 级**可见性，不是包级）。用户技术上能 `use` 包内**任何** public 类型 ⇒ **"命名空间 = 可见性"在 PHP 里根本不成立**。
3. **方案自己已经为 `seal()` 开了豁免** —— §10.1 原文："`@internal` 判定以**标注**为准，不以命名空间为准"。那是同一个问题的**第一次**出现；组合根违规是**第二次**。⇒ 同一个模型错两次，就该换模型，而不是再补一次清单。

★ **另有一个前提，取决于分发形态**：
- **源码同编**（`bin`，`sources` 含框架 `src/`）⇒ 框架与业务在**同一次 `tpc` 调用**里编译成单个 exe ⇒ `@internal` 类型**会被编译进同一个二进制**，**不会**有链接期/运行期错误 ⇒ **lint 是唯一防线**；
- **`-m lib` 共享库**（见 §10.1.2）⇒ `#[NoExport]` 让内部符号**根本不进 ABI** ⇒ 业务**想引也引不到**（链接期就找不到符号）⇒ **编译器强制，比 lint 更强**。

⇒ 所以"物理隔离用哪个机制"由**分发形态**决定（§10.1.2 的表）。

#### 10.1.1 ① 物理边界：前缀划分（composer PSR-4 是它的机器可读形式）

| 前缀 | 目录 | 归属 | 升级时谁负责 |
|------|------|------|------------|
| `Drift\` | `src/` | **框架包** | 框架（按 §10.2 第 1 条的语义化版本） |
| `Drift\Dev\` | `tools/` | 工具链（**不进 composer autoload**，工具入口自行注册） | 框架 |
| `App\<X>\` | `apps/<x>/src/` | **业务代码** | 应用作者 |

★ **判据**：`composer.json` 的 `autoload.psr-4` **就是**这张表的机器可读形式 ⇒ **不需要任何新机制**（见 §4.1）。

#### 10.1.2 ★ 分发形态三选一（`-m lib` 实测调研 + **GUI 仓实证**，2026-10-14）

`tpc` 有 **`-m lib`** 模式（手册 §4；权威细节见 `typephp/docs/zh-cn/MIXED_CPP_PHP.md` §3）。**它改变了"物理隔离"和"SDK 形态"两件事**：

| 分发形态 | 产物 | 物理隔离靠什么 | 应用侧 `project.yml` 要什么 |
|---------|------|--------------|--------------------------|
| **A. 源码同编**（`-m bin`） | 单 exe | ❌ 无 ⇒ **只能靠 lint** | `sources` 含框架 `src/` + `cpp-src/` + **Skia include/link 路径 + 全部 link-libs** |
| **B. `-m lib` 共享库** | `<target>.stub.php` + `.dll` + 导入库 `.lib`（Linux `.so`） | ✅ **`#[NoExport]` + 编译器生成的 stub**（链接期强制）—— **★ 已实测跑通** | `sources` 只加 **`<target>.stub.php`**（`linkLibs` 由 tpc **自动追加**）⇒ **不需要 Skia 头/库** |
| **C. `-m ext` 扩展** | PHP `.dll`/`.so`（加载进 PHP SAPI） | ✅ 导出面由扩展注册决定 | ❌ **明确不做**（GUI 要自带运行时；且 §17.2 已裁定"不需要任何扩展"） |

> ★★ **注意 C 与 B 在符号解析层是同一件事**：上游把"**共享运行时的库**"（`libraryEmbedsPhp = false`）
> 在符号解析上**按 `BUILD_MODE_EXT` 处理**（`NativeCommandOptionsTrait.php:212-217`，注释原文
> "A shared-runtime library resolves Zend symbols from its consumer, just like a PHP extension"）。
> ⇒ 形态 B 与形态 C 的差别只在**入口形态**（B 是 exe 链接它；C 是被 PHP SAPI 加载），
> 不在"Zend 符号从哪来"。这也解释了为什么 B 必须与宿主**同 PHP 版本**。

##### ★★★ 实证：这不是纸面分析 —— **GUI 仓（Drift 的前身）已把 `-m lib` 端到端跑通过**

Drift2 的 §10 不是从零推演。**GUI 仓在 2026-10-10 ~ 10-11 做过完整的 `-m lib` spike**，
并留下了**六条缺陷的完整定位（含 tpc 源码行号）+ 一份上游缺陷报告**。这是 Drift2 能"直接写结论"的底气。

**spike 的形态**（`GUI/sdk/project.yml` + `GUI/apps/spike/project.yml`，**框架源码一字未改的对照实验**）：

| 角色 | 配置 | 关键点 |
|------|------|--------|
| **库侧** | `sdk/project.yml`：`mode: lib` · `sources: [../src, ../diagnostics, ../cpp-src]` · 完整 Skia `link-libs` | 从仓库根跑 `tpc sdk/project.yml -O2 -m lib` |
| **消费侧** | `apps/spike/project.yml`：`mode: bin` · `sources: [main.php, ../demo/src, ../../typephp_gui.stub.php]` | **不含** `src` / `cpp-src`；`ld-flags` 从 **35 行缩到 1 行**（`/STACK`） |

**★ 消费侧的收益已实测兑现**：`ld-flags` 里 Skia 的 21 个库**全部消失**（已在 `.dll` 里链好）。
⇒ §10.1.2 前文"应用侧不需要 Skia 工具链"**不是推论，是实测**。

**★ 四条判据全部拿到证据**（`apps/spike/project.yml` 文件头原文）：
① 子类化（`CounterApplication extends \GUI\Application`，含 protected 成员跨边界）·
② 回调方向（lib 的 `run()` 调到应用覆写的钩子）·
③ **闭包跨边界**（lib 的 `Widget::when()` / `CustomPaint(painterClosure:)` 同步调用应用传入的闭包）·
④ 桥接链接（exe 建真窗 + 跑一帧 layout/paint）。
**最终结果：spike `372 ok / 1 fail`，与 `-m bin` demo 基线逐条一致**（唯一 fail = 真机字体差异，与 lib 无关）。

**六条缺陷的现在状态**（2026-10-14 复核本地 `typephp` master = **0.9.5**）：

| # | 症状 | GUI 仓当时 | **现在** |
|---|------|-----------|---------|
| D1 | lib 模式给每个 TU 强制 `/FI<php_<target>_all_decl.h>` ⇒ 聚合头把 `php::operator*` 模板提到 Skia 头之前 ⇒ 位运算重载歧义（C2666/C2661/C2056） | ✅ 本仓绕过（`0562049`：桥接头去掉 `using namespace php`） | ❌ **根因未修**（`NativeCommandOptionsTrait.php:99-104` 仍无条件注入）⇒ **Drift 必须继承这条纪律** |
| D2 | stub 的参数默认值把 `self::CONST` 又拼一次命名空间 ⇒ `GUI\GUI\PopupPanel::DefaultBlur` | ✅ 本仓绕过（`bcbd635`，4 处改全限定名） | ✅ **已修**（`gen_stub.php` 改用 `resolveClassConstFetchClassName`，3 处）⇒ **绕法可回退** |
| D3 | stub 推出的库名**裸着**交给链接器，MSVC `link.exe` 按扩展名分派 ⇒ 裸名当 `<name>.obj` 找 ⇒ `LNK1181` | 旁路：`.lib` 复制成 `.obj` | ✅ **已修**（`Platform/Windows.php:80-84` 补 `.lib`，注释原文 "link.exe treats an extensionless positional input as an .obj"） |
| D4 | `*_arg_N_default_value()` **定义处**漏 `getFunctionDeclarationPrefix()` ⇒ 670 个 `LNK2019` | 旁路：`/EXPORT:` 响应文件（678 条；`.def` 全部 `LNK4017`，一条不生效） | ⏳ **待复验**（定义处仍无前缀；但上游把 shared-runtime 库按 EXT 处理后可能已连带解决） |
| **D5** | 消费方从不 `module_init` 导入库 ⇒ **库侧 `php_request_cache` 恒为 `nullptr` ⇒ 首次方法派发段错误（exit 139）** | ✅ 本仓验证修法（`lib-mode-fix` → `6c466d91`） | ✅ **已修**（上游 `89fe80aa`：引用计数 `php_request_users` + 导出 `typephp_<target>_library_request_startup/shutdown()` + persistent cache 重置）<br>⚠️ **仅在源码树**（见下） |
| **D6** | lib 模式**全程序去虚化**"具体空实现的可覆写方法" ⇒ 消费方覆写被**静默忽略**（8 条断言丢失） | ❌ 未解，第二个硬阻塞 | ✅ **已修**（`MethodCallTrait.php:284/331` 加 `isBuildModeLib()` 守卫）<br>⚠️ **仅在源码树** |

**★ 结论翻转（本轮最重要的修正之一）**：GUI 仓 2026-10-11 的裁断是
"`-m lib` 机制上可行，但**当前不可直接交付**（差 D6，且 D3/D4 需旁路）"。
**这个结论今天已过期** —— 上游 master（0.9.5）已修 D2/D3/D5/D6，
并补了官方实现 `89fe80aa`（更彻底：新增 `libraryEmbedsPhp` 概念 + 3 个 CI 测试）。
⇒ **形态 B 从"纸面可行"升级为"实测可行、上游已支持"。**

> **★ GUI 仓那笔补丁的真实归宿（要写准，别美化）**：GUI 仓是以 **PR #166** 提的
> （分支 `lib-mode-fix`，提交 `6c466d91`，PR 说明见 `typephp/PR-lib-mode-fix.md`）。
> 实测 `git merge-base --is-ancestor 6c466d91 master` = **false** ⇒ **该 PR 本身没有被合入**。
> 上游是**另起实现** `89fe80aa`（同一标题族："manage consumer request lifecycles and virtual dispatch"）。
> ⇒ 准确说法：**问题是 GUI 仓报的、修法是上游自己写的** —— 报告被采纳，补丁未被采纳。

##### ★★★ 但要立刻加一条**工具链约束**：**发行版 `tpc.exe` v0.9.5 不含这个修复**

**判据（生成物 diff，2026-10-14 实测，非推测）**：用同一份最小 `-m lib` 工程分别跑**发行版 `tpc.exe`** 与
**源码树 tpc**（`php bin/tpc.php`），对生成的 `extension-probe.cc` 做 diff：

| 生成物特征 | 发行版 `tpc.exe` v0.9.5 | 源码树 tpc（master） |
|-----------|------------------------|---------------------|
| `php_request_users` 引用计数 | ❌ 无 | ✅ 有 |
| RINIT 二次进入 | `php_error_docref(... "already initialized")` + **`return FAILURE`**（**修复前形态**） | `++php_request_users; return SUCCESS;` |
| 导出的 `typephp_<target>_library_request_startup/shutdown()` | ❌ 无 | ✅ 有 |
| persistent cache 重置（`php::resetPersistentCache`） | ❌ 无 | ✅ 有（3 个 map） |

**时间线也印证**：版本打点 `21770951`（0.9.5）= **10-13 14:59**；修复 `89fe80aa` = **10-13 17:43**（晚 2h44m）；
发行版文件 mtime = **10-13 07:53**（更早）。⇒ **发布早于修复。**

⇒ **Drift 的落地约束（硬）**：
- **形态 B（`-m lib`）必须用源码树 tpc**（`php bin/tpc.php` + `PHPX_HOME`），**不能用发行版 `tpc.exe`**；
- 或等上游下一个发行版（届时重新跑一次上面这张 diff 表即可确认是否已带上）；
- ★ 这与 GUI 仓 memory 完全一致："**lib 必须用源码树 tpc，因为修复在编译器源码里**"
  ⇒ 不是我的推断，是 GUI 仓当时的实际做法（`build/build_lib_srctpc.sh`）。
- ⇒ **§16 的工具链脚本必须提供两个入口**：`build.sh`（发行版，形态 A）与 `build-lib.sh`（源码树，形态 B）。

**★ 上游还补了 GUI 报告里点名缺的那个夹具**（报告 §"上游漏检根因"建议"补这个夹具"）：
新增 `phpunit/code/library/{consumer,leaf,left,right}.php` + `repeat.cc` / `failing.cc`
+ `LibraryLifecycleTest.php`（3 个测试：失败库启动应中止消费方 / 独立 C ABI 库保留内嵌运行时 /
**共享库跨内嵌请求重置**）。⇒ **D5/D6 这类"静默失败"从此有 CI 兜底了。**

**★ `-m lib` 的实测事实**（读源码得出，非推测）：

| 事实 | 出处 |
|------|------|
| 发布产物 = 自动生成的 `<target>.stub.php`（带 `/** @import-library */`）+ Windows `.dll` + 导入库 `.lib`；Linux `.so` | `MIXED_CPP_PHP.md` §3 · `Translator.php:1010/1017` |
| **库名由 stub 文件名推导**（`drift.stub.php` ⇒ 库 `drift`） | 同上 |
| 消费侧只需把 stub 放进 `sources` ⇒ **tpc 自动把它加入 `linkLibs`**（无需手写 `link-libs`） | `Preprocessor.php:478-480` |
| **stub 是"声明汇总"**：函数体清空、类方法体清空、property hook 的 `get/set` 保留声明**移除实现**；保留 `TraitUse` / `ClassConst` / `EnumCase` / 属性 | `LibraryImportStubGenerator.php:126-170` |
| **`#[NoExport]`** 把符号排除出公开 ABI；**类注解级联到全部方法**；单个方法也可独立标；声明**仍参与本库编译**，只是不加导出修饰 | `MIXED_CPP_PHP.md` §3 |
| `#[NoExport]` **只在根命名空间**：全局可写 `#[NoExport]`，其他命名空间必须 `#[\NoExport]` | 同上 |
| **`#[Native]` 类不能通过库 stub 导出** ⇒ 必须标 `#[NoExport]` | `LibraryImportStubGenerator.php:107-114` |
| **`libraryEmbedsPhp`（0.9.5 新增）**：库是否需要**自带内嵌运行时**。`false` ⇒ 共享宿主运行时（按 `BUILD_MODE_EXT` 解析 Zend 符号）；`true` ⇒ 独立 C ABI 库（自 `TYPEPHP_RUNTIME_INIT`） | `CompilerBase.php` · `NativeCommandOptionsTrait.php:212-217` |
| **lib 模式给每个 C++ TU 无条件强制 `/FI<php_<target>_all_decl.h>`**（**无 yml 开关**）⇒ 聚合头被提到 TU 最前 | `NativeCommandOptionsTrait.php:99-104` |
| 自动生成的 stub **放宽类型要求**（无类型声明保留为 `php::Var`），而**手写本地 stub 必须显式 ABI 类型** | `Preprocessor.php:1396-1398` |
| **传递导入不会被再导出**（`externalImportStubFiles` 被跳过）⇒ 若 Drift 的库自己也导入别的库，消费方必须**另外**提供那个库 | `LibraryImportStubGenerator.php:40-42` |
| 库构建仍**需要 PHP 运行时与 PHPX**（Windows：`php8.lib`/`php8ts.lib` 或 `php8.dll`；**`phpx.lib` 链接期 + `phpx.dll` 运行期**）⇒ **不是完全自包含产物** | `Platform/Windows.php:183-230` |

**★ 形态 B 的最大收益（对 SDK 分发）**：**应用侧完全不需要 Skia 头文件与 20 个 link-libs**（全部烘进 `.dll`）。§16.4 那份"cxx-flags / cxx-std / include-paths / link-paths / link-libs 一大堆"的 `project.yml`，对第三方应用会缩到几乎为空。⇒ **这是"框架作为 SDK 分发给第三方"唯一现实的形态。**

**★ 形态 B 的代价（必须诚实写清）**：
1. **失去跨模块内联** —— `layout()` / `paint()` / `mutate()` 这些**热路径**在单编译单元里能被内联；跨 `.dll` 边界只剩一次间接调用。§15.3 实测的 **0.92 µs/节点**是在**同编**下测的 ⇒ 形态 B 的性能退化**幅度仍未测** ⏳（**GUI 仓的 spike 只验了"对不对"，从未量"快不快"**）。
2. **部署变成多文件** —— AOT 单 exe 的"一个文件"优势没了（`.exe` + `drift.dll` + PHP 运行时/PHPX 运行期依赖）。★ **唯一已知解法是 `--nano`**（把 PHPX 源码编进产物、`getLibraryPaths()` 返回空），但今天不可用 —— 三条硬冲突见 **§16.6.4**。
3. **版本/CRT 矩阵** —— `.dll` 与宿主必须**同 CRT、同 PHP 版本**（共享运行时按 EXT 解析 Zend 符号 ⇒ 版本必须精确匹配）。§16.4 已踩过 `LNK2038`（`/MT` vs `/MD`）。
4. **`#[Native]` 类在库模式下不可见**（硬约束）⇒ 若 Drift 想用 `#[Native]` 加速热路径对象，**形态 B 下应用拿不到它们** ⏳（实际限制未验）。
5. ~~闭包跨 `.dll` 边界未验~~ ⇒ **✅ 已验（GUI 仓 spike 判据 ③）**：`Widget::when()` / `CustomPaint(painterClosure:)` 同步调用应用传入的闭包，与 bin 基线逐条一致。★ 但**跨 `.dll` 边界的"异步/跨线程"闭包仍未验**（与 §17.2.2 的"闭包跨工作线程未验"是同一类问题）。

**★ Drift 必须继承的两条 GUI 仓纪律（用真金白银换来的）**：

1. **Drift 自己的 C++ 桥接头文件**（`cpp-src/*.h`）**绝不许写 `using namespace php;`** —— 因为 D1 根因未修，lib 模式会把聚合头提到每个 TU 最前，一旦桥接头引入 `using namespace php`，Skia 的枚举位运算就会重载歧义。**GUI 仓为此付了 `0562049` 一个提交**。
2. **`tpc.exe` 不加载同目录 `php.ini`**（`php.exe` 会）⇒ 缺 `mbstring` 直接 `Fatal: Failed to startup module`。必须**两件都做**：① `PHPRC=<发行版根>`；② `php.ini` 的 `extension_dir` 改**绝对路径**（`./ext/` 按 CWD 解析，而 tpc 惯例从仓库根调用）。**0.9.0 不要求，0.9.4 起强制** ⇒ 升级发行版即破坏既有构建脚本（GUI 仓 `5e5c0eb` 就是补这个）。⇒ **Drift 的 `tools/build.sh` 从第一天就设 `PHPRC`。**

**★ 落地前的探针门（M1 前，⏳）—— 从 6 问缩到 3 问**（前 3 问已被 GUI 仓实证回答）：

| # | 问题 | 状态 |
|---|------|------|
| ① | 能否真的产出 `.dll` + `.stub.php`？ | ✅ **已验证**（`typephp_gui.dll` 11.3MB，导出 1233 个符号） |
| ② | **闭包跨 `.dll` 边界**？ | ✅ **已验证**（spike 判据 ③，372 ok 与 bin 基线一致） |
| ③ | `#[NoExport]` **级联**的实际行为？ | ✅ **已验证**（`#[Native]` 类必须标 `#[NoExport]`，否则编译期拒绝 —— `LibraryImportStubGenerator.php:107-114`） |
| ④ | **热路径跨模块调用的性能差**（对照 §15.3 的 0.92 µs/节点）？ | ⏳ **仍未测** —— GUI 仓没量过 lib vs bin |
| ⑤ | **`#[Native]` 类的实际限制**（若 Drift 想用它加速热路径）？ | ⏳ **未验** |
| ⑥ | CRT/PHP 版本不匹配的**实际症状**？ | ⏳ 部分已知（`LNK2038`），lib 专属症状未验 |

⇒ **裁决：两种形态都支持，定位不同**（不是二选一）：
- **形态 A（源码同编）= 默认 / 开发形态**：单 exe、性能上限、调试直接、零版本矩阵。适用于**应用自己发布 exe**（Drift 自己的 demo / 应用工程）。
- **形态 B（`-m lib`）= SDK 分发形态**：框架**编译一次**给多个应用复用、应用侧**不需要 Skia 工具链**。适用于**框架作为 SDK 交给第三方**。

★ **两种形态下"公共面"的表达方式统一**（这是本节的关键结论）：

| 层 | 形态 A（同编） | 形态 B（lib） |
|----|--------------|--------------|
| **物理隔离**（能不能调到） | ❌ 无 ⇒ 退化为 **lint**（`internal-leak`） | ✅ **`#[NoExport]` + 编译器生成的 `.stub.php`**（链接期强制） |
| **契约承诺**（承诺不破） | `@api` 标注 + `api-surface.json` | 同左，**且 `api-surface.json` 可从 `.stub.php` 派生** |
| **一致性守卫** | `scan-autoload-drift` | `scan-autoload-drift` + **`export-surface-drift`**（`@api` 面 ⊆ stub 导出面） |

⇒ **`.stub.php` 是"技术上能调到的上界"（机器产出），`@api` 是"承诺不破的面"（人标注）**。两者**不同**：`@internal` 的符号**可以**出现在 stub 里（因为某个 `@api` 签名引用了它），但**不在**契约面里。
★ 而 `#[NoExport]` 的**级联排除**正好对应"公共签名引用了内部类型"这个两难：被排除符号的依赖也一并排除 ⇒ 若一个 `@api` 方法签名用了 `#[NoExport]` 的类型，**签名会不完整** ⇒ **编译器/链接器会响**（不静默）。手册的提醒印证了这点："发布库时请对照自动生成的 `<target>.stub.php` 核对导出清单。"


**★ 形态 A 的两种挂载方式**（同一份框架源码，两种 `sources` 写法）：

```yaml
# 变体 A1：SDK 目录（下载 / 解压 / 子模块）
sources:
  - src
  - ../drift-sdk/src                 # 框架源码目录

# 变体 A2：composer 包（composer require drift/drift）
sources:
  - src
  - vendor/drift/drift/src           # 框架包
```

> **★ 两种写法都必须配一条 lint（新增，SDK 模型独有）**：`project.yml` 的 `sources` 覆盖的命名空间 **必须**与 `composer.json` 的 `autoload.psr-4` 声明一致。
> **为什么必须**：`sources` 是给 **AOT**（tpc 扫文件建符号表）的，`autoload.psr-4` 是给**解释器**（`drift-preview` / 单测 / `drift-lint`，§16.2 明写"内环不编译，纯 PHP 可在解释器里跑"）的。**两者不一致的症状是"preview 能跑、AOT 编译不过"（或反之）** —— 典型的静默不一致，正是 G1 要消灭的。规则见 §16.5.3。

#### 10.1.3 ② 架构分层：命名空间 = 目录（**不是**权限）

**§4.2 的全部十个命名空间都对应用可见**，因为它们表达的是**分层**，不是权限。

| 谁守什么 | 规则 |
|---------|------|
| **"谁能依赖谁"** | §4.3 依赖规则 lint（`Rendering` 不得依赖 `Widget` 这类） |
| **"用户能不能用"** | §10.1.4 的 `@api` 标注（**与命名空间无关**） |

⇒ 这是两个**正交**的问题。上一版把后者错写成"五个命名空间白名单"，于是 §4.2 的另外五个命名空间（`Geometry` / `Text` / `Input` / `Platform` / `App`）被误判成"不可用"。

#### 10.1.4 ③ 公共契约面：`@api` 标注（唯一判据）

**判据**：**标了 `@api` 的类型/方法 ⇒ 在契约面内；其余一律 `@internal`。**

| 标注 | 含义 | 业务引用的后果 |
|------|------|--------------|
| `@api` | 承诺向后兼容（按 §10.2 第 1 条的语义化版本） | ✅ 正常使用；签名进 `api-surface.json` |
| `@api extension`（可选细分） | 给**控件作者**的 API（`StatefulWidget` / `State` / `RenderBox` / `Canvas` / 布局契约 / `SubscribingWidget`） | ✅ |
| `@api platform`（可选细分） | 给**平台适配者**的 API（`Platform` 接口族 / `WindowHost` / `Screen` / `SurfaceProvider`） | ✅ |
| `@internal`（**默认**） | 不保证兼容；业务引用 ⇒ lint **error** | ❌ 除非显式豁免 |

**★ 默认值是 `@internal`，不是 `@api`** —— 这是 G2 的落点：

- 默认 `@internal`：漏标 ⇒ **编译期报错**（"`App\` 引用了未标 `@api` 的 `Drift\…`"）⇒ **响亮失败**，改一行标注即可；
- 默认 `@api`：漏标 ⇒ **悄悄进了 `api-surface.json`** ⇒ 再想收回来**就是破坏性变更**（§10.2 第 1 条：破坏性只在主版本）⇒ **静默扩面**。

**★ `@internal` 的引用是 error 级，但可显式豁免** —— **复用 GUI 仓 R11/R12 已经验证过的"两级严重度 + 白名单"机制**（"未登记 = error 挡新债 / 已登记 = warn 留痕"），不新发明：

```php
/**
 * @internal-use-allowed: 等 upstream 修 #123 后移除
 * 责任人：Acme\Hello\src\pages\UserPage.php
 */
$el = $this->widget()->element();      // 引用 @internal ⇒ 有这条豁免才放行，且降为 warn
```

**★ 三类用户（应用作者 / 控件作者 / 平台适配者）的细分是"可选细分"**：M0 只强制二值（`@api` / `@internal`），需要时再细分，**不阻塞 M0**。

**★ 为什么"角色"值得留**：`api-surface.json` 能**按角色 diff** ⇒ "这次改动只影响控件作者，不影响应用作者" ⇒ 语义化版本的判定更精确、changelog 能按受众分组。

### 10.2 治理七条

1. **语义化版本**：补丁只修 bug；小版本**只加不破**；破坏性只在主版本。
2. **`@api` 标注（判据唯一化）**：契约面每个类型/方法标注；**未标注 = `@internal`（默认）**。CI 有 **API 表面快照**（`tools/api-surface.json`，**只含 `@api` 签名**，diff 即评审事件）。
3. **弃用策略**：`@deprecated` 至少保留一个 minor；删除必须在 changelog 点名。
4. **"要么不提供、要么必须生效"**（GUI 仓纪律原文）：声明了但从未被读的 API（`Container::$constraints` 就是这类）比不提供更糟 ⇒ **死字段 lint 常设**。
5. **控件是纯函数**：值 → 描述；**订阅责任只能落在实现了 `SubscribingWidget` 接口的控件上**（`SubjectBuilder` / `Bound` / `PageHost` / `StatusBar`），不能落在渲染型控件上。
   - ★ **判据是"接口标记"，不是"命名约定"**（裁决 `D-10`）。理由：**命名约定被方案自己的例子否证** —— §12.2 明写"`StatusBar` / `PageHost` 的形态就是专职订阅者"，但 `StatusBar` 的名字不含 `*Builder`。接口标记让"专职订阅"从**约定**变成**类型事实**（G1），同时给 §21 A16 的 lint 提供判据。
6. **糖与契约分离**：语法糖只允许转发权威构造器，不许自造契约 —— **删掉全部糖，框架契约与测试原样完整**（反向不成立）。
   - ★ **澄清（裁决 `D-06`）**：**变体工厂允许转发到"同族的另一个控件"**，不只限于同类。
     `TextField::bind($cell)` 转发到 `Bound::make($cell, fn($v) => TextField::make(...))` —— 它**仍是纯转发**（删掉 `bind()`，`Bound` 这个契约原语与测试**原样完整**，只是更啰嗦）。
     ⇒ **纪律不动**，只是把 §15.4"变体工厂 = `make()` 的薄壳别名"的"`make()`"从"本类的"扩为"**同族契约原语的**"。这比"为糖开一个例外"好：§10.2 第 6 条是方案里最硬的一条纪律，为它开例外会削弱它。
7. **SDK 发布契约（新增）**：`sources`（AOT 扫描面）与 `composer.json` 的 `autoload.psr-4`（解释器面）**必须一致** ⇒ lint 强制（§16.5.3）。理由见 §10.1.3 的警示框。

### 10.3 稳定的四个具体承诺

| 承诺 | 机制 |
|------|------|
| 公共签名不悄悄变 | `tools/api-surface.json` 快照（**只含 `@api`**）+ CI diff |
| `@internal` 不被业务引用 | lint（**非 `Drift\` 前缀**引用了 `@internal` ⇒ **error**；有豁免标注 ⇒ warn） |
| 每个公开参数都被真正读取 | 死字段 lint（**W3**，清单见 §16.5.2） |
| **SDK 扫描面与 autoload 面一致** | **新增**：`sources` ↔ `autoload.psr-4` 一致性 lint（§16.5.3，裁决 `D-02`） |
| **（`-m lib` 形态）`@api` 面 ⊆ ABI 导出面** | **新增**：`export-surface-drift`（§16.5.3；对照编译器生成的 `<target>.stub.php`，裁决 `D-02`） |

---

## §11 UI 控件与业务的隔离 + 数据交互（重点 8）

### 11.1 两条缝 + 隔离的三样

```
┌────────────────────────────────────────────────────────────┐
│ 业务层  Store（Subject 子类）：数据 + 规则 + IO，不认识 GUI   │
│         通知：subscribeFor(owner, fn) / unsubscribeAllOf     │
└────────────────────────────────────────────────────────────┘
        ▲ 值缝：数据 → UI（唯一通道：控件吃「值」）
┌────────────────────────────────────────────────────────────┐
│ 绑定层  State —— 只持纯 UI 态（选中/焦点/编辑中/滚动）        │
│         SubjectBuilder —— 唯一允许订阅的控件（专职订阅者）    │
└────────────────────────────────────────────────────────────┘
        │ build($ctx) → Widget（纯值、不可变）
┌────────────────────────────────────────────────────────────┐
│ 视图层  三树 + 动作缝：命令（`command('a.b')`）/ 局部闭包     │
└────────────────────────────────────────────────────────────┘
```

**隔离的三样**：**持有**（数据归谁）、**规则**（业务逻辑）、**变更来源**（谁发起变更）。
**State 瘦身判据**：业务编码（如 `"\x00"` 分隔符）在 State 里出现次数 = **0**。（= GUI 仓 `lint R10` 的判据，见 §16.5）

> ★ **术语修正（第二轮评审）**：上一版此处写 `Intent / 命令 / 局部闭包` —— 但 `Intent` 全篇**只有 3 次提及、0 次使用**，§4.2 的文件清单里没有它，§11.4 的样例用的是 `command('form.submit')` + `handleCommand(string, int)`（**字符串 + 整数**）。两个名字指同一件事 = §21 A12（两套真相源）。
> ⇒ **本方案只保留 `command` 这一个名字，`Intent` 从全文删除**。理由：`handleCommand(): bool` 的冒泡语义、§16.1 的"死命令 lint"、§9.1 的路由链**全部围绕字符串命令建立**；而 `Intent` 对象会带来"谁构造 / 谁携带参数 / 谁解构"三件新事 —— 正是 §18 从 WPF 学"RoutedCommand"时明确要避开的那部分。
> （动作缝的**机制**见 §11.4.3：冒泡链 + 应用级注册表。）

### 11.2 三轴正交（数据来源 × 绑定方向 × 更新时机）

| 轴 | 回答 | 取值 |
|----|------|------|
| A 数据来源 | 从哪来 | 内存 / 文件 / HTTP / 输入 |
| B 绑定方向 | 谁驱动谁 | 上行（UI→业务）/ 下行（业务→UI）/ 双向 |
| C 更新时机 | 何时、重建多少 | 显式 setState / 显式 notify（**无自动追踪**） |

**核心判断**：A 轴（远端 / 本地）与 B/C **正交**。HTTP 只决定"可能慢、可能失败"，不改变绑定机制一个字。**三态（loading / error / ready）的必要性由"延迟/失败"决定，与"是否远端"无关**——本地文件 IO 同样需要三态。

### 11.3 做进设计的四件事（GUI 仓里它们是补丁，Drift 里是地基）

| GUI 仓现状 | Drift 设计 |
|-----------|-----------|
| RT-1 订阅生命周期无守卫（忘了退订 ⇒ 泄漏 + 僵尸更新，**静默**） | `State` 持**订阅袋**：`watch()` 记账；`dispose()` 基类统一全退；`mounted` 守卫；**build 期订阅 ⇒ 立刻上报** |
| RT-3 一个订阅者抛异常 ⇒ 其余全收不到（GUI 仓全仓 0 个 try/catch） | `notifyChanged()` 每订阅者异常隔离 + 写 `Errors` 出口 |
| RT-4 相等门控（设同值也广播） | `Cell` / `setIfChanged` 进原语；`===` 严格比较 |
| RT-2 盒属性同步（两份手写字段赋值 ⇒ 静默分叉） | Widget 字段 → 盒字段**单一同步路径** + 字段对齐 lint |

#### 11.3.1 三种"变了"的消歧（★ 第二轮评审补）

上一版让 `Subject::notifyChanged()` / `State::setState()` / `Cell::setIfChanged()` 三者在 §11 里**并列出现却从未说清何时用哪个**，且 `Cell` **一处使用样例都没有**。补一张判据表：

| 原语 | 层次 | 谁调 | 语义 | 门控 |
|------|------|------|------|------|
| `Subject::notifyChanged()` | **业务层**（`Store` / `FormData`） | 业务代码，改完数据**手动调一次** | "我的数据变了，请所有订阅者重建" | **无**（同值也广播 ⇒ 业务侧要自己避免无谓 notify，或用 `Cell` 包字段） |
| `State::setState(fn)` | **绑定层**（页面的 `State`） | 页面代码，改**纯 UI 态** | "只标脏我自己，帧末重建我的子树" | **无**（`State` 不比较值） |
| `Cell::setIfChanged($v)` | **State 层的单值单元** | 需要"设同值不重建"时 | "值真的变了才广播" | **有**（`===` 严格比较） |

**一句话判据**：
> **业务数据**变 ⇒ `Subject::notifyChanged()`；**UI 态**变 ⇒ `State::setState()`；**想要"设同值不重建"** ⇒ 那个值用 `Cell` 装，别裸放。

**`Cell` 的使用样例**（上一版缺失）：

```php
// Cell 住在 State 里（不是 Store 里）—— 典型场景：高频、可能重复设值的 UI 态
final class HoverCardState extends State
{
    private Cell $hovered = new Cell(-1);        // -1 = 无（§15.2 哨兵三档定则：int 用负值）

    public function onRowHover(int $index): void
    {
        // 鼠标在行内移动会反复触发；setIfChanged 让"同一行"不发第二次重建
        $this->hovered->setIfChanged($index);    // 内部：=== 不等 ⇒ setState 标脏；相等 ⇒ 什么都不做
    }

    public function build(BuildContext $ctx): Widget
    {
        $h = $this->hovered->value();
        return ForEach::make(fn(Row $r): int => $r->id)->items($this->rows(), fn(Row $r): Widget =>
            Row::make()->hovered($r->id === $h)->children([...]));
    }
}
```

> ★ 注意 `Cell` 与 `Subject` 的**分工不是"大/小"而是"谁的门控"**：`Subject` 是"广播端，无门控"（因为它一次可能改了 5 个字段，门控无意义）；`Cell` 是"单值端，有门控"。
> **不要**为了门控把 `Store` 的每个字段都换成 `Cell` —— 那会让 `Store` 依赖 `State` 层类型，违反 §11.1 的"业务层零 GUI 依赖"。

#### 11.3.2 两条纪律推论（★ 第二轮评审补，都是既有裁决的必然结果）

1. **订阅控件不得跨 `build()` 缓存复用。** `SubjectBuilder` / `Bound`（见 `DECISIONS.md` D-06）也是 Widget，受 §15 的 Seal-on-Mount 约束。若开发者把它建好存进字段、下帧再塞回树里，**它捕获的闭包是旧快照** ⇒ 看着是"订阅了"，实际订阅的是上一帧的 `$store`。⇒ **纪律：订阅控件必须在 `build()` 内构造**，与 §21 A4（build 期订阅）配成一对。
2. **订阅粒度不得细到"每列表项一个订阅"。** 1000 行的列表若每行 `State` 各自 `watch($store)`，就有 1000 个订阅者，任一 `notify` 要跑 1000 次回调（还都要过 §11.3 RT-3 的 try/catch）。⇒ **纪律：订阅落在"列表容器"那一层，行内只吃值**。这也是 §12.3 虚拟化回收的前提——项 `State` 被 dispose 时若还挂着订阅，回收成本会随视口滚动反复付。（订阅袋的 `dispose` 全退是**兜底**，不是**方案**。）

#### 11.3.3 订阅粒度：`SubjectBuilder::select()`（裁决 `D-05`）

**问题**：上一版的订阅粒度 = **整个 `Subject`**。一个 `Store` 有 10 个字段 + 10 个订阅者 ⇒ 任一字段变，**10 个全重建**。

上一版靠"每个切片自己是一个 `Subject`"绕（§12.2 的 `statusLine()` / `currentPage()`）—— 但那让 **`Store` 必须暴露 N 个 `Subject`**，而且**隐含依赖"每次调用返回同一个实例"**（这一点方案没写、也无法 lint）。⇒ 那**不是"没做"，是"做了个坏版本"**（= §18 从 Flutter 学来的要避开的 "provider soup"：**业务反向依赖 UI 的关切**）。

**裁决：`SubjectBuilder` 增加 `select()`**：

```php
SubjectBuilder::make($store)
    ->select(fn(UserStore $s): array => $s->rows())        // 只关心这一个切片
    ->builder(fn(array $rows): Widget => ...)
```

| 收益 | 说明 |
|------|------|
| 粒度细，**且 `Store` 不必暴露 N 个 Subject** | 纯函数式 ⇒ **没有实例身份问题** ⇒ 消灭 §12.2 的 `statusLine()` 歧义 |
| 相等门控**复用 §11.3 RT-4** | 落在 `select` 的**返回值**上（`===`），**不引入第二套相等机制** |
| **可 lint（G1）** | `select` 闭包里出现的属性集合 = 该订阅者**真正依赖的字段集** ⇒ **编译期可生成依赖清单**（= §16.1"远期编译期推导"的第一步） |
| 每帧成本 | 一次闭包调用 ⇒ 可忽略 |

**★ 两条必须写进契约的话**：
1. **`select` 的返回值必须是标量或不可变值对象**（否则 `===` 门控失效 —— 数组 `===` 是**逐元素严格比较**，对象是**同一实例**；返回"每次都新建的对象"会让门控退化成"每次都不等"⇒ 每帧重建）。
2. **`select` 闭包不得有副作用**（它每帧都会跑，且会被 lint 用来推导依赖）。

**★ 与 `builder()` 签名的兼容**：`select` 之后 `builder` 要收参数 ⇒ 提供两个变体（`builder(fn(): Widget)` 无 `select` 时 / `builder(fn($v): Widget)` 有 `select` 时），由 lint 保证"有 `select` 必须有参数、无 `select` 必须无参数"。

#### 11.3.4 派生值（computed）的落点（裁决 `D-07`）

**问题**：`fullName = first + ' ' + last` 放哪？放 `Store` getter ⇒ **每帧重算**；放页面 `build()` ⇒ 每帧重算。§18 明说借了 Vue 的"计算缓存 + 版本跳过（**形状**）"，但 §11 里没有这个原语。

**裁决：`Store` 里的"缓存 getter" + 一条 lint。不做 `Computed` 原语。**

```php
final class UserStore extends Subject
{
    private ?string $fullNameCache = null;           // ★ 缓存槽（?T，null = 未算）

    public function fullName(): string               // 派生值
    {
        return $this->fullNameCache ??= $this->first . ' ' . $this->last;
    }

    public function setFirst(string $v): void
    {
        $this->first = $v;
        $this->fullNameCache = null;                 // ★ 依赖变了 ⇒ 清缓存（lint 守这一行）
        $this->notifyChanged();
    }
}
```

**为什么不做 `Computed` 原语**（三条）：
1. **§11.6 明确不做运行期自动依赖追踪** ⇒ `Computed` 若不带自动追踪，就要**手写依赖清单**。而"手写依赖清单"与"手写脏标记"**是同一类风险**（忘了 = **静默旧值**，正是 §21 A9 的形态），但 `Computed` 还**多一个原语 + 一套版本号机制**。⇒ **收益不抵成本。**
2. **有了 §11.3.3 的 `select()`，`Computed` 更不必要**：`select(fn($s) => $s->fullName())` ⇒ 门控落在**返回值**上 ⇒ `fullName` 没变就不重建。⇒ `Computed` 的"可单独订阅"收益被 `select` 拿走，只剩"省一次重算"，而缓存 getter 已经省了。
3. **缓存 getter 的成本可控且可被机器守**：`??=` 一行 + 清缓存一行。★ **新增 lint**（§16.5.3）：`Store` 里出现 `??=` 赋值的属性 ⇒ 所有会修改其**依赖字段**的方法里必须有 `$this->xxxCache = null`。判据静态可判（字段集编译期已知）。

**★ 诚实边界**：缓存 getter 只解决"重算"，**不解决"订阅者只跟它"** —— 后者交给 `select()`。

### 11.4 上行：值回家，提交走命令

#### 11.4.1 表单值：property hooks 进 Store（裁决 `D-06` 第一步，M2）

★ **上一版这里用 `get()/set()` 字符串键，每个字段名出现 3 次**（`make('name')` + `get('name')` + `set('name',…)`）⇒ **10 个字段 = 30 个字符串字面量**，每个都是 typo ⇒ 静默失败。
而 §2.1 明写 **property hooks 可用**、且是"受控属性的物理基础" —— **能力在手却没用**。改为：

```php
final class SignupForm extends Subject          // 业务层：不认识任何 GUI 类型
{
    public string $name  = '' { set (string $v) { $this->name  = $v; $this->notifyChanged(); } }
    public string $email = '' { set (string $v) { $this->email = $v; $this->notifyChanged(); } }

    public function validate(): array { /* … */ }
    public function toApiPayload(): array { /* ★ 唯一序列化出口 */ }
}
```

**收益（对照上一版）**：字段名从**字符串键**变**属性**（拼错 = **编译错误**，不是静默）；`get()/set()` 两个方法消失；`(string)` 转换消失（属性已带类型）。**框架零改动** —— 只是 Store 的写法。

#### 11.4.2 表单控件：`Bound` 自带订阅（裁决 `D-06` 第二步，M3）

★ **上一版这里还有一个静默坏页面**：`$form->set(...)` 会 notify，但**谁因此重建**？若页面是 `StatelessWidget`，输入后的值**永远不回流到 `value()`**。对照 §11.5 的列表样板 —— **那个有 `SubjectBuilder`，这个没有**。

**根治**：把订阅**做进控件**，而不是靠人记得包一层。

```php
// 页面里一行，取代"value + onChanged + 外层 SubjectBuilder"三件事
TextField::bind($form->name)->label('姓名')->name('name')
TextField::bind($form->email)->label('邮箱')->name('email')

// 等价展开（契约层 —— 糖删掉后仍在）：
Bound::make($form->name, fn(string $v): Widget =>
    TextField::make('name')->label('姓名')->value($v)->onChanged(fn(string $x) => $form->name = $x))
```

**摩擦点对照（单字段）**：

| 指标 | 上一版 | `bind()` 后 |
|------|--------|-----------|
| 字段名出现次数 | **3**（全是字符串） | **1**（**属性**，拼错 = 编译错误） |
| 手写闭包 | 1 | **0** |
| `(string)` 转换 | 1 | **0** |
| 要记得包 `SubjectBuilder` | ✅ 要 | ❌ **不用**（`Bound` 自带） |
| 10 字段合计字符串字面量 | **30** | **0** |

**`Bound` 的定位（关键，为了不动 §10.2 第 6 条）**：
- `Bound` 是**契约原语**，进 `Drift\State`，与 `SubjectBuilder` 同族（都实现 `SubscribingWidget`，都是"专职订阅控件"）。
- `TextField::bind($cell)` 是**变体工厂**，转发到 `Bound::make(...)` ⇒ **仍是纯转发**（§10.2 第 6 条已澄清"变体工厂可转发到同族控件"）⇒ **纪律不动**。

#### 11.4.3 动作缝：命令的注册与派发（裁决 `D-01`）

★ **上一版只有一行样例**（`handleCommand` 全篇出现 **1 次**），注册在哪 / 谁派发 / 找不到怎么办 / 多 handler 谁先 / `arg` 为什么只能是 `int` —— **全未定义**。而它比其它缺口更贵：**每个交互控件都要走这条路**。

```php
// ── 注册：应用级一次（组合根或 Application），可枚举、可 lint、可单测 ──
final class HelloApp extends Application
{
    protected function commands(): CommandRouter
    {
        return CommandRouter::make()
            ->on('nav.buttons',   NavigationCommands::class)   // handler 是类，不是闭包
            ->on('user.del',      UserCommands::class)
            ->on('form.submit',   SignupCommands::class);
    }
}

// ── 派发：从命中的 Element 向上冒泡（与 §9.1 键盘/指针路由同族）──
//   ① 目标 Element 的 State::handleCommand($name, $arg)   → true ⇒ 停止冒泡
//   ② 祖先 State（逐级向上，直到 Root）                    → true ⇒ 停止冒泡
//   ③ CommandRouter（应用级注册表）                        → true ⇒ 停止冒泡
//   ④ 都没有 ⇒ Errors::report（Diagnostic 级）—— 不静默
```

**为什么是"冒泡链 + 注册表"而不是二选一** —— 方案**自己已经要了两件互斥的事**：

| 方案已有的裁决 | 它要求什么 |
|--------------|-----------|
| §11.4 裁决 2"跨树复用、一个 handler 管一列" | ⇒ 需要**应用级注册表** |
| §11.4 样例"页面就近接管"、改 `$this->result` | ⇒ 需要**树内 handler** |

⇒ **两者都要**，所以是**链**。而且这样一来，`handleCommand(): bool` 那个**方案已经写出来但从未解释**的返回值，终于有了明确含义：**"我处理了，停止冒泡"**。

★ **不选另外两条路的理由**（都是"方案自己犯自己的反模式"）：
- **只有页面 handler** ⇒ 放弃 §11.4 裁决 2；且 `bool` 返回值**没人接** ⇒ 变成死代码 ⇒ 犯 §10.2 第 4 条（"声明了但从未被读的 API 比不提供更糟"，A1）。
- **只有应用级注册表** ⇒ 放弃"页面就近接管"；`bool` 同样变死代码；且**页面私有命令必须提到应用级** ⇒ 页面不再是自包含单元，§12.2 的"单页独立预览"对**任何带命令的页面**都不成立。

**接口与参数类型**：

```php
interface CommandHandler { public function handle(string $name, CommandArg $arg): bool; }

// ★ arg 不再是裸 int —— 上一版只能传一个整数 ⇒ 传不了 string id / 复合参数
final readonly class CommandArg
{
    public function __construct(public int $i = 0, public string $s = '') {}
}
```

★ **handler 必须是类，不能是闭包**：闭包**不可枚举**（devtools 列不出来）、**不可单测**、且 §16.1 的"死命令 lint"需要**静态可达性**（闭包拿不到）。

**handler 侧样例**：

```php
final class SignupCommands implements CommandHandler
{
    public function __construct(private SignupForm $form, private UserSource $src) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'form.submit') { return false; }              // 不是我管 ⇒ 继续冒泡
        $errs = $this->form->validate();                            // 业务层校验
        if ($errs !== []) { /* 反馈走 Store，不走 State */ return true; }
        $this->src->submit('/api/signup', $this->form->toApiPayload());  // ★ 唯一序列化出口
        return true;
    }
}
```

> **★ 反馈回 UI 的路（上一版这里也含糊）**：上一版写 `$this->result = ...`，但**没说这个 `$this` 是 Widget 还是 State**（若是 Widget，改已封存对象 ⇒ Fatal）。
> 正确做法：**提交结果属于业务状态 ⇒ 放 Store**（`$this->form->submitError = '…'`），由 §11.4.2 的 `Bound` 订阅回 UI。**State 只持纯 UI 态**（裁决 3）—— 这条现在才真正闭合。

**三个钉死的裁决**（不变）：

1. 表单值的家是 **`FormData`（`Subject` 子类，业务层）**，不是 `State`。
2. 提交走**命令**不走闭包（可审计、可 lint、跨树复用、一个 handler 管一列）。
3. `State` 只持**纯 UI 态**，不持业务数据。

### 11.5 下行：Store + 三态 + 订阅点下移

```php
enum LoadStatus: int { case Idle = 0; case Loading = 1; case Ready = 2; case Error = 3; }

final class UserStore extends Subject            // 业务层：不认识任何 GUI 类型
{
    public function __construct(private DataSource $src) {}

    private array  $rows   = [];
    private LoadStatus $status = LoadStatus::Idle;   // ★ backed enum（裁决 D-04）

    public function status(): LoadStatus { return $this->status; }
    public function rows(): array    { return $this->rows; }

    public function load(int $page = 1): void
    {
        $this->status = LoadStatus::Loading; $this->notifyChanged();
        $r = $this->src->fetchPage($page);                   // 同步三态后端（M1–M3）
        if ($r->ok) { $this->rows = $r->items; $this->status = LoadStatus::Ready; }
        else        { $this->status = LoadStatus::Error; }
        $this->notifyChanged();                              // 一次通知，帧末一次重建
    }
}
```

> **⚠️ 同步后端下的诚实边界（上一版此处措辞错误，本次修正）**：单线程同步后端里，两次 `notifyChanged()` 处于**同一调用栈**，中间没有任何一帧能被渲染（§5.2：统一重建在帧末 `flush`）⇒ **`loading` 态画不出来**，`status = LoadStatus::Loading` 只是给**错误处理与调试**留的语义，不是可见骨架。
> 结论：**M1–M3 的三态只在 `error` 一支真正对用户可见**；`loading` 骨架要等 M4+ 的 `TaskHost` 后台后端（§17）才成立。这不是实现缺陷，是"同步 IO + 帧末统一 flush"的结构后果——不要靠"先广播一次"来假装异步。

```php
// 页面：订阅点放在「真正变的那层」，不放根
SubjectBuilder::make($store)
    ->select(fn(UserStore $s): LoadStatus => $s->status())        // ★ 切片：只跟 status（裁决 D-05）
    ->builder(fn(LoadStatus $st): Widget =>
        match ($st) {                                             // ★ 无 default（裁决 D-04）
            LoadStatus::Loading => Text::make('加载中…')->caption(),
            LoadStatus::Error   => Text::make('加载失败，点刷新重试')->error(),
            LoadStatus::Ready   => ForEach::make(fn(User $u): int => $u->id)   // ★ key 必填（D-03）
                ->items($store->rows(), fn(User $u): Widget =>
                    Row::make()->spacing(8)->children([
                        Text::make($u->name)->body(),
                        Button::danger('删除')->command('user.del')->arg(new CommandArg(i: $u->id)),
                    ])),
            LoadStatus::Idle    => Text::make('尚未加载')->caption(),   // ★ 漏一支 ⇒ UnhandledMatchError
        })
```

> **★ 三处修正在此闭合（第二轮评审的 D-03 / D-04 / D-05）**：
> ① **`ForEach::make(fn(User $u): int => $u->id)`** —— key **进 `make()` 必填**（`keyBy` 全篇 0 次的缺口补上）。理由：G1 判据成立（`fn(User $u)` 的类型就在签名里 ⇒ **编译器知道列表项有身份**）；且 §16.1 早已写"key 唯一 / 稳定 ⇒ **构建期校验（key 是结构事实）**"——这条不是新决定，是那条裁决的**落地**。
> ② **backed enum + 无 `default` 的 `match`** —— 上一版 `default => rows` 意味着"**任何未来新增的状态都会静默显示数据行**"（= §21 A7）。现在新增一个 case 而不改这里 ⇒ **`UnhandledMatchError`**（响亮失败，G2）。§2.1 已探针验证 backed enum 可用。
> ③ **`select()` 切片** —— 上一版订阅粒度 = **整个 `Subject`**（Store 有 10 个字段 ⇒ 任一字段变、10 个订阅者全重建）。见 §11.3.3。

> **脏边界真正生效**：订阅点下移到 `SubjectBuilder` ⇒ 兄弟子树不重建。GUI 仓最大的性能缺陷就是"订阅点挂根 ⇒ 一次变更整页重建，且忘下移不报错"。

> **⚠️ 诚实边界（第二轮评审补，与本节对 `loading` 的诚实边界同款）**：上面那句**只在 build 层成立**。
> §13.3 明写"Render 层没有脏边界，`relayout()` 永远全树"（M5 才做）⇒ **一帧的真实成本 = 被重建的 build + 全树的 layout + 全树的 paint**。
> 所以在 M5 之前，"订阅点下移"省下的只是 **build**，会被全树 `relayout` 吃掉一大半；只有当页面**很宽很浅**（重建代价 > 全树 layout）时才明显。
> ⇒ **不要把"订阅点下移"当 M2 的性能卖点讲**；它是"正确性 + 为 M5 铺路"，性能收益要等 M5 的脏矩形一起兑现。

### 11.6 明确不做

**运行期自动依赖追踪**（Vue `ref` / SwiftUI `@Observable` / WPF Binding）——两次否决的理由：① 换 C 轴范式（两套"数据变了怎么刷新"语义）；② 代价（包装对象丢标量直读、依赖图维护、与 G3 确定性的张力）。
**长期方向**：编译器推导依赖（Svelte 路线），以 spike + kill criteria 推进，**不承诺**。

### 11.7 异步（与 §17 同一件事的两个面）

单线程消息泵 ⇒ **任何阻塞调用都会卡死整个 UI**（`tetris-win32` 的 `usleep(16000)` 循环里同步 curl，帧就停了）。所以：

- **M1–M3**：同步 `DataSource` + 三态。★ 诚实边界：`loading` 态**画不出来**（同栈内无帧可渲染，见 §11.5 注），所以此阶段的实际表现是"**大响应时界面停顿**，`error` 态可正确呈现"。不要用"先广播一次 loading"来掩盖这一点。
- **M4+**：`TaskHost` 端口接**线程池后端**（§17.2.2），UI 代码**零改动**——Store 只调 `$src->fetchPage()`，换后端是组合根的事。（★ 探针 E 已证物理可行：`std::thread` + `ts_resource(0)`，零扩展。）

---

## §12 多页面怎么组合（重点 9）

> **用户明确要求：不能把所有布局在一个类里实现。** 这是 GUI 仓 A10 反模式的原文（"全局 UI 在一个类里堆 ⇒ 540 行 if-chain"）。下面七条是结构性对策。

### 12.1 七条约定 + 两个原语

1. **一页一文件**：`src/pages/XxxPage.php`，页面 = 普通 Widget 子树，**无框架新概念**。GUI 仓实测："集中一处的只有路由表，页面本体永远一页一文件"。
2. **路由表用 `match`**（不用 class-string 表 / 嵌套数组配置）：

```php
private function pageFor(string $name): ?Widget
{
    return match ($name) {
        'buttons' => ButtonsPage::make($this->store),   // IDE 可跳转 · 零闭包分配 · 仍惰性
        'forms'   => FormsPage::make($this->store),
        default   => null,                               // 未找到 ⇒ 显式出口，不静默空白
    };
}
```

   ★ `match` 编译成跳转表；需要枚举时换 backed enum + **无 `default`** 的 `match`（漏一支 = `UnhandledMatchError`）。

   ★ **两个规模档，不是两套语义**：小应用直接在 `Root` 里 `match`（零分配、零新概念）；需要 **devtools 列举 / 单测 / 动态装配**时才升级为 `RouteTable`（表是数据，值是页面工厂闭包）。二者产出的是同一件事——"名字 → 页面 Widget"，`match` 只是它的最简形态。§12.2 用的是后者。

3. **外壳 + 内容槽**：`Dock(top:, left:, right:, bottom:, center:)`；外壳**持久**（不随页面重建），只有 `center` 槽换；内容区必须可滚动。
4. **`Offstage` 原语**：隐藏但保留挂载——跳过 layout / paint / hitTest，Element / State 全部保留 ⇒ "切走再切回要不要保留状态"的答案是**保留**，且代价为零。
   - ★ 为什么不是 `IndexedStack`：它"全部 layout、只 paint 一个"，而 layout 是性能瓶颈，隐藏页也排一遍是反方向。
   - ★ 为什么不是 `Stack + Opacity(0)`：隐藏页照样参与 layout，且 Stack 取最大子节点 ⇒ 隐藏页能把可见页撑歪。
5. **页面间引用判据**（优先级从高到低）：**① 共享数据源**（各自订阅同一个 Subject，页面互不认识）→ **② 命令**（跨树、要复用、要审计）→ **③ 按 Key 取 State**（命令式、数据表达不了时）。页面参数走共享 store（`selectedId`），**不从根逐层钻参数**。
   - **⚠️ 诚实边界（第二轮评审补）**：这条的代价上一版没写全，两条：
     ① **参数会残留**——导航离开后 `selectedId` 还在 store 里，回到列表页时"上次选中的那行"仍然高亮。要清就得显式清（或在页面 `dispose` 时清），这是**手写的**，框架不管。
     ② **带参页面无法独立预览**——§12.2 宣称的"每页可独立预览"对**不带参页面**成立；带参页面必须先喂一个假 store（`selectedId = 1`）才能挂载。⇒ 工具链的"单页预览"要支持**注入 store 夹具**，这不是免费的。
   - **为什么仍然选这条路**（而不是"从根逐层钻参数"）：钻参数的代价是**每一层中间容器都要知道它不关心的参数**（`Root` → `Dock` → `PageHost` → 页面），改一个参数要动 N 个签名；而残留/预览是**两个局部问题**，各有一个局部解法。⇒ 得失上仍然划算，只是不该当成"纯收益"讲。
6. **导航栈不做**：桌面主形态 = 外壳 + 内容槽 + **模态**（`Application::showModal()`）；只有出现真实"多级钻取"需求才做 Navigator——不需要栈的应用硬上栈 = 多一个必然出错的全局状态。
7. **`PageHost` 与 `RouteTable` 分工**：`RouteTable` 是**数据**（名字→页面工厂，可被 devtools 列举、可单测）；`PageHost` 是**容器控件**（持当前页 Element：换页 = 旧页 `Offstage` 化而非卸载，状态保留）。简单应用在 Root 里直接 `match` 即可。

### 12.2 Root 只做组合（反 A10 的正面样板）

★ **关键纪律：`Root` 传「Subject」，不传「值」。** 读值 = 必须在 build 期订阅，而订阅只允许落在专职订阅控件上（§11.5）。若 `Root` 直接读 `$store->currentPage()`，`StatelessWidget` 不会因 store 变化重建 ⇒ **换页永不发生、状态栏永不更新**（这是上一版样板的错误，本次修正）。

```php
// src/HelloRoot.php —— 只组合，不写任何具体布局
final class HelloRoot extends StatelessWidget
{
    public function __construct(private readonly AppStore $store) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->title('Drift Demo')->children([
                NavButton::make('按钮')->command('nav.buttons'),
                NavButton::make('表单')->command('nav.forms'),
            ]))
            // ★ 传 store + 切片函数，由 StatusBar 内部 select 订阅 —— 值不在这里读
            ->bottom(StatusBar::make()
                ->source($this->store)
                ->select(fn(AppStore $s): string => $s->statusLine()))     // 裁决 D-05
            // ★ 传 store + 切片；PageHost 内部 select 订阅当前页，换页 = 旧页 Offstage 化
            ->center(PageHost::make()
                ->source($this->store)
                ->select(fn(AppStore $s): string => $s->currentPage())
                ->table($this->routeTable()))
            ->overflow(Overflow::clip);
    }

    private function routeTable(): RouteTable
    {
        return RouteTable::make()->route('buttons', fn(): Widget => ButtonsPage::make($this->store))
                                 ->route('forms',   fn(): Widget => FormsPage::make($this->store));
    }
}
```

`StatusBar` / `PageHost` 的形态就是"专职订阅者"（实现 §10.2 第 5 条的 `SubscribingWidget` 接口）：

```php
final class PageHost extends StatefulWidget implements SubscribingWidget   // ★ 接口标记（裁决 D-10）
{
    // ... 内部 State::initState 里 watch(select 出来的切片)，dispose 里全退（§11.3 订阅袋）
    //     notify ⇒ setState ⇒ 只重建 content 槽；旧页走 Offstage 而非卸载
}
```

**判据（可 lint）**：`Root` 里出现具体布局控件（`Column`/`Row`/`Padding`…）= 违规；**`Root` 的 `build()` 里出现 `$store->某方法()` 形态的取值调用 = 违规**（应传 store + `select`）；`Root` 只允许壳控件 + `PageHost` + `match`/`RouteTable`。`src/pages/*.php` 每个文件一个类、一个 `build()`。

**附带收益**：每页可独立预览（工具链只挂载那一棵子树）⇒ 改一页只跑一页（对抗 AOT 20–50s 构建成本）。
（★ 限定见 §12.1 第 5 条：**带参页面**需注入 store 夹具才能预览。）

> **★ 两处修正已落地（第二轮评审）**：
> ① **`statusLine()` / `currentPage()` 的隐患已消除** —— 上一版它们让 `Store` 必须暴露 N 个 `Subject`（"方法名像 getter 实际返回 Subject"），且隐含依赖"每次调用返回同一个实例"（不可 lint）。现在改成**纯函数式的切片函数**（`select`），**没有实例身份问题**，且闭包里的属性集 = **编译期可推导的依赖清单**（§11.3.3）。
> ② **"专职订阅控件"有了类型判据** —— 实现 `SubscribingWidget` 接口（§10.2 第 5 条 / 裁决 D-10）。上一版只有"只能是它"这句话，没有判据，导致 §21 A16 的 lint 无从实现。

### 12.3 状态保留的三档 + 回收纪律（★ 评审新增）

上一版把"虚拟化"只写在 §19 M5 一行，且**没定义它与 `Offstage`、与 `Seal-on-Mount` 的交互**。补三条裁决：

| 档 | 机制 | Element | State | 用在哪 |
|----|------|---------|-------|--------|
| **保留** | `Offstage` | 保留（不卸载） | **保留** | **页面级**切换（§12.1 第 4 条） |
| **回收** | 虚拟化（M5） | 卸载 | **dispose** | 长列表视口外 |
| **丢弃** | 条件分支不渲染 | 卸载 | **dispose** | 常规 `if`/`match` |

★ **关键纪律（M5 的前置条件）**：**虚拟化会 dispose 被回收的 Element 的 State。** 所以任何"看起来像 UI 态、但必须跨回收存活"的东西（**被虚拟化列表的滚动位置、选中项、展开状态、编辑中的草稿**）**必须外置到 Store**，不能放 `State`。

> **⚠️ 措辞限定（第二轮评审修正）**：上一版把"滚动位置"**无条件**列为必须外置，与 §12.1 第 4 条（`Offstage` **保留** State）有张力 —— 页面级切换走 `Offstage`，State 是被保留的，页面滚动位置放 `State` **完全没问题**。
> 正确口径：**只有"被虚拟化回收"的容器的滚动位置**才必须外置（因为它的 Element 会被 dispose）。判据是问**"承载这个滚动位置的那个 Element，会不会被回收？"**，而不是"是不是滚动位置"。

> 这条不是新发明，是 §11.5"State 只持纯 UI 态"的**必然推论**——上一版没说透，导致 M5 做虚拟化时会踩。写成纪律后，判据清晰：**问一句"这个列表项滚出视口再滚回来，它应该记得吗？"** 应该记得 ⇒ 放 Store。

★ **与 `Seal-on-Mount` 的交互：无冲突，但要说清。** 被回收的 Widget 实例**已经 sealed**；重新进入视口时**同一个实例可以再次挂载**（`seal()` 幂等，§15.2 已定，且同一实例复用到多处合法）⇒ **回收再挂载不需要任何特殊处理**。这是 §15.2 删掉 owner 校验的**第二个收益**（第一个是允许 `children([$t, $t])`）。

### 12.4 明确不做（★ 评审新增，防默认漏洞）

§11.6 与 §12.1 第 6 条已有"不做运行期自动追踪 / 不做导航栈"。补齐其余**结构性不做**项——每条给依据与将来要做的代价，避免读者当成漏洞：

| 不做 | 依据 | 将来要做的代价 |
|------|------|--------------|
| **多窗口** | 桌面主形态 = 单窗口 + 外壳 + 模态（§12.1 第 6 条同理）。`Application` 持**单** surface（§7.2） | **结构性**：要改 `Application` / `EventLoop` / `RenderSurface` **三处所有权**（surface 从单数变集合、事件路由要带窗口标识）。不是加个 API 能补的 |
| **原生菜单栏** | 风格割裂（§5.1 裁决）；违反 §18"不把渲染外包给原生工具包" | 小：自绘 `MenuBar` 已覆盖同一场景 |
| **OS 级拖放**（资源管理器 → 窗口） | 需要 OLE `IDropTarget`（`DragAcceptFiles` 只够最简场景），M5+ | 中：`Platform` 加 `dropTarget()` 端口（§5.1 已留注释位）。★ **应用内拖放（控件 ↔ 控件）不受影响**，那是纯框架内的 `Draggable`/`DragTarget`，**M3 就做，零平台端口** |
| **运行期自动依赖追踪** | §11.6 已列（两次否决） | 大：换 C 轴范式 |
| **导航栈** | §12.1 第 6 条已列 | 中：出现真实"多级钻取"需求时再补 Navigator |

---

## §13 布局契约（重点 12）

### 13.1 两阶段 + 六条契约

```
layout(BoxConstraints $c): Size        // 纯函数：约束 → 尺寸 + 子节点相对位置
paint(Canvas $canvas): void            // 父级已 translate 到本盒原点
```

| # | 契约 | 内容 | 出处 |
|---|------|------|------|
| C1 | **单向数据流** | 父 → 子传约束；子 → 父报尺寸；**父定位子**（`Stack/Positioned` 例外也是父驱动，子不自定位） | Flutter/Web 盒模型共识 |
| C2 | **无界 = `+inf`** | `BoxConstraints(maxWidth: INF)`；**永不拿 0 当哨兵**；`constrain()/tighten()` 纯函数 | GUI 仓三次踩坑 |
| C3 | **内禀测量带另一轴** | `intrinsicWidth(float $height)` / `intrinsicHeight(float $width)`（GTK4 口径 `measure(orientation, for_size)`） | Greenfield N1 证伪"measure/arrange 分离是 no-op"后的真实形态 |
| C4 | **Flex 松上界规则** | 第一遍量自然高：格内有**竖直**弹性后代 ⇒ 给**无界高**，否则给 `0..maxH`；**`0` 是合法高度，不能当"无界"** | Grid/Table 实测缺陷（整行被撑成父盒可用高） |
| C5 | **溢出策略是契约** | 每个容器必须声明行为：`clip / scroll / wrap / report`；**外壳类控件（AppBar/StatusBar）必须有溢出策略**，且必须是布局期行为而非宽度阈值 | 窄屏工具栏溢出缺陷 |
| C6 | **违反 ⇒ 响亮失败** | 无界约束传给有内禀尺寸的子、约束违反、溢出未声明 ⇒ `Errors::report`（**Fatal 级，release 也中止**，见 §1.2） | G2 |

### 13.2 Widget → RenderBox 构造契约（RT-2 的根治）

渲染控件提供 `createRenderObject(): RenderBox`；Element 挂载时调用它建盒；`(type,key)` 未变而配置更新时**用新 Widget 重新调用同一函数**造新盒替换。

Widget 不可变（§15 的 Seal-on-Mount 保证）⇒ 参数只会整体换新，**不存在"字段级半同步"**——GUI 仓"两份手写字段赋值 ⇒ 首次正常、刷新变样，**且静默**"因此结构上不可能。字段对齐由 lint 比对 Widget 字段集与 `createRenderObject` 读到的字段集。

### 13.3 relayout 脏边界

Element 层有脏边界（脏边界重建：干净分支一次 build 都不跑）；**Render 层没有**（`relayout()` 永远全树）。列为 **M5+ 与脏矩形成对做**——单独做任一个，瓶颈只是搬到对面（GUI 仓自己定的门禁：等症状）。

---

## §14 布局/渲染确定性，不依赖具体 OS（重点 11）

### 14.1 四条确定性规则

1. **布局是纯函数**：`layout(constraints) → size`，输入只有（Widget 子树、约束、主题、**注入的 `TextMetricsProvider`**）；不读 OS、不读环境时钟、不读随机源；**位置无关**（从不读自身 `ox/oy`——GUI 仓实测 `layout()` 本来就是纯函数，Drift 写成契约）。
2. **渲染是纯函数**：`paint(canvas)` 只依赖（渲染树 + 画布状态 + 主题）⇒ 录制成 DisplayList ⇒ 可重放、可 golden 比对。
3. **时间注入**：动画相位 = f(帧时间戳)；`FrameClock` 在测试里是脚本化的假时钟。
4. **稳定序**：禁止依赖无序 map 的迭代序；排序必须稳定；float 量化点唯一（§6）。

### 14.2 一套测量、两个后端

`TextMetricsProvider` 接口 + 两个实现（SkParagraph 真测量 / 确定性参考实现），conformance 套件互校。

★ GUI 仓的教训：预览的**模拟器**（`preview_sim` 的假换行）与真实排版有**漂移风险**——Drift 的对策不是"模拟得更像"，而是**同一份断行算法**在两侧运行，差异由 conformance 抓住。

### 14.3 确定性的回报

golden 测试、几何普查（多断点 0 溢出）、跨时段 A/B（性能数字可比）、快照可回放进 CI、**预览免费得到**（布局层零 OS 依赖 ⇒ 可在 `php.exe` 直接跑，不编译）。

---

## §15 控件写法：fluent-first + Seal-on-Mount（重点 14）★ 本文核心裁决

### 15.1 问题：链式写法与"Widget 不可变"的矛盾

用户想要的写法（IDE 自动联想、心智负担小）：

```php
Text::make('Hello Drift')->title()
Button::primary('保存')->command('form.submit')
Column::make()->spacing(20)->children([ ... ])
```

**但这里有一个真问题**，既有 Drift 文档没有正面解决：

| 实现方式 | 后果 |
|---------|------|
| `mutate + return $this`（builder 式） | Widget **可变** ⇒ 三树 diff 的"不可变 ⇒ 参数整体换新"前提**破裂** ⇒ GUI 仓 RT-2/A9（"两份手写字段赋值 ⇒ 刷新变样且静默"）复活 |
| `clone`（每次修饰新实例） | 纯、安全，但每修饰一次分配 ⇒ 每帧重建树时分配量 × modifier 数 |

GUI 仓的教训很明确：**"糖有自己的状态路径、与构造器分叉"是最大的坑**。所以不能简单地"再开一套 builder"。

### 15.2 裁决：一个类、一条链、挂载即封存

**结论**：fluent-first，**单一表面**——不搞"构造器 vs 链式"双表面（那正是分叉的来源）。全部 API 活在同一个类上：

```php
namespace Drift\Widget;

abstract class Widget
{
    /** @internal 0 = 可配置（构建期）；1 = 已封存（挂载后） */
    private int $sealed = 0;

    /** 基类构造器 —— 子类必须调 parent::__construct()（§12.2 的 Root 即如此） */
    public function __construct() {}

    /**
     * 挂载时由 Element 调用：把描述封存为不可变。
     *
     * ★ **裁决（§23.3-1）：纯内部方法，不进公共 API。** 标 @internal；§10.1 的
     *   公共命名空间清单不含它，`tools/api-surface.json` 不收录。用户不需要它
     *   —— "挂载时自动封存"已覆盖全部合法场景（理由见 §15.6 末段）。
     *
     * 幂等——同一实例被复用到多处是**合法**的（Widget 是纯描述，各处各有自己的
     * Element），所以这里不做任何 owner 校验。
     */
    final public function seal(): void
    {
        $this->sealed = 1;
    }

    /** 链式修饰的统一入口：封存后写入 ⇒ 响亮失败（Fatal 级，release 也中止，§1.2） */
    final protected function mutate(): void
    {
        if ($this->sealed === 1) {
            Errors::report('Widget 已封存，禁止再修改：' . static::class);
        }
    }
}
```

> **上一版设计的一处错误（本次修正）**：上一版让 `freeze(ownerId)` 在"同一实例挂两处"时报错。探针证明这是**误杀**——Widget 是不可变描述，`Column::make()->children([$t, $t])` 是合法且常见的复用，各处各有独立 Element。owner 校验已删除。

```php
// ★ 裁决（§23.3-2）：哨兵不靠"取值域外的裸数字"，而靠 enum 里的一个显式 case。
//   这样 0 是**合法取值**，"未设置"是它的语义 —— A2（拿合法值当哨兵）从根上消失，
//   且改 enum 的人不可能无意中撞坏哨兵。
enum TextRole: int
{
    case Unset   = 0;   // 未设置 —— 由主题决定（走 ThemeData 的默认文本角色）
    case Title   = 1;
    case Heading = 2;
    case Body    = 3;
    case Caption = 4;
    case Error   = 5;
}

final class Text extends Widget
{
    private string $content;
    private int    $role   = TextRole::Unset->value;   // 语义明确，不是哨兵
    private float  $size   = -1.0;   // -1 = 未设置（float 无 enum；负值域外 ⇒ 安全）
    private int    $bold   = -1;     // 三态：-1 未设置 / 0 否 / 1 是
    private int    $maxLines = 0;    // 0 = 不限（"0 行"无意义 ⇒ 取值域外，非 A2）
    private int    $ellipsize = 0;

    /** 唯一入口 —— 只吃必填项，IDE 打 Text:: 立刻可见 */
    public function __construct(string $content) { parent::__construct(); $this->content = $content; }
    public static function make(string $content): self { return new self($content); }

    // ── 语义角色（backed enum，拼错编译错；字号走主题 metrics，切主题跟随）──
    public function title():   self { $this->mutate(); $this->role = TextRole::Title->value;   return $this; }
    public function heading(): self { $this->mutate(); $this->role = TextRole::Heading->value; return $this; }
    public function body():    self { $this->mutate(); $this->role = TextRole::Body->value;    return $this; }
    public function caption(): self { $this->mutate(); $this->role = TextRole::Caption->value; return $this; }

    // ── 修饰（全部 set + return $this 薄壳，零分支、零缓存、零默认值合并）──
    public function size(float $pt):      self { $this->mutate(); $this->size = $pt; return $this; }
    public function bold(bool $v = true): self { $this->mutate(); $this->bold = $v ? 1 : 0; return $this; }
    public function maxLines(int $n):     self { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): self { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }
}
```

**三条配套纪律**：

1. **`make()` 只吃必填项**，作为**唯一入口**（IDE 打 `Text::` 立刻看到 `make`，打 `->` 全亮）。完整控制仍可走构造器（`new Text(...)` 带命名参数），但**文档只教链式一种**（避免双表面分叉）。
2. **`mutate()` 是链式方法的统一前缀**——不是每方法各写一遍守卫（一个口径一个落点，GUI 仓坑 #7）。
3. **封存后写入 ⇒ 响亮失败**（Fatal 级，release 也中止，§1.2），不是静默忽略。`seal()` 幂等，**同一实例复用到多处合法**（Widget 是纯描述，各处各有独立 Element）——不做 owner 校验。
4. ★ **哨兵值三档定则**（裁决 §23.3-2，消灭 A2）：
   | 字段类型 | "未设置"怎么写 | 例 |
   |---------|--------------|----|
   | **枚举语义** | **enum 里加一个显式 case**（如 `TextRole::Unset = 0`）⇒ 0 是合法取值 | `$role = TextRole::Unset->value` |
   | **float** | 负值域外 `-1.0`（尺寸/位置非负） | `$size = -1.0` |
   | **bool 三态** | `-1` 未设置 / `0` 否 / `1` 是 | `$bold = -1` |

   ⇒ 判据：**哨兵值必须能写成一个"有名字的东西"**（enum case / 负值常量），而不是裸数字。裸 `0` 只允许出现在"该取值本身无意义"处（如 `$maxLines = 0` 表示不限——"0 行"不存在）。

### 15.3 三个方案：实测裁决（含一次被证伪的推断）

微基准 `probes/fluent_cost/probe.php`（`-O2`，N = 300,000 个 5-修饰控件，**三次取中位**；完整数据见 `probes/RESULTS.md §3`）：

| 实现 | 字段 | 守卫 | seal | 中位 | 单控件 | 相对 |
|------|------|------|------|------|--------|------|
| A. clone-with（链式） | public readonly | ✗ | ✗ | 951.5 ms | 3.17 µs | **3.4×** |
| A2. clone-with（类型化局部变量、顺序语句） | public readonly | ✗ | ✗ | 805.0 ms | 2.68 µs | 2.9× |
| E. private，无守卫 | private | ✗ | ✗ | 236.9 ms | 0.79 µs | 0.86× |
| **D+. private + 守卫 + seal（★ 设计真实形态）** | private | **✓** | **✓** | **276.0 ms** | **0.92 µs** | **1.0×** |
| C. 纯构造器（无链，基线） | public readonly | ✗ | ✗ | 195.9 ms | 0.65 µs | 0.71× |

帧预算换算（60 FPS = 16.7 ms）：

| 全量重建规模 | A clone-with | **D+ 本裁决** | 差值 |
|-------------|-------------|--------------|------|
| 300 节点 | 0.95 ms（5.7% 帧） | 0.28 ms（1.7%） | +0.67 ms |
| 1000 节点 | 3.17 ms（19% 帧） | **0.92 ms（5.5%）** | +2.25 ms |

**四条读数**：

1. **链式写法本身几乎免费**——`D+` 比纯构造器只贵 **0.27 µs/节点**（含 5 次链式调用 + 5 次守卫 + 1 次 seal）。"链式写法本身慢"是错觉。
2. **clone-with 贵 3.4×**——因为 `clone($this, [...])` 不编译成内联拷贝，而是"构造 `php::Var` 属性数组 + 运行时调用 + 新对象"（生成 C++ 实证，见 `RESULTS.md §2`）。
3. **类型化局部变量能省 ~16%**（A2 vs A）——链式中间结果落在 `php::Var` 里会丢静态解析，写成顺序语句可恢复（与 §2.2.1 同源）。
4. **运行间方差 ±15%** ⇒ 上表数字只应读作**量级**，不要当精确值引用。

#### 15.3.1 ★ 一次被证伪的推断（本方案的方法论样本）

**v1 探针报"B = 0.68 µs/节点"，并据此断言"守卫在噪声量级内 ⇒ 去掉它省不下任何东西"。这个断言是错的。**

v1 的 `TextB` 是**裸 `set + return $this`**：**没有 `mutate()` 守卫**，字段还是 **`public`**——而设计（§15.2）是 `private` 字段 + 每修饰调 `mutate()`。**被测量的实现 ≠ 被裁决的实现。**

v2 补测（隔离守卫与 seal，三次一致为正的才算）：

| 差值 | run1 | run2 | run3 | 结论 |
|------|------|------|------|------|
| **守卫** `D − E` | +35.9 | +27.3 | +15.7 | **+26.3 ms（+11%）—— 可测量** |
| **守卫**（同含 seal）`D+ − F+` | +28.3 | +35.7 | +22.0 | **+28.7 ms —— 更干净的对照** |
| `seal()` `F+ − E` | +19.8 | +17.5 | **−2.0** | 有正有负 ⇒ **落在噪声内，可视为免费** |

⇒ 修正后的三条结论：

1. **守卫可测量，不是噪声**：约 **0.09 µs/节点（+11%）**。`seal()` 本身**确实几乎免费**（挂载时一次赋值）。
2. **但裁决不变**——理由要换成**比值**而不是"免费"：守卫的 **0.09 µs/节点**，相对 clone-with 为省掉它而多付的 **2.25 µs/节点**，便宜 **25×**。为省一行 `if` 付 25 倍代价，不划算。
3. **绝对量级仍小**：1000 节点全量重建 = 0.92 ms = **5.5% 帧预算**。⇒ 归 §1.2 的 **Diagnostic** 级（值得知道），不是"需要改设计"。

> **这段保留在文档里的原因**：它是 §20 第 3 条"先验后用"要防的典型形态——**把推断写成实测**。
> 同一个错误如果发生在"守卫能省"的方向上，就会直接导致砍掉 G2 的守卫。

**"不用守卫"的唯一办法**是改成 clone-with 语义（每个 modifier 返回新实例，压根不修改 ⇒ 无可守卫）——代价是 3.4× 性能。所以这不是"能不能省"的问题，是"**用 25× 的代价换掉一行 `if`**"是否值得的问题。**答案是：不值得。**

### 15.4 完整 API 形状（对照用户原始想法）

```php
// ── 用户的想法：Text::make('Hello Drift')->title()  ✅ 直接支持 ──

Column::make()->spacing(20)->children([
    Text::make('用户目录')->title(),

    Row::make()->spacing(8)->children([
        TextField::bind($store->query)->name('query')          // ★ 一行 = 值 + 变更 + 订阅（裁决 D-06）
            ->label('搜索')
            ->placeholder('搜索姓名'),
        Button::primary('刷新')->command('users.refresh'),
    ]),

    SubjectBuilder::make($store)
        ->select(fn(UserStore $s): LoadStatus => $s->status())   // ★ 切片（裁决 D-05）
        ->builder(fn(LoadStatus $st): Widget =>
            match ($st) {                                        // ★ 无 default（裁决 D-04）
                LoadStatus::Loading => Spinner::make(),
                LoadStatus::Error   => Text::make('加载失败')->error(),
                LoadStatus::Ready   => ForEach::make(fn(User $u): int => $u->id)   // ★ key 必填（D-03）
                    ->items($store->rows(), fn(User $u): Widget =>
                        Row::make()->spacing(8)->children([
                            Text::make($u->name)->body(),
                            Text::make($u->email)->caption(),
                            Button::danger('删除')->command('user.del')->arg(new CommandArg(i: $u->id)),
                        ])),
                LoadStatus::Idle    => Text::make('尚未加载')->caption(),
            }),
])
```

**命名规则（唯一一套，无例外）**：

| 场景 | 形态 | 例子 |
|------|------|------|
| 造控件 | `Type::make(必填项…)` | `Text::make('hi')` · `Column::make()` · **`ForEach::make(keyFn)`** |
| 修饰属性 | `->修饰名(值)` | `->spacing(20)` · `->value($v)` · `->maxWidth(640)` |
| 语义角色 | `->角色名()`（backed enum，零参数） | `->title()` · `->caption()` · `->danger()` |
| 树结构 | `->children([...])`（**数组形参**，PHP 无尾随闭包） | `->children([$a, $b])` |
| 变体工厂 | `Type::变体(必填项…)`（= `make()` 的薄壳别名，**可转发到同族控件**） | `Button::primary('ok')` · **`TextField::bind($cell)` → `Bound::make(...)`** |
| 事件 | `->onXxx(闭包)` 或 `->command('名')->arg(new CommandArg(…))` | `->onPressed($fn)` |
| **订阅切片** | `->select(fn(Store $s): T => …)`（**T 必须是标量或不可变值对象**） | `->select(fn($s): array => $s->rows())` |

**"糖只转发"的删除测试**：`Button::primary('x')` 必须等价于 `Button::make('x')->variant(ButtonVariant::Primary)`；
`TextField::bind($cell)` 必须等价于 `Bound::make($cell, fn($v) => TextField::make(...))`。
删掉全部变体工厂与角色方法，框架契约与测试**原样完整**（只是更啰嗦）—— **这正是 §10.2 第 6 条"变体工厂可转发到同族控件"的含义**（裁决 D-06）。

### 15.5 心智负担的量化对照（同页面复刻）

| 指标 | 构造器写法（旧） | fluent 写法（本裁决） |
|------|----------------|---------------------|
| `new` 关键字数 | ~14 | ~2（仅 `make()` 内部） |
| 需记忆的命名参数名 | spacing / children / variant / command / arg … | **0**（打 `->` 全亮） |
| 文本角色 | `new TextStyle(role: TextRole::Title)` 现场拼 | `->title()` 一词 |
| IDE 可发现性 | 3/5 | **5/5** |
| 语义角色内建 | 4/5 | **5/5** |

> 依据：GUI 仓 85 处手写 `SizedBox`、67 处内联 `TextStyle`、49 处 `Color::fromRgb`——**能力早有、采用率 0**，因为示例没教。所以 §18 的验收里有一条硬指标：**每个契约能力必须在示例中出现过**（没出现 = 没交付）。

### 15.6 为什么不加 `->build()`（否决记录）

用户提案：链式中间都返回 `$this`，最后用 `->build()` 组装成 Widget：

```php
Text::make('用户目录')->title()->build();
```

**语义方向是对的**（区分"可变构建窗口"与"不可变产物"），但落到 Drift 上有四条成本，且**收益为零**：

| # | 问题 | 说明 |
|---|------|------|
| 1 | **名字与既有概念硬冲突** | `StatelessWidget extends Widget` 已声明 `abstract public function build(BuildContext $ctx): Widget`。若基类再加 `Widget::build(): Widget`（零参）作封存，子类的 `build($ctx)` 就是**不兼容覆盖 ⇒ PHP Fatal error**。必须改名（`seal()` / `freeze()` / `done()`），不能叫 `build()` |
| 2 | **要么到处都是，要么是装饰** | 若强制 ⇒ 每个节点一次（§15.4 那个页面要写 6 个 `->build()`）；若挂载时自动 build ⇒ 它退化成 **no-op**，直接违反 G2"要么不提供、要么必须生效"（声明了却没作用，与 `Container::$constraints` 同类） |
| 3 | **类型强制会翻倍类数量** | 唯一能让"忘记 `->build()`"变成**编译错误**的办法是 Builder 与 Widget 拆成两个类型 ⇒ 45 控件 × 2 类，且"糖只转发"纪律变难守 |
| 4 | **它想解决的问题已有天然边界** | "何时结束可变窗口"在 mutate 语义下由**挂载**回答——Element 采纳 Widget 时自动封存（§15.2）。开发者不需要再声明一次 |

#### 15.6.1 裁决：**不保留 `->seal()`**（评审后改判）

上一版把 `->seal()` 留作"可选的显式句号"。**评审后删除**，理由是它两条宣称的收益都不成立：

| 宣称收益 | 实际 |
|---------|------|
| "省掉挂载时的封存判断" | 挂载时的封存**没有判断可省**——就是 `$this->sealed = 1` 一次赋值。实测 `F+ − E` 三次有正有负（+19.8 / +17.5 / **−2.0**）⇒ **落在噪声内**（`RESULTS.md §3.3`） |
| "便于把不变控件提到 build() 外跨帧复用" | 跨帧复用**不需要 `seal()`**。而且同一实例复用本来就合法（§15.2），`seal()` 幂等 ⇒ 第二次挂载调它也无事发生 |

⇒ 收益 ≈ 0，代价却是把 `seal()` 拖进**永久公共契约**（§10.2：进 `api-surface.json` 就再也不能悄悄改）。

这正好撞在方案自己的 G2 纪律上：**"要么不提供、要么必须生效"**——提供 `->seal()` 但作用 ≈ 0，属于"提供了却没作用"，与 `Container::$constraints`（§10.2 第 4 条点名的死字段）**同一类**。

**裁决**：`seal()` 保持 `final public`（Element 在 `Drift\Widget` 命名空间内需要调），但**标 `@internal`、不进公共面**；用户不需要它，文档不教它。§10.1 的五个公共命名空间清单不含它。

**为什么不能靠 `->build()` 顺手拿到"别名安全"**：别名隐患（`$t = Text::make('x'); children([$t->title(), $t->body()])` 两个都变成 `$t` 终态）在 mutate 语义下**存在**，但 `->build()` 也治不了它（`$t->title()->build()` 与 `$t->body()->build()` 返回的都是"构建时的 $t 快照"，但两次 `->build()` 之间 `$t` 已被覆盖）。**唯一根治办法是 clone-with，而那要付 3.4×（§15.3）**。所以走 lint：

> **lint 规则（M1 新增）**：同一 `children([...])` 数组内出现**两次同一根变量**（`$t->a()`、`$t->b()` 形态）⇒ 报错。这是"可变 builder 别名"的静态形态，可静态检出，无需运行期机制。

---

## §16 工具链（重点 10）

### 16.1 原则（G1 的全面落地）

**编译器知道 ⇒ 构建期做。** 把 GUI 仓"运行期在做而编译器本来就知道"的清单逐项搬移：

| 事 | GUI 仓现状 | Drift |
|----|-----------|-------|
| 哪个 State 变了 ⇒ 更新哪棵子树 | 运行期手写订阅 | 运行期（订阅点下移）+ **远期编译期推导**（spike） |
| 死命令（命令全树无人接管 ⇒ 点了没反应） | ✅ lint `dead-command`（R5） | 继承，并扩为**跨应用级 `CommandRouter`** 的可达性校验（见 `DECISIONS.md` D-01） |
| key 唯一 / 稳定 | 运行期位置匹配 | **构建期校验**（key 是结构事实） |
| 样式最终值 | 运行期每帧解析 | 角色枚举 + `ControlStyle` 值对象；**不做字符串注册表** |
| 盒字段对齐（RT-2） | 约定 | **lint**（Widget 字段集 vs 盒字段集比对） |
| 命名空间分层违反 | 无（平铺 namespace 没法查） | **lint**（§4.3 依赖规则） |
| API 表面漂移 | 无 | **API 快照 CI** |
| **封存后修改 Widget** | 无此概念 | **lint + 运行期断言**（§15.2，本方案新增） |
| **必填 modifier 未调用** | 无 | **lint**（§15.4 命名规则 + §15.2 `make()` 契约） |
| **焦点序混用**（同容器内有/无 `focusOrder`） | 无 | **lint**（§9，本方案新增） |
| **加速键与编辑命令冲突** | 无 | **lint**（§9：`AcceleratorTable` 与 `TextInputClient` 独占键集求交） |
| **跨回收存活的状态放进了 State** | 无 | **纪律 + review**（§12.3；M5 虚拟化的前置条件，静态难判） |
| **`build()` 里读 `Subject` 但不在订阅者内**（静默不更新） | 无 | **lint**（§16.5.3 的 `dead-subscription`，裁决 D-09；判据 = `SubscribingWidget`） |
| **派生值忘了清缓存**（静默旧值） | 无 | **lint**（§16.5.3 的 `stale-derived`，裁决 D-07） |
| **业务引用 `@internal`** | 无（平铺 namespace 无从判定） | **lint**（§16.5.3 的 `internal-leak`，裁决 D-02；error + 可显式豁免） |
| **`sources` 与 `composer autoload` 不一致** | 无 | **lint**（§16.5.3 的 `scan-autoload-drift`，裁决 D-02；SDK 模型独有） |

### 16.2 五件套（全部 PHP 单语言、单一入口）

| 工具 | 职责 | 关键性质 |
|------|------|---------|
| `drift-lint` | 编译期校验：**R1–R12 移植（清单见 §16.5）+ 新增规则**（分层 / 死字段 / 死命令 / key / API 表面 / **封存后修改** / **控件契约 W1–W7**） | **量具双向自检**（该响会响、不该响不误响——"从不报错的检查器与正确输出一样"） |
| `drift-preview` | 无头跑完整 layout + paint（软光栅后端）→ SVG / PNG | **不编译、不依赖 OS**（GUI 仓 V7 实测：layout 可在 `php.exe` 跑通）；支持单页预览 |
| `drift-devtools` | 树转储 / 语义树 / 快照 `{schema,trees,semantics}` / 几何普查（多断点）/ 溢出探针 | 快照是数据 ⇒ 可 diff、可进 CI |
| `drift-conformance` | 合成树夹具 + 期望数据；**新实现必须过套件** | 夹具不依赖示例应用 |
| `golden` | DisplayList 回放比对 + 几何对账 | G3 的验收形态 |

### 16.3 CI 门禁顺序与开发内环

```
CI:      lint → preview → build(tpc) → selftest(conformance) → golden diff
内环:    php -l → drift-lint → drift-preview（单页，秒级，不编译）
提交前:  内环 + tpc build（一个应用）+ conformance
```

- **lint 是构建的前置门禁**（不是事后检查）——AOT 的静默失败只能靠它守。
- **内环不编译**：preview 走软光栅、纯 PHP，可在解释器里跑 ⇒ 对冲 AOT 20–50s 的构建成本（这是 Drift 唯一真实且无法用 API 糖补的差距）。

### 16.4 构建配置（`project.yml` 要点）

**★ 已按真实 schema 校正**（对照 `TypePHP使用手册.md` L187–243 与 `GUI/apps/demo/project.yml` 实测配置）：

```yaml
name: drift-demo
mode: bin
platform: win32                     # ★ 真实键（对照 GUI/apps/demo/project.yml）；上一版样板漏了
version: 0.1.0
php-version: "8.5"
optimize: 2
job: 16                             # ★ 构建并行度 —— 直接砍 AOT 20–50s 的唯一旋钮
cxx-std: c++20                      # Skia 头需要；MSVC 默认 c++17 不够（SkMathPriv 用 <bit>）
build-dir: ../../build/demo         # ★ 每应用一个子目录，见下方陷阱 1
no-console: true                    # yml 键；等价命令行 --no-console

sources:
  - main.php
  - src                             # 本应用（App\<X>\，§4.1）
  - ../../src                       # 共享框架源码（PSR-4 Drift\）
  - ../../cpp-src                   # C++ 桥接 + .stub.php
  - path: src/platform/win32        # 条件 source（手册 L205–208 明确支持）
    if: PHP_OS_FAMILY == "Windows"

# ★ SDK 分发形态（§10.1.2，裁决 D-02）—— 上面是"同仓开发"形态，另两种是：
#   形态 1（SDK 目录）：  - ../drift-sdk/src        # 下载 / 解压 / git submodule
#   形态 2（composer 包）：- vendor/drift/drift/src  # composer require drift/drift
# ★ 三种形态都必须满足：本 `sources` 覆盖的命名空间 == composer.json 的 autoload.psr-4
#   ⇒ 由 §16.5.3 的 `scan-autoload-drift` 守（不一致的症状是"preview 能跑、AOT 编译不过"）

ignore:                             # ★ 顶层键（不是 sources 的子键）；路径同样相对本文件
  - src/platform/macos              # 共享 src/ 里排除异平台目录（§4.5.1 配套）

defines:
  - DRIFT_DEMO_ALL_DECL=<php_drift_demo_all_decl.h>   # 注入 AOT 聚合声明头名

include-paths:
  - ../../cpp/skia/include          # 相对【本文件所在目录】
link-paths:
  - ../../cpp/skia/out/Release-x64
link-libs:                          # ★ 真实键名（手册 L217 确认），不是 link-libs 之外的写法
  - user32.lib
  - gdi32.lib
  - ole32.lib
  - imm32.lib                       # IME
  - comdlg32.lib                    # ★ 文件对话框 —— §5.1 的 dialog() 端口需要
  - shell32.lib                     # ★ 托盘 / 外壳 —— §5.1 的 tray() 端口需要
  - advapi32.lib                    # ★ ICU 时区探测（RegOpenKeyExW）—— 缺则 LNK2019
cxx-flags:
  - /utf-8
  - /MT                             # 静态 CRT，必须与 Skia 预编译包一致（否则 LNK2038）
  - /DUSE_SKIA
```

### 16.4.1 五个必须知道的陷阱（GUI 仓 + 探针 E 实测，方案上一版漏了）

**陷阱 1 —— 两类路径基准不同（最贵的一处）**

| 字段 | 相对什么解析 |
|------|------------|
| `sources` / `include-paths` / `link-paths` / `build-dir` | **本 `project.yml` 所在目录** |
| `cxx-flags` / `ld-flags` / `c-flags` | **链接/编译进程的 cwd**（原样拼进命令行，不经路径解析） |

⇒ §4.5 的 `apps/<app>/project.yml` + 共享 `src/` 布局下，前一类必须写 `../../` 前缀，后一类必须与**调用目录**逐字对齐（GUI 的约定是"从仓库根调用"，`cd <repo-root> && tpc apps/demo/project.yml -O2 -m bin`）。两类混用看着别扭，但它是"不改 cxx/ld-flags"与"exe 落根目录"两条约束下唯一自洽的写法。

**陷阱 2 —— `build-dir` 必须每应用一个子目录**

手册示例写 `build-dir: build`（单应用语境）。多应用下共用同一目录，`build/` 里按 targetName 分键的增量缓存会互相失效 ⇒ **两个应用互相触发全量重编**。故写 `../../build/<app>`。

**陷阱 3 —— 从命名空间内调全局 stub 函数必须写前导 `\`（§2.2 早已记录事实，探针 E 补上机制与报错形态）**

★ **先说明来源**：这条**事实**在 §2.2 的表里已经记着（"PHP 调桥接须写前导 `\`"，出处 GUI 仓 AGENTS.md §4）。
本节新增的是三样它没有的东西：**机制**、**报错形态**、**强制手段**。

stub 文件（`*.stub.php`）里的桥接函数都声明在**全局命名空间**。若从 PHP 命名空间内不加 `\` 直接调：

```php
namespace P;
\thr_spawn(100, 7);     // ✅ 正确
thr_spawn(100, 7);      // ❌ 编译失败
```

tpc **不会注入 decl 头** ⇒ 生成的 `.cc` 缺 `#include <php_<target>_cpp_<stub>_<hash>_decl.h>` ⇒ 报

```
error C3861: "php_thr_spawn": 找不到标识符
```

**报错指向生成的 C++ 而非 PHP 源**，按手册 §12 的排错法也要绕一圈。
机制：decl 头注入依据 `Translator::getDeclarationHeadersForFile()` 的
`symbolCallInFile[$file]` → `symbolDeclInFile[$symbol]`；命名空间内的 `thr_spawn()` 被解析成
`P\thr_spawn`，与 stub 声明的全局 `thr_spawn` 符号对不上 ⇒ 依赖链断掉。

⇒ **Drift 的桥接层纪律**：所有 `platform_*` / `pool_*` 等 stub 函数，调用点一律写 `\`。
（GUI 仓一直是这么写的：`src/Shell/platform.php:161` 的 `\win_create_window(...)`、
`src/App/Application.php:855` 的 `\win_peek_message()` —— 只是没人把这规矩写下来。）
⇒ 这一条应由 §16.1 的 lint 强制（**调用 stub 函数无前导 `\`** 即报错），不靠人记。

**陷阱 4 —— `tpc.exe` 不加载同目录 `php.ini`（`php.exe` 会）⇒ 构建脚本必须设 `PHPRC`（GUI 仓 `5e5c0eb` 就是补这个）**

症状（0.9.4 起）：`Fatal error: Failed to startup module [typephp_tpc]` —— 缺 `mbstring`。
**两件都必须做，缺一不可**：

```bash
export PHPRC="<TypePHP 发行版根目录>"      # ① tpc 找 ini 的地方
# ② 该 php.ini 里的 extension_dir 必须改**绝对路径**
#    ./ext/ 按 CWD 解析，而 tpc 惯例是从仓库根调用 ⇒ 会指向仓库根
```

★ **已验证无效的尝试**（别走弯路）：`tpc -d extension=mbstring` / `tpc -c php.ini` / 只改 `extension_dir` 而**不设** `PHPRC`。

★ **为什么必须写进方案**：**0.9.0 不要求 `mbstring`，0.9.4 起强制** ⇒ **升级发行版会静默破坏既有构建脚本**。这不是"新用户配置问题"，是发行版行为的破坏性变更。
⇒ **Drift 的 `tools/build.sh` 从第一天就设 `PHPRC`**；且 `PHPRC` 与**运行期** exe 的 `PHPRC`（§16.2 内环用另一个，关 opcache）**是两回事**，别混用。

**陷阱 5 —— lib 模式给每个 C++ TU **无条件强制** `/FI<php_<target>_all_decl.h>`（**无 yml 开关**）⇒ 聚合头被提到 TU 最前**

`NativeCommandOptionsTrait.php:99-104`，`isBuildModeLib()` 分支里写死。后果：
聚合头把 `php::operator|&==+*` 模板提到**所有第三方头之前** ⇒ 若 Drift 的桥接头（`cpp-src/*.h`）
写了 `using namespace php`，**Skia 的枚举位运算会重载歧义**（C2666 / C2661 / C2056）。

⇒ **Drift 的永久纪律**：**`cpp-src/` 下的头文件绝不写 `using namespace php;`**，一律写全限定 `php::Int` / `php::Str`。
（GUI 仓为此付了 `0562049` 一个提交；**根因至今未修** —— 上游 0.9.5 的 `/FI` 注入仍无条件 ⇒ 这条纪律是长期的。）

**另有两条曾经"手册与实测矛盾"的项 —— 已探针拍死（2026-10-14，tpc v0.9.5）**

| 特性 | 手册 | GUI 仓注释 | **实测结论** |
|------|------|-----------|------------|
| `include:`（复用公共配置） | L214：支持，按序合并 | "没有 `include`，被静默忽略 ⇒ `sources` 为空 ⇒ `Duplicate function main`" | ✅ **可用**。GUI 的注释是**过时**的——`include` 支持提交于 2026-09-21，而当时的 tpc 更早 |
| `ignore`（排除目录扫描） | L213：可按访问路径排除 | "`sources` 没有排除能力" | ✅ **可用**，能排除被递归扫描的子目录（带对照组的实测） |

**探针证据**（`probes/include-ignore-probe.sh`，五个案子 + 一个对照组，完整数据见 `probes/RESULTS.md §4`）：

| 问题 | 实测 |
|------|------|
| `include:` 可用吗 | ✅ 只有 `include:` 一个键的 yml 成功构建 |
| 被包含文件里的 `sources` 相对谁解析 | ❗ **相对顶层 project.yml 的目录**（**不是**被包含文件自己的目录） |
| 列表合并 | ✅ **整体替换**（`sources: [a,b]` 被 `sources: [a]` 完全替换） |
| 标量优先级 | ✅ **当前文件覆盖被包含文件** |
| `ignore:` 可用吗 | ✅ 可排除子目录（去掉 `ignore` 的对照组里该文件确实被扫进来了） |

★ **最反直觉的是第 2 条**：`include` 解决的是**配置复用**，但被包含文件里的**路径仍按"使用者"的视角解析**。⇒ **共享 base 只有在所有使用方位于同一目录深度时才成立**（`apps/<app>/project.yml` 全部同深度 ⇒ base 里写 `../../src` 对每个 app 都成立）。用法见 §4.5。

⚠️ **顺带抓到一个 tpc bug**：被包含文件的 `sources` 指向一个在顶层目录下**不存在**的路径时，tpc **崩溃**而非报错：

```
exit=255
PHP Fatal error: Uncaught TypeError: TypePhp\Translator::getAbsolutePath():
  Return value must be of type string, bool returned
```

根因：路径不存在时 `realpath()` 返回 `false`，而调用点声明 `: string` ⇒ 未捕获 `TypeError`。
⇒ **共享 base 的路径一旦与使用方深度不匹配，症状是"编译器崩溃"而不是"路径不存在"**。
这条正是 §4.3 第 3 条"目录移动不需要改 `use`"要配的护栏：**`include` 的引入必须配一个路径存在性检查**，否则第一个踩的人会去查编译器 bug 而不是查自己少写了一个 `../`。

**Windows GUI 子系统**：`no-console: true` 让 PE 头 subsystem = 2（WINDOWS），不弹黑框；tpc 会**自动补 `/ENTRY:mainCRTStartup`**（否则 CRT 找不到入口，链接/启动即挂）。代价：无 stdout/stderr，调试只能靠日志文件 / 弹窗 / 附加调试器（手册 §6）。验证：`dumpbin /headers your.exe | findstr subsystem`。

---

### 16.5 规则清单（★ 第二轮评审补 —— 上一版只有编号，从未枚举）

上一版 §10.3 用了 `W3`、§16.2/§19 用了 `R1–R12` 与 `W1–W7`，但**一个都没枚举** ⇒ 读者无法据此实现 `drift-lint`。本节补齐两张表。

#### 16.5.1 `R1–R12`：从 GUI 仓移植的编译期规则

**权威清单**（对照 GUI 仓 `php tools/lint.php --list`，2026-10-14 实测输出）：

| # | 规则名 | 判据（症状） | Drift 处置 |
|---|--------|------------|-----------|
| R1 | `flex-parent` | 弹性项（`Expanded`/`Spacer`）不是 `Row`/`Column` 的**直接**子节点（静默不生效） | 直接移植 |
| R2 | `positioned-parent` | `Positioned` 不在 `Stack` 里（`left/top/…` 被忽略） | 直接移植 |
| R3 | `divider-in-row`（warn） | `Divider` 放在 `Row` 里（吃掉整行剩余宽度） | 直接移植 |
| R4 | `dup-key` | 同层兄弟 `Key` 重复（diff 复用错乱） | 移植，且**升格**：`ForEach` 的 key 改为**必填**（见 `DECISIONS.md` D-03） |
| R5 | `dead-command` | 命令**全树无人接管**（点了没反应） | 移植，并**扩为跨 `CommandRouter` 的可达性校验**（见 D-01） |
| R6 | `grid-unsized` | `Grid` 既没给 `columns` 也没给 `minCellWidth`（列数无从谈起） | 直接移植 |
| R7 | `table-span` | `Table` 某行 `colSpan` 之和超过列数（末尾格子被挤出） | 直接移植 |
| R8 | `intent-unhandled` | 动作全树无人接管（与 R5 **同构的第二条通道**） | ❌ **不移植** —— `Intent` 已删除（§11.1），动作缝只有命令一条通道 ⇒ **与 R5 合并** |
| R9 | `intent-enumeration` | 声明了动作常量却无 `handleIntent` 接管（`match` 漏分支） | ❌ **不移植** —— 同上，无 `Intent` 即无此通道 |
| R10 | ⏭ **GUI 仓尚未落地** | 业务编码知识（复合键分隔符 `"\x00"`）泄漏到 UI 层 | ✅ **移植并落地**（= §11.1 的"State 瘦身判据"）。★ 这是 Drift 相对 GUI 仓的**净增** |
| R11 | `dead-field` | 公开字段声明了但全仓库没人读（★ 未登记=error 挡新债 / 已登记=warn 留痕） | 直接移植 |
| R12 | `render-sync` | 渲染盒的配置属性在 `update()` 里漏同步（Widget 改了它、盒内停在首帧值） | 直接移植（对应 §13.2 的字段对齐） |

> ★ **删掉 `Intent` 的直接收益在这里量化了**：GUI 仓的三条规则（R5 / R8 / R9）在 Drift 里**塌缩成一条**（R5）。少一个概念 = 少两条 lint + 少一套"谁构造/谁携带参数/谁解构"。
> ★ **R10 值得单独说**：GUI 仓把它标为"⏭ 待做成规则"，而 Drift 的 §11.1 已经把**同一条判据**写成了纪律（"业务编码在 State 里出现次数 = 0"）⇒ 这是"设计先行、规则补上"的正面样本。
> ★ 两级严重度（`error` 挡新债 / `warn` 留痕）与白名单机制**一并移植**（R11/R12 各有白名单文件）。

#### 16.5.2 `W1–W7`：控件契约七项（★ 本节补定义）

**来源说明**：`W1–W7` 此前只在 §10.3（`W3`）与 §19 M2 里被口头引用，**方案内从未枚举**，GUI 仓也无对应编号（`grep` 无命中）⇒ 本节按方案**已有的裁决**补出这七项，作为"一个控件从写法到渲染要过的七道检查"。

| W | 检查项 | 判据 | 依据 |
|---|--------|------|------|
| W1 | **单一造盒路径** | 渲染控件**有且只有** `createRenderObject()` 一个造盒入口；不得在 Element 之外 `new` 盒 | §13.2 |
| W2 | **盒字段对齐** | Widget 的每个配置字段都被 `createRenderObject()` 读到（R12 的"Widget 侧"） | §13.2 / §16.1 |
| W3 | **死字段** | 公开参数**声明了就必须被读**（= R11） | §10.3 |
| W4 | **溢出策略已声明** | 每个容器声明 `clip / scroll / wrap / report`；**外壳类控件（`AppBar`/`StatusBar`）必须有** | §13.1 C5 |
| W5 | **布局纯函数** | `layout()` 不读自身 `ox/oy`、不读 OS/时钟/随机源；**无界用 `INF` 不用 0** | §13.1 C2 / §14.1 |
| W6 | **Seal-on-Mount** | 挂载后不得写 Widget；`make()` 契约里的**必填 modifier 已调用** | §15.2 / §15.4 |
| W7 | **违反 ⇒ 响亮失败** | 无界传给有内禀尺寸的子、约束违反、溢出未声明 ⇒ `Errors::report`（**Fatal**） | §13.1 C6 |

> ★ **W1–W7 与 R1–R12 不是两套东西**：R 是"**树级**规则"（看父子关系、看命令可达性），W 是"**控件级**规则"（看单个控件的契约完整性）。W3 = R11、W2 = R12 的镜像 —— 同一个判据从两个视角看。实现时**同一个检查器**，只是报错分组不同。

#### 16.5.3 Drift 新增的规则（第二轮评审裁决 `D-01`/`D-02`/`D-07`/`D-09`/`D-10`）

上一版 §16.1 列了"要做 lint"的意图，但**没有规则名、没有判据**。补齐：

| 规则名 | 判据（静态可判吗？） | 严重度 | 依据 |
|--------|-------------------|--------|------|
| `internal-leak` | **非 `Drift\` 前缀**的代码引用了 `@internal` 类型/方法（**判据 = 标注 + 前缀，不是命名空间白名单**） | **error**；有 `@internal-use-allowed: 理由` 豁免 ⇒ warn | 裁决 D-02 |
| `scan-autoload-drift` | `project.yml` 的 `sources` 覆盖的命名空间 ≠ `composer.json` 的 `autoload.psr-4` 声明 | **error** | 裁决 D-02（SDK 模型独有） |
| `export-surface-drift` | **仅 `-m lib` 形态**：`@api` 面 ⊄ `<target>.stub.php` 的导出面（即某个标了 `@api` 的东西**没进** ABI，或某个 `@api` 签名引用了被 `#[NoExport]` 级联排除的类型 ⇒ **签名不完整**） | **error** | 裁决 D-02（§10.1.2 的探针门配套） |
| `dead-subscription`（= A16） | `build()` 体内出现对 `Subject` 类型字段的方法调用 ⇒ 该 `build()` 所属类必须实现 `SubscribingWidget`，或位于某 `SubscribingWidget` 子树内 | **error** | 裁决 D-09 / D-10 |
| `dead-command`（扩） | ① 全树 `command('x')` 发出的名字 ∈ `CommandRouter` 注册集；② 注册集里的名字**至少被一处发出**（**双向**：死命令 + 死注册） | **error** | 裁决 D-01（R5 扩展） |
| `select-purity` | `select` 闭包的返回类型必须是**标量 / enum / 不可变值对象**（`readonly class`）；出现 `new` 非 readonly 对象 ⇒ 报错 | **error** | 裁决 D-05 |
| `stale-derived` | `Store` 里出现 `??=` 赋值的属性（缓存槽）⇒ 所有会修改其**依赖字段**的方法里必须有 `$this->xxx = null` | **error** | 裁决 D-07 |
| `subscriber-mark` | 声明了 `->select()` / `watch()` 的类必须 `implements SubscribingWidget` | **error** | 裁决 D-10 |
| `nested-subscribe` | 同一容器内混用"每列表项一个订阅"（订阅出现在 `ForEach` 的 item builder 里） | **warn** | §11.3.2 纪律 2 |
| **`nano-hygiene`** | 出现**匿名类** / `yield`（或 `Generator`/`Fiber`）/ `getenv`·`putenv` / `eval`·`include`·`require` ⇒ 报错（★ **为保留"将来可切 `--nano`"的可选性**） | **error** | **§16.6.5**（nano 禁用清单；Drift 现状已全部满足，故零成本） |

> ★ **全部九条都满足 G1 判据**（"编译器知道吗？"）：类型声明、前缀、标注、闭包签名**都是编译期可见的** ⇒ 都该在构建期做，不靠人记。
> ★ **两级严重度 + 白名单**（`error` 挡新债 / `warn` 留痕）沿用 GUI 仓 R11/R12 已验证的机制，**不新发明**。
> ★ **量具双向自检**（§20 第 1 条）：这八条**每条**都要配"已知错误"用例 —— 既验该响会响，也验不该响不误响。

### 16.6 ★ `--nano`（无 VM 原生构建）评估（2026-10-14 用户提示后补研）

**结论先行**：**M0–M2 不启用** `--nano` 作为构建方式；**但采纳它的禁用清单作为 Drift 的源码纪律**（零成本，且**Drift 现状已全部满足**）。
即：**不换运行时，只借用它的"编译器强制"。**

#### 16.6.1 `--nano` 是什么（读手册 §3 + tpc 源码得出）

`--nano` 把 **PHP 源码 + php-nano + PHPX 源码整体编译进产物**，产物**不链接 `libphp`、不含 Zend opcode 解释器**。

| 事实 | 出处 |
|------|------|
| 支持 `mode: bin` 与 **`mode: lib`**；**不支持 `ext`**（显式报错） | 手册 §3 · `Translator.php:657-659` |
| **强制 NTS**：`PHP_ZTS = false`（不把宿主 ZTS 折进产物） | `Translator.php:503-505` |
| **不消费宿主的 PHP/PHPX 运行时**；`getLibraryPaths()` → `[]`；`getLibraries()` → 只剩系统库（`advapi32`/`bcrypt`…） | `SourcePipelineTrait.php:602-606` · `NativeBuildConfigurationTrait.php:202-204 / 238+` |
| **要求 `cxx-std` 必须是 `c++17`**（其它值直接报错） | `Translator.php:661-663` |
| 需要额外 composer 包 **`swoole/php-nano`**（在 typephp 的 `require-dev`，**本机未装**） | `ComposerNativePackage.php:78-82` · `composer.json` |
| `--nano` 与 `--full-static` 互斥（nano 已自带运行时源码） | `Translator.php:664-666` |
| **两层开关**：`--nano` 总是开 `nanoPolicyMode`（语法策略）；只有 `NanoBuildBackend::composesRuntimeSources()` 为真才开 `nanoMode`（换运行时） | `Translator.php:498-507` |

#### 16.6.2 nano 禁止的东西（**编译器强制**，不是 lint）

| 类别 | 具体内容 | 出处 |
|------|---------|------|
| **VM 入口** | `eval` · `include` / `include_once` / `require` / `require_once` | `NanoSyntaxValidationVisitor.php:39-52` |
| **匿名类** | `new class { … }` | 同 `:58-60` |
| **协程 / 生成器** | `Fiber` · `Generator` · `ReflectionFiber` · `ReflectionGenerator` · `yield` · `yield from`（理由："C++17 无标准栈切换 API"） | 同 `:11-16 / 62-67` |
| **shell** | 反引号执行 | 同 `:54-56` |
| **进程 / 环境** | `dl` `exec` `passthru` `popen` `proc_*` `shell_exec` `system` · **`getenv`** `putenv` · `set_time_limit` | `CompilerBase.php:285-337` |
| **网络 / DNS / socket** | `fsockopen` · 全部 `stream_socket_*` · `stream_select` · `gethostby*` `gethostbyname*` `gethostname` · `dns_*` · `header*` · `mail` · `syslog` | 同上 |
| **前缀禁用** | `pcntl_` `posix_` `socket_` `ftp_` `opcache_` | `CompilerBase.php:339-345` |

#### 16.6.3 对 Drift 的**正面价值**（真金白银，三条）

1. **可消除 §10.1.2 形态 B 的一条代价** —— "库构建**仍需 PHP 运行时与 PHPX**（`phpx.lib` 链接期 + `phpx.dll` 运行期）⇒ 不是自包含产物"。
   nano 下 `getLibraryPaths()` 直接返回 `[]`，PHPX 源码编进产物 ⇒ **形态 B 的 `.dll` 有望变成自包含**，部署从"多文件"缩回来。★ 这是 §10.1.2 代价 #2 唯一的已知解法。
2. **把"纪律"升级为"编译器拒绝"** —— 上面那张禁用表是**编译期报错**，不是 lint、不是 Code Review。与 G1（编译器优先）同向。
3. **与 Drift 已有的裁决方向一致** —— D-03（key 必填）/ D-04（enum + 无 `default` `match`）/ D-10（接口标记）/ `#[Immutable]` / typed array 全都在往"编译期可判定"走；nano 是这条路的终点。

#### 16.6.4 对 Drift 的**硬冲突**（三条，都是今天的既成事实）

| # | 冲突 | 依据 |
|---|------|------|
| **①** | **nano 强制 NTS，而 §17 的 TaskHost 建立在 ZTS 之上** —— "`worker` 线程跑分配型 PHP，前置条件 = 入口先 `ts_resource(0)`"（全文 19 处），而 `ts_resource()` 是 **ZTS 专属**机制；nano 把 `PHP_ZTS` 钉成 `false` | `Translator.php:505` · §17.2.1（探针 E 实测） |
| **②** | **nano 要求 `cxx-std: c++17`，Drift/GUI 用的是 `c++20`** —— `--nano requires the C++17 language standard` 是显式报错，不是警告 | `Translator.php:661-663` · §16.4 / GUI `sdk/project.yml` |
| **③** | **nano 禁全部网络/DNS/socket，而 §387 的 TaskHost 后台含"网络"、§924 的数据来源含 HTTP** | `CompilerBase.php:301-337` · §11.6 / §12 |

⇒ **任何一条都足以让 nano 今天不可用**；且 ① 会把 §17 这个**尚未定案**的问题提前锁死。

#### 16.6.5 ★ 裁决与落地

- **不启用**（M0–M2 明确排除，形态 A 与形态 B 都走**非 nano**）。
- **但采纳其禁用清单作为源码纪律** —— 逐条核对 Drift 现状：

| nano 禁用项 | Drift 现状 | 判定 |
|------------|-----------|------|
| `eval` / `include` / `require` | 0 处（`require_once` 0 次） | ✅ 已满足 |
| **匿名类** | 0 处 | ✅ 已满足 |
| **Fiber / Generator / `yield`** | §393 / §1967 明写"**零 Fiber、零协程是刻意选择**"；`yield` 仅在 §93 的"候选"清单里，**未进核心** | ✅ 已满足 —— ★ **但要把 §93 的"生成器 `yield` 候选"这条划掉**（否则留了个将来撞墙的口子） |
| `getenv` / `putenv` | 0 处 | ✅ 已满足 |
| 网络 / HTTP | §387 / §924 **有** | ⚠️ **保留**（nano 兼容性让位于功能） |

  ⇒ **除"网络"外 Drift 已全部满足 nano 的约束** ⇒ 相当于**零成本保留了"未来切 nano"的可选性**。
  新增 lint 规则：**`nano-hygiene`**（禁匿名类 / 禁 `yield`+`Generator`+`Fiber` / 禁 `getenv` / 禁 `eval`+`include`）—— 见 §16.5.3。
- **重估时机**：**§17（线程模型）定案之后**。若 §17 最终不依赖 `ts_resource(0)`（例如改成"worker 只跑纯 C++ 计算、不跑分配型 PHP"），nano 才值得重新评估。

#### 16.6.6 ★ 一个必须记下的陷阱：**过期注释**

`SourcePipelineTrait.php:602-606` 的注释写着 "**Windows Nano deliberately leaves `nanoMode=false`**"，
但 `NanoBuildBackend::composesRuntimeSources()` **无条件 `return true`**（不看平台名）。
⇒ **注释已过期，代码才是真的**：Windows 上 `--nano` **会**开 `nanoMode`。
（这与 GUI 仓踩过的"过时注释"是同一类风险：§16.4.1 表里 `include:` / `ignore` 那条就是被过期注释误导过。）

---

## §17 异步与线程（重点 15）

> **本章结论在 2026-10-14 探针 E 之后被改写**：原判"协程是让 PHP 离开 UI 线程的手段"**因果反了**。
> 现在的裁决是：**异步收进 `TaskHost` 端口，后端用 C++ 线程池（+ TSRM），协程降级为无关**。
> 改写依据见 §17.2.1（含实测数据）。**读本章时请以 §17.2 为准，§17.1 只保留"UI 线程不跑协程"这一半。**

### 17.1 先回答"要不要"

**UI 线程不跑协程。** 理由（实证）：

1. Win32 的消息泵是**阻塞等待**（`WaitMessage` / `PeekMessage`）；把协程调度器塞进同一线程，需要在 `pump()` 里"非阻塞轮询 + 让出"，与"阻塞等待不空转"冲突。
2. GUI 仓全仓零协程、零 Fiber、零线程，且**能跑通**（`tetris-win32`、`markdown-editor` 都是单线程泵）——说明协程不是 UI 的必要条件。
3. 手册 §9.14：**协程入口必须 `catch (\Throwable)`**，异常不能穿透至其它协程或依赖外层 `try/catch`，否则可能 `terminate`。把这条约束引入 UI 热路径是净增风险。

★ 第 3 条在探针 E 之后**反而更容易守**：既然不走协程，这条约束就不进热路径了。

### 17.2 裁决：异步收进 `TaskHost` 端口，两条后端

```php
namespace Drift\App;

interface TaskHost
{
    /** 提交后台任务；完成结果经 postEvent 回 UI 线程 */
    public function submit(callable $job, callable $onDone): void;
}
```

```
        ┌──────────────── UI 线程（唯一）────────────────┐
        │  pump() → EventLoop → 树操作 / 布局 / 绘制      │
        │        ▲                              │        │
        │        │ postEvent(结果)               │ submit │
        └────────┼──────────────────────────────┼────────┘
                 │                              ▼
        ┌────────┴──────────── TaskHost（平台端口）────────┴────────┐
        │  后端 A：**线程池**（C++ `std::thread` + TSRM，已证可用）    │
        │  后端 B：同步三态（M1–M3 默认，永远可用）                   │
        └───────────────────────────────────────────────────────────┘
```

#### 17.2.1 ★ 实测修正（2026-10-14 探针 E，`probes/taskhost_thread/`）

上一版把后端 A 写成"**worker 线程跑协程调度器**，在 `\Swoole\Coroutine\run` 里执行 `$job()`"。
**这个样例不成立**，而且它把因果搞反了：

| 上一版 | 实测 |
|--------|------|
| 后端 A 依赖 Swoole/Swow 扩展 | ❌ **不依赖**。线程后端只用 C++ `std::thread`，零扩展 |
| "协程"是让 PHP 离开 UI 线程的手段 | ❌ 手段是 **TSRM 线程上下文**（`ts_resource(0)`），与协程无关 |
| ⏳ ① tpc 能否创建线程 | ✅ 能。`spawn_return_us=40~60`（worker 要跑 11–13ms）⇒ 不阻塞 |
| ⏳ ② 跨线程队列 / 原子可用性 | ✅ 可用。`mutex`+`deque` 队列 + `std::atomic` 均正常，结果正确送达（`sum=33`，3/3） |
| ⏳ ③ Swoole 在 AOT 产物里的加载方式 | ⚠️ **不再是阻塞项** —— 线程后端不需要它 |
| （新增）④ 线程能否跑 **PHP** | ✅ 能，**前置条件 = `ts_resource(0)`**；4 线程并发亦通过 |

**证据链（探针 E §5.3，3 次逐次一致）**：

| PHP 函数体 | 主线程 | worker（不补 TSRM） | worker（先 `ts_resource(0)`） |
|-----------|-------|-------------------|---------------------------|
| 纯整数算术 | — | **42** ✅ | — |
| 纯标量循环 1000 次 | `499500` ✅ | **499500** ✅ | — |
| 循环拼字符串 64 次 | `64` ✅ | **SIGSEGV 3/3** ❌ | **64** ✅ |
| 同上 × **4 线程并发** | — | — | **okCount 4 / tsrmFail 0 / Σ 256** ✅ |

⇒ 边界不在"是不是 PHP 代码"，而在**"碰不碰 Zend 运行时（分配 / 引用计数）"**；
⇒ **修法是一行**：worker 入口先 `ts_resource(0)`。补了之后多线程同时分配 Zend 字符串也不互踩。

#### 17.2.2 后端 A（修正后）—— C++ 线程池 + TSRM

```php
final class ThreadPoolTaskHost implements TaskHost
{
    public function submit(callable $job, callable $onDone): void
    {
        // C++ 侧：从池里取一个 worker（其入口已调过 ts_resource(0)），
        // 执行 job，结果压进跨线程队列；UI 泵每帧 drain 队列并调 $onDone。
        \pool_submit($job, $onDone);   // stub：pool_* → C++ php_pool_*
    }
}
```

- **UI 侧零改动**：Store 调 `$host->submit(...)`，回调里写 `$this->rows = ...`（走显式 notify），三态由 Store 持有。
- **`ts_resource(0)` 归 C++ 侧管**，是桥接层的职责 —— PHP 开发者不该知道 TSRM 存在。
- **⚠️ 仍未验（不假装已验）**：
  - **闭包跨线程调用**。探针 E 验的是**编译出来的具名函数**（`php_p__thr_php_add` / `php_p__thr_php_concat`），**没有验过把 `$job`（PHP 闭包，带捕获作用域）搬到 worker 线程上调用**。闭包是对象，跨线程调用可能要的不止 `ts_resource(0)`。⇒ **M4 前的探针门**。
  - **`ts_resource(0)` 之后的完整线程安全**。本探针只证明"分配 + 引用计数"可用；AOT 运行时是否还有**非 TLS** 的全局状态（`get_str()` 持久字符串表、`get_persistent_func` 缓存、opcache）在多 worker 下竞争 —— 未验。**线程池正式落地前必须补这一枪。**
- **⚠️ 线程池复用线程时**，线程退出/回收要配 `ts_free_thread()`（一次性进程可省，池化不可省）。

#### 17.2.3 后端 B —— 同步三态（M1–M3 默认）

```php
final class SyncTaskHost implements TaskHost
{
    public function submit(callable $job, callable $onDone): void
    {
        // 单线程直跑：先广播 loading（界面不卡死到不可用），再跑，再广播结果
        $onDone($job());
    }
}
```

**诚实边界**：后端 B 在大响应时**会短暂停顿**（`tetris-win32` 的 `usleep(16000)` 循环里同步 IO，帧就停了）。这是**平台端口两种后端的取舍**，不是 AOT 能力门 —— **换后端是组合根一行的事**：

```php
// bootstrap-win32.php
$app = new HelloApp(new Win32Platform(), new ThreadPoolTaskHost());
```

#### 17.2.4 为什么**不**走协程（结论有变，理由要写清）

协程没被"否决"，是被**降级为无关**：

- 协程解决的是"**一个线程内复用多个 IO 等待**"。TaskHost 要的是"**别占用 UI 线程**"。
  两件事不同 —— 这是上一版把因果搞反的地方。
- 想要"PHP 离开 UI 线程"，直接给线程补 TSRM 即可（已证）；协程既非必要也非充分。
- 想要"UI 线程上不阻塞地做 IO"，那是**事件循环归属**问题（§5.2）：得让 Swoole/Swow 接管整个
  Win32 消息泵。代价极大，而 Windows 上 Swoole 仍在 RC。
- ⇒ **协程留作"未来若需在 UI 线程上复用 IO 等待"的备选**，与 TaskHost 解耦。

**⇒ 对"要不要下 PHP 8.4 用 Swow 验"的回答：不需要。**
要验的东西（PHP 能不能离开 UI 线程）已经验完，答案是"能，且不依赖任何扩展"。
Swow 只支持 8.4 反而会把 Drift 钉在 8.4 —— 为一个**已被证伪的前提**付版本代价。

### 17.3 为什么不用协程做 UI 状态

响应式的"数据变了刷新 UI"与协程的"让出等待"是**两件事**。把 UI 重建放进协程会引入"重建中途让出 ⇒ 树处于半更新态"的新失效模式，与 G3（确定性）冲突。**协程只服务 IO，不服务 UI。**

---

## §18 借鉴总表（八家框架：借什么 / 避开什么）

| 框架 | 借 | 避开 |
|------|----|------|
| **Flutter** | 三树 + 不可变 Widget + `(type,key)` diff + relayout boundary + GestureArena | `BuildContext.of()` 层层嵌套 ⇒ provider soup |
| **SwiftUI** | modifier 链（**§15 的直接来源**）· 环境值（= ThemeScope）· 语义值（`Font.title` 式角色） | 自动失效追踪（失效粒度过粗）；属性包装器 |
| **Qt** | 信号槽解耦 · `sizeHint()+setGeometry()` 两段式 · **QObject 销毁自动断连**（= State 订阅袋） | moc 样板 · QML 运行期绑定引擎 |
| **React** | 单向数据流 · "视图是状态的纯函数" · keyed diff 心智 | 运行期 diff（编译期已知道何必 diff）· 陈旧闭包 |
| **Compose** | slot API（组合优于继承）· 单一状态源 · modifier 链 | 重组范围靠运行期推断 |
| **WPF** | MVVM 三层职责分离 · RoutedCommand（可路由 + 可置灰 + 可审计） | 绑定表达式 + 路径解析（需属性级 diff ⇒ 样板爆炸） |
| **Vue** | 计算缓存 + 版本跳过（形状）· stop 句柄形态 · `v-for` 的控制流语义 | `reactive()` 运行期 Proxy（AOT 做不了）；`v-model` 自动双向（第二套语义） |
| **Aria** | 订阅生命周期纪律 · 相等门控 · **绝不静默** · 列表 diff 契约 · 编号契约 + 一致性套件 | 自动依赖追踪 · 把渲染外包给原生工具包 |
| **HTML/CSS** | 盒模型 + flex/grid **词汇**（Container / Padding / Stack / Grid / Wrap）· stretch 默认 · `mainAxisAlignment`（= justify-content）· GridTrack `fr`/`minmax` · maxWidth 相对约束 · **容器查询** · border-box · 基线对齐 | **margin 合并** · **特异性/层叠** · 全局类名 · 运行期样式表 · **流式布局**（`display:block/inline` 隐式排列 = "两个 Text 横排还是竖排"式魔法，违反 G2） |

> **一句话**：Drift 站在 Flutter 那条路上（三树 + 显式 Key + 显式 setState），**表达层学 SwiftUI 的 modifier 链**，其余框架只借**局部机制**（Qt 的断连、WPF 的分层、Aria 的契约纪律、Svelte 的编译期化），不借范式。

---

## §19 路线图（M0–M5）

| 里程碑 | 内容 | 出口判据 |
|--------|------|---------|
| **M0 骨架** | PSR-4 分层 + 契约文件 + `tools/smoke.php` | 冒烟全绿；依赖方向 lint 有断言 |
| **M1 可显示** | Win32 Platform + RenderSurface(Skia CPU) + Canvas 接口 + 文本栈桥接 + DPI 缩放 + **语言探针门（fluent 微基准已完成，见 §15.3）** | 一个窗口画出「Hello Drift」，分数缩放下不糊 |
| **M2 三树 + 布局** | Element/State/调和 + RenderBox 两阶段布局 + 首批控件 + **fluent-first 全部落地** + **焦点遍历顺序** + **光标形状（命中三方消费）** + **`SubscribingWidget` 接口**（§10.2 第 5 条）+ **property hooks 进 Store**（§11.4.1，D-06 第一步）+ **`ForEach` key 必填**（D-03）+ **`DisplayList` 录制**（D-11） | 布局 conformance 夹具全过；首批控件过 **W1–W7 控件契约**（§16.5.2）；Tab 序有 conformance 夹具；`ForEach` 缺 key ⇒ 构建期报错 |
| **M3 数据缝/多页** | Store 三件套 + SubjectBuilder + **`select()`**（D-05）+ **`Bound`**（D-06 第二步）+ RouteTable/PageHost + **动作缝（`CommandRouter` + 冒泡链，D-01）** + **三态 enum**（D-04）+ 错误边界 + **AcceleratorTable** + **自绘 MenuBar/ContextMenu** + **应用内拖放** + **A16 lint**（D-09） | 局部态与跨页共享两条路径都能写；`Root` 只组合（lint 可查）；Ctrl+S 等加速键可用；`§16.5.3` 的 8 条新规则全部**双向自检**过 |
| **M4 工具链 + 异步** | drift-preview / golden / conformance / drift-lint 齐备 + **TaskHost 线程池后端**（§17.2.2）+ **`notifyChanged` 线程断言**（D-08）+ **ThemeChanged / 深色模式** | 无头 preview + golden diff 可跑；后台 IO 不卡帧；**worker 里 notify ⇒ 响亮失败**；跟得上系统主题切换 |
| **M5 业务底座** | 虚拟化（含 §12.3 的 State 外置纪律）+ 脏矩形 + 错误边界容器 + **WindowStateStore** + Windows UIA 桥接 | 长列表滚动不掉帧；子树崩溃不整树陪葬；窗口位置/尺寸跨启动保留 |

**M1 的探针门（先验后用，任一不过即退化）**：

| 探针 | 若不过的退化路径 |
|------|----------------|
| 命名参数 / `readonly` / backed enum / 箭头函数 | 糖退化为位置参数工厂，契约不动 |
| 链式写法在 AOT 下的分派行为（§2.2.1） | 热路径改类型化局部变量；设计不变 |
| `static` 返回类型（IDE 链式推断） | 用 PHPDoc `@return $this` 替代 |
| ~~线程创建 + 跨线程队列~~ | ✅ **已过（探针 E，§17.2.1）**：`std::thread` 可建、跨线程队列可用、worker 跑分配型 PHP 可行（`ts_resource(0)`），4 线程并发已过。**剩余 ⏳**：闭包（而非具名函数）跨线程调用未验（§17.2.2） |
| 命名参数在 AOT 下的支持度（`Dock(top:, center:)`） | 退显式工厂方法，API 形状不变 |

---

## §20 验收体系

**五段验收**：`lint → preview → build → selftest → golden`。

**三条纪律**：

1. **量具双向自检**：每条规则配"已知错误"用例——既验该响会响，也验不该响不误响。
2. **不留沉默的否决**：每个"不做"都要有结论 + 依据（防重开）。
3. **先验后用**：新 AOT 构造（闭包进类型化属性、嵌套数组、文本拼接、线程、封存）先写探针跑通再进 `src/`——否则得到"lint 全绿、真机 segfault"。

**工效量具（本方案新增，对抗"糖已有、采用率 0"）**：同一个"用户目录页"，测四项：① `new` 关键字数 ② 内联 `TextStyle` 数 ③ 订阅样板行数 ④ **示例最短路径覆盖的契约比例**。

---

## §21 反模式表

| # | 反模式 | 后果 | 正确做法 |
|---|--------|------|---------|
| A1 | 声明了却从未被读的 API | 静默失效，比不提供更糟 | 死字段 lint |
| A2 | 拿合法取值当哨兵（0 当无界 / 未知） | 安静地错 | 取值域外哨兵（`INF` / `null` / `-1`） |
| A3 | 构建期魔法阈值（宽度 < N 折叠） | 改个标签就腐烂 | 布局期行为（Wrap/Scroll）；阈值问**本容器约束宽** |
| A4 | build 期订阅 / 忘了退订 | 订阅暴涨 / 僵尸更新 | 只 initState 订；State 订阅袋 + dispose 全退 |
| A5 | 设同值也广播 | 白重建 | `===` 门控（`setIfChanged`） |
| A6 | 裸 `catch(...){}` 吞回调失败 | 缺陷永久静默 | 写进 `Errors` 出口 |
| A7 | 状态语义只写进某个 else 分支 | 其余取值静默失效（主按钮禁用仍满色） | 状态语义覆盖全部取值，**先判禁用再判变体** |
| A8 | 用 Theme/Inherited 传业务数据 | 变了不刷新 | 业务数据走 Subject + SubjectBuilder |
| A9 | Widget 字段与盒字段两条同步路径 | 首次正常、刷新变样，且静默 | 单一同步函数（`createRenderObject`）+ 字段对齐 lint |
| A10 | 全局 UI 在一个类里堆 | 540 行 if-chain | 一页一文件 + match 路由表；**Root 只做组合** |
| **A11** | **挂载后修改 Widget**（链式误用） | 树状态与描述不一致，静默错乱 | **Seal-on-Mount**：封存后写入 ⇒ 响亮失败（§15） |
| **A12** | **糖自造状态路径**（链式与构造器分叉） | 两套真相源 | 一个类、一条链；`mutate()` 统一守卫；删除测试 |
| **A13** | **在 UI 线程跑阻塞 IO** | 整帧卡死 | `TaskHost` 端口（§17） |
| **A14** | **跨回收存活的状态放进 `State`**（滚动位置 / 选中 / 展开 / 草稿） | 虚拟化回收后静默丢失（滚回原位、选中清空） | 外置到 Store（§12.3） |
| **A15** | **提供收益≈0 的公开 API**（如 `->seal()`） | 永久进入公共契约却不起作用 | 删除它；`seal()` 降为 `@internal`（§15.6.1） |
| **A16** | **`build()` 里读 `Subject` 的值，但该子树不在任何订阅者内**（裁决 `D-09`） | **改了数据界面不动，且不报错** —— 最难查的一类 | 把读值的那层包进订阅控件（`SubjectBuilder` / `Bound`）；**lint**：`build()` 体内出现对 `Subject` 类型字段的方法调用 ⇒ 该 `build()` 必须是 `SubscribingWidget` 或位于其中（§16.5.3） |

---

## §22 术语表

| 术语 | 含义 |
|------|------|
| 三树 | Widget（不可变描述）/ Element（身份 + State 宿主 + 调和）/ RenderObject（几何 + 绘制） |
| 值缝 / 动作缝 | 数据→UI 的唯一通道 / UI→业务的唯一通道 |
| 命令（`command`） | 动作缝的**唯一**载体：字符串标识 + `CommandArg`。★ 上一版的 `Intent` 已删除（§11.1），全篇只有这一个名字 |
| **`CommandRouter`** | 应用级命令注册表（`名字 → CommandHandler` 类）；派发链的**第 ③ 环**（§11.4.3，裁决 D-01） |
| **`CommandArg`** | 命令参数的值对象（`int $i` / `string $s`）；取代上一版的裸 `int`（传不了 string id） |
| Subject | 无 GUI 依赖的观察者基类（通知发出端） |
| SubjectBuilder | 专职订阅控件；`->select(fn)` 提供**订阅粒度切片**（§11.3.3，裁决 D-05） |
| **`Bound`** | 专职订阅控件（`TextField::bind($cell)` 的目标原语）；订阅**随控件自带** ⇒ 消灭"忘记包 `SubjectBuilder`"（§11.4.2，裁决 D-06） |
| **`SubscribingWidget`** | "**专职订阅控件**"的**接口判据**（`SubjectBuilder` / `Bound` / `PageHost` / `StatusBar` 实现它）⇒ 让 A16 的 lint 有判据（§10.2 第 5 条，裁决 D-10） |
| **`select`** | 订阅切片函数；返回值必须是**标量 / enum / 不可变值对象**，相等门控落在它上面（§11.3.3） |
| Cell | State 层单值可观察单元；`setIfChanged` 带 `===` 门控 |
| `createRenderObject` | 渲染控件造盒的唯一入口（Widget → 盒的单一路径） |
| **Seal-on-Mount** | 链式构造后，挂载即封存；封存后写入 ⇒ 响亮失败（§15） |
| ThemeScope | 显式主题 scope 链节点（谁生效查父链，不算特异性权重） |
| Offstage | 隐藏但保留挂载（跳过 layout/paint/hitTest，State 保留） |
| DisplayList | 录制下来的绘制命令序列（数据 ⇒ 可重放 / 可快照 / 可 golden） |
| 逻辑单位 / DIP | 密度无关像素；布局 / 控件 / 主题的唯一语言 |
| dpr | `devicePixelRatio = dpi / 96`；逻辑→物理的唯一定量化边界 |
| TaskHost | 后台任务端口；两条后端（**线程池** / 同步三态）（§17.2） |
| **AcceleratorTable** | 应用级快捷键表；在**焦点之前**拦截 `KeyEvent`（§9.1） |
| **CursorShape** | 鼠标指针形状的 backed enum；由 `RenderObject` 声明、命中链取最深者（§9） |
| **ThemeChanged** | 系统主题变更事件（深色模式 / 高对比度）；与 `DpiChanged` 同通道（§9.2） |
| **WindowStateStore** | 窗口位置/尺寸/最大化状态的持久化；放 `Drift\App`，不进 `Platform`（§5.4） |
| **哨兵三档定则** | 未设置怎么写：枚举语义 ⇒ 加显式 enum case；float ⇒ 负值；bool ⇒ 三态（§15.2 纪律 4） |
| **Errors 三级** | Fatal（release 也中止）/ Recoverable（release 降级）/ Diagnostic（只记日志）（§1.2） |
| RT-N | 运行期红线编号（订阅生命周期 / 盒属性同步 / 通知异常隔离 / 相等门控…） |
| ⏳ 待验 | 未实测的设计点——落地前必须先探针 |

---

## §23 评审结论与决断记录（2026-10-14）

第一轮评审报告见同目录 **`REVIEW.md`**，第二轮见 **`REVIEW-2.md`**，逐项裁决的得失分析见 **`DECISIONS.md`**。本节记录**已落地的修正**与**逐条决断**——第一轮的 P0/P1/P2 项已在本方案内定案（§23.2 / §23.3），只剩 §23.7 的三个真正开放项；**第二轮的发现见 §23.9，其 11 项裁决见 §23.10**。

**一句话总结本轮**：架构选型不变；改了 **8 处事实性错误**（含 `project.yml` schema、两处自相矛盾、一处跑不通的样板、一处假结论），补齐 **11 项桌面刚需**（其中 **6 项零平台端口成本**），用探针**推翻了一条自己的推断**、**平反了两条被误记为"不可用"的构建能力**，并顺手抓到 **1 个 tpc bug**。

### 23.1 本轮已修正（事实性 / 自相矛盾）

| # | 位置 | 修正 |
|---|------|------|
| 1 | §16.4 | `project.yml` 按**真实 schema** 重写：补 `job:` / `defines:` / 真实的 `link-libs` 清单；`build-dir` 改每应用子目录；**新增 §16.4.1 两个陷阱**（cwd vs yml 双路径基准；`include:` 与 `ignore` 的手册-实测矛盾，标为必须先探针） |
| 2 | §12.1 / §12.2 | 消除"不用闭包表" vs 样板用闭包表的矛盾；明确 `match` 与 `RouteTable` 是**同一决策的两个规模档** |
| 3 | §12.2 | `Root` 样板改为**传 Subject 不传值**（原样板读 `$store->currentPage()` 但 StatelessWidget 不会重建 ⇒ 换页永不发生） |
| 4 | §11.5 / §11.7 | 删掉"骨架先可见"的错误措辞，改为诚实边界：**同步后端下 `loading` 态画不出来** |
| 5 | §1.2 | 新增 **`Errors::report` 三级定义**（Fatal / Recoverable / Diagnostic），统一 §5.3 与 §13.1 的矛盾口径；§15.2 守卫定为 Fatal |
| 6 | §15.3 | 补**证据强度声明**：探针 B 无守卫、字段为 public ⇒ 原"守卫在噪声量级内"是推断不是实测 |
| 7 | §15.2 | 补 `Widget::__construct()` 与 `Text::__construct()`（原样例不可编译，且 §12.2 调了 `parent::__construct()`） |
| 8 | §0 / §16.1 | 图数量 4→6；修 §16.1 对 §15.3 的错位引用 |

### 23.2 已决 —— 能力缺口（P2）

上一版**全篇 0 次提及**的桌面刚需，逐条裁决。**原则：能靠"零平台端口"解决的，就不加端口。**

| 能力 | 裁决 | 落点 | 依据 / 方案 |
|------|------|------|-----------|
| **键盘快捷键 / 加速键** | ✅ **做** | `Drift\Input\AcceleratorTable` + §9.1 路由链第 ① 环 | **不加平台端口**（加速键是应用级数据，平台只需把 `WM_KEYDOWN` 规范化成 `KeyEvent`——已有）。两条冲突规则：IME 组合期不吃快捷键；`TextInputClient` 可声明独占键集（`Ctrl+A/C/V/X/Z`） |
| **菜单栏 / 上下文菜单** | ✅ **做，但自绘** | `MenuBar`/`Menu`/`MenuItem`/`ContextMenu`（普通 Widget） | **零平台端口**。原生菜单栏**不做**（风格割裂 + 违反 §18"不把渲染外包给原生工具包"） |
| **鼠标指针形状** | ✅ **做** | `WindowHost::setCursor(CursorShape)` | 窗口级能力，与 `setCapture()` 同族 ⇒ 进 `WindowHost`，**不新增端口**。命中测试改为**一次命中三方消费**（事件 / 语义 / 光标） |
| **系统主题变更** | ✅ **做** | §9.2；`Screen::systemTheme()` | **不新增端口**：复用 `screen()` 通道，把 `WM_SETTINGCHANGE` 规范化成 `ThemeChanged`，与 `DpiChanged` 同路 |
| **焦点遍历顺序** | ✅ **定义** | §9 表格 | 默认文档序（深度优先）；容器 `->skipFocus()`；定制 `->focusOrder(int)`。★ **lint**：同一容器内混用有无 `focusOrder` ⇒ 报错 |
| **窗口状态持久化** | ✅ **做** | §5.4 `Drift\App\WindowStateStore` | **不进 `Platform`**（不是平台能力，是应用生命周期）。启动/退出各一次同步 IO，不需要 `TaskHost` |
| **列表虚拟化** | ✅ **做（M5），并补前置纪律** | §12.3 | 关键推论：**虚拟化会 dispose State ⇒ 跨回收存活的状态（滚动位置/选中/展开/草稿）必须外置到 Store** |
| **应用内拖放**（控件 ↔ 控件） | ✅ **做（M3）** | `Draggable` / `DragTarget` | 纯框架内，**零平台端口** |
| **多窗口** | ❌ **明确不做** | §12.4 | 桌面主形态 = 单窗口 + 外壳 + 模态。将来要做的代价是**结构性**的（改 `Application`/`EventLoop`/`RenderSurface` 三处所有权） |
| **原生菜单栏** | ❌ **明确不做** | §5.1 / §12.4 | 风格割裂；自绘 `MenuBar` 已覆盖同一场景 |
| **OS 级拖放**（资源管理器 → 窗口） | ❌ **明确不做（M5+）** | §5.1 留 `dropTarget()` 注释位 | 需 OLE `IDropTarget`。★ 与"应用内拖放"是两件事，后者 M3 就做 |

### 23.3 已决 —— 语义未定（P1 剩余）

| # | 项 | **裁决** | 依据 |
|---|----|---------|------|
| 1 | `seal()` 的 API 地位 | **纯内部方法，不进公共面。同时删除 `->seal()`。** | 它两条宣称的收益都不成立（§15.6.1）：挂载时的"封存判断"就是一次赋值、实测在噪声内（`F+ − E` = +19.8/+17.5/**−2.0**）；跨帧复用本来就不需要它。提供它却作用≈0 = §10.2 第 4 条点名的"死字段"同类 |
| 2 | `TextRole` 取值域 | **`TextRole::Unset = 0` 作为显式 enum case**（不是"value 从 1 起"） | 让 0 成为**合法取值**，"未设置"是它的语义 ⇒ **A2 从根上消失**，且改 enum 的人不可能无意撞坏哨兵。配套：§15.2 纪律 4 给出**哨兵三档定则**（enum case / 负值 / bool 三态） |
| 3 | `Paragraph` 所有权 | **归 `Drift\Text`；从 `GpuContext` 摘除 `createParagraph`** | `GpuContext` 的职责是设备/队列所有权，"排版不是 GPU 的事"。`Paragraph` 是**值对象**（非句柄），由 `TextLayout::layout()` 生产；**缓存在 `TextMetricsProvider` 实现内部**（§8.1 的注入点），Skia 的段落对象是私有实现细节 |
| 4 | 补测带守卫的 B | ✅ **已补测（§15.3.1）** | 结果**推翻了原结论**：守卫可测量（+11%），不是噪声。**裁决不变但依据换成比值**（25×）。详见 §23.4 |

### 23.4 本轮补测：探针 v2（★ 一次被证伪的推断）

`probes/fluent_cost/probe.php` 已重写，隔离**守卫**与 **`seal()`** 两个变量，三次运行（完整数据 `RESULTS.md §3`）：

| 差值 | run1 | run2 | run3 | 结论 |
|------|------|------|------|------|
| **守卫** `D − E` | +35.9 | +27.3 | +15.7 | **+26.3 ms（+11%）⇒ 可测量** |
| **守卫**（同含 seal）`D+ − F+` | +28.3 | +35.7 | +22.0 | +28.7 ms（更干净的对照） |
| `seal()` `F+ − E` | +19.8 | +17.5 | **−2.0** | **噪声内 ⇒ 可视为免费** |

⇒ **§15.3 原话"守卫在噪声量级内 ⇒ 去掉它省不下任何东西"被证伪。** 修正为：

1. 设计真实形态（`D+`）**0.92 µs/节点**——v1 报的 0.68 µs **低估 ~32%**（漏了守卫、用了 `public` 字段）。
2. 守卫 **0.09 µs/节点（+11%）**，可测量。
3. **裁决不变**（clone-with 仍贵 3.4×），但理由换成**比值**：守卫 0.09 µs vs clone-with 为省掉它多付的 2.25 µs ⇒ **便宜 25×**。
4. 绝对量级仍小：1000 节点 = 0.92 ms = **5.5% 帧预算** ⇒ Diagnostic 级，不需要改设计。

> **保留这段的原因**：它是 §20 第 3 条"先验后用"要防的典型形态——**把推断写成实测**。同一个错误若发生在"守卫能省"的方向上，就会直接导致砍掉 G2 的守卫。

### 23.5 本轮补测：`include:` / `ignore:`（★ 平反两条被误记为"不可用"的能力）

GUI 仓的注释写着"tpc 没有 `include`（被静默忽略）"与"`sources` 没有排除能力"，与手册 L213–214 直接矛盾。查源码发现 `include` 的支持提交于 **2026-09-21**，而 tpc.exe 构建于 **2026-10-13** ⇒ 二进制应当已含。**探针（五个案子 + 一个对照组，tpc v0.9.5）拍死**：

| 问题 | 实测 |
|------|------|
| `include:` 可用吗 | ✅ **可用**（GUI 注释过时） |
| 被包含文件里的 `sources` 相对谁解析 | ❗ **相对顶层 `project.yml` 的目录**，不是被包含文件自己的目录 |
| 列表合并 | ✅ 整体替换（不深合并） |
| 标量优先级 | ✅ 当前文件覆盖被包含文件 |
| `ignore:` 可用吗 | ✅ 可用（对照组证明信号有效） |

⇒ **§4.5 因此新增 §4.5.1**：共享 `base.yml` 是可行的，但有三条硬约束（同深度前提 / 列表整体替换 / 路径写错会让 tpc 崩溃）。GUI 仓那套 `yml-parity` 断言可以退役。

⇒ ⚠️ **顺带抓到 1 个 tpc bug**：被包含文件的 `sources` 指向不存在的路径 ⇒ `Uncaught TypeError: getAbsolutePath(): Return value must be of type string, bool returned`（`exit=255`），而非干净错误。已记入 §16.4.1，并要求 `include` 的引入**配路径存在性检查**。

完整数据：`probes/RESULTS.md §4`；复现：`bash probes/include-ignore-probe.sh`。

### 23.6 本轮补测：探针 E —— TaskHost 的线程能力（★ 又一次因果被推翻）

§17.2 上一版把"后端 A"写成 **worker 线程跑协程调度器（`\Swoole\Coroutine\run`）**。
新探针（`probes/taskhost_thread/`，`-m bin` + `std::thread`，跑 3 次逐次一致，完整数据 `RESULTS.md §5`）拍死：

| 问题 | 实测 |
|------|------|
| 起线程是否阻塞主线程 | ✅ `spawn_return_us=40~60`（worker 要跑 11–13ms） |
| 两线程是否真并发 | ✅ 主线程自旋 `355867~432054` 次的同时 worker 推进 `19532` 个进度块 |
| 跨线程结果队列 | ✅ `pair` 顺序不定而 `sum=33` 恒定（并发的旁证） |
| worker 跑**纯整数** PHP | ✅ `42` |
| worker 跑**纯标量循环** PHP | ✅ `499500` |
| worker 跑**分配字符串** PHP（不补 TSRM） | ❌ **SIGSEGV，3/3** |
| 同上，worker 入口先 `ts_resource(0)` | ✅ `64` |
| 同上，**4 线程并发**各补 TSRM | ✅ `okCount 4 / tsrmFail 0 / Σ 256` |

⇒ **三条修正**：

1. **因果反了**。让 PHP 离开 UI 线程靠的是 **TSRM 线程上下文**（`ts_resource(0)`），
   **不是协程**。协程既非必要也非充分。
2. **不需要任何扩展**。线程后端只用 C++ `std::thread` + `std::mutex` + `std::deque`。
   §17.2 的 ⏳③"（Swoole 在 AOT 产物里的加载方式）"**不再是阻塞项**。
3. **边界在"碰不碰 Zend 运行时"**，不在"是不是 PHP 代码"。
   负对照保持诚实：不补 TSRM 的同一段代码在同一进程里仍段错误（`EXIT=139`），崩点在补 TSRM 那一步**之后** ⇒ 变量只有一个。

⇒ **§17.2 后端 A 改写为"C++ 线程池 + TSRM"**（§17.2.2）。**遗留一项未验**：
`ts_resource(0)` 之后的**完整**线程安全（AOT 运行时的非 TLS 全局状态是否竞争）——
本探针只证明"分配 + 引用计数"可用，**线程池正式落地前必须补这一枪**。

⇒ ⚠️ **tpc 陷阱的补充（★ 收窄表述）**：命名空间内调全局 stub 函数**必须写前导 `\`** 这条事实，
**§2.2 的表里早就有了**（"PHP 调桥接须写前导 `\`"）。探针 E 新增的只有三件：**机制**
（decl 头注入依赖 `symbolCallInFile`→`symbolDeclInFile`，命名空间内解析成 `P\thr_spawn` ⇒ 依赖边丢失）、
**报错形态**（`C3861` 指向生成的 `.cc`，不是 PHP 源）、**强制手段**（应由 lint 守，不靠人记）。
详见 §16.4.1 陷阱 3。★ 这条留在这里当方法论提醒：**"我撞上了" ≠ "文档没有"**。

### 23.7 仍未决（真正的开放项）

| # | 项 | 为什么还没定 |
|---|----|------------|
| 1 | ~~TaskHost 协程后端的可行性~~ | ✅ **已验（§17.2.1，探针 E）**：三个 ⏳ 全部拍死 —— 线程可建、跨线程队列可用、**不需要任何扩展**。后端 A 从"协程"改为"**C++ 线程池 + TSRM**"，且**已证 4 线程并发跑分配型 PHP 可行**。**遗留一项**：`ts_resource(0)` 之后的**完整**线程安全（非 TLS 全局状态是否竞争）未验，线程池正式落地前必须补 |
| 2 | `D:\TypePHP\Drift` 是否由 `Drift2` 取代 | ✅ **已决（用户 2026-10-14 拍板：Drift2 取代）**。处置见 §23.8 |
| 3 | ICU 打包策略 | §8.3 给了三档（打包进 exe / 退最小实现 / 自实现禁则），需按体积预算选 |

> ~~`include:` / `ignore` 是否可用~~ —— **已探针拍死（§16.4.1）**：两者都可用；附带发现一条"被包含文件的路径按使用者视角解析"的硬约束，与一个 tpc 崩溃 bug。

### 23.8 `D:\TypePHP\Drift` 的处置（用户已拍板：由 Drift2 取代）

**现状核查（2026-10-14）**：`D:\TypePHP\Drift` 是一个 **git 仓，`master` 分支零提交** ——
只有工作区文件，无任何历史。内容为 `DESIGN.md`（86KB）、`DRIFT-REDESIGN-V2.md`、`DRIFT-REDESIGN-V3.md`、
`WIDGET-ERGO-PLAN.md`、`DATA-SEAM-PLAN.md`、`FLUENT-VS-FRAMEWORKS-BENCH.md`、
`DRIFT-VS-GUI-COMPARISON.md`、`src/`、`tools/`。

**处置**：

| 项 | 动作 |
|----|------|
| 代码 / 方案文档 | **不迁移**。`Drift2/DESIGN.md` 已吸收其全部有效裁决，且带探针实证 |
| 价值在于"历史推理过程"的文档 | 若需要，**归档**（`Drift/_archive/` 或移出工作区）即可 —— 零提交 ⇒ 无 git 历史可依赖，**别指望从它恢复** |
| 后续所有开发 | **只在 `Drift2/` 进行** |

⇒ **纪律**：**不要把 `D:\TypePHP\Drift` 当"上一版实现"参考** —— 它没有提交、没有测试、没有构建脚本，
它的结论凡与 `Drift2/DESIGN.md` 冲突，一律以 **`Drift2`（有探针背书的那一份）** 为准。

### 23.9 第二轮评审（2026-10-14 晚，`REVIEW-2.md` + `DECISIONS.md`）

**一句话**：第一轮修的是"事实错误 + 能力缺口"；第二轮暴露的是"**机制被命名了，但没有被定义**"。

**已直接补进本文的（= 设计已定、文档遗漏，无需裁决）**：

| # | 位置 | 补充 |
|---|------|------|
| 1 | §5.1 | `surface(object $window)` → `surface(int $windowHandle)`（同节纪律 1 要求"只传原语"） |
| 2 | §11.1 | **删除幽灵术语 `Intent`**（3 次提及 / 0 次使用）⇒ 动作缝只有 `command` 一个名字；顺带使 GUI 仓的 R5/R8/R9 **塌缩成一条**（§16.5.1） |
| 3 | §11.3.1（新） | 三种"变了"的消歧表（`notifyChanged` / `setState` / `setIfChanged`）+ **`Cell` 使用样例**（上一版 0 样例） |
| 4 | §11.3.2（新） | 两条纪律推论：**订阅控件不得跨 `build()` 缓存**；**订阅粒度不得细到每列表项一个** |
| 5 | §11.5 | 补诚实边界：**"订阅点下移省的是 build，不是 layout"**（§13.3 relayout 全树，M5 前收益打折） |
| 6 | §12.1 第 5 条 | 补"页面参数走全局 store"的两条代价（**参数残留** / **带参页面无法独立预览**） |
| 7 | §12.3 | 限定"滚动位置必须外置"的适用范围（**只有被虚拟化回收的容器**；`Offstage` 保留的不算） |
| 8 | §16.2 / §16.4 / §19 / §10.3 | 补 `platform: win32` 与 `ignore:`；`R1–R12` / `W1–W7` **从编号变为枚举**（见下） |
| 9 | §16.5（新） | **`R1–R12` 权威清单**（对照 GUI 仓 `tools/lint.php --list`）+ **`W1–W7` 控件契约七项**（本方案补定义） |
| 10 | §22 | 术语表删 `Intent`，加"命令"条目 |

**第二轮 11 项设计缺陷的处置**：动作缝机制（D-01）· 公共面按标注而非命名空间（D-02）· `ForEach` key 必填（D-03）· 三态改 backed enum（D-04）· 订阅粒度 `select()`（D-05）· 表单绑定 `Bound` + property hooks（D-06）· 派生值 computed 的落点（D-07）· `notifyChanged` 的线程断言（D-08）· 反模式 A16 与 lint（D-09）· "专职订阅控件"的判据（D-10）· DisplayList 是否总录制（D-11）。
> ⇒ **全部 11 项已于 2026-10-14 晚裁决并落地，见 §23.10。**

### 23.10 第二轮裁决落地（2026-10-14 晚，用户拍板：**全部按推荐执行**）

| # | 裁决 | 落地位置 | 关键取舍 |
|---|------|---------|---------|
| **D-01** | 动作缝 = **冒泡链 + 应用级注册表** | §11.4.3 · §4.2（新 `Drift\Commands`）· §16.5.3（`dead-command` 双向）· §22 | 唯一能同时满足"跨树复用"（裁决 2）与"页面就近接管"（样例）的方案；且让方案**已写出但从未解释**的 `bool` 返回值有了含义（"我处理了，停止冒泡"）⇒ 不犯 §10.2 第 4 条（声明了没被读的 API） |
| **D-02** | 公共面 = **按 `@api` 标注**（默认 `@internal`）+ **分发形态三选一** | **§10 整章重写**（§10.1.1~10.1.4）· §4.1 · §4.3 · §10.2 第 7 条（新）· §16.5.3 · §16.4 | 拆出**三个面**：物理边界（PSR-4 前缀）/ 架构分层（命名空间 + §4.3）/ 公共契约面（标注 + 快照）。上一版的错是**用命名空间表达可见性** —— 而 PHP 没有"包内私有"，这个模型根本不成立（`seal()` 豁免是第 1 次，组合根违规是第 2 次）。<br>★ **用户提示后补研 `-m lib`**：它有**编译器强制**的 ABI 排除（`#[NoExport]` + 自动生成的 `<target>.stub.php`）⇒ 物理隔离在 lib 形态下**不必靠 lint**。⇒ 裁决为**两种形态并存**：源码同编（默认/开发，单 exe + 性能上限）/ `-m lib`（SDK 分发，应用侧**不需要 Skia 工具链**）。新增 `scan-autoload-drift` + `export-surface-drift` 两条规则，并挂 **M1 前的 `-m lib` 探针门**。<br>★★ **用户再提示后（GUI 仓实证）**：`-m lib` **已被 GUI 仓端到端跑通**（spike `372 ok / 1 fail` = `-m bin` 基线），上游 master **已修** D2/D3/D5/D6 ⇒ 形态 B 从"纸面可行"升级为"**实测可行**"，探针门 **6 问缩到 3 问**（见 §10.1.2）。★ 两处必须写准：① GUI 仓的补丁是 **PR #166、`6c466d91`，未被合入**（上游另起实现 `89fe80aa`）；② **发行版 `tpc.exe` v0.9.5 不含该修复**（生成物 diff 实测）⇒ **形态 B 必须用源码树 tpc**。新增两条永久纪律：C++ 桥接头**绝不 `using namespace php`**（D1 根因未修）· `build.sh` **从第一天就设 `PHPRC`** |
| **D-03** | `ForEach::make(keyFn)` **必填** | §11.5 · §15.4 · §16.5.1（R4 升格） | G1 判据成立（`fn(User $u)` 的类型就在签名里）；"按位置"从**默认**变**显式选择**（`fn($item, int $i): int => $i`） |
| **D-04** | 三态 = **backed enum + 无 `default` 的 `match`** | §11.5 | §12.1 第 2 条**自己的建议**；修掉 `default => rows` 的静默兜底（= A7） |
| **D-05** | `SubjectBuilder::select()` **切片** | §11.3.3（新）· §11.5 · §12.2 · §15.4 · §16.5.3（`select-purity`） | 上一版不是"没做"，是"做了个坏版本"（`statusLine()` ⇒ Store 暴露 N 个 Subject + 依赖实例身份且不可 lint）；`select` 闭包的属性集 = **编译期可推导的依赖清单** |
| **D-06** | property hooks（M2）→ **`Bound`**（M3），**纪律不动** | §11.4.1/11.4.2 · §10.2 第 6 条（澄清）· §15.4 · §4.2 · §22 | 字段名 3 次 → **1 次**（且是属性 ⇒ 拼错 = 编译错误）；10 字段 30 个字面量 → **0**。`bind()` 是 `Bound` 的**变体工厂**（转发到**同族控件**）⇒ 仍是纯转发 ⇒ **不需要为糖改纪律** |
| **D-07** | 派生值 = **缓存 getter + lint**（**不做 `Computed` 原语**） | §11.3.4（新）· §16.5.3（`stale-derived`） | §11.6 不做自动追踪 ⇒ `Computed` 要**手写依赖清单**，与"手写脏标记"是同一类风险却多一个原语；且 `select` 已拿走它"可单独订阅"的收益 |
| **D-08** | 线程断言 = **`notifyChanged()` 入口 + `setState()`** | §5.3 第 3 条 | 上一版有个**真漏口**：非控件订阅者在 worker 线程上跑，**无任何断言**。成本 = 一次整数比较。M4 落地 |
| **D-09** | 反模式 **A16** + 页面级 lint | §21 · §16.1 · §16.5.3（`dead-subscription`） | A4（订了但时机错）与 A16（该订没订）是**同一枚硬币的两面**；`drift-lint` 已有树结构分析能力（R1/R2 先例） |
| **D-10** | "专职订阅控件" = **接口标记**（`SubscribingWidget`） | §10.2 第 5 条 · §12.2 · §4.2 · §16.5.3（`subscriber-mark`）· §22 | 命名约定**被方案自己的 `StatusBar` 否证**；接口标记同时是 D-09 lint 的判据 ⇒ 两条决策**合并成一个机制** |
| **D-11** | `DisplayList` **总是录制**（+ 缓冲复用契约） | §7.2 第 1/2 条 | §14.1 第 2 条已承诺"渲染是纯函数 ⇒ 录制成 DisplayList"；§20 第 1 条要求"量具与生产同一条路"。成本可控（"每帧全画"是既定事实，无跳过机会可损失） |

**净增的机器守卫**（§16.5.3 新增 9 条规则，全部满足 G1"编译器知道"）：`internal-leak` · `scan-autoload-drift` · `export-surface-drift` · `dead-subscription` · `dead-command`（扩为双向）· `select-purity` · `stale-derived` · `subscriber-mark` · `nested-subscribe`。

**新增的公共类型**（须进 `api-surface.json`）：`CommandRouter` · `CommandHandler` · `CommandArg`（`Drift\Commands`）· `Bound` · `SubscribingWidget`（`Drift\State`）。

**新增的探针门**（M1 前，⏳）：**`-m lib` 分发形态**（§10.1.2）。**经 GUI 仓实证后从 6 问缩到 3 问** —— ✅ 已回答：① 能产出 `.dll` + `.stub.php`（11.3MB / 1233 导出符号）·② **闭包跨 `.dll` 边界可用**（spike 判据 ③）·③ `#[NoExport]` 级联（`#[Native]` 类必须标 `#[NoExport]`，否则编译期拒绝）。⏳ **仍要验**：④ **热路径跨模块调用的性能差**（对照 §15.3 的 0.92 µs/节点 —— GUI 仓只验了"对不对"，**从未量"快不快"**）·⑤ `#[Native]` 类的实际限制 · ⑥ CRT/PHP 版本不匹配的实际症状。★ 其中"跨 `.dll` 的异步/跨线程闭包"仍未验，与 §17.2.2 的"闭包跨线程未验"是**同一类边界问题**。

---

*本文所有"不能"均可在 `TypePHP使用手册.md` §9 找到依据；所有"能"均可在 `examples/`（swoole、property-hook、closure、fn、attributes、qt-taskboard、tetris-win32、android-native）找到实证。§15 的 Seal-on-Mount 与 §17 的 TaskHost 是本方案相对既有 Drift 文档的**新增裁决**，均标注了落地前的探针门。*

*2026-10-14 评审（`REVIEW.md` + §23）：8 处事实性错误已修；11 项桌面刚需已裁决（6 项零平台端口成本）；§15.3 的"守卫免费"**经补测证伪**，裁决不变但依据换成 25× 比值。*

*2026-10-14 探针 E（§23.6）：§17.2 的"协程后端"**因果被推翻** —— 让 PHP 离开 UI 线程靠 **TSRM 线程上下文**而非协程，且**零扩展**（`std::thread` 已证可用，4 线程并发跑分配型 PHP 通过）。同时抓到 1 个 tpc 陷阱（命名空间内调 stub 函数必须写前导 `\`，§16.4.1 陷阱 3）。*

*2026-10-14 第二轮评审（`REVIEW-2.md` + `DECISIONS.md` + §23.9/§23.10）：**10 项文档遗漏已直接补进本文**（含删除幽灵术语 `Intent`、补 `R1–R12` / `W1–W7` 枚举、补 `Cell` 样例与两条订阅纪律）；**11 项设计缺陷已全部裁决并落地**（用户拍板"按推荐执行"，见 §23.10）—— 净增 **9 条机器守卫规则**、**5 个公共类型**、**1 个探针门**。*

*2026-10-14 `-m lib` 调研（用户提示后补，§10.1.2）：`tpc` 有库模式（`<target>.stub.php` + `.dll` + `.lib`），带**编译器强制**的 ABI 排除（`#[NoExport]`，类注解级联到方法）。⇒ §10 的"物理隔离"因此分两种形态：**源码同编**（靠 lint）与 **`-m lib`**（靠 ABI）。后者是"框架作为 SDK 交给第三方"唯一现实的形态（应用侧**不需要 Skia 头文件与 link-libs**），代价是**失去跨模块内联**（§15.3 的 0.92 µs/节点是同编下测的）。*

*2026-10-14 `-m lib` **实证复核**（用户提示"GUI 仓已试过、bug 已提上游、现已修复"，§10.1.2 + `DECISIONS.md` D-02.d）：查得 **GUI 仓 2026-10-10~11 做过完整 spike** —— 产物先出（`typephp_gui.dll` 11.3MB / 1233 导出符号），但**消费方跑不起来**，共定位 **6 条缺陷**（D1~D6，含 tpc 源码行号），其中 **D5**（库侧 `php_request_cache` 恒 `nullptr` ⇒ 段错误 exit 139）与 **D6**（lib 模式全程序去虚化 ⇒ 消费方覆写被**静默忽略**）是硬阻塞。GUI 仓以 **PR #166** 提上游（`lib-mode-fix` → `6c466d91`，PR 说明见 `typephp/PR-lib-mode-fix.md`）。⇒ ★★ 复核发现两处必须写准：**① 该 PR 本身未被合入**（`git merge-base --is-ancestor 6c466d91 master` = false），上游是**另起实现** `89fe80aa`（新增 `libraryEmbedsPhp`：共享运行时库按 `BUILD_MODE_EXT` 解析 Zend 符号；引用计数 `php_request_users`；导出 `typephp_<target>_library_request_startup/shutdown()`），并补上了 GUI 报告点名缺的 CI 夹具；**② 发行版 `tpc.exe` v0.9.5 不含该修复** —— 用同一最小 `-m lib` 工程分别跑发行版与源码树 tpc，对生成的 `extension-probe.cc` 做 diff（40 行差异）：发行版仍是 `RINIT` 二次进入 `return FAILURE` + "already initialized" 警告的**修复前形态**，缺 `php_request_users`、缺导出的 request_startup/shutdown、缺 persistent cache 重置。时间线印证：版本打点 `21770951`（0.9.5）= 10-13 14:59，修复 `89fe80aa` = 10-13 17:43，发行版文件 mtime = 10-13 07:53 ⇒ **发布早于修复**。⇒ **结论翻转但带约束**：形态 B 从"纸面可行"升级为"**实测可行、上游已支持**"（spike 终态 `372 ok / 1 fail`，与 `-m bin` 基线逐条一致），**但必须用源码树 tpc**（与 GUI 仓 `build_lib_srctpc.sh` 的做法一致）⇒ §16 需两个构建入口。⇒ 探针门 **6 问缩到 3 问**；新增两条永久纪律：**C++ 桥接头绝不 `using namespace php`**（D1 根因未修）· **`build.sh` 从第一天设 `PHPRC`**（`tpc.exe` 不加载同目录 ini，0.9.4 起强制 `mbstring`）。*

*2026-10-14 `--nano` 评估（用户提示后补，§16.6）：`--nano` 是**无 VM 原生构建**（不链接 `libphp`、不含 Zend 解释器，支持 `bin`/`lib`，不支持 `ext`）。★ **正面价值**：它是 §10.1.2 形态 B"库仍需 PHP 运行时与 PHPX ⇒ 不是自包含产物"这条代价的**唯一已知解法**（nano 下 `getLibraryPaths()` 返回空、PHPX 源码编进产物）。★ **但今天不可用，三条硬冲突**：① nano **强制 NTS**，而 §17 的 TaskHost 建立在 ZTS 的 `ts_resource(0)` 之上；② nano **要求 `cxx-std: c++17`**，与既定的 `c++20` 直接冲突；③ nano **禁全部网络/DNS/socket**，与 §387/§924 的 HTTP 数据源冲突。⇒ **裁决：M0–M2 不启用；但采纳其禁用清单作为源码纪律**（新增 lint `nano-hygiene`：禁匿名类 / `yield`+`Generator`+`Fiber` / `getenv` / `eval`+`include`），Drift 现状**除"网络"外已全部满足** ⇒ **零成本保留"将来可切 nano"的可选性**；同时划掉 §93 的"生成器候选"。★ 附带发现：`SourcePipelineTrait.php:602` 的注释"Windows Nano 刻意 leaves `nanoMode=false`"**已过期**（`NanoBuildBackend::composesRuntimeSources()` 无条件返回 `true`）。*
