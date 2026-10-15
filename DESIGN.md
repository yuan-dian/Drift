# Drift —— TypePHP 声明式 GUI 框架 · 设计方案 v2.0

> **状态**：v2.0 · 2026-10-14 · 覆盖用户提出的 **15 个设计重点**。
> **v2.0 相对 v1.0 只做三件事**（**不改任何设计裁决**）：
> 1. **结构重排** —— 过程记录（评审轮次、探针原始数据、被证伪的推断、"上一版如何错"）全部移出，见 `HISTORY.md`；
> 2. **补完整示例** —— 新增 **§A**（端到端可跑应用 + §9 声明但零示例的能力 + 反模式错对对照 + 工具链用法）；
> 3. **修 8 处文档内不一致**（条数矛盾 / 图缺层 / 示例路径错 / 术语残留等，清单见 §24.1）。
> 另新增 **§23 优化提案**（用户在 v2 修订期间提出的"找可优化点与新想法"）—— 其中标注「可直接补」的已落地，标注「待裁决」的等拍板。
>
> **配套文档**：
> | 文件 | 职责 |
> |------|------|
> | `DESIGN.md`（本文） | **方案本体**：只写"现在是什么" |
> | `DECISIONS.md` | 裁决档案（问题本质 / 得失 / 推荐 / 依赖图） |
> | `HISTORY.md` | 历史、探针证据、**已作废结论清单** |
> | `probes/RESULTS.md` | 探针原始数据 |
> | `architecture.html` | 六张架构图 |
> | `REVIEW.md` / `REVIEW-2.md` | 两轮评审发现过程 |
>
> **证据分级**（本文每个结论都属以下一档，不混用）：
> | 标记 | 含义 |
> |------|------|
> | **【实测】** | 真机跑过、有产物或数据（探针 / GUI 仓 spike / 生成物 diff） |
> | **【读源码】** | 读过 tpc / PHP 源码得出，**没跑过** |
> | **【手册】** | `TypePHP使用手册.md` 有记载 |
> | **【推断】** ⏳ | 推理得出、未验证 —— **落地前必须探针** |
>
> **怎么读**：
> - 只想拿结论 → §0 一页表。
> - 要动手写代码 → **§A 完整示例** + §4（布局）+ §15（控件写法）+ §11（数据交互）。
> - 要做架构决策 → §1 公理 + §10（公共面）+ §13（布局契约）+ §23（提案）。
> - 要落地某个里程碑 → §19（路线图）+ §16（工具链）+ §24.2（开放项）。

---

## §0 一页结论：15 个重点的裁决

| # | 重点 | 一句话裁决 | 详节 |
|---|------|-----------|------|
| 1 | **PSR-4 代码布局** | `Drift\` → `src/`，**目录即分层**；应用 `App\<X>\` → `apps/<x>/src/`；依赖方向由 lint 强制 | §4 |
| 2 | **平台边界 + 事件循环 + 线程** | 平台是**能力端口集合**（只传原语）；**循环归属平台、派发归属框架**；UI **单线程亲和**，后台结果以事件回贴 | §5 |
| 3 | **坐标系 / DPI / 分数缩放** | 逻辑单位（DIP）与物理像素两套空间，**只在表面边界量化一次**；布局全程 float；文本永远在设备分辨率塑形 | §6 |
| 4 | **渲染表面 + GPU 上下文** | `RenderSurface` + `GpuContext` 后端无关；`Canvas` 是**接口**（Skia / 软光栅 / 录制器三实现）；绘制**总是**录制成 `DisplayList` | §7 |
| 5 | **文本与字体栈** | 六层：FontManager → FontCollection → TextShaper → TextLayout → Paragraph → TextMetricsProvider；**测量实现唯一、双后端互校** | §8 |
| 6 | **输入 / 手势 / IME / 无障碍** | **键盘**：`AcceleratorTable`（焦点之前）→ IME 组合期让位 → 焦点链；**指针**：一次命中**三方消费**（事件 / 语义 / 光标形状）；`GestureArena` 消歧；IME 走 `TextInputClient`；无障碍 = **数据化 SemanticsTree** | §9 |
| 7 | **公开 API 稳定** | **三个面分开**：物理边界（PSR-4 前缀）/ 架构分层（命名空间 + lint）/ **公共契约面（`@api` 标注，默认 `@internal`）**；`-m lib` 形态下物理隔离由 **ABI** 强制 | §10 |
| 8 | **UI 与业务隔离 + 数据交互** | **两条缝**：值缝（`select` 切片订阅）+ 动作缝（**`CommandRouter` + Element 冒泡链**）；`Subject` / `SubjectBuilder` / `SubscribingWidget`；State 只持纯 UI 态 | §11 |
| 9 | **多页面组合** | 一页一文件；`match` 路由表；`Dock` 外壳 + 内容槽；`Offstage` 保状态；页面间**首选共享数据源**；**Root 只做组合** | §12 |
| 10 | **工具链** | **编译器知道 ⇒ 构建期做**；drift-lint / drift-preview / drift-devtools / drift-conformance / golden 六件套 | §16 |
| 11 | **布局/渲染确定性** | 布局 = 纯函数（树, 约束, 主题, **注入的文本度量**）；渲染 = 纯函数 → `DisplayList` 可重放；**不读环境时钟** | §14 |
| 12 | **布局契约** | 两阶段 `layout(constraints)→size` + `paint`；无界 = `+inf`（**永不拿 0 当哨兵**）；每容器声明溢出策略；违反 ⇒ 响亮失败 | §13 |
| 13 | **架构图** | 见 §3 与 `architecture.html`（**六图**：分层 / 事件循环+线程 / 三树+帧生命周期 / 双缝 / fluent 封存生命周期 / fluent 实测裁决） | §3 |
| 14 | **控件写法（链式，IDE 联想）** | **fluent-first + 挂载即封存**：`Text::make('Hello Drift')->title()`；`make()` 只吃必填项；链式方法 `set + return $this`；**不加 `->build()`**、**也不加 `->seal()`**；封存后写入 ⇒ 响亮失败（Fatal）。**【实测】** 设计真实形态 **0.92 µs/节点**，`clone-with` 贵 **3.4×** | §15 |
| 15 | **协程（Swoole/Swow）** | UI 线程**不跑协程**；异步收进 `TaskHost` 端口：**线程池后端**（C++ `std::thread` + `ts_resource(0)`，**【实测】** 可用）/ **同步三态后端**（永远可用）。★ 协程**不需要**：要的是"别占 UI 线程"，线程直接给 | §17 |

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
| **G3 确定性优先** | 布局/渲染是**纯函数**：可重放、可对账、可快照 | 布局全程位置无关；绘制录制成 `DisplayList`；时间必须注入不能读环境时钟 |

**G2 的落地定义：`Errors::report` 分三级**：

| 级别 | 语义 | debug | release | 用于 |
|------|------|-------|---------|------|
| **Fatal** | 框架不变式被破坏，继续跑必然得到错误结果 | 写日志 + **中止** | 写日志 + **中止** | 非 UI 线程改树（§5.3）；约束违反（C6）；封存后修改 Widget（§15.2） |
| **Recoverable** | 契约被违反但可安全降级 | 写日志 + **中止** | 写日志 + 降级继续 | 订阅者在 build 期注册；同值广播（§11.3） |
| **Diagnostic** | 值得知道，不是错误 | 写日志 | 写日志 | 布局普查发现溢出、性能阈值超限 |

★ **判据**：一条检查若在 release 下被静默吞掉，它就**不配出现在 §21 反模式表的"正确做法"列** —— 那等于承诺了"响亮失败"却没兑现（正好是 A6 裸吞）。

★ **"中止"在 GUI 里的具体形态**（★ **2026-10-14 裁决**，原 §23 O-01）：桌面应用**没有控制台**（`no-console: true` ⇒ 无 stdout/stderr），裸 `abort()` 的用户体验是**窗口闪退、什么都不留下** —— 这恰好是"静默"的另一种形态，与 G2 的意图相反。⇒ 定义**标准中止序列**（三步，顺序不可换）：

| 步 | 动作 | 理由 |
|---|------|------|
| ① | 写 `Errors.log`：错误 + 栈 + **最近 100 条 Diagnostic 的环形缓冲** | 现场可重建；环形缓冲让"Fatal 之前发生了什么"可读 |
| ② | 弹**原生错误框** —— 走 **`Platform::fatalBox()`**（§5.1） | ★ **不依赖渲染栈**：渲染栈本身可能正是崩掉的那一环 |
| ③ | `exit(3)`（固定退出码） | 可被启动器 / CI 观测到 |

**两条配套约束**：

1. ★ **`fatalBox()` 必须走平台端口抽象，不得直调 `MessageBoxW`** —— 否则"平台名只出现在组合根"（§5.1）被破坏。`Errors` 侧**不依赖 `Platform` 类型**，只收一个 callable：

```php
// Application::run() 内部（组合根之后、事件循环之前）
Errors::installFatalBox(fn(string $t, string $b) => $platform->fatalBox($t, $b));
```

2. ★ **启动早期 Fatal 的兜底**：`Platform` 实例尚未建立时（引导阶段），退化到"写日志 + `abort()`" —— 此时**没有任何 UI 可弹**。这条兜底登记在 §24.3（G2 显式例外）。

---

## §2 TypePHP 能力边界（一切设计的前提）

写任何设计前必须先钉死这张表，否则会把设计建立在**不存在的能力**上（历史教训：曾假设"编译期 `#[Signal]` 注解可自动重写 set hook"，作者确认不存在）。

### 2.1 能用（附证据）

| 能力 | 证据 | 对 Drift 的含义 |
|------|------|----------------|
| 闭包 / 箭头函数 / `use` 值捕获 | `examples/closure.php`、`fn.php` | `fn(int $i): Widget` 映射直接可用 |
| 命名参数 / `readonly` / backed enum | PHP 8 常规语法 | 契约层枚举化、不可变值对象 |
| **property hooks（set/get）** | `examples/property-hook.php` | 受控属性的物理基础（`Store` 基类封装，§11.4.1） |
| 运行时反射（Attribute 读取） | `examples/attributes/test.php` | devtools / 树转储；**不在渲染热路径用**（G1） |
| **C++ 线程（`std::thread` + `mutex` + `atomic`）** | **【实测】** 探针 E | `TaskHost` 后端 A 的物理基础；worker 跑 PHP 的前置条件 = `ts_resource(0)`（§17.2） |
| C++ 互操作（`php_` 前缀 + `.stub.php`） | `qt-taskboard` / `tetris-win32` / `android-native` | 平台桥接的唯一形态 |
| `php::Box`（C++ 对象交 PHP GC） | `examples/native`、`tetris-win32/BOX_USAGE_GUIDE.md` | 窗口 / 表面句柄跨边界传递 |
| `--no-console`（PE 子系统 = GUI） | 【手册】§6、`win32-hello` | 交付无黑框 exe |
| `-m lib` 共享库 + 自动 `.stub.php` | 【手册】§4 · **【实测】** GUI 仓 spike | 框架以库形态分发，应用薄壳链接（§10.2） |
| `include:` / `ignore:`（构建配置复用与排除） | **【实测】** 探针 D | 共享 `base.yml` 可行（§4.6） |
| **协程（Swoole/Swow，需扩展）** | `examples/swoole/co.php` | ⚠️ **与 Drift 无关**：TaskHost 要的是"别占 UI 线程"，C++ 线程池 + `ts_resource(0)` 即可，**零扩展**（§17.2.4） |
| ~~生成器 `yield`~~ | `examples/yield.php` | ❌ **不作为候选**：`yield` / `Generator` 会被 `--nano` 编译期拒绝 ⇒ Drift 源码禁用生成器，懒序列改用显式的"分页 / 窗口"数据结构（§16.7） |

### 2.2 不能用 / 有坑（必须绕）

| 限制 | 出处 | Drift 对策 |
|------|------|-----------|
| `$$` / `extract()` / 内嵌 HTML / 游离代码 | 【手册】§9.2 / §9.3 | 不做模板文件、不做动态拼接 |
| `Closure::call/bind/bindTo` | 【手册】§9.7 | 排除 hooks 式设计；回调一律显式传参 |
| 对 property hook 属性取引用 | 【手册】§9.2 | 不取引用 |
| **类型不可变**（声明后不能改类型） | 【手册】§9.5 | 哨兵值取代可空；可空走显式 `?T` |
| **类型化属性参与运算 ⇒ 装箱 + 动态分派** | **【实测】** 探针 A（旧记录"⇒ `C2440`"**已作废**） | **不报错**。热路径把属性读**先落类型化局部变量**（恢复原生直读 + 静态分派，实测快 ~14%）。详见 §2.4 纪律 1 |
| 多字节 UTF-8 不能用增长累加器（`$s = $s . …`）⇒ 堆损坏，崩在无关代码 | GUI 仓 AGENTS.md §4 | 文本拼接一律 `implode` |
| 全局只放 POD 指针，不放有析构对象 | GUI 仓 Contract.md §5.1 | 平台句柄是**不透明 int** |
| 子类覆写 `protected` 钩子可能被**去虚化 ⇒ 静默不跑** | GUI 仓 AGENTS.md §4 | 语义写成**字段 / 构造参数**，不靠虚方法 |
| 桥接签名逐字一致，不一致**静默失败**；PHP 调桥接须写前导 `\` | GUI 仓 AGENTS.md §4 · **【实测】** 探针 E 补机制 | lint 校验 stub ↔ C++ 符号一致；前导 `\` 由 lint 强制（§16.5 陷阱 3） |
| 协程入口必须 `catch (\Throwable)`，否则异常越过 Zend 回调边界 ⇒ 进程中止 | 【手册】§9.14 | 不进热路径（§17.1：不走协程） |
| 构建 20–50s（C++ 编译） | GUI 仓实测 | 迭代回路是硬成本 ⇒ 无头 preview 是投资不是消费（§16.3） |

### 2.3 一句话心智模型

> **写 PHP；只在 §2.4 的纪律清单处受限。** 其余"框架特性"（响应式、异步、声明式）都是**设计选择**，不是 AOT 能力门 —— 把两者混为一谈会导致错误归因。

### 2.4 编码纪律（全篇唯一权威清单）

散落在各章的纪律集中在此。每条都对应一个**真实的失败形态**，不是风格偏好。

| # | 纪律 | 为什么 | 谁守 |
|---|------|--------|------|
| 1 | **热路径属性读先落类型化局部变量** | 类型化属性走 `attr()` 取 `php::Var` + 拆箱，且三元条件里同一属性会读两次（有 hook 则 get 跑两次）。落局部变量恢复静态解析（快 ~14%） | 人 + code review（M5 后可有性能 lint） |
| 2 | **文本拼接一律 `implode`** | `$s = $s . …` 增长累加器在多字节下堆损坏，**崩在无关代码** | lint（`str-concat-loop`，M2 起） |
| 3 | **`cpp-src/*.h` 绝不写 `using namespace php;`** | lib 模式无条件 `/FI` 聚合头 ⇒ `php::operator*` 提到 Skia 头前 ⇒ 位运算重载歧义（D1 根因**未修**） | lint（`no-using-php`，M1 起） |
| 4 | **从命名空间内调 stub 函数必须写前导 `\`** | 否则 decl 头不注入 ⇒ `C3861` 指向**生成的 `.cc`**，极难定位 | lint（`stub-prefix`，M1 起） |
| 5 | **`tools/build.sh` 从第一天设 `PHPRC`** | `tpc.exe` 不加载同目录 `php.ini`；缺 `mbstring` 直接 `Fatal: Failed to startup module`。0.9.0 不要求、**0.9.4 起强制** | 脚本 + 文档（§16.5 陷阱 4） |
| 6 | **`nano-hygiene`**：禁匿名类 / `yield`+`Generator`+`Fiber` / `getenv`·`putenv` / `eval`·`include`·`require` | 零成本保留"将来可切 `--nano`"的可选性（§16.7） | lint（M1 起） |
| 7 | **一个类一个文件，文件名 = 类名**；**trait 不单独成文件** | 只有 trait 的文件不产出翻译单元（`cl` 收到空文件名） | lint（`namespace-mismatch` / `orphan-trait`） |
| 8 | **业务层（`apps/*/store/`）零 GUI 依赖** | 隔离的物质前提；`Subject` 只依赖 `Drift\State` 内部的 `ThreadAffinity::assertUi()`，不依赖 `Platform` | lint（§4.3 依赖规则） |
| 9 | **订阅控件必须在 `build()` 内构造**，不得跨帧缓存 | 缓存复用会让它捕获旧快照 ⇒ 看着订了、订的是上一帧的 store（§11.3.2） | lint（`cached-subscriber`） |
| 10 | **订阅粒度不得细到"每列表项一个"** | 1000 行列表 = 1000 个订阅者，任一 notify 跑 1000 次回调（§11.3.2） | lint（`nested-subscribe`，warn） |

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
│                              · Cell · SubscribingWidget              │
│  Drift\Commands   动作缝：CommandRouter · CommandHandler · CommandArg  │
│  Drift\Input      事件 · 手势 · 焦点 · IME · 加速键 · 语义(无障碍)     │
├──────────────────────────────────────────────────────────────────────┤
│  Drift\Rendering  渲染表面 · GPU 上下文 · Canvas(接口) · DisplayList   │
│  Drift\Text       字体栈 · 排版 · 度量提供者 · IME 协议                │
│  Drift\Geometry   坐标 · DPI/Scale · BoxConstraints                   │
├──────────────────────────────────────────────────────────────────────┤
│  Drift\Platform   平台端口：窗口 · 表面 · 输入源 · 定时器 · 屏幕 · 壳服务│
├──────────────────────────────────────────────────────────────────────┤
│  Drift\App        Application · EventLoop(派发) · FrameClock · TaskHost│
│                   · WindowStateStore                                   │
│  Drift\Errors     错误出口（"绝不静默"的物质载体）                     │
└──────────────────────────────────────────────────────────────────────┘
```

★ 与 `architecture.html` 图 01 一致（图内不含 `Drift\Commands`，以本节为准；图待同步）。

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
EventLoop 入队 → 路由（加速键 → IME → 焦点 ／ 命中测试 → GestureArena → 目标 Element）
   ▼
帧末 flush：脏 Element 重建 → relayout → paint(录制 DisplayList) → surface.present()
```

> 四张图的完整可视化（分层 / 事件循环 / 三树 / 数据双缝）见同目录 **`architecture.html`**。

### 3.4 `BuildContext` 的能力面（★ 2026-10-14 定义，原 §23 O-02a）

**一句话**：`BuildContext` 是**当前渲染环境的只读快照**，**不是查找入口**。

```php
namespace Drift\Widget;

/**
 * build() 的入参：本次构建所处的渲染环境。
 *
 * ★ 三条边界 —— 都不是"风格偏好"，每条都对应一个判据：
 *   ① 只读 —— 无 setter；改环境走 Widget / 主题树。
 *   ② 无业务数据 —— 不得出现 Subject 子类型（理由见下）。
 *   ③ 无向上查找 —— 无 of() / find() / maybeOf() 式 API（§18 已否决 Flutter 的 provider soup）。
 */
final readonly class BuildContext
{
    /** 本容器收到的约束（逻辑单位，§6.1） */
    public float $width;              // ★ v2 之前唯一被实际使用的成员
    public float $height;

    /** 当前生效的主题 —— 沿 Element 父链【解析后】的结果，不是查找入口 */
    public function theme(): ThemeData;
    public function color(ColorToken $t): Color;
    public function metric(MetricToken $t): float;

    /** 焦点状态（只读） */
    public function hasFocus(FocusNode $n): bool;

    // ★ 明确【不】提供：
    //   of() / find() / maybeOf() / dependOnInheritedWidgetOfExactType()   —— §18 否决
    //   store() / subject() / 任何返回 Subject 子类型的成员               —— 边界 ②
}
```

**★ 为什么"无业务数据"不是风格偏好，而是判据前提**：

`dead-subscription`（§16.6.3 第 4 条 = §21 A16 的守卫）的判据是"**`build()` 体内出现对 `Subject` 子类型实例的方法调用**"。
⇒ 这条判据成立的**充要条件**是：**`Subject` 实例只能从 `build()` 的可见作用域（`$this` 的字段 / 参数 / 局部变量）拿到**。
⇒ 若 `BuildContext` 能返回 `Subject`，"读值 + 订阅"就可以都藏在 `$ctx` 后面 ⇒ 判据被绕过 ⇒ **A16 退化成"文档承诺了、机制守不住"**（正是 §1.2 判据要消灭的形态）。

**★ 业务数据怎么到页面（回答 O-02 复核留下的"开口"）**：

**在组合点直接注入构造器**，不经 `BuildContext` —— §12.1 第 2 条已经这么写了：

```php
// Root 内部（§12.2：Root 只做组合）
private function pageFor(string $name): ?Widget
{
    return match ($name) {
        'users'  => UsersPage::make($this->store),    // ★ 直接传构造器
        'signup' => SignupPage::make($this->store),
        default  => null,
    };
}
```

⇒ `Dock` 的 `center` 槽收的是**已经构造好的 Widget**，`PageHost` 只是容器 ⇒ **store 不经过任何中间层**。
⇒ **"中间层要透传 store"这个担忧不成立** —— Drift 走的是"**Widget 构造时注入**"，不是"**运行期上下文查找**"。
⇒ **连带结论（O-02b）**：`ctx-no-business` lint **不需要**（业务数据没有路径流进 `build()`）；边界 ② 以**纪律**形式保留（守它的人：review + §16.6.3 的 `dead-subscription` 判据本身）。

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
      //   ★ bin/ 必须**按平台逐条列出**（不是笼统的 bin/）：组合根在
      //     bin/<platform>/Bootstrap.php 里声明 `namespace App\Hello;`（§A.1），
      //     只有把 bin/win32/ 与 bin/macos/ 各自挂到前缀上，该声明才落在
      //     PSR-4 的"前缀根"上；写笼统的 bin/ 会要求命名空间是 App\Hello\win32。
      //   两个平台目录同时列出无害：非当前平台的目录根本不会被查到。
      //   实际选哪个文件由 project.yml 的 if: 条件编译（§4.5）。
      "App\\Hello\\": ["apps/hello/src/", "apps/hello/bin/win32/", "apps/hello/bin/macos/"],
      "App\\Users\\": ["apps/users/src/", "apps/users/bin/win32/", "apps/users/bin/macos/"]
    }
  }
}
```

★ **这张表不只是 autoload 配置，它就是 §10.1.1 的"物理边界表"** —— 谁在 `Drift\` 前缀下 = 框架包，其余 = 业务。`scan-autoload-drift`（§16.6.3）拿它与 `project.yml` 的 `sources` 对账。

★ **`App\` 前缀是必需的，不是风格选择**：桥接 stub 函数（`thr_*` / `win_*` / `platform_*`）**全部声明在全局命名空间**。若业务代码也在全局命名空间，业务函数就会与 stub 混在同一空间 —— 而命名空间内的调用点与 stub 声明的全局符号对不上会导致 decl 依赖链断掉（§16.5 陷阱 3）。⇒ **业务必须有前缀。**

### 4.2 目录即分层

| 目录 | 命名空间 | 文件 |
|------|---------|------|
| `src/App/` | `Drift\App` | `Application.php` · `EventLoop.php` · `FrameClock.php` · `TaskHost.php` · `ThreadPoolTaskHost.php` · `SyncTaskHost.php` · `WindowStateStore.php` |
| `src/Platform/` | `Drift\Platform` | `Platform.php` · `WindowHost.php` · `SurfaceProvider.php` · `InputSource.php` · `TimerHost.php` · `Screen.php` · `Clipboard.php` · `Dialog.php` · `Tray.php` |
| `src/Platform/Win32/` | `Drift\Platform\Win32` | `Win32Platform.php` · `Win32WindowHost.php` · …（首个实现） |
| `src/Geometry/` | `Drift\Geometry` | `Point` · `Size` · `Rect` · `EdgeInsets` · `Scale` · `BoxConstraints` |
| `src/Rendering/` | `Drift\Rendering` | `RenderSurface` · `GpuContext` · `Canvas` · `DisplayList` · `Paint` · `Color` · `BoxStyle` · `SkiaCanvas` · `RasterCanvas` · `RecordingCanvas` |
| `src/Text/` | `Drift\Text` | `TextRole` · `FontManager` · `FontCollection` · `FontFace` · `TextShaper` · `TextLayout` · `Paragraph` · `TextMetricsProvider` · `TextStyle` · `TextInputClient` |
| `src/Layout/` | `Drift\Layout` | `RenderObject` · `RenderBox` · 各 `Render*` 族 |
| `src/Widget/` | `Drift\Widget` | `Widget` · **`BoxWidget`** · `StatelessWidget` · `StatefulWidget` · `Element` · `State` · `BuildContext` · `Key` |
| `src/Input/` | `Drift\Input` | `PointerEvent` · `KeyEvent` · `KeyChord` · `GestureArena` · `FocusNode` · `SemanticsTree` · `AcceleratorTable` · `CursorShape` · `ThemeChangedEvent` |
| `src/State/` | `Drift\State` | `Subject` · `Subscription` · `SubjectBuilder` · `Cell` · `SubscribingWidget` |
| `src/Commands/` | `Drift\Commands` | `CommandRouter` · `CommandHandler` · `CommandArg` |
| `src/Theme/` | `Drift\Theme` | `Theme` · `ThemeData` · `ThemeScope` · **`SystemTheme`** |
| `src/Routing/` | `Drift\Routing` | `Page` · `RouteTable` · `PageHost` · `Offstage` |
| `src/Errors.php` | `Drift\Errors` | 错误出口 |

### 4.3 依赖规则（lint 强制，违反即报错）

| 层 | 可以依赖 | 不许依赖 |
|----|---------|---------|
| `Geometry` | `Errors` | 一切 |
| `Platform` | `Geometry` / `Errors` | 上层任何东西 |
| `Text` | `Geometry` / `Errors` | Widget / Rendering / Platform |
| `Rendering` | `Geometry` / `Text` / `Errors` | Widget / Theme / Platform（表面由 App 注入。★ `ColorToken` / `MetricToken` 在这里 ⇒ **Widget 与 Theme 都能引用它们**，这是第 18 处缺口的修正） |
| `Input` | `Geometry` / `Errors`；**协议例外**：`Text\TextInputClient` 与 `Layout\SemanticsConfiguration` 可被引用，但仅限类型签名 | Widget / Rendering |
| `Widget` / `Layout` / `State` | 下面全部；**协议例外**：`Theme\ThemeData` / `ColorToken` / `MetricToken` 可被引用，但**仅限 `BuildContext` 的类型签名**（§3.4） | Routing / Theme（**除该例外**）/ Commands / App |
| `Commands` | Geometry / Errors / **State**（只做类型签名） | Widget / Layout / Rendering / Platform |
| `Theme` / `Routing` | Widget / Layout / State / Text / **Rendering** / Geometry | App |
| `App` | 全部 | apps |
| `apps` | Drift **公共 API**（标 `@api` 的） | Drift 内部实现（`@internal`，§10.1.4；有豁免标注 ⇒ warn） |

★ 与 GUI 仓的差别：GUI 用平铺 `namespace GUI;` + classmap，分层检查**没有抓手**（lint 的类索引必须递归 glob）；PSR-4 之后"谁能依赖谁"第一次变得**可静态检查**。

### 4.4 三条执行纪律

1. **一个类一个文件，文件名 = 类名**。GUI 仓的坑"只有 trait 的文件不产出翻译单元（`cl` 收到空文件名）"的对策：**trait 不单独成文件**，或所在文件必须同时含一个具体类 —— 写进 lint。
2. **新文件不需要改构建配置**：`tpc` 的 `sources` 列目录且递归；只有**新增一个应用**才加一行 `project.yml`。
3. **目录移动不需要改 `use`**：PSR-4 下命名空间绑定路径；lint 有一条 `namespace-mismatch` 规则兜底。

### 4.5 应用工程形态

```
apps/<app>/
  project.yml        # tpc 构建配置（defines 注入 ALL_DECL 头名）
  main.php           # 入口（bin 模式硬要求：全局唯一 main()）
  bin/
    win32/Bootstrap.php    # 组合根：平台名只出现在这里
    macos/Bootstrap.php
  src/
    <App>Application.php   # extends Application：只写 buildRoot() / title() / commands() / accelerators()
    <App>Root.php          # 外壳 + 路由：只做组合
    pages/                 # 一页一文件
    store/                 # 业务：普通 PHP 类，零 GUI 依赖
    commands/              # CommandHandler 实现（§11.4.3）
    widgets/               # 应用私有控件（如 StatusBar）
```

★ **两个平台的 `Bootstrap` 同名不同文件** ⇒ 靠 `project.yml` 的 `if:` 条件 source 二选一（§16.4）。

### 4.6 共享构建参数：用 `include:`（**【实测】** 可用）

```
build/
  base.yml               # 共享：cxx-std / cxx-flags / ld-flags / link-paths / include-paths / defines / optimize / job
apps/<app>/
  project.yml            # 只写：include: ../../build/base.yml + 本应用特有项（name / build-dir / sources 增量）
```

**★ 三条硬约束（实测得出，必须遵守）**：

1. ❗ **被包含文件里的路径按"使用者"的视角解析** —— 相对**顶层 `project.yml` 所在目录**，**不是** base 自己的目录。
   ⇒ 共享 base **只有在所有使用方同目录深度时才成立**。`apps/<app>/project.yml` 全部同深度 ⇒ base 里写 `../../src`、`../../cpp-src` 对每个 app 都成立。**这是这套用法能 work 的唯一前提**，新增一个不同深度的应用会直接破。
2. **列表整体替换，不深合并**；标量由**当前文件覆盖**。
   ⇒ base 里给了 `sources: [...]`，应用里再写 `sources: [...]` 会**完全替换**（不是追加）；要增量就得把完整列表写进应用，或让 base 不给 `sources`。
3. ⚠️ **base 路径写错 ⇒ tpc 崩溃**（`Uncaught TypeError ... getAbsolutePath()`，`exit=255`），不是"路径不存在"的干净错误。
   ⇒ 引入 `include:` 的同时**必须配一个路径存在性检查**（lint 或构建前置脚本），否则第一个踩的人会去查编译器 bug。

**配套**：`ignore:` 已**【实测】**可用 ⇒ 共享 `src/` 扫描时可排除子目录（如 Windows 构建里排除 `src/platform/macos`）。

★ 这条纪律取代了 GUI 仓的 `yml-parity` 断言 —— 参数不再是两份拷贝，就不需要比对器了；但要新增"base 路径存在性"检查（约束 3）。

---

## §5 平台抽象边界 + 事件循环归属 + 线程模型（重点 2）

### 5.1 边界：平台是能力端口的集合，不是上帝接口

```php
namespace Drift\Platform;

interface Platform
{
    public function screen(): Screen;                          // DPI / 工作区 / 显示器枚举 / systemTheme()
    public function window(): WindowHost;                      // 窗口生命周期 + setCursor/setCapture
    public function surface(int $windowHandle): ?SurfaceProvider;  // 渲染表面（不支持 ⇒ null）
    public function input(): InputSource;                      // 原始事件泵
    public function timers(): TimerHost;                       // 定时器
    public function fatalBox(string $title, string $body): void;  // ★ Fatal 中止序列第 ② 步（§1.2）
    public function clipboard(): ?Clipboard;                   // 壳服务：不支持 ⇒ null
    public function dialog(): ?Dialog;
    public function tray(): ?Tray;
    // ★ 这里【不】加 menu() —— 菜单是自绘控件，不是平台能力
    // ★ dropTarget() 为 M5+ 预留位（OS 级拖放需 OLE，明确不做，§12.4）
}
```

★ **`fatalBox()` 是唯一一个"不为功能、只为失败"的端口方法** —— 它存在的理由见 §1.2 的标准中止序列。

**三条端口边界裁决**：

1. ★ **菜单不进 `Platform`**：原生菜单栏（`HMENU` + `SetMenu`）与自绘界面**风格割裂**（GUI 仓 manifest 教训：原生对话框退回 Win95 外观时与 Skia 界面并排非常突兀）。⇒ **菜单 = 普通 Widget**（`MenuBar` / `Menu` / `MenuItem` / `ContextMenu` 全走 §15 链式写法，放在 `Dock::top` 里），**零平台端口成本**。**原生菜单栏：明确不做**（风格割裂 + 无法与自绘主题统一 + 违反 §18）。
2. ★ **鼠标指针形状进 `WindowHost`**（不是新端口）：它是**窗口级**状态（`SetCursor` 作用于 HWND），且与 `setCapture()` 同族。
3. ★ **`ThemeChanged` 复用现有事件通道**（不新增端口方法）：`screen()` 已有；Win32 侧把 `WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`）规范化成 `ThemeChanged` 事件即可（与 `DpiChanged` 同路，见 §9.2）。
4. ★ **`fatalBox()` 进主接口，不拆独立端口**（**2026-10-14 裁决**，原 §23 O-01）：它是**基础能力**（每个平台都有 —— Win32 用 `MessageBoxW`，其它平台最差也能写 stderr），**不存在"不支持"这个状态**。⇒ 若按第 2 条拆成 `?FatalBox`（返回 `null`），调用方就要处理一个**永远不会发生**的分支 —— 正是 §10.3 第 4 条（A1 死字段）的形态。

**四条边界纪律**（GUI 仓 M2 教训直接继承）：

1. **只传原语**：端口方法签名只出现 `int/float/string/bool/array` 与 Drift 值对象；OS 句柄（HWND / NSWindow…）是**不透明 int**，跨接口后不得解引用（GUI 仓 Contract.md §5.1：全局只放 POD 指针）。
   - 句柄的解引用**只允许发生在 `Drift\Platform\<Os>\` 内部**（`Win32Platform::surface()` 里 `reinterpret_cast<HWND>($windowHandle)`）。
2. **端口拆小**：剪贴板 / 托盘 / 对话框**不进主接口** —— 跨平台语义差异大，强迫每个实现全量方法会让大量方法沦为抛异常的桩。独立端口让"不支持"在**类型层面**表达清楚（返回 `null`）。
3. **"生"与"活"分开**：`createWindow()` 管创建，`WindowHost` 管运行期控制（标题 / 位置 / IME 插入符 / 指针捕获 / 指针形状 / 定时器）。
4. **平台实现放 `Drift\Platform\<Os>\`**；`Application` 主循环一行不改。

**应用与平台的边界**：平台类名只出现在**每平台引导文件**（组合根）。共享代码（页面 / 控件 / store / Application 子类）**零平台引用**；跨平台 = 同一份应用源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。

```php
// apps/users/bin/win32/Bootstrap.php —— 组合根，全应用唯一提到 Win32 的地方
namespace App\Users;

final class Bootstrap
{
    public static function run(): void
    {
        $app = new UsersApplication(
            new \Drift\Platform\Win32\Win32Platform(),
            new \Drift\App\SyncTaskHost(),                    // M1–M3；换线程池 = 换这一行
            new \Drift\App\WindowStateStore('users.window'),
        );
        $app->run();
    }
}
```

### 5.2 事件循环归属：循环属于平台，派发属于框架

```
OS 消息泵（WindowHost::pump，阻塞等待，不空转）
   │  规范化：Win32 MSG → Drift Event（Pointer / Key / Resize / Dpi / Theme / Close / Timer / Shell…）
   ▼
EventLoop（Drift\App）—— 拥有派发，不拥有等待
   ├─ 入队：派发中产生的事件（setState、动画起停、回贴的后台结果）进 next-tick 队列
   ├─ 路由：加速键 → IME → 焦点 ／ 命中测试 → GestureArena → 目标 Element
   └─ 帧末：flush 脏 Element → layout → paint → surface.present()
```

**三条裁决**：

1. **框架绝不自建循环**：`pump()` 由平台阻塞等待下一条消息（事件驱动，不空转）；帧时钟 `FrameClock` 也由平台驱动（VSync / `WM_TIMER`），Drift 只消费帧时间戳。
   - 实证：`tetris-win32/main.php` 的 `while { win_peek_message(); handleKey(); render(); usleep(16000); }` —— **手动泵 + 帧睡眠**就是现实形态。
2. **时间注入**：动画相位 = f(帧时间戳)，**不读环境时钟** —— 这是 G3 确定性的物质基础（golden 测试可以喂固定帧序列）。
3. **重入安全**：事件处理中途的 `setState()` 只标脏；统一重建发生在帧末 `flush` 阶段；派发中产生的回调入队下一 tick ⇒ "build 期 setState 触发重入"这类问题**结构上不存在**（回调里连点十次只重建一次）。

### 5.3 线程模型：UI 单线程亲和 + 结果回贴

```
UI 线程（唯一）：  全部树操作 / 布局 / 绘制 / 事件派发 / setState
后台（TaskHost）： 文件 IO · 网络 · 计算 —— ★ 只碰业务数据，不碰任何 Widget/Element
回贴（marshal）：  后台完成 ⇒ postEvent 入 UI 线程队列 ⇒ 在 UI 线程改 Store ⇒ 通知 → 重建
```

**三条裁决**：

1. **框架核心无锁**。单线程亲和是 Win32 消息模型的自然延伸，也是 AOT 现实下的安全默认。实证：GUI 仓全仓 `grep` 零多线程 / 零 Fiber / 零协程 —— 这不是能力缺失，是**刻意选择**。
2. **`TaskHost` 是平台能力**（C++ 线程池）。**【实测】**（探针 E）：`std::thread` 可建（31–60 µs 返回，不阻塞）、`mutex`+`deque` 跨线程队列可用、`std::atomic` 可用；**worker 线程跑分配型 PHP 亦可用，前置条件 = 入口先 `ts_resource(0)`**，4 线程并发已过。**M1 仍先做单线程 + 同步 IO 占位**（后端 B），线程池作为独立端口实现可后补。
3. **断言点有两个**：
   - **① `Subject::notifyChanged()` 入口** —— "**通知必须在 UI 线程发出**"；
   - **② `State::setState()` / 树变更** —— 非 UI 线程调用 ⇒ 同上。

   两处都是 `Errors::report`（**Fatal 级**，release 也中止）（G2：线程错用是静默数据竞争的源头，必须响）。

   > **★ 为什么必须有第 ① 处**：线程池落地后，**最可能踩的路径不是"直接改树"，而是"在 worker 里改 Store 然后 notify"**：
   > ```
   > worker 线程：$store->setRows($data); $store->notifyChanged();
   >    ⇒ 订阅者回调在 worker 线程上执行 ⇒ setState ⇒ 树变更 ⇒ 数据竞争
   > ```
   > `notifyChanged()` **本身不是**"树变更"，只查 ② 不会触发断言。而它有两个后果：
   > - **订阅者是控件** ⇒ 最终会在 `setState` 处响，但**错误在更深的栈上被报出来**（栈里看不到"哪个 notify 引起的"）；
   > - **订阅者不是控件**（纯业务订阅者，比如日志 / 缓存失效） ⇒ **它不会 `setState`** ⇒ **worker 线程上跑业务回调，无任何断言**，而业务回调可能改别的 Store ⇒ **数据竞争完全静默**。
   >
   > **成本**：一次整数比较（缓存的 UI 线程 id vs 当前线程 id）⇒ 可忽略。
   > **与"业务层零 GUI 依赖"不冲突**：`Subject` 依赖的是 `Drift\State` **内部**的 `ThreadAffinity::assertUi()` 静态方法（不是 `Platform`）⇒ 业务层仍然不知道 GUI 是什么。
   > **落地时机**：**与 M4 的线程池同时落地**（M1–M3 单线程，该断言永远成立 ⇒ 是纯开销）。

### 5.4 窗口状态持久化

**裁决：放 `Drift\App`，不进 `Platform`。**

理由：它**不需要任何平台能力** —— `WindowHost` 已有位置/尺寸/最大化状态的读写，而"存到哪、什么时候存"是**应用生命周期**的事，不是平台的事。放进 `Platform` 会破坏 §5.1 第 2 条"端口只放平台能力"的纪律。

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
2. **恢复失败必须静默降级到默认值**，不报错 —— 这是少数**允许静默**的场合（显示器拔了、分辨率变了、配置文件被删，都不该让应用起不来）。★ 这是 G2 的**显式例外**，写进 §24.3 例外登记表以免被 lint 误判。
3. **存储后端可换**（文件 / 注册表 / 平台偏好），但**不进 `Platform` 端口** —— 因为换它不影响"谁能依赖谁"的分层，只影响一个 `Drift\App` 内部实现。

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
| 文本 | 排版在逻辑空间，**字形在设备分辨率塑形与光栅**（永远不按逻辑分辨率放大 —— 模糊的根源） |
| `TextScale` | 用户字号偏好，**只乘文本度量**，不动布局几何（与 dpr 正交的两轴） |
| **两轴顺序** | 先 `TextScale`（文本度量内）再 `dpr`（表面量化），**各取整一次**；中间不取整 |
| Per-monitor DPI | `Screen::scale` 变化 ⇒ `DpiChanged` 事件 ⇒ **触发 relayout，不重建窗口**；窗口跨屏拖动 = scale 变更 = 布局事件 |
| 分数缩放 | 布局全程 float，**不在中间步骤取整**；取整只发生在 ① 表面量化 ② 文本行高（按 FontMetrics 归一后取整） |

### 6.2 反模式（三条都有实测出处）

1. ❌ **拿 0 当"无界/未知"哨兵** —— GUI 仓三次踩坑（`0..maxH` 松上界被当"可用高"吃满；合法值当哨兵 ⇒ 安静地错）。**无界 = `+inf`（`INF`）**。
2. ❌ **构建期魔法宽度阈值**（"宽度 < 891 就折叠"）—— 阈值必然腐烂（改一个标签就破）。★ **正解不是"更好的阈值"，是让阈值问对对象**：判据从"屏幕宽"升级为"**本容器约束宽**"（CSS 容器查询思路）+ 布局期行为 ⇒ 阈值腐烂在结构上被消灭。
3. ❌ **在控件里直接读 `dpi` 做分支** —— 缩放是**表面属性**，控件看到的是逻辑约束；`$ctx->width` 是逻辑宽，物理换算只在绘制边界发生。

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

> ★ **`Paragraph` 归 `Drift\Text`，不在 `GpuContext`。**
> `GpuContext` 的职责是"设备/队列所有权"（`uploadBitmap` 是 GPU 资源，合理）；**排版不是 GPU 的事**。把 `createParagraph` 放进去等于让文本栈的所有权被渲染层绑架，且与 §8.1 的 `Paragraph`（不可变排版结果 + 缓存键）**构成两套表示、两个所有者**。
> - `Paragraph` 是**值对象**（不是句柄），由 `TextLayout::layout(...): Paragraph` 生产；
> - **缓存归 `TextMetricsProvider` 实现内部**（§8.1 的注入点），键 = `(text, style, maxWidth, scale)`；
> - Skia 后端若需要缓存 `skia::textlayout::Paragraph` 对象，那是 `SkiaCanvas` / `SkiaTextMetricsProvider` 的**私有实现细节**，**不上公共接口**。

### 7.2 四个裁决

1. **`Canvas` 从 `final class` 改为接口**。GUI 仓原话："绘制层硬依赖（`Canvas` 是 `final class`，直连 `\gfx_*`）⇒ 换绘制后端要先抽象"。Drift 第一天就是接口，**三个实现**：
   - **`RecordingCanvas`** —— **生产路径的入口**：把绘制命令录成 `DisplayList`；
   - `SkiaCanvas` —— **`DisplayList` 的回放器**（转发 Skia 桥接；GUI 仓实测 `SkCanvas::MakeRasterDirectN32` 画到离屏 bitmap，`SetDIBitsToDevice` 贴到 HWND）；
   - `RasterCanvas` —— **软光栅**：无头预览、CI、测试（无 OS 依赖 ⇒ §14 确定性的物质基础）。
2. **`DisplayList` = 绘制命令即数据**。`paint()` 的产物不是"立即画到屏上"，而是录制成命令序列，由 surface 执行。收益：可重放（golden）、可快照（devtools 传输）、可跨进程（将来的 Inspector）、为多线程光栅铺路。★ 这是 G3 在渲染侧的落点。
   > **★ `DisplayList` 总是录制，生产路径也走它**（不是只在 dev 录制）。**理由**（三条）：
   > ① §14.1 第 2 条已经承诺"渲染是纯函数 ⇒ 录制成 `DisplayList` ⇒ 可重放、可 golden 比对" —— 只在 dev 录制会让这条承诺**在生产上不成立**；
   > ② §20 第 1 条"量具双向自检"的核心就是"量具与生产走同一条路" —— 只在 dev 录制 ⇒ golden 通过 ≠ 生产正确；
   > ③ **成本可控**：GUI 仓的教训是"**每帧全画**"（不取 Flutter 的 `shouldRepaint`）⇒ 本来就没有"跳过绘制"的优化可损失；`DisplayList` 只是把"直接画"变成"先记录再画"，多出的成本是**内存 + 一次函数调用转发**。
   > **★ 配套契约（必须，否则反而变慢）**：**缓冲复用 —— 每帧零分配**（复用同一块 `DisplayList` 缓冲，不 `new`）。一条命令约 16–32 字节，一帧几千条 ⇒ 几十 KB/帧。
   > **★ 配套断言**：录制结束时**命令栈深度必须为 0**（`save`/`restore` 配对），否则缓冲复用会把上一帧的未闭合栈带到下一帧。写入 W 规则（§16.6.2 W8）。
   > **★ 与 §7.3 的分期不冲突**：M1 = "录制 + 立即回放"；M5 的脏矩形 = "按需重放"（同一份 `DisplayList`，只是不再全量重放）。
3. **坐标约定不变**（GUI 仓验证过）：父级在绘制子盒前已 translate 到盒原点，`paint` 内部以 (0,0) 为盒左上角作画；`save/restore` 语义、`pushClip/popClip` 配对纪律原样继承。
4. **表面与应用解耦**：`SurfaceProvider` 由平台按窗口给出；`Application` 持 surface，`RenderObject` 树不认识 surface —— 测试里手搓 `RasterCanvas` 就能跑完整渲染。

### 7.3 GPU 上下文：诚实的分期

| 阶段 | 后端 | 依据 |
|------|------|------|
| **M1** | Skia **CPU 光栅** + 位块传送（BitBlt / SetDIBits） | GUI 仓实测路径，已验证可跑通并出图 |
| **M4+** | 可选 D3D11 / OpenGL / Vulkan 后端 | ⏳ **【推断】**。GUI 仓 `project.yml` 虽链接了 `d3d12.lib/opengl32.lib`，但**代码未用 `GrDirectContext`** —— GPU 路径是**未开采的性能上限**，不是已证伪的方向 |

> **诚实结论**：CPU 光栅是当前**性能天花板**（GUI 仓实测结论）。`GpuContext` 接口第一天就存在，但 M1 的实现可以是"直通 CPU"的空实现 —— **接口先行、后端后补**，避免重演 GUI 仓"硬连线 ⇒ 换后端要先抽象"。

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

> ★ **凡是影响排版结果的输入都在 `TextStyle` 里**（对齐 / 行数上限 / 省略号亦然），
>   而不是 `TextMetricsProvider::layout()` 的额外参数 —— 因为缓存键被钉死为
>   `(text, style, maxWidth, scale)`（§7.1 同）：**多一个不在键里的输入，缓存就会
>   对同一个键给出不同结果**。widget 层的 `->align()` / `->maxLines()` 经主题解析后
>   落成这里的值（§15.7）。

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

1. **字体初始化空指针 ⇒ 启动崩溃，且会被误判为"无控制台子系统的问题"**。GUI 仓早期 `--no-console` 启动报 `0xC0000005`，根源是 `ensureFont()` 里字体句柄为空。【手册】§6 原话："**无控制台程序的启动崩溃常掩盖真正的空指针/初始化错误，先排查自身初始化路径**"。
   → Drift 对策：`FontManager` 初始化**必须**在任何绘制前完成并断言非空（G2），失败走 `Errors::report` 而非崩。
2. **ICU 是运行期硬依赖**。GUI 仓用 `SkUnicodes::ICU::Make()` 做断行/字素/双向，**运行期硬依赖 `icudtl.dat`（778 KB），缺失即 `check(fUnicode)` abort**。
   → Drift 对策（三条，按优先级）：① 把 ICU 数据经 `embedded-files` 或 PE 资源**打包进 exe**（【手册】§10），交付仍是单文件；② 若体积不可接受，退"内置简体中文断行 + 拉丁"的最小实现（放弃 bidi）；③ 中文断行规则（禁则）单独实现，不依赖 ICU 的完整 UAX #14。
   → ⏳ **开放项**（§24.2）：三档按体积预算选。

### 8.4 IME 协议（`TextInputClient`）

编辑器实现协议（`setEditingState` / 组合区间 / **插入符矩形上报** / 撤销回写），平台 IME 桥接：

- Win32：IMM32 组字 + **系统插入符登记**。GUI 仓实测：第三方输入法（搜狗 / QQ 拼音）靠 `GetGUIThreadInfo` 取插入符矩形定位候选窗，**自绘窗口不登记 ⇒ 候选框永不出现**。
- IME 定位一律用**光标行**几何；焦点判据用 `focusedElementId`（不用可能过期的副本）。
- 加速键与 IME 的让位规则见 §9.1。

---

## §9 输入 / 手势 / IME / 无障碍模型（重点 6）

### 9.1 路由链

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
                        └─ 光标形状（WindowHost::setCursor）
```

| 件 | 裁决 |
|----|------|
| **命中测试** | 从根 RenderObject 深度优先，`hitTest(x,y)` 返回最深的命中盒；**禁用盒仍参与并吞掉事件**（absorb —— 禁用 ≠ 穿透，点禁用按钮不应落到背后的控件上）；显式穿透只有 `IgnorePointer`；`Offstage` 直接返回 null。★ **一次命中，三方消费**：事件路由 / 语义 / 光标形状（不重复走树） |
| **手势** | `GestureArena`：多个识别器可同时竞争（拖动中的 tap 让位），胜出者独占直到手势结束；识别器是**无状态的判定函数 + arena 成员资格**，不是每控件一份定时器 |
| **指针捕获** | 按下即捕获（Win32 只把鼠标消息发给光标下的窗口 ⇒ 不捕获则按下态永不释放、拖拽永不结束）；`WindowHost::setCapture()` |
| **光标形状** | `RenderObject` 声明 `cursor`（`CursorShape` backed enum：`Default`/`IBeam`/`PointingHand`/`SizeAll`/`SizeNS`/`SizeWE`/`Wait`/`NotAllowed`）；`EventLoop` 在 `WM_SETCURSOR` 时问**命中链**，取最深声明者 ⇒ `WindowHost::setCursor()`。**不给控件开自绘光标的口子**（自绘光标要自己画、自己跟帧，得不偿失） |
| **快捷键 / 加速键** | `Drift\Input\AcceleratorTable`，挂在 `Application` 上（**应用级数据，不是平台能力** ⇒ 零新端口）。`EventLoop` 在派发 `KeyEvent` **之前**查表。冲突处理两条：① **IME 组合期不吃快捷键**（否则中文输入会误触）；② **编辑命令优先**：`TextInputClient` 可声明独占键集（`Ctrl+A/C/V/X/Z`），命中时让位给输入框 |
| **菜单** | **自绘控件**（`MenuBar` / `Menu` / `MenuItem` / `ContextMenu`），零平台端口。原生菜单栏**明确不做**（理由见 §5.1）。上下文菜单走右键手势（GestureArena 已支持） |
| **焦点** | `FocusNode`；焦点是**渲染态**（不放业务值） |
| **焦点遍历顺序** | 默认 = **文档序（深度优先）**，与 Flutter / Qt 一致。容器可 `->skipFocus()` 整体跳过（如 AppBar 工具按钮组，**显式**，不隐式）。需定制时用 `->focusOrder(int)`，同一父容器内**稳定排序**。★ **lint 判据**：同一容器内混用"有 `focusOrder`"与"无 `focusOrder`"的子节点 ⇒ 报错（否则隐式相对顺序有歧义，属 G2） |
| **IME** | `TextInputClient` 协议（§8.4）；caret / anchor / 撤销栈归属 State 的渲染态域（GUI 仓 V1 结案结论：外部不可能知道"用户点到哪、按了几个左箭头"） |
| **无障碍** | `SemanticsTree` 从 RenderObject 的 `SemanticsConfiguration` 生成：角色 / 标签 / 动作 / 取值 / 状态；**树是数据** ⇒ 可序列化 ⇒ 探针可断言。Windows UIA 桥接列为 M5+ |

> **P3 陷阱**：语义**不能**靠覆写 `protected` 钩子实现（子类覆写可能被去虚化 ⇒ **静默不跑**）。语义写成**字段/构造参数** —— GUI 仓的 `SemanticsBuilder` 已是这个形态。

**用法示例**（完整版见 §A.3）：

```php
// 加速键：应用级注册，零平台端口
final class UsersApplication extends Application
{
    protected function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::ctrl('N'), 'nav.signup')
            ->bind(KeyChord::f(5),        'users.refresh');
    }
}

// 菜单：自绘，放在 Dock::top 里
MenuBar::make()->menus([
    Menu::make('文件')->items([
        MenuItem::make('新建用户')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
        MenuItem::separator(),
        MenuItem::make('退出')->command('app.quit'),
    ]),
])

// 光标形状：控件声明，命中链取最深者
Row::make()->cursor(CursorShape::PointingHand)->children([...])
```

### 9.2 系统环境变更

`DpiChanged` 与 `ThemeChanged` **走同一条通道**，因为它们的后果相同：**环境变了 ⇒ 全树重建**（不是 relayout —— 主题变了字号/颜色都会变）。

| 事件 | 平台来源 | 框架响应 |
|------|---------|---------|
| `DpiChanged` | `WM_DPICHANGED` | 更新 `Screen::scale` ⇒ 全树重建（§6） |
| **`ThemeChanged`** | `WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`） | 更新 `Screen::systemTheme()`（`Light`/`Dark`）⇒ 全树重建 |
| **高对比度** | `SystemParametersInfo(SPI_GETHIGHCONTRAST)` | 并入 `ThemeData`（作为 token 的覆盖层） |

---

## §10 公开 API 稳定（重点 7）

### 10.1 三个面，三种表达手段

一个 SDK 有**三个不同的"面"**。它们**互不重合**，而且**各自只有一种可行的表达手段** —— 混用必然出错：

| 面 | 定义 | 唯一可行的表达手段 | 能强制吗 |
|----|------|-----------------|---------|
| **① 物理边界** | 哪些文件属于框架包 | **composer PSR-4 前缀**（`Drift\` → `src/`） | ✅ 工具强制（autoload / 文件扫描） |
| **② 架构分层** | 包内**谁能依赖谁** | **命名空间 + §4.3 依赖规则 lint** | ✅ lint 强制 |
| **③ 公共契约面** | **承诺不破**的签名 | **`@api` 标注** + `api-surface.json` 快照 | ✅ lint 强制（lib 形态下另有 ABI 强制） |

★ **为什么不能用"命名空间白名单"表达 ③**（三条硬理由）：

1. **组合根必须引用平台实现** —— 这是 §5.1"平台类名只出现在每平台引导文件"的**必然结果**。⇒ `Drift\Platform\Win32` **必须**对应用可见。不是"清单漏了"，是**清单这条路本身走不通**。
2. **PHP 没有"包内私有"** —— `@internal` 是**文档约定**，不是语言强制（`internal` 关键字是 **namespace 级**可见性，不是包级）。用户技术上能 `use` 包内**任何** public 类型 ⇒ **"命名空间 = 可见性"在 PHP 里根本不成立**。
3. **同一个模型错两次就该换模型**，而不是再补一次清单（第一次：给 `seal()` 开豁免；第二次：组合根违规）。

★ **物理隔离用哪个机制，取决于分发形态**（§10.2）：
- **源码同编**（`bin`，`sources` 含框架 `src/`）⇒ 框架与业务在**同一次 `tpc` 调用**里编译成单个 exe ⇒ `@internal` 类型**会被编译进同一个二进制**，**不会**有链接期/运行期错误 ⇒ **lint 是唯一防线**；
- **`-m lib` 共享库** ⇒ `#[NoExport]` 让内部符号**根本不进 ABI** ⇒ 业务**想引也引不到**（链接期就找不到符号）⇒ **编译器强制，比 lint 更强**。

#### 10.1.1 ① 物理边界：前缀划分

| 前缀 | 目录 | 归属 | 升级时谁负责 |
|------|------|------|------------|
| `Drift\` | `src/` | **框架包** | 框架（按 §10.3 第 1 条的语义化版本） |
| `Drift\Dev\` | `tools/` | 工具链（**不进 composer autoload**，工具入口自行注册） | 框架 |
| `App\<X>\` | `apps/<x>/src/` + `apps/<x>/bin/` | **业务代码** | 应用作者 |

★ **判据**：`composer.json` 的 `autoload.psr-4` **就是**这张表的机器可读形式 ⇒ **不需要任何新机制**（§4.1）。

#### 10.1.2 ② 架构分层：命名空间 = 目录（**不是**权限）

**§4.2 的全部命名空间都对应用可见**，因为它们表达的是**分层**，不是权限。

| 谁守什么 | 规则 |
|---------|------|
| **"谁能依赖谁"** | §4.3 依赖规则 lint（`Rendering` 不得依赖 `Widget` 这类） |
| **"用户能不能用"** | §10.1.4 的 `@api` 标注（**与命名空间无关**） |

⇒ 这是两个**正交**的问题。

#### 10.1.3 ③ 公共契约面：`@api` 标注（唯一判据）

**判据**：**标了 `@api` 的类型/方法 ⇒ 在契约面内；其余一律 `@internal`。**

| 标注 | 含义 | 业务引用的后果 |
|------|------|--------------|
| `@api` | 承诺向后兼容（按 §10.3 第 1 条的语义化版本） | ✅ 正常使用；签名进 `api-surface.json` |
| `@api extension`（可选细分） | 给**控件作者**的 API（`StatefulWidget` / `State` / `RenderBox` / `Canvas` / 布局契约 / `SubscribingWidget`） | ✅ |
| `@api platform`（可选细分） | 给**平台适配者**的 API（`Platform` 接口族 / `WindowHost` / `Screen` / `SurfaceProvider`） | ✅ |
| `@internal`（**默认**） | 不保证兼容；业务引用 ⇒ lint **error** | ❌ 除非显式豁免 |

**★ 默认值是 `@internal`，不是 `@api`** —— 这是 G2 的落点：

- 默认 `@internal`：漏标 ⇒ **编译期报错**（"`App\` 引用了未标 `@api` 的 `Drift\…`"）⇒ **响亮失败**，改一行标注即可；
- 默认 `@api`：漏标 ⇒ **悄悄进了 `api-surface.json`** ⇒ 再想收回来**就是破坏性变更**（§10.3 第 1 条：破坏性只在主版本）⇒ **静默扩面**。

**★ `@internal` 的引用是 error 级，但可显式豁免** —— **复用 GUI 仓 R11/R12 已验证过的"两级严重度 + 白名单"机制**（"未登记 = error 挡新债 / 已登记 = warn 留痕"），不新发明：

```php
/**
 * @internal-use-allowed: 等 upstream 修 #123 后移除
 * 责任人：Acme\Hello\src\pages\UserPage.php
 */
$el = $this->widget()->element();      // 引用 @internal ⇒ 有这条豁免才放行，且降为 warn
```

**★ 三类用户（应用作者 / 控件作者 / 平台适配者）的细分是"可选细分"**：M0 只强制二值（`@api` / `@internal`），需要时再细分，**不阻塞 M0**。

**★ 为什么"角色"值得留**：`api-surface.json` 能**按角色 diff** ⇒ "这次改动只影响控件作者，不影响应用作者" ⇒ 语义化版本的判定更精确、changelog 能按受众分组。

### 10.2 分发形态三选一（**【实测】**）

`tpc` 有 **`-m lib`** 模式（【手册】§4；权威细节见 `typephp/docs/zh-cn/MIXED_CPP_PHP.md` §3）。**它改变了"物理隔离"和"SDK 形态"两件事**：

| 分发形态 | 产物 | 物理隔离靠什么 | 应用侧 `project.yml` 要什么 |
|---------|------|--------------|--------------------------|
| **A. 源码同编**（`-m bin`） | 单 exe | ❌ 无 ⇒ **只能靠 lint** | `sources` 含框架 `src/` + `cpp-src/` + **Skia include/link 路径 + 全部 link-libs** |
| **B. `-m lib` 共享库** | `<target>.stub.php` + `.dll` + 导入库 `.lib`（Linux `.so`） | ✅ **`#[NoExport]` + 编译器生成的 stub**（链接期强制） | `sources` 只加 **`<target>.stub.php`**（`linkLibs` 由 tpc **自动追加**）⇒ **不需要 Skia 头/库** |
| **C. `-m ext` 扩展** | PHP `.dll`/`.so`（加载进 PHP SAPI） | ✅ 导出面由扩展注册决定 | ❌ **明确不做**（GUI 要自带运行时；且 §17.2 已裁定"不需要任何扩展"） |

> ★★ **注意 C 与 B 在符号解析层是同一件事**：上游把"**共享运行时的库**"（`libraryEmbedsPhp = false`）
> 在符号解析上**按 `BUILD_MODE_EXT` 处理**（`NativeCommandOptionsTrait.php:212-217`，注释原文
> "A shared-runtime library resolves Zend symbols from its consumer, just like a PHP extension"）。
> ⇒ 形态 B 与形态 C 的差别只在**入口形态**（B 是 exe 链接它；C 是被 PHP SAPI 加载），不在"Zend 符号从哪来"。这也解释了为什么 B 必须与宿主**同 PHP 版本**。

#### 10.2.1 形态 B 已端到端跑通（GUI 仓 spike，**【实测】**）

| 角色 | 配置 | 关键点 |
|------|------|--------|
| **库侧** | `sdk/project.yml`：`mode: lib` · `sources: [../src, ../diagnostics, ../cpp-src]` · 完整 Skia `link-libs` | 从仓库根跑 `tpc sdk/project.yml -O2 -m lib` |
| **消费侧** | `apps/spike/project.yml`：`mode: bin` · `sources: [main.php, ../demo/src, ../../typephp_gui.stub.php]` | **不含** `src` / `cpp-src`；`ld-flags` 从 **35 行缩到 1 行**（Skia 21 库全部烘进 `.dll`） |

**四条判据全过**：① 子类化跨边界（含 protected 成员）② 回调方向（lib 的 `run()` 调到应用覆写的钩子）③ **闭包跨 `.dll` 边界** ④ 桥接链接（建真窗 + 跑一帧）。
**终态 `372 ok / 1 fail`**，与 `-m bin` demo 基线逐条一致（唯一 fail = 真机字体差异）。
⇒ **§10.2 前文"应用侧不需要 Skia 工具链"不是推论，是实测。**

#### 10.2.2 ★ 硬约束：**发行版 `tpc.exe` v0.9.5 不含 lib 修复 ⇒ 形态 B 必须用源码树 tpc**

**判据（生成物 diff，**【实测】**）**：同一份最小 `-m lib` 工程分别跑**发行版 `tpc.exe`** 与**源码树 tpc**（`php bin/tpc.php`），对生成的 `extension-probe.cc` 做 diff：

| 生成物特征 | 发行版 v0.9.5 | 源码树 master |
|-----------|--------------|--------------|
| `php_request_users` 引用计数 | ❌ 无 | ✅ 有 |
| RINIT 二次进入 | `php_error_docref("already initialized")` + **`return FAILURE`**（修复前形态） | `++php_request_users; return SUCCESS;` |
| 导出 `typephp_<target>_library_request_startup/shutdown()` | ❌ 无 | ✅ 有 |
| persistent cache 重置 | ❌ 无 | ✅ 有 |

**时间线**：版本打点 `21770951`（0.9.5）= 10-13 14:59 · 修复 `89fe80aa` = 10-13 17:43 · 发行版文件 mtime = 10-13 07:53 ⇒ **发布早于修复**。

⇒ **落地约束（硬）**：
- **形态 B 必须用源码树 tpc**（`php bin/tpc.php` + `PHPX_HOME`），**不能用发行版 `tpc.exe`**；
- 或等上游下一个发行版（届时重跑一次上面这张 diff 表即可确认）；
- ⇒ **§16 的工具链脚本必须提供两个入口**：`build.sh`（发行版，形态 A）与 `build-lib.sh`（源码树，形态 B）。

#### 10.2.3 `-m lib` 的实测事实（**【读源码】**，非推测）

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
| **传递导入不会被再导出** ⇒ 若 Drift 的库自己也导入别的库，消费方必须**另外**提供那个库 | `LibraryImportStubGenerator.php:40-42` |
| 库构建仍**需要 PHP 运行时与 PHPX**（Windows：`php8.lib`/`php8ts.lib` 或 `php8.dll`；**`phpx.lib` 链接期 + `phpx.dll` 运行期**）⇒ **不是完全自包含产物** | `Platform/Windows.php:183-230` |

#### 10.2.4 形态 B 的收益与代价

**最大收益**：**应用侧完全不需要 Skia 头文件与 20 个 link-libs**（全部烘进 `.dll`）。§16.4 那份"cxx-flags / cxx-std / include-paths / link-paths / link-libs 一大堆"的 `project.yml`，对第三方应用会缩到几乎为空。⇒ **这是"框架作为 SDK 分发给第三方"唯一现实的形态。**

**代价（诚实写清）**：

| # | 代价 | 状态 |
|---|------|------|
| 1 | **失去跨模块内联** —— `layout()` / `paint()` / `mutate()` 这些热路径在单编译单元里能被内联；跨 `.dll` 边界只剩一次间接调用。§15.3 的 **0.92 µs/节点**是在**同编**下测的 | ⏳ **退化幅度仍未测**（GUI 仓 spike 只验了"对不对"，从未量"快不快"） |
| 2 | **部署变成多文件** —— AOT 单 exe 的"一个文件"优势没了（`.exe` + `drift.dll` + PHP 运行时/PHPX 运行期依赖）。★ **唯一已知解法是 `--nano`**（把 PHPX 源码编进产物、`getLibraryPaths()` 返回空），但 **nano 已明确不做**（§16.7.5，**2026-10-14 裁决**）⇒ **形态 B 必须接受"多文件部署"，不再指望 nano 消除它** | ❌ **已定：接受** |
| 3 | **版本/CRT 矩阵** —— `.dll` 与宿主必须**同 CRT、同 PHP 版本**（共享运行时按 EXT 解析 Zend 符号 ⇒ 版本必须精确匹配）。§16.4 已踩过 `LNK2038`（`/MT` vs `/MD`） | ⚠️ 部分已知 |
| 4 | **`#[Native]` 类在库模式下不可见**（硬约束）⇒ 若 Drift 想用 `#[Native]` 加速热路径对象，**形态 B 下应用拿不到它们** | ⏳ 实际限制未验 |
| 5 | 闭包跨 `.dll` 边界 | ✅ **已验可用**（同步闭包）。★ 但**异步/跨线程闭包**仍未验（与 §17.2.2 同类边界问题） |

**★ Drift 必须继承的两条永久纪律**（用真金白银换来）：

1. **`cpp-src/*.h` 绝不写 `using namespace php;`** —— D1 根因未修，lib 模式会把聚合头提到每个 TU 最前，一旦桥接头引入 `using namespace php`，Skia 的枚举位运算就会重载歧义。
2. **`tools/build.sh` 从第一天就设 `PHPRC`** —— 见 §2.4 纪律 5 / §16.5 陷阱 4。

#### 10.2.5 形态 A 的两种挂载方式

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

> **★ 两种写法都必须配一条 lint**（`scan-autoload-drift`，§16.6.3）：`project.yml` 的 `sources` 覆盖的命名空间 **必须**与 `composer.json` 的 `autoload.psr-4` 声明一致。
> **为什么必须**：`sources` 是给 **AOT**（tpc 扫文件建符号表）的，`autoload.psr-4` 是给**解释器**（`drift-preview` / 单测 / `drift-lint`）的。**两者不一致的症状是"preview 能跑、AOT 编译不过"（或反之）** —— 典型的静默不一致，正是 G1 要消灭的。

#### 10.2.6 定位裁决：两种形态都支持，不是二选一

| 形态 | 定位 | 适用 |
|------|------|------|
| **A（源码同编）** | **默认 / 开发形态** | 单 exe、性能上限、调试直接、零版本矩阵 ⇒ **应用自己发布 exe** |
| **B（`-m lib`）** | **SDK 分发形态** | 框架**编译一次**给多个应用复用、应用侧**不需要 Skia 工具链** ⇒ **框架作为 SDK 交给第三方** |

**两种形态下"公共面"的表达方式统一**：

| 层 | 形态 A（同编） | 形态 B（lib） |
|----|--------------|--------------|
| **物理隔离**（能不能调到） | ❌ 无 ⇒ 退化为 **lint**（`internal-leak`） | ✅ **`#[NoExport]` + 编译器生成的 `.stub.php`**（链接期强制） |
| **契约承诺**（承诺不破） | `@api` 标注 + `api-surface.json` | 同左，**且 `api-surface.json` 可从 `.stub.php` 派生** |
| **一致性守卫** | `scan-autoload-drift` | `scan-autoload-drift` + **`export-surface-drift`**（`@api` 面 ⊆ stub 导出面） |

⇒ **`.stub.php` 是"技术上能调到的上界"（机器产出），`@api` 是"承诺不破的面"（人标注）**。两者**不同**：`@internal` 的符号**可以**出现在 stub 里（因为某个 `@api` 签名引用了它），但**不在**契约面里。
★ 而 `#[NoExport]` 的**级联排除**正好对应"公共签名引用了内部类型"这个两难：被排除符号的依赖也一并排除 ⇒ 若一个 `@api` 方法签名用了 `#[NoExport]` 的类型，**签名会不完整** ⇒ **编译器/链接器会响**（不静默）。【手册】的提醒印证了这点："发布库时请对照自动生成的 `<target>.stub.php` 核对导出清单。"

### 10.3 治理七条

1. **语义化版本**：补丁只修 bug；小版本**只加不破**；破坏性只在主版本。
2. **`@api` 标注（判据唯一化）**：契约面每个类型/方法标注；**未标注 = `@internal`（默认）**。CI 有 **API 表面快照**（`tools/api-surface.json`，**只含 `@api` 签名**，diff 即评审事件）。
3. **弃用策略**：`@deprecated` 至少保留一个 minor；删除必须在 changelog 点名。
4. **"要么不提供、要么必须生效"**：声明了但从未被读的 API 比不提供更糟 ⇒ **死字段 lint 常设**。
5. **控件是纯函数**：值 → 描述；**订阅责任只能落在实现了 `SubscribingWidget` 接口的控件上**（`SubjectBuilder` / `PageHost` / `StatusBar`），不能落在渲染型控件上。
   - ★ **判据是"接口标记"，不是"命名约定"**。理由：**命名约定被方案自己的例子否证** —— `StatusBar` 的名字不含 `*Builder`。接口标记让"专职订阅"从**约定**变成**类型事实**（G1），同时给 §21 A16 的 lint 提供判据。
6. **糖与契约分离**：语法糖只允许转发权威构造器，不许自造契约 —— **删掉全部糖，框架契约与测试原样完整**（反向不成立）。
   - ★ **澄清**：**变体工厂允许转发到"同族的另一个控件"**，不只限于同类。例：`Button::primary('ok')` ≡ `Button::make('ok')->variant(ButtonVariant::Primary)`。
     ⇒ 这比"为糖开一个例外"好：第 6 条是方案里最硬的一条纪律，为它开例外会削弱它。
   - ★ **越界判据（2026-10-14 补，来自 §23.2 O-16）**：糖只许是"**固定变体**"（`Button::primary`），**不许是"参数化的机制封装"**。`TextField::bind($cell)` 曾是本条的例子，但它把"订阅 + 读 + 写"三种机制打包成一个三参数 API ⇒ **越界** ⇒ 已删。
7. **SDK 发布契约**：`sources`（AOT 扫描面）与 `composer.json` 的 `autoload.psr-4`（解释器面）**必须一致** ⇒ lint 强制（§16.6.3）。

### 10.4 稳定的四个具体承诺

| 承诺 | 机制 |
|------|------|
| 公共签名不悄悄变 | `tools/api-surface.json` 快照（**只含 `@api`**）+ CI diff |
| `@internal` 不被业务引用 | lint `internal-leak`（**非 `Drift\` 前缀**引用了 `@internal` ⇒ **error**；有豁免标注 ⇒ warn） |
| 每个公开参数都被真正读取 | 死字段 lint（**W3**，§16.6.2） |
| **SDK 扫描面与 autoload 面一致** | `scan-autoload-drift`（§16.6.3） |
| **（`-m lib` 形态）`@api` 面 ⊆ ABI 导出面** | `export-surface-drift`（§16.6.3；对照编译器生成的 `<target>.stub.php`） |

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
│         SubjectBuilder —— 专职订阅控件                       │
└────────────────────────────────────────────────────────────┘
        │ build($ctx) → Widget（纯值、不可变）
┌────────────────────────────────────────────────────────────┐
│ 视图层  三树 + 动作缝：命令 command('a.b')                    │
│         + 纯 UI 回调（->onPressed(fn)，不承载业务）           │
└────────────────────────────────────────────────────────────┘
```

**隔离的三样**：**持有**（数据归谁）、**规则**（业务逻辑）、**变更来源**（谁发起变更）。
**State 瘦身判据**：业务编码（如 `"\x00"` 分隔符）在 State 里出现次数 = **0**。（= GUI 仓 `lint R10` 的判据，见 §16.6.1）

★ **"局部闭包"与"命令"的界线**（消歧，避免与 §11.4 裁决 2"提交走命令不走闭包"冲突）：

| 用途 | 用什么 | 例 |
|------|--------|-----|
| **业务动作**（提交、删除、导航、加载） | **命令**（字符串 + `CommandArg`，可审计、可 lint、可跨树复用） | `->command('user.del')` |
| **纯 UI 回调**（不承载业务语义，只改本页纯 UI 态） | 局部闭包 | `->onPressed(fn() => $this->setState(...))` |

⇒ **判据**：这个回调**会不会改变业务数据 / 触发 IO / 需要跨树复用或审计**？会 ⇒ 命令；不会 ⇒ 闭包。

### 11.2 三轴正交（数据来源 × 绑定方向 × 更新时机）

| 轴 | 回答 | 取值 |
|----|------|------|
| A 数据来源 | 从哪来 | 内存 / 文件 / HTTP / 输入 |
| B 绑定方向 | 谁驱动谁 | 上行（UI→业务）/ 下行（业务→UI）/ 双向 |
| C 更新时机 | 何时、重建多少 | 显式 setState / 显式 notify（**无自动追踪**） |

**核心判断**：A 轴（远端 / 本地）与 B/C **正交**。HTTP 只决定"可能慢、可能失败"，不改变绑定机制一个字。**三态（loading / error / ready）的必要性由"延迟/失败"决定，与"是否远端"无关** —— 本地文件 IO 同样需要三态。

### 11.3 做进设计的四件事（GUI 仓里它们是补丁，Drift 里是地基）

| GUI 仓现状 | Drift 设计 |
|-----------|-----------|
| RT-1 订阅生命周期无守卫（忘了退订 ⇒ 泄漏 + 僵尸更新，**静默**） | `State` 持**订阅袋**：`watch()` 记账；`dispose()` 基类统一全退；`mounted` 守卫；**build 期订阅 ⇒ 立刻上报** |
| RT-3 一个订阅者抛异常 ⇒ 其余全收不到（GUI 仓全仓 0 个 try/catch） | `notifyChanged()` 每订阅者异常隔离 + 写 `Errors` 出口 |
| RT-4 相等门控（设同值也广播） | `Cell` / `setIfChanged` 进原语；`===` 严格比较 |
| RT-2 盒属性同步（两份手写字段赋值 ⇒ 静默分叉） | Widget 字段 → 盒字段**单一同步路径** + 字段对齐 lint |

#### 11.3.1 三种"变了"的消歧

| 原语 | 层次 | 谁调 | 语义 | 门控 |
|------|------|------|------|------|
| `Subject::notifyChanged()` | **业务层**（`Store` / `FormData`） | 业务代码，改完数据**手动调一次** | "我的数据变了，请所有订阅者重建" | **无**（同值也广播 ⇒ 业务侧要自己避免无谓 notify，或用 `Cell` 包字段） |
| `State::setState(fn)` | **绑定层**（页面的 `State`） | 页面代码，改**纯 UI 态** | "只标脏我自己，帧末重建我的子树" | **无**（`State` 不比较值） |
| `Cell::setIfChanged($v)` | **State 层的单值单元** | 需要"设同值不重建"时 | "值真的变了才广播" | **有**（`===` 严格比较） |

**一句话判据**：
> **业务数据**变 ⇒ `Subject::notifyChanged()`；**UI 态**变 ⇒ `State::setState()`；**想要"设同值不重建"** ⇒ 那个值用 `Cell` 装，别裸放。

**`Cell` 的使用样例**：

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
        return Each::make(fn(User $r): int => $r->id)->items($this->rows(), fn(User $r): Widget =>
            Row::make()->hovered($r->id === $h)->children([...]));
    }
}
```

> ★ `Cell` 与 `Subject` 的**分工不是"大/小"而是"谁的门控"**：`Subject` 是"广播端，无门控"（它一次可能改了 5 个字段，门控无意义）；`Cell` 是"单值端，有门控"。
> **不要**为了门控把 `Store` 的每个字段都换成 `Cell` —— 那会让 `Store` 依赖 `State` 层类型，违反"业务层零 GUI 依赖"。

#### 11.3.2 两条纪律推论

1. **订阅控件不得跨 `build()` 缓存复用。** `SubjectBuilder` 也是 Widget，受 §15 的 Seal-on-Mount 约束。若开发者把它建好存进字段、下帧再塞回树里，**它捕获的闭包是旧快照** ⇒ 看着是"订阅了"，实际订阅的是上一帧的 `$store`。⇒ **纪律：订阅控件必须在 `build()` 内构造**，与 §21 A4（build 期订阅）配成一对。
2. **订阅粒度不得细到"每列表项一个订阅"。** 1000 行的列表若每行 `State` 各自 `watch($store)`，就有 1000 个订阅者，任一 `notify` 要跑 1000 次回调（还都要过 §11.3 RT-3 的 try/catch）。⇒ **纪律：订阅落在"列表容器"那一层，行内只吃值**。这也是 §12.3 虚拟化回收的前提 —— 项 `State` 被 dispose 时若还挂着订阅，回收成本会随视口滚动反复付。（订阅袋的 `dispose` 全退是**兜底**，不是**方案**。）

#### 11.3.3 订阅粒度：`SubjectBuilder::select()`

**问题**：订阅粒度 = **整个 `Subject`** 时，一个 `Store` 有 10 个字段 + 10 个订阅者 ⇒ 任一字段变，**10 个全重建**。

靠"每个切片自己是一个 `Subject`"绕（`$store->statusLine()` 返回 `Subject`）会让 **`Store` 必须暴露 N 个 `Subject`**，而且**隐含依赖"每次调用返回同一个实例"**（不可 lint）。⇒ 那**不是"没做"，是"做了个坏版本"**（= §18 从 Flutter 学来的要避开的 "provider soup"：**业务反向依赖 UI 的关切**）。

**裁决：`SubjectBuilder` 增加 `select()`**：

```php
SubjectBuilder::make($store)
    ->select(fn(UserStore $s): array => $s->rows())        // 只关心这一个切片
    ->builder(fn(array $rows): Widget => ...)
```

| 收益 | 说明 |
|------|------|
| 粒度细，**且 `Store` 不必暴露 N 个 Subject** | 纯函数式 ⇒ **没有实例身份问题** |
| 相等门控**复用 §11.3 RT-4** | 落在 `select` 的**返回值**上（`===`），**不引入第二套相等机制** |
| **可 lint（G1）** | `select` 闭包里出现的属性集合 = 该订阅者**真正依赖的字段集** ⇒ **编译期可生成依赖清单**（= §16.1"远期编译期推导"的第一步） |
| 每帧成本 | 一次闭包调用 ⇒ 可忽略 |

**★ 门控的两端是什么**（最容易被误读的一处 —— **它不是"Store 的新旧值对比"**）：

比的是 **上一次 `select` 的输出** vs **这一次 `select` 的输出**；**旧值缓存在订阅者自己身上**（`State` 的一个字段），框架**不保存 `Subject` 的快照**。

```php
// §A.2.11 StatusBar 的实体：$this->text 就是"上次输出"的缓存槽
$v = ($this->widget()->getSelect())($this->widget()->getSource());
if ($v === $this->text) { return; }          // ★ 两次输出比较，不是 Store 新旧值
$this->setState(fn() => $this->text = (string) $v);
```

**三条推论**（都从"比的是两次输出"推出）：

1. **`select` 闭包每次都跑** ⇒ 门控省下的是 `builder()` 的**子树重建**，**不省 `select` 的计算**。⇒ 闭包必须廉价（这也解释了 §11.3.4 为什么让 `visible()` 走缓存 getter，而不是在 `select` 里现过滤）。
2. **返回值是你加工过的** ⇒ `select` 一个"摘要"（如 `rowsVersion()`）时，底层 1000 行变了而摘要没变 ⇒ **不重建**。这是"选择能代表变化的最小值"的正解（§23 O-06），也可能掩盖真实变化 —— 要知情使用。
3. **返回"每次新建的对象/数组" ⇒ 永远不等** ⇒ 两次输出是两个不同实例 ⇒ 门控失效、每帧重建（这就是下面契约 1 的机制解释）。

**★ 与 `Cell::setIfChanged` 的区别**（都叫"门控"，但比的东西不同）：

| 原语 | 比较的两端 | 是"新旧数据对比"吗 |
|------|-----------|------------------|
| `Cell::setIfChanged($v)` | **同一字段的旧值 vs 新值** | **是** —— 真的是"原来的数据 vs 变更后的数据" |
| `SubjectBuilder::select` | **上次闭包输出 vs 本次闭包输出** | **不是** —— 是两次**函数输出**比较；框架不存 `Subject` 快照 |

**★ 三条必须写进契约的话**：
1. **`select` 的返回值必须是标量或不可变值对象**（否则 `===` 门控失效 —— 数组 `===` 是**逐元素严格比较**，对象是**同一实例**；返回"每次都新建的对象"会让门控退化成"每次都不等"⇒ 每帧重建）。
2. **`select` 闭包不得有副作用**（它每帧都会跑，且会被 lint 用来推导依赖）。
3. **大数组切片：`===` 是 O(n)，但实测**不构成问题**（探针 F，数据见 `probes/RESULTS.md §6`）**：最坏形态（1000 嵌套数组、内容相同但数组实例不同）也只有 **40.3 µs = 1000 节点重建的 4.4%**；列表页真实形态（`Store` 持有对象数组 ⇒ 返回同一个 `zend_array` ⇒ COW 命中引用比较）只要 **0.020 µs**。⇒ 见 §23.2 O-06：**不加机制、不加 lint**，只保留一条纪律 —— **`select` 返回数组时应返回 `Store` 持有的稳定数组（不要每帧新建），并优先选摘要**。

**★ 与 `builder()` 签名的兼容**：`select` 之后 `builder` 要收参数 ⇒ **无 `select` 时 Element 调 `$f()`、有 `select` 时调 `$f($v)`**（★ **不是**统一传 `null` —— 统一传 `null` 会凭空多出"传 null 给有类型形参"这条静默路径，见探针 G 读数 ③′/④）。

★ **类型系统强制不了这件事**（**2026-10-14 实测**，见 §23.2 O-05 复核发现 / `probes/RESULTS.md §7`）：PHP 的 `callable` 是**不透明类型**（语言层面无法表达"1 元闭包"），用户态泛型也不可用 ⇒ `SelectedBuilder<T>` 的 `<T>` 只能是文档；`tpc -m bin --dry` 对 docblock 里的 `callable(T): string` 与**故意元数不符**的实参**零诊断**。⇒ 想要机器守卫只能用 **lint**（§16.6.3 的"闭包签名"本就在"编译期可见"清单里）。

★ **诚实边界**：五个错误方向里**只有一个静默** —— "有 `select` 但 `builder` 忘了接形参"。而它**写作时自纠**（不接形参就没有 `$st` 可 `match`）、**运行时立刻可见**（渲染成静态内容；选择器仍在门控、不崩）⇒ 属"**立刻可见的错误**"，不是 G2 要消灭的"静默错误"。⇒ **不加守卫**（§23.2 O-05：推荐**不做**）。

#### 11.3.4 派生值（computed）的落点

**问题**：`fullName = first + ' ' + last` 放哪？放 `Store` getter ⇒ **每帧重算**；放页面 `build()` ⇒ 每帧重算。

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
2. **有了 `select()`，`Computed` 更不必要**：`select(fn($s) => $s->fullName())` ⇒ 门控落在**返回值**上 ⇒ `fullName` 没变就不重建。⇒ `Computed` 的"可单独订阅"收益被 `select` 拿走，只剩"省一次重算"，而缓存 getter 已经省了。
3. **缓存 getter 的成本可控且可被机器守**：`??=` 一行 + 清缓存一行。★ **lint `stale-derived`**（§16.6.3）：`Store` 里出现 `??=` 赋值的属性 ⇒ 所有会修改其**依赖字段**的方法里必须有 `$this->xxxCache = null`。判据静态可判（字段集编译期已知）。

**★ 诚实边界**：缓存 getter 只解决"重算"，**不解决"订阅者只跟它"** —— 后者交给 `select()`。

### 11.4 上行：值回家，提交走命令

#### 11.4.1 表单值：property hooks 进 Store（M2）

```php
final class SignupForm extends Subject          // 业务层：不认识任何 GUI 类型
{
    public string $name  = '' { set (string $v) { $this->name  = $v; $this->notifyChanged(); } }
    public string $email = '' { set (string $v) { $this->email = $v; $this->notifyChanged(); } }

    public function validate(): array { /* … */ }
    public function toApiPayload(): array { /* ★ 唯一序列化出口 */ }
}
```

**收益**：字段名从**字符串键**变**属性**（拼错 = **编译错误**，不是静默）；`get()/set()` 两个方法消失；`(string)` 转换消失（属性已带类型）。**框架零改动** —— 只是 Store 的写法。

#### 11.4.2 表单控件：**不提供** `bind()`（M3）

**✅ 已裁决（2026-10-14，§23.2 O-16 方案 A′）**：**删除 `Bound` 原语，同时删除 `TextField::bind()`**。表单控件的官方形态就是 §A.2.9 一直在用的那一种 —— **`SubjectBuilder::select(...)` 包一层 + 手写 `value() / onChanged()`**。

**为什么不做"一行双向绑定"**（四层论据，完整对照见 §23.2 O-16）：

| 层 | 结论 |
|----|------|
| **语言层** | ★ **实测**（探针 J，`probes/RESULTS.md §10`）：PHP **禁止对带 `set` hook 的属性做任何间接修改**（`&$f->p` 与 by-ref 传参**都抛 `Error`**）⇒ **"字段名出现 1 次"与"写入自动通知"在 PHP 里二选一**。§11.4.1 已选后者 |
| **收益层** | 可行形态必须传 **read + write 两个闭包** ⇒ `$form->name` **仍出现 2 次**（与手写**完全等价**） |
| **结构层** | 它既不是"契约原语"（能力已被 `SubjectBuilder::select` 覆盖），也不是 §10.3 第 6 条允许的"**固定变体**"糖 —— 它是**参数化的机制封装** |
| **同源层** | §18 早已把 Vue `v-model` / WPF 绑定表达式 / SwiftUI 属性包装器**全部列入"避开"栏** |

**官方形态（照抄这个）**：

```php
// 表单字段：SubjectBuilder 包一层 + 手写 value/onChanged
SubjectBuilder::make($form)
    ->select(fn(SignupForm $f): string => $f->name)            // ★ 属性（拼错 = 编译错误）
    ->builder(fn(string $v): Widget =>
        TextField::make()->value($v)->label('姓名')
            ->onChanged(fn(string $x) => $form->name = $x))    // ★ 写回：hook 里自动 notifyChanged()
```

**代价与收益（诚实版 —— 与 §11.4.2 旧稿的"摩擦点对照"不同，那张表按**做不到**的形态算）**：

| 指标 | 字符串键写法（v1） | **本形态（A′）** | 旧稿宣称的 `bind()` |
|------|------------------|----------------|-------------------|
| 字段名出现次数 | 3（**字符串键**） | **2**（**属性**，拼错 = 编译错误） | 1（**做不到**） |
| 手写闭包 | 1 | 1 | 0（**做不到**） |
| `(string)` 转换 | 1 | **0**（属性已带类型） | 0 |
| 要记得包 `SubjectBuilder` | — | ✅ 要 | ❌ 不用 |

⇒ **相对 v1 的真实收益 = "字符串键 → 属性"（编译期安全）+ 去掉 `(string)` 转换**；"1 次出现 / 0 个闭包"是**宣称过但做不到**的部分。★ 代价是真的：每个字段要写 4 行，且**必须记得包 `SubjectBuilder`**（漏了 ⇒ 值变了不刷新 ⇒ 由 lint `dead-subscription` 守，§16.6.3 规则 4）。

★ **对照：Flutter / React 也没有绑定原语** —— `TextField(controller: …)` 的 controller 是**用户自己持有的显式对象**，React 受控组件是 `value + onChange`。⇒ **本形态是回归主流，不是能力退化**；`SubjectBuilder::select` ≈ Flutter 的 `ValueListenableBuilder<T>` / `AnimatedBuilder`。

#### 11.4.3 动作缝：命令的注册与派发

```php
// ── 注册：应用级一次（组合根或 Application），可枚举、可 lint、可单测 ──
//    ★ 2026-10-14 裁决（§23.2 O-15，方案 E）：`on()` 收 **handler 实例**，不收类名。
//      实例的类**具名** ⇒ 三条理由（可枚举 / 可单测 / 死命令 lint 的静态可达性）全满足；
//      且依赖**就在构造点** ⇒ 零新 API、零反射、零动态 new。完整样例见 §A.2.5。
final class UsersApplication extends Application
{
    protected function commands(): CommandRouter
    {
        $nav  = new NavigationCommands($this->app);          // 多命令共用 ⇒ 提局部变量（E 的代价）
        $user = new UserCommands($this->users, $this->app);

        return CommandRouter::make()
            ->on('nav.users',     $nav)                      // handler 是**具名类的实例**，不是闭包
            ->on('nav.signup',    $nav)
            ->on('user.del',      $user)
            ->on('form.submit',   new SignupCommands($this->form, $this->src));
    }
}

// ── 派发：从命中的 Element 向上冒泡（与 §9.1 键盘/指针路由同族）──
//   ① 目标 Element 的 State::handleCommand($name, $arg)   → true ⇒ 停止冒泡
//   ② 祖先 State（逐级向上，直到 Root）                    → true ⇒ 停止冒泡
//   ③ CommandRouter（应用级注册表）                        → true ⇒ 停止冒泡
//   ④ 都没有 ⇒ Errors::report（Diagnostic 级）—— 不静默
```

**为什么是"冒泡链 + 注册表"而不是二选一** —— 方案**自己已经要了两件互斥的事**：

| 已有裁决 | 它要求什么 |
|---------|-----------|
| "跨树复用、一个 handler 管一列" | ⇒ 需要**应用级注册表** |
| "页面就近接管"（改本页状态） | ⇒ 需要**树内 handler** |

⇒ **两者都要**，所以是**链**。而且这样一来，`handleCommand(): bool` 那个返回值终于有了明确含义：**"我处理了，停止冒泡"**。

★ **不选另外两条路的理由**（都是"方案自己犯自己的反模式"）：
- **只有页面 handler** ⇒ 放弃跨树复用；且 `bool` 返回值**没人接** ⇒ 变成死代码 ⇒ 犯 §10.3 第 4 条（A1）。
- **只有应用级注册表** ⇒ 放弃"页面就近接管"；`bool` 同样变死代码；且**页面私有命令必须提到应用级** ⇒ 页面不再是自包含单元，§12.2 的"单页独立预览"对**任何带命令的页面**都不成立。

**接口与参数类型**：

```php
interface CommandHandler { public function handle(string $name, CommandArg $arg): bool; }

// ★ arg 不是裸 int —— 裸 int 传不了 string id / 复合参数
final readonly class CommandArg
{
    public function __construct(public int $i = 0, public string $s = '') {}

    public static function none(): self            { return new self(); }
    public static function id(int $id): self       { return new self(i: $id); }
    public static function key(string $k): self    { return new self(s: $k); }
}
```

★ **handler 必须是「具名类的实例」，不能是闭包**（★ 2026-10-14 裁决，§23.2 O-15 **方案 E**）：闭包**不可枚举**（devtools 列不出来）、**不可单测**、且"死命令 lint"需要**静态可达性**（闭包拿不到）。**具名类的实例三条全满足**：`get_class($h)` 可枚举 · 实例可单测 · `new UserCommands(...)` 字面量在调用点静态可见。
（★ 这条与 §17.2 的"TaskHost 收 `Job` 类而非 `callable`"是**同一条设计语言**：凡是要跨边界的东西都是类。）

★ **E 的三条代价（必须一起接受）**：

| # | 代价 | 纪律 / 修法 |
|---|------|------------|
| 1 | handler 在**注册期**即构造 ⇒ 天然是**单例** | ★ **纪律：handler 必须无状态**（跨命令状态属于 Store，不属于 handler）；`handle()` 是纯入口，不持可变状态 |
| 2 | 多命令共用同一 handler ⇒ **要提局部变量** | 见上面 `$nav` / `$user` 的写法（不提变量会 `new` 出多个实例，语义变味） |
| 3 | 依赖清单**隐式化**（不再有一张集中的 `provide()` 表） | 依赖仍在**组合根**（唯一装配入口，§A.2.5 `boot()`）；★ 好处是**漏参 = 编译错误**（A 的 `provide()` 漏参只能靠 lint 抓 —— 见 §23.2 O-15 复核发现 ⑧） |

**handler 侧样例**：

```php
final class SignupCommands implements CommandHandler
{
    public function __construct(private SignupForm $form, private UserSource $src) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'form.submit') { return false; }              // 不是我管 ⇒ 继续冒泡
        $errs = $this->form->validate();                            // 业务层校验
        if ($errs !== []) { $this->form->submitError = '请检查输入'; return true; }   // ★ 反馈回 UI 走 Store
        $this->src->submit('/api/signup', $this->form->toApiPayload());  // ★ 唯一序列化出口
        return true;
    }
}
```

> **★ 反馈回 UI 的路**：提交结果属于**业务状态** ⇒ 放 Store（`$this->form->submitError = '…'`），由 §11.4.2 的订阅形态（`SubjectBuilder::select`）订阅回 UI。**State 只持纯 UI 态** —— 这条现在才真正闭合。

**三个钉死的裁决**：

1. 表单值的家是 **`FormData`（`Subject` 子类，业务层）**，不是 `State`。
2. 提交走**命令**不走闭包（可审计、可 lint、跨树复用、一个 handler 管一列）。
3. `State` 只持**纯 UI 态**，不持业务数据。

### 11.5 下行：Store + 三态 + 订阅点下移

```php
enum LoadStatus: int { case Idle = 0; case Loading = 1; case Ready = 2; case Error = 3; }

final class UserStore extends Subject            // 业务层：不认识任何 GUI 类型
{
    public function __construct(private UserSource $src) {}

    private array  $rows   = [];
    private LoadStatus $status = LoadStatus::Idle;   // ★ backed enum

    public function status(): LoadStatus { return $this->status; }
    public function rows(): array        { return $this->rows; }

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

> **⚠️ 同步后端下的诚实边界**：单线程同步后端里，两次 `notifyChanged()` 处于**同一调用栈**，中间没有任何一帧能被渲染（§5.2：统一重建在帧末 `flush`）⇒ **`loading` 态画不出来**，`status = LoadStatus::Loading` 只是给**错误处理与调试**留的语义，不是可见骨架。
> 结论：**M1–M3 的三态只在 `error` 一支真正对用户可见**；`loading` 骨架要等 M4+ 的 `TaskHost` 后台后端（§17）才成立。这不是实现缺陷，是"同步 IO + 帧末统一 flush"的结构后果 —— 不要靠"先广播一次"来假装异步。

```php
// 页面：订阅点放在「真正变的那层」，不放根
SubjectBuilder::make($store)
    ->select(fn(UserStore $s): LoadStatus => $s->status())        // ★ 切片：只跟 status
    ->builder(fn(LoadStatus $st): Widget =>
        match ($st) {                                             // ★ 无 default
            LoadStatus::Loading => Text::make('加载中…')->caption(),
            LoadStatus::Error   => Text::make('加载失败，点刷新重试')->error(),
            LoadStatus::Ready   => Each::make(fn(User $u): int => $u->id)   // ★ key 必填
                ->items($store->rows(), fn(User $u): Widget =>
                    Row::make()->spacing(8)->children([
                        Text::make($u->name)->body(),
                        Button::danger('删除')->command('user.del')->arg(CommandArg::id($u->id)),
                    ])),
            LoadStatus::Idle    => Text::make('尚未加载')->caption(),   // ★ 漏一支 ⇒ UnhandledMatchError
        })
```

> **★ 三处关键设计在此闭合**：
> ① **`Each::make(fn(User $u): int => $u->id)`** —— key **进 `make()` 必填**。理由：G1 判据成立（`fn(User $u)` 的类型就在签名里 ⇒ **编译器知道列表项有身份**）；且"key 唯一 / 稳定 ⇒ 构建期校验"这条裁决的**落地**。
> ② **backed enum + 无 `default` 的 `match`** —— `default => rows` 意味着"**任何未来新增的状态都会静默显示数据行**"（= §21 A7）。现在新增一个 case 而不改这里 ⇒ **`UnhandledMatchError`**（响亮失败，G2）。
> ③ **`select()` 切片** —— 否则订阅粒度 = 整个 `Subject`。

> **脏边界真正生效**：订阅点下移到 `SubjectBuilder` ⇒ 兄弟子树不重建。GUI 仓最大的性能缺陷就是"订阅点挂根 ⇒ 一次变更整页重建，且忘下移不报错"。

> **⚠️ 诚实边界**：上面那句**只在 build 层成立**。
> §13.3 明写"Render 层没有脏边界，`relayout()` 永远全树"（M5 才做）⇒ **一帧的真实成本 = 被重建的 build + 全树的 layout + 全树的 paint**。
> 所以在 M5 之前，"订阅点下移"省下的只是 **build**，会被全树 `relayout` 吃掉一大半；只有当页面**很宽很浅**（重建代价 > 全树 layout）时才明显。
> ⇒ **不要把"订阅点下移"当 M2 的性能卖点讲**；它是"正确性 + 为 M5 铺路"。

### 11.6 明确不做

**运行期自动依赖追踪**（Vue `ref` / SwiftUI `@Observable` / WPF Binding）—— 两次否决的理由：① 换 C 轴范式（两套"数据变了怎么刷新"语义）；② 代价（包装对象丢标量直读、依赖图维护、与 G3 确定性的张力）。
**长期方向**：编译器推导依赖（Svelte 路线），以 spike + kill criteria 推进，**不承诺**。

### 11.7 异步（与 §17 同一件事的两个面）

单线程消息泵 ⇒ **任何阻塞调用都会卡死整个 UI**（`tetris-win32` 的 `usleep(16000)` 循环里同步 curl，帧就停了）。所以：

- **M1–M3**：同步 `DataSource` + 三态。★ 诚实边界：`loading` 态**画不出来**（同栈内无帧可渲染，见 §11.5 注），所以此阶段的实际表现是"**大响应时界面停顿**，`error` 态可正确呈现"。不要用"先广播一次 loading"来掩盖这一点。
- **M4+**：`TaskHost` 端口接**线程池后端**（§17.2），UI 代码**零改动** —— Store 只调 `$src->fetchPage()`，换后端是组合根的事。

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
        'users'  => UsersPage::make($this->store),    // IDE 可跳转 · 零闭包分配 · 仍惰性
        'signup' => SignupPage::make($this->store),
        default  => null,                             // 未找到 ⇒ 显式出口，不静默空白
    };
}
```

   ★ **为什么这里允许 `default`**（与第 3 条的"禁止 `default`"不矛盾）：
   **页面名是开放字符串域**（将来加页、插件化路由都会加新名字），未命中是**预期内**的合法结果 ⇒ `default` 是"显式出口"。
   而 **`LoadStatus` 是封闭枚举域**，新增 case 是"作者改了枚举忘了改这里" ⇒ 必须 `UnhandledMatchError`。
   ⇒ **统一判据**：**开放域（string / int 键）允许 `default`；封闭域（enum）禁止 `default`。**

   ★ **两个规模档，不是两套语义**：小应用直接在 `Root` 里 `match`（零分配、零新概念）；需要 **devtools 列举 / 单测 / 动态装配**时才升级为 `RouteTable`（表是数据，值是页面工厂闭包）。二者产出的是同一件事 —— "名字 → 页面 Widget"，`match` 只是它的最简形态。§12.2 用的是后者。

3. **外壳 + 内容槽**：`Dock(top:, left:, right:, bottom:, center:)`；外壳**持久**（不随页面重建），只有 `center` 槽换；内容区必须可滚动。
4. **`Offstage` 原语**：隐藏但保留挂载 —— 跳过 layout / paint / hitTest，Element / State 全部保留 ⇒ "切走再切回要不要保留状态"的答案是**保留**，且代价为零。
   - ★ 为什么不是 `IndexedStack`：它"全部 layout、只 paint 一个"，而 layout 是性能瓶颈，隐藏页也排一遍是反方向。
   - ★ 为什么不是 `Stack + Opacity(0)`：隐藏页照样参与 layout，且 Stack 取最大子节点 ⇒ 隐藏页能把可见页撑歪。
5. **页面间引用判据**（优先级从高到低）：**① 共享数据源**（各自订阅同一个 Subject，页面互不认识）→ **② 命令**（跨树、要复用、要审计）→ **③ 按 Key 取 State**（命令式、数据表达不了时）。页面参数走共享 store（`selectedId`），**不从根逐层钻参数**。
   - **⚠️ 诚实边界（代价两条）**：
     ① **参数会残留** —— 导航离开后 `selectedId` 还在 store 里，回到列表页时"上次选中的那行"仍然高亮。要清就得显式清（或在页面 `dispose` 时清），这是**手写的**，框架不管。
     ② **带参页面无法独立预览** —— §12.2 宣称的"每页可独立预览"对**不带参页面**成立；带参页面必须先喂一个假 store（`selectedId = 1`）才能挂载。⇒ 工具链的"单页预览"要支持**注入 store 夹具**，这不是免费的（§A.5 给了夹具样例）。
   - **为什么仍然选这条路**（而不是"从根逐层钻参数"）：钻参数的代价是**每一层中间容器都要知道它不关心的参数**（`Root` → `Dock` → `PageHost` → 页面），改一个参数要动 N 个签名；而残留/预览是**两个局部问题**，各有一个局部解法。
6. **导航栈不做**：桌面主形态 = 外壳 + 内容槽 + **模态**（`Application::showModal()`）；只有出现真实"多级钻取"需求才做 Navigator —— 不需要栈的应用硬上栈 = 多一个必然出错的全局状态。
7. **`PageHost` 与 `RouteTable` 分工**：`RouteTable` 是**数据**（名字→页面工厂，可被 devtools 列举、可单测）；`PageHost` 是**容器控件**（持当前页 Element：换页 = 旧页 `Offstage` 化而非卸载，状态保留）。简单应用在 Root 里直接 `match` 即可。

### 12.2 Root 只做组合（反 A10 的正面样板）

★ **关键纪律：`Root` 传「Subject」，不传「值」。** 读值 = 必须在 build 期订阅，而订阅只允许落在专职订阅控件上（§11.5）。若 `Root` 直接读 `$store->currentPage()`，`StatelessWidget` 不会因 store 变化重建 ⇒ **换页永不发生、状态栏永不更新**。

```php
// src/UsersRoot.php —— 只组合，不写任何具体布局
final class UsersRoot extends StatelessWidget
{
    public function __construct(private readonly AppStore $store) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->title('用户管理')->children([
                NavButton::make('列表')->command('nav.users'),
                NavButton::make('注册')->command('nav.signup'),
            ]))
            // ★ 传 store + 切片函数，由 StatusBar 内部 select 订阅 —— 值不在这里读
            ->bottom(StatusBar::make()
                ->source($this->store)
                ->select(fn(AppStore $s): string => $s->statusLine()))
            // ★ 传 store + 切片；PageHost 内部 select 订阅当前页，换页 = 旧页 Offstage 化
            ->center(PageHost::make()
                ->source($this->store)
                ->select(fn(AppStore $s): string => $s->currentPage())
                ->table($this->routeTable()))
            ->overflow(Overflow::clip);
    }

    private function routeTable(): RouteTable
    {
        return RouteTable::make()
            ->route('users',  fn(): Widget => UsersPage::make($this->store))
            ->route('signup', fn(): Widget => SignupPage::make($this->store));
    }
}
```

`StatusBar` / `PageHost` 的形态就是"专职订阅者"（实现 §10.3 第 5 条的 `SubscribingWidget` 接口）：

```php
final class PageHost extends StatefulWidget implements SubscribingWidget   // ★ 接口标记
{
    // ... 内部 State::initState 里 watch(select 出来的切片)，dispose 里全退（§11.3 订阅袋）
    //     notify ⇒ setState ⇒ 只重建 content 槽；旧页走 Offstage 而非卸载
}
```

**判据（可 lint）**：
- `Root` 里出现具体布局控件（`Column`/`Row`/`Padding`…）= 违规；
- **`Root` 的 `build()` 里出现对 `Subject` 子类型实例的方法调用 = 违规**（应传 store + `select`）；
  ★ 判据写成"**对 `Subject` 子类型实例的方法调用**"，不是"`$store->某方法()`" —— 后者会误伤 `$this->store` 属性访问。
- `Root` 只允许壳控件 + `PageHost` + `match`/`RouteTable`。`src/pages/*.php` 每个文件一个类、一个 `build()`。

**附带收益**：每页可独立预览（工具链只挂载那一棵子树）⇒ 改一页只跑一页（对抗 AOT 20–50s 构建成本）。
（★ 限定：**带参页面**需注入 store 夹具才能预览，§12.1 第 5 条 + §A.5。）

### 12.3 状态保留的三档 + 回收纪律

| 档 | 机制 | Element | State | 用在哪 |
|----|------|---------|-------|--------|
| **保留** | `Offstage` | 保留（不卸载） | **保留** | **页面级**切换（§12.1 第 4 条） |
| **回收** | 虚拟化（M5） | 卸载 | **dispose** | 长列表视口外 |
| **丢弃** | 条件分支不渲染 | 卸载 | **dispose** | 常规 `if`/`match` |

★ **关键纪律（M5 的前置条件）**：**虚拟化会 dispose 被回收的 Element 的 State。** 所以任何"看起来像 UI 态、但必须跨回收存活"的东西（**被虚拟化列表的滚动位置、选中项、展开状态、编辑中的草稿**）**必须外置到 Store**，不能放 `State`。

> **★ 措辞限定**：**只有"被虚拟化回收"的容器的滚动位置**才必须外置（因为它的 Element 会被 dispose）。页面级切换走 `Offstage`，State 是被保留的，页面滚动位置放 `State` **完全没问题**。
> 判据是问 **"承载这个滚动位置的那个 Element，会不会被回收？"**，而不是"是不是滚动位置"。

> 这条是 §11.5"State 只持纯 UI 态"的**必然推论**。判据一句话：**"这个列表项滚出视口再滚回来，它应该记得吗？"** 应该记得 ⇒ 放 Store。

★ **与 `Seal-on-Mount` 的交互：无冲突，但要说清。** 被回收的 Widget 实例**已经 sealed**；重新进入视口时**同一个实例可以再次挂载**（`seal()` 幂等，且同一实例复用到多处合法）⇒ **回收再挂载不需要任何特殊处理**。

★ **同一实例挂两处的 Element 身份**：`children([$t, $t])` 合法，两个 Element 的 **diff 身份由位置（index）决定**。若要"位置变了身份不变"，必须给不同 `Key` —— 这也是 §16.6.1 R4（`dup-key`）存在的原因。

### 12.4 明确不做（防默认漏洞）

| 不做 | 依据 | 将来要做的代价 |
|------|------|--------------|
| **多窗口** | 桌面主形态 = 单窗口 + 外壳 + 模态。`Application` 持**单** surface（§7.2） | **结构性**：要改 `Application` / `EventLoop` / `RenderSurface` **三处所有权**（surface 从单数变集合、事件路由要带窗口标识）。不是加个 API 能补的 |
| **原生菜单栏** | 风格割裂（§5.1）；违反 §18"不把渲染外包给原生工具包" | 小：自绘 `MenuBar` 已覆盖同一场景 |
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
| C6 | **违反 ⇒ 响亮失败** | 无界约束传给有内禀尺寸的子、约束违反、溢出未声明 ⇒ `Errors::report`（**Fatal 级，release 也中止**） | G2 |

★ **C5 的"每个容器"的边界**（消歧）：`Overflow` 有**默认值**（`Overflow::clip`），只有"可能超出给定约束"的容器（`AppBar` / `StatusBar` / `Row` / `Dock` 槽 / 自绘容器）**必须显式声明**；`Text` / `Icon` / `Padding` 这类"尺寸由内容或子决定、不会超"的容器用默认值即可。判据：**这个容器在约束不足时，是否必须"选择一种舍弃方式"？** 是 ⇒ 显式声明。（★ 这条在 v2 之前是隐性的，见 §23.1 第 9 项。）

### 13.2 Widget → RenderBox 构造契约（RT-2 的根治）

渲染控件提供 `createRenderObject(): RenderBox`；Element 挂载时调用它建盒；`(type,key)` 未变而配置更新时**用新 Widget 重新调用同一函数**造新盒替换。

Widget 不可变（§15 的 Seal-on-Mount 保证）⇒ 参数只会整体换新，**不存在"字段级半同步"** —— GUI 仓"两份手写字段赋值 ⇒ 首次正常、刷新变样，**且静默**"因此结构上不可能。字段对齐由 lint 比对 Widget 字段集与 `createRenderObject` 读到的字段集（W2 / R12）。

### 13.3 relayout 脏边界

Element 层有脏边界（脏边界重建：干净分支一次 build 都不跑）；**Render 层没有**（`relayout()` 永远全树）。列为 **M5+ 与脏矩形成对做** —— 单独做任一个，瓶颈只是搬到对面（GUI 仓自己定的门禁：等症状）。

---

## §14 布局/渲染确定性（重点 11）

### 14.1 四条确定性规则

1. **布局是纯函数**：`layout(constraints) → size`，输入只有（Widget 子树、约束、主题、**注入的 `TextMetricsProvider`**）；不读 OS、不读环境时钟、不读随机源；**位置无关**（从不读自身 `ox/oy`）。
2. **渲染是纯函数**：`paint(canvas)` 只依赖（渲染树 + 画布状态 + 主题）⇒ 录制成 `DisplayList` ⇒ 可重放、可 golden 比对。
3. **时间注入**：动画相位 = f(帧时间戳)；`FrameClock` 在测试里是脚本化的假时钟。
4. **稳定序**：禁止依赖无序 map 的迭代序；排序必须稳定；float 量化点唯一（§6）。

### 14.2 一套测量、两个后端

`TextMetricsProvider` 接口 + 两个实现（SkParagraph 真测量 / 确定性参考实现），conformance 套件互校。

★ GUI 仓的教训：预览的**模拟器**（`preview_sim` 的假换行）与真实排版有**漂移风险** —— Drift 的对策不是"模拟得更像"，而是**同一份断行算法**在两侧运行，差异由 conformance 抓住。

★ **golden 的可复现性前提**（**2026-10-14 裁决**，原 §23 O-08）：`DisplayList` 里内嵌的**字形**依赖具体字体文件 ⇒ **golden 必须绑定一份打包在仓库里的测试字体**，否则"同一份 `DisplayList`"在换机器后位图不同。这是 `RasterCanvas` + `TextMetricsProvider` 之外**第三个**必须钉死的输入。

**裁决**：仓库内**打包一份测试专用字体**（覆盖拉丁 + 常用 CJK 子集，或直接用一份开源字体），`golden` 工具**强制**用它 —— 不给参数就用默认测试字体，并**断言**实际用的字体 == 测试字体（防止从环境字体栈"顺"到别的字体，让 golden 悄悄退化成 flaky 测试）。

### 14.3 确定性的回报

golden 测试、几何普查（多断点 0 溢出）、跨时段 A/B（性能数字可比）、快照可回放进 CI、**预览免费得到**（布局层零 OS 依赖 ⇒ 可在 `php.exe` 直接跑，不编译）。

---

## §15 控件写法：fluent-first + Seal-on-Mount（重点 14）★ 核心裁决

### 15.1 问题：链式写法与"Widget 不可变"的矛盾

用户想要的写法（IDE 自动联想、心智负担小）：

```php
Text::make('Hello Drift')->title()
Button::primary('保存')->command('form.submit')
Column::make()->spacing(20)->children([ ... ])
```

**但这里有一个真问题**：

| 实现方式 | 后果 |
|---------|------|
| `mutate + return $this`（builder 式） | Widget **可变** ⇒ 三树 diff 的"不可变 ⇒ 参数整体换新"前提**破裂** ⇒ GUI 仓 RT-2/A9（"两份手写字段赋值 ⇒ 刷新变样且静默"）复活 |
| `clone`（每次修饰新实例） | 纯、安全，但每修饰一次分配 ⇒ 每帧重建树时分配量 × modifier 数 |

GUI 仓的教训很明确：**"糖有自己的状态路径、与构造器分叉"是最大的坑**。所以不能简单地"再开一套 builder"。

### 15.2 裁决：一个类、一条链、挂载即封存

**结论**：fluent-first，**单一表面** —— 不搞"构造器 vs 链式"双表面（那正是分叉的来源）。全部 API 活在同一个类上：

```php
namespace Drift\Widget;

abstract class Widget
{
    /** @internal 0 = 可配置（构建期）；1 = 已封存（挂载后） */
    private int $sealed = 0;

    /** 基类构造器 —— 子类必须调 parent::__construct() */
    public function __construct() {}

    /**
     * 挂载时由 Element 调用：把描述封存为不可变。
     *
     * ★ 纯内部方法，不进公共 API（标 @internal，`tools/api-surface.json` 不收录）。
     *   用户不需要它 —— "挂载时自动封存"已覆盖全部合法场景。
     * 幂等 —— 同一实例被复用到多处是**合法**的（Widget 是纯描述，各处各有自己的
     *   Element），所以这里不做任何 owner 校验。
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

★ **为什么删掉 owner 校验**：Widget 是不可变描述，`Column::make()->children([$t, $t])` 是合法且常见的复用，各处各有独立 Element ⇒ 报错是**误杀**。

```php
// ★ 哨兵不靠"取值域外的裸数字"，而靠 enum 里的一个显式 case。
//   这样 0 是**合法取值**，"未设置"是它的语义 —— A2（拿合法值当哨兵）从根上消失。
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
    public function error():   self { $this->mutate(); $this->role = TextRole::Error->value;   return $this; }

    // ── 修饰（全部 set + return $this 薄壳，零分支、零缓存、零默认值合并）──
    public function size(float $pt):      self { $this->mutate(); $this->size = $pt; return $this; }
    public function bold(bool $v = true): self { $this->mutate(); $this->bold = $v ? 1 : 0; return $this; }
    public function maxLines(int $n):     self { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): self { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }
}
```

**四条配套纪律**：

1. **`make()` 只吃必填项**，作为**唯一入口**（IDE 打 `Text::` 立刻看到 `make`，打 `->` 全亮）。完整控制仍可走构造器（`new Text(...)` 带命名参数），但**文档只教链式一种**（避免双表面分叉）。
2. **`mutate()` 是链式方法的统一前缀** —— 不是每方法各写一遍守卫（一个口径一个落点）。
3. **封存后写入 ⇒ 响亮失败**（Fatal 级），不是静默忽略。`seal()` 幂等，**同一实例复用到多处合法** —— 不做 owner 校验。
4. ★ **哨兵值三档定则**（消灭 A2）：
   | 字段类型 | "未设置"怎么写 | 例 |
   |---------|--------------|----|
   | **枚举语义** | **enum 里加一个显式 case**（如 `TextRole::Unset = 0`）⇒ 0 是合法取值 | `$role = TextRole::Unset->value` |
   | **float** | 负值域外 `-1.0`（尺寸/位置非负） | `$size = -1.0` |
   | **bool 三态** | `-1` 未设置 / `0` 否 / `1` 是 | `$bold = -1` |
   | **int（有合法负数域）** | 域外负值（如索引类用 `-1`） | `new Cell(-1)` |

   ⇒ 判据：**哨兵值必须能写成一个"有名字的东西"**（enum case / 负值常量），而不是裸数字。裸 `0` 只允许出现在"该取值本身无意义"处（如 `$maxLines = 0` 表示不限 —— "0 行"不存在）。

### 15.3 三个方案的实测裁决

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

1. **链式写法本身几乎免费** —— `D+` 比纯构造器只贵 **0.27 µs/节点**（含 5 次链式调用 + 5 次守卫 + 1 次 seal）。"链式写法本身慢"是错觉。
2. **clone-with 贵 3.4×** —— 因为 `clone($this, [...])` 不编译成内联拷贝，而是"构造 `php::Var` 属性数组 + 运行时调用 + 新对象"（生成 C++ 实证，见 `RESULTS.md §2`）。
3. **类型化局部变量能省 ~16%**（A2 vs A）—— 链式中间结果落在 `php::Var` 里会丢静态解析，写成顺序语句可恢复（与 §2.4 纪律 1 同源）。
4. **运行间方差 ±15%** ⇒ 上表数字只应读作**量级**，不要当精确值引用。

**★ 守卫的代价是"可测量"的**：隔离测试（三次一致为正才算）得 **守卫 ≈ +0.09 µs/节点（+11%）**；`seal()` 落在噪声内（+19.8 / +17.5 / **−2.0** ⇒ 有正有负）。
⇒ 裁决不变，但依据是**比值**：守卫的 0.09 µs，相对 clone-with 为省掉它而多付的 **2.25 µs**，**便宜 25×**。为省一行 `if` 付 25 倍代价，不划算。
⇒ 绝对量级仍小：1000 节点全量重建 = 0.92 ms = **5.5% 帧预算** ⇒ 归 §1.2 的 **Diagnostic** 级，不是"需要改设计"。

> **"不用守卫"的唯一办法**是改成 clone-with 语义（每个 modifier 返回新实例，压根不修改 ⇒ 无可守卫）—— 代价是 3.4× 性能。所以这不是"能不能省"的问题，是"**用 25× 的代价换掉一行 `if`**"是否值得的问题。**答案：不值得。**

### 15.4 完整 API 形状（对照用户原始想法）

```php
// ── 用户的想法：Text::make('Hello Drift')->title()  ✅ 直接支持 ──

Column::make()->spacing(20)->children([
    Text::make('用户目录')->title(),

    Row::make()->spacing(8)->children([
        // ★ 表单字段的官方形态（§11.4.2）：订阅 + 取值 + 写回 —— 不提供 bind()
        SubjectBuilder::make($store)
            ->select(fn(UserStore $s): string => $s->query())
            ->builder(fn(string $q): Widget =>
                TextField::make()->value($q)->label('搜索')->placeholder('搜索姓名')
                    ->onChanged(fn(string $x) => $store->setQuery($x))),
        Button::primary('刷新')->command('users.refresh'),
    ]),

    SubjectBuilder::make($store)
        ->select(fn(UserStore $s): LoadStatus => $s->status())   // ★ 切片
        ->builder(fn(LoadStatus $st): Widget =>
            match ($st) {                                        // ★ 无 default（封闭域）
                LoadStatus::Loading => Spinner::make(),
                LoadStatus::Error   => Text::make('加载失败')->error(),
                LoadStatus::Ready   => Each::make(fn(User $u): int => $u->id)   // ★ key 必填
                    ->items($store->rows(), fn(User $u): Widget =>
                        Row::make()->spacing(8)->children([
                            Text::make($u->name)->body(),
                            Text::make($u->email)->caption(),
                            Button::danger('删除')->command('user.del')->arg(CommandArg::id($u->id)),
                        ])),
                LoadStatus::Idle    => Text::make('尚未加载')->caption(),
            }),
])
```

**命名规则（唯一一套，无例外）**：

| 场景 | 形态 | 例子 |
|------|------|------|
| 造控件 | `Type::make(必填项…)` | `Text::make('hi')` · `Column::make()` · **`Each::make(keyFn)`** |
| 修饰属性 | `->修饰名(值)` | `->spacing(20)` · `->value($v)` · `->maxWidth(640)` |
| 语义角色 | `->角色名()`（backed enum，零参数） | `->title()` · `->caption()` · `->danger()` |
| 树结构 | `->children([...])`（**数组形参**，PHP 无尾随闭包） | `->children([$a, $b])` |
| 变体工厂 | `Type::变体(必填项…)`（= `make()` 的薄壳别名，**只许是固定变体**，不许封装机制 —— §10.3 第 6 条） | `Button::primary('ok')` · `Button::danger('删')` · `Text::make('x')->title()` |
| 事件 | `->onXxx(闭包)` 或 `->command('名')->arg(CommandArg::…)` | `->onPressed($fn)` |
| **订阅切片** | `->select(fn(Store $s): T => …)`（**T 必须是标量或不可变值对象**） | `->select(fn($s): array => $s->rows())` |

**枚举 case 命名统一**：**PascalCase**（`Overflow::Clip` · `TextRole::Title` · `CursorShape::IBeam` · `LoadStatus::Ready`）。★ v1 的 §12.2 写 `Overflow::clip`（小写）是不一致，v2 已统一。

**"糖只转发"的删除测试**：`Button::primary('x')` 必须等价于 `Button::make('x')->variant(ButtonVariant::Primary)`；
★ **反例（已删）**：`TextField::bind($cell)` 曾作为"转发"的例子，但它其实是**参数化的机制封装** ⇒ 越出本条 ⇒ 见 §10.3 第 6 条的越界判据与 §23.2 O-16。
删掉全部变体工厂与角色方法，框架契约与测试**原样完整**（只是更啰嗦）。

### 15.5 心智负担的量化对照（同页面复刻）

| 指标 | 构造器写法（旧） | fluent 写法（本裁决） |
|------|----------------|---------------------|
| `new` 关键字数 | ~14 | ~2（仅 `make()` 内部） |
| 需记忆的命名参数名 | spacing / children / variant / command / arg … | **0**（打 `->` 全亮） |
| 文本角色 | `new TextStyle(role: TextRole::Title)` 现场拼 | `->title()` 一词 |
| IDE 可发现性 | 3/5 | **5/5** |
| 语义角色内建 | 4/5 | **5/5** |

> 依据：GUI 仓 85 处手写 `SizedBox`、67 处内联 `TextStyle`、49 处 `Color::fromRgb` —— **能力早有、采用率 0**，因为示例没教。所以 §20 的验收里有一条硬指标：**每个契约能力必须在示例中出现过**（没出现 = 没交付）。

### 15.6 为什么不加 `->build()`（否决记录）

用户提案：链式中间都返回 `$this`，最后用 `->build()` 组装成 Widget：

```php
Text::make('用户目录')->title()->build();
```

**语义方向是对的**（区分"可变构建窗口"与"不可变产物"），但落到 Drift 上有四条成本，且**收益为零**：

| # | 问题 | 说明 |
|---|------|------|
| 1 | **名字与既有概念硬冲突** | `StatelessWidget extends Widget` 已声明 `abstract public function build(BuildContext $ctx): Widget`。若基类再加 `Widget::build(): Widget`（零参）作封存，子类的 `build($ctx)` 就是**不兼容覆盖 ⇒ PHP Fatal error**。必须改名（`seal()` / `freeze()` / `done()`），不能叫 `build()` |
| 2 | **要么到处都是，要么是装饰** | 若强制 ⇒ 每个节点一次（§15.4 那个页面要写 6 个 `->build()`）；若挂载时自动 build ⇒ 它退化成 **no-op**，直接违反 G2"要么不提供、要么必须生效" |
| 3 | **类型强制会翻倍类数量** | 唯一能让"忘记 `->build()`"变成**编译错误**的办法是 Builder 与 Widget 拆成两个类型 ⇒ 45 控件 × 2 类，且"糖只转发"纪律变难守 |
| 4 | **它想解决的问题已有天然边界** | "何时结束可变窗口"在 mutate 语义下由**挂载**回答 —— Element 采纳 Widget 时自动封存。开发者不需要再声明一次 |

#### 15.6.1 裁决：**不保留 `->seal()`**

把 `->seal()` 留作"可选的显式句号"是**不需要的**，理由是它两条宣称的收益都不成立：

| 宣称收益 | 实际 |
|---------|------|
| "省掉挂载时的封存判断" | 挂载时的封存**没有判断可省** —— 就是 `$this->sealed = 1` 一次赋值。**【实测】** `F+ − E` 三次有正有负（+19.8 / +17.5 / **−2.0**）⇒ **落在噪声内** |
| "便于把不变控件提到 build() 外跨帧复用" | 跨帧复用**不需要 `seal()`**。而且同一实例复用本来就合法，`seal()` 幂等 ⇒ 第二次挂载调它也无事发生 |

⇒ 收益 ≈ 0，代价却是把 `seal()` 拖进**永久公共契约**（§10.3：进 `api-surface.json` 就再也不能悄悄改）。
这正好撞在 G2 纪律上：**"要么不提供、要么必须生效"** —— 提供 `->seal()` 但作用 ≈ 0，属于"提供了却没作用"，与 `Container::$constraints`（死字段）**同一类**。

**裁决**：`seal()` 保持 `final public`（Element 在 `Drift\Widget` 命名空间内需要调），但**标 `@internal`、不进公共面**；用户不需要它，文档不教它。

**为什么不能靠 `->build()` 顺手拿到"别名安全"**：别名隐患（`$t = Text::make('x'); children([$t->title(), $t->body()])` 两个都变成 `$t` 终态）在 mutate 语义下**存在**，但 `->build()` 也治不了它（两次 `->build()` 之间 `$t` 已被覆盖）。**唯一根治办法是 clone-with，而那要付 3.4×**。所以走 lint：

> **lint 规则 `alias-mutate`（M1）**：同一 `children([...])` 数组内出现**两次同一根变量**（`$t->a()`、`$t->b()` 形态）⇒ 报错。这是"可变 builder 别名"的静态形态，可静态检出，无需运行期机制。

---

### 15.7 盒样式：`BoxStyle` 值对象 + `BoxWidget` 中间基类（M2）

**✅ 已裁决（2026-10-14，§23.2 O-17 方案 A′）**。背景 / 边框 / 圆角这类**绘制面**样式多个控件都要，但**不许进 `Widget` 基类**（§15.3 的性能依据正是"只有真正用到的字段才存在"）。

**先拆面，再选手段**：

| 面 | 归属 | 例子 |
|----|------|------|
| 语义颜色 | **主题角色**（`ColorToken`） | `->title()` · `->error()` |
| **盒绘制样式** | **`BoxStyle` 值对象**（本节） | `->background($c)` · `->border($c, 1.0)` · `->radius(8)` |
| 文本样式 | **文本族自己的字段**（§15.2 的 `Text`） | `->size(18)` · `->bold()` |
| **宽 / 高 / 间距 / 内边距** | **几何面**（§13.1） | `->width(200)` · `->padding(8)` |

★ **判据（可复用）：族内"纯样式字段" ≥ 4 个且常整体替换 ⇒ 值对象；否则散字段。**
盒族要 4~6 个（background / border / radius / shadow）⇒ **`BoxStyle`**；文本族只 2 个（`$size` / `$bold`）⇒ **维持 §15.2 的散字段，不在 widget 层引入 `TextStyle`**。
（★ 注意 `Drift\Text\TextStyle` **已经存在**（§4.2），那是**文本栈**的样式（字体族 / 字重 / 排版），与本节无关 —— 这也正是"不在 widget 层再造一个 `TextStyle`"的理由。）

**`BoxStyle`（`Drift\Rendering`，不可变值对象）**：

```php
namespace Drift\Rendering;

/** 盒样式：只管"画成什么样"，不管几何（§13.1）。null 字段 = 该项不绘制。 */
final readonly class BoxStyle
{
    public function __construct(
        public ?Color $background  = null,
        public ?Color $borderColor = null,
        public float  $borderWidth = 0.0,   // 0 = 无边框
        public float  $radius      = 0.0,   // 0 = 直角
    ) {}

    // ★ 逐字段 with-改写。**不用带默认值的 copyWith** —— 那需要哨兵区分"没传"与"传 null"（§15.2 哨兵定则）
    public function withBackground(Color $c): self { return new self($c, $this->borderColor, $this->borderWidth, $this->radius); }
    public function withBorder(Color $c, float $w): self { return new self($this->background, $c, $w, $this->radius); }
    public function withRadius(float $r): self { return new self($this->background, $this->borderColor, $this->borderWidth, $r); }
}
```

**`BoxWidget`（`Drift\Widget`，中间基类）**：

```php
namespace Drift\Widget;

use Drift\Rendering\BoxStyle;

/** 需要盒样式的控件继承它；结构节点（Column / Row / Each / Spacer / Text）继承裸 Widget ⇒ 拿不到 ->background() */
abstract class BoxWidget extends Widget
{
    /** @internal null = 无盒样式（零成本；符合 §15.2 哨兵定则） */
    private ?BoxStyle $box = null;

    public function box(): ?BoxStyle { return $this->box; }          // 渲染层读它

    public function background(Color $c): static { $this->mutate(); $this->box = ($this->box ?? new BoxStyle())->withBackground($c); return $this; }
    public function border(Color $c, float $w = 1.0): static { $this->mutate(); $this->box = ($this->box ?? new BoxStyle())->withBorder($c, $w); return $this; }
    public function radius(float $r): static { $this->mutate(); $this->box = ($this->box ?? new BoxStyle())->withRadius($r); return $this; }
}
```

**五条纪律**：

1. **`null` = 无样式，零成本** —— 盒族控件每个多付 **1 个可空指针**；不用样式的控件**不付对象分配**（`BoxStyle` 只在真用样式时构造一次）。
2. **`?BoxStyle` 是整体替换，不做字段级合并**；`BoxStyle` 不可变 ⇒ `===` 门控直接可用（与 §11.3.3 的门控同源）。
3. **合并只发生在"构造期、单一来源"**；主题 token 与显式样式的优先级**只在 `ThemeData` 解析一次** —— 这是 §18"避开 CSS 层叠 / 特异性"的必然推论。
4. **与 `ThemeScope` 的分工（必须写清，否则又是"两套写法"）**：

| 手段 | 粒度 | 何时用 |
|------|------|--------|
| **`ThemeScope`**（§8） | **面**（整棵子树） | "这一屏都用暗色 / 这一块字号都大一点" |
| **`BoxStyle`**（本节） | **点**（单个控件） | "就这一个盒子要红底" |
| 主题 token（`ColorToken` / `MetricToken`） | 键 | 上面两者的**取值来源** |

5. **`static` 返回类型**：若 M1 探针门判定不可用（§19），退化为 `self` + PHPDoc `@return $this`，**运行期语义不变**。

★ **否决的替代形态：Flutter 式的 `Container` 包裹**（`Container::make()->decoration(…)->child($w)`）。理由：Drift 的既有 idiom 是**修饰符**（`Column::make()->spacing(16)->padding(24)`），若"内边距走修饰符、背景走包裹" ⇒ **同一族两套写法（A12）**。⇒ 选修饰符形态。
★ **不用 trait 共享糖方法**：§2.4 纪律 7 只验过"trait 不单独成文件"，**"trait 混入多个类"在 AOT 下展开成什么未验**（探针门）；而**继承已验**。不为了省打字引入未验机制。
★ **不引入 `TextStyle` 值对象**：文本族纯样式只 2 个字段，不达"≥4"阈值 ⇒ **§15.2 不动**。

---

## §16 工具链（重点 10）

### 16.1 原则（G1 的全面落地）

**编译器知道 ⇒ 构建期做。** 把 GUI 仓"运行期在做而编译器本来就知道"的清单逐项搬移：

| 事 | GUI 仓现状 | Drift |
|----|-----------|-------|
| 哪个 State 变了 ⇒ 更新哪棵子树 | 运行期手写订阅 | 运行期（订阅点下移）+ **远期编译期推导**（spike） |
| 死命令（命令全树无人接管 ⇒ 点了没反应） | ✅ lint `dead-command`（R5） | 继承，并扩为**跨应用级 `CommandRouter`** 的双向可达性校验 |
| key 唯一 / 稳定 | 运行期位置匹配 | **构建期校验**（key 是结构事实） |
| **`make()` 必填参数未提供**（如 `Each` 没给 keyFn） | 无 | **lint + 类型强制**（`make()` 签名） |
| 样式最终值 | 运行期每帧解析 | **角色枚举 + token（`ColorToken` / `MetricToken`）解析为值对象（`BoxStyle`，§15.7）**；**不做字符串注册表**（★ `ControlStyle` 是孤儿名 ⇒ 已删，见 §23.2 O-17） |
| 盒字段对齐（RT-2） | 约定 | **lint**（Widget 字段集 vs 盒字段集比对） |
| 命名空间分层违反 | 无（平铺 namespace 没法查） | **lint**（§4.3 依赖规则） |
| API 表面漂移 | 无 | **API 快照 CI** |
| **封存后修改 Widget** | 无此概念 | **lint + 运行期断言**（§15.2） |
| **焦点序混用**（同容器内有/无 `focusOrder`） | 无 | **lint**（§9.1） |
| **加速键与编辑命令冲突** | 无 | **lint**（`AcceleratorTable` 与 `TextInputClient` 独占键集求交） |
| **跨回收存活的状态放进了 State** | 无 | **纪律 + review**（§12.3；M5 虚拟化的前置条件，静态难判） |
| **`build()` 里读 `Subject` 但不在订阅者内**（静默不更新） | 无 | **lint `dead-subscription`** |
| **派生值忘了清缓存**（静默旧值） | 无 | **lint `stale-derived`** |
| **业务引用 `@internal`** | 无（平铺 namespace 无从判定） | **lint `internal-leak`**（error + 可显式豁免） |
| **`sources` 与 `composer autoload` 不一致** | 无 | **lint `scan-autoload-drift`**（SDK 模型独有） |

### 16.2 六件套（全部 PHP 单语言、单一入口）

| 工具 | 职责 | 关键性质 |
|------|------|---------|
| `drift-lint` | 编译期校验：**R1–R12 移植（清单见 §16.6.1）+ Drift 新增 17 条**（§16.6.3） | **量具双向自检**（该响会响、不该响不误响 —— "从不报错的检查器与正确输出一样"） |
| `drift-preview` | 无头跑完整 layout + paint（软光栅后端）→ SVG / PNG | **不编译、不依赖 OS**（GUI 仓 V7 实测：layout 可在 `php.exe` 跑通）；支持单页预览 + **store 夹具注入**（§A.5） |
| `drift-devtools` | 树转储 / 语义树 / 快照 `{schema,trees,semantics}` / 几何普查（多断点）/ 溢出探针 | 快照是数据 ⇒ 可 diff、可进 CI |
| `drift-conformance` | 合成树夹具 + 期望数据；**新实现必须过套件** | 夹具不依赖示例应用 |
| `golden` | `DisplayList` 回放比对 + 几何对账 | G3 的验收形态；★ **必须绑定测试字体**（§14.2） |
| **`drift-doctor`** | 环境自检：`PHPRC` 指向的 ini / `extension_dir` 是否绝对路径 / `mbstring` 可加载 / Skia include+lib 位置 / `icudtl.dat` 可定位 / `cl.exe` 在 PATH / `c++20` 支持 / 系统字体族可枚举。**输出"哪一项不满足 + 症状会是什么"** | §16.5 的六个陷阱**全都是"环境配置错 ⇒ 报错指向别处"**（缺 `mbstring` 报 `Failed to startup module`、CRT 不匹配报 `LNK2038`、缺 `icudtl.dat` 直接 abort、字体初始化失败报 `0xC0000005`）⇒ 值得一个自检入口。**★ 2026-10-14 裁决**（原 §23 O-10） |
| **`drift-codegen`** | 把 `src/` + 各 `apps/<app>/src` 交给 **tpc 翻译**一遍（`--dry`，只生成 C++，不编译）⇒ `php -l` 与 lint 都放过的 AOT 专属缺陷在这一层现形 | **★ 2026-10-15 裁决（原 §23 O-14 方案 A）**：T1 档常驻门（实测数秒）。① **必须用源码树 `bin/tpc.php`** —— 修复只在源码树，发行版 `tpc.exe` 不含（拿它跑只会得到已修好的假警报）；② `--dry` **也仍校验工具链** ⇒ 用 `cmd.exe` 垫的假 `cl.exe`/`link.exe`（`--compiler` 直指它，比改 `PATH` 干净）；③ `PHPRC` 指向**私有 ini 副本**（`extension_dir` 改成绝对路径 —— 发行版 ini 里是相对 `./ext/`，跟着 cwd 漂）；④ 每次**先删 `build/`** 再跑 —— 留缓存的话第二遍全是 `[cached]`，"每个文件都翻译过"这条断言就成了空话，**门不能信缓存**；⑤ 七条断言：退出码 / `Dry run completed` / 无 `skip:`·`unsupported syntax` / `convert:` 集合 ↔ 自枚举的源文件集合（双向）/ `prepare completed` 计数相符 / `backend` 行里是我们传的编译器 / 产物清单行数相符。**退出码 0=通过 · 1=未通过 · 2=门没跑成**（"没跑"与"通过"绝不能同码，G2） |

### 16.3 CI 门禁顺序与开发内环

```
CI:      lint → codegen(tpc --dry) → preview → build(tpc) → selftest(conformance) → golden diff
内环:    php -l → drift-lint → drift-codegen（秒级，不编译）→ drift-preview（单页，秒级，不编译）
提交前:  内环 + tpc build（一个应用）+ conformance
```

- **lint 是构建的前置门禁**（不是事后检查）—— AOT 的静默失败只能靠它守。
- **内环不编译**：preview 走软光栅、纯 PHP，可在解释器里跑 ⇒ 对冲 AOT 20–50s 的构建成本（这是 Drift 唯一真实且无法用 API 糖补的差距）。
- ★ **`drift-codegen` 守的是 lint 结构与解释器都够不着的那一层**（**2026-10-15 实测举证**）：把 `src/Text` 下放一个 `private string $x = SomeEnum::A->value;` 的探针，`php -l` 报 "No syntax errors detected" 放行，而 codegen 门报 `Expr_PropertyFetch given` 失败 ⇒ **两道门抓的不是同一批东西**，"多一道门"在这里不是冗余。（该探针用完即删；门自身也做了双向自检：该响会响、撤掉后不误响。）
- ★ **`drift-lint` 的落地条数不写进本文件**——它会随每次补规则变化，写死即过期。**单一出处 = `php tools/lint.php --list` 的实际输出**（§16.6.3 的 17 条是"设计清单"，未落地的一目了然）。

### 16.4 构建配置（`project.yml` 要点）

**★ 已按真实 schema 校正**（对照【手册】L187–243 与 `GUI/apps/demo/project.yml` 实测配置）：

```yaml
name: users
mode: bin
platform: win32                     # ★ 真实键
version: 0.1.0
php-version: "8.5"
optimize: 2
job: 16                             # ★ 构建并行度 —— 直接砍 AOT 20–50s 的唯一旋钮
cxx-std: c++20                      # Skia 头需要；MSVC 默认 c++17 不够（SkMathPriv 用 <bit>）
build-dir: ../../build/users        # ★ 每应用一个子目录，见陷阱 2
no-console: true                    # yml 键；等价命令行 --no-console

sources:
  - main.php
  - src                             # 本应用（App\Users\，§4.1）
  - ../../src                       # 共享框架源码（PSR-4 Drift\）
  - ../../cpp-src                   # C++ 桥接 + .stub.php
  - path: bin/win32                 # ★ 条件 source（【手册】L205–208 明确支持）
    if: PHP_OS_FAMILY == "Windows"  #    两个平台的 Bootstrap 同名 ⇒ 靠这里二选一
  - path: bin/macos
    if: PHP_OS_FAMILY == "Darwin"

# ★ SDK 分发形态（§10.2）—— 上面是"同仓开发"形态，另两种是：
#   形态 1（SDK 目录）：  - ../drift-sdk/src        # 下载 / 解压 / git submodule
#   形态 2（composer 包）：- vendor/drift/drift/src  # composer require drift/drift
# ★ 三种形态都必须满足：本 `sources` 覆盖的命名空间 == composer.json 的 autoload.psr-4
#   ⇒ 由 `scan-autoload-drift` 守（不一致的症状是"preview 能跑、AOT 编译不过"）

ignore:                             # ★ 顶层键（不是 sources 的子键）；路径同样相对本文件
  - ../../src/platform/macos        # 共享 src/ 里排除异平台目录（§4.6 配套）
  - ../../src/platform/win32        # （按目标平台留一个）

defines:
  - DRIFT_USERS_ALL_DECL=<php_users_all_decl.h>   # 注入 AOT 聚合声明头名

include-paths:
  - ../../cpp/skia/include          # 相对【本文件所在目录】
link-paths:
  - ../../cpp/skia/out/Release-x64
link-libs:                          # ★ 真实键名（【手册】L217 确认）
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

### 16.5 六个必须知道的陷阱

**陷阱 1 —— 两类路径基准不同（最贵的一处）**

| 字段 | 相对什么解析 |
|------|------------|
| `sources` / `include-paths` / `link-paths` / `build-dir` / `ignore` | **本 `project.yml` 所在目录** |
| `cxx-flags` / `ld-flags` / `c-flags` | **链接/编译进程的 cwd**（原样拼进命令行，不经路径解析） |

⇒ `apps/<app>/project.yml` + 共享 `src/` 布局下，前一类必须写 `../../` 前缀，后一类必须与**调用目录**逐字对齐（GUI 的约定是"从仓库根调用"，`cd <repo-root> && tpc apps/users/project.yml -O2 -m bin`）。两类混用看着别扭，但它是"不改 cxx/ld-flags"与"exe 落根目录"两条约束下唯一自洽的写法。

**陷阱 2 —— `build-dir` 必须每应用一个子目录**

【手册】示例写 `build-dir: build`（单应用语境）。多应用下共用同一目录，`build/` 里按 targetName 分键的增量缓存会互相失效 ⇒ **两个应用互相触发全量重编**。故写 `../../build/<app>`。

**陷阱 3 —— 从命名空间内调全局 stub 函数必须写前导 `\`**

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

**报错指向生成的 C++ 而非 PHP 源**，按【手册】§12 的排错法也要绕一圈。
机制：decl 头注入依据 `Translator::getDeclarationHeadersForFile()` 的 `symbolCallInFile[$file]` → `symbolDeclInFile[$symbol]`；命名空间内的 `thr_spawn()` 被解析成 `P\thr_spawn`，与 stub 声明的全局 `thr_spawn` 符号对不上 ⇒ 依赖链断掉。

⇒ **Drift 的桥接层纪律**：所有 `platform_*` / `pool_*` 等 stub 函数，调用点一律写 `\`。
（GUI 仓一直是这么写的：`src/Shell/platform.php:161` 的 `\win_create_window(...)`、`src/App/Application.php:855` 的 `\win_peek_message()` —— 只是没人把这规矩写下来。）
⇒ 由 lint `stub-prefix` 强制，不靠人记。

**陷阱 4 —— `tpc.exe` 不加载同目录 `php.ini`（`php.exe` 会）⇒ 构建脚本必须设 `PHPRC`**

症状（0.9.4 起）：`Fatal error: Failed to startup module [typephp_tpc]` —— 缺 `mbstring`。
**两件都必须做，缺一不可**：

```bash
export PHPRC="<TypePHP 发行版根目录>"      # ① tpc 找 ini 的地方
# ② 该 php.ini 里的 extension_dir 必须改**绝对路径**
#    ./ext/ 按 CWD 解析，而 tpc 惯例是从仓库根调用 ⇒ 会指向仓库根
```

★ **已验证无效的尝试**（别走弯路）：`tpc -d extension=mbstring` / `tpc -c php.ini` / 只改 `extension_dir` 而**不设** `PHPRC`。

★ **为什么必须写进方案**：**0.9.0 不要求 `mbstring`，0.9.4 起强制** ⇒ **升级发行版会静默破坏既有构建脚本**。这不是"新用户配置问题"，是发行版行为的破坏性变更。
⇒ **`tools/build.sh` 从第一天就设 `PHPRC`**；且 `PHPRC` 与**运行期** exe 的 `PHPRC`（关 opcache 的那个）**是两回事**，别混用。

**陷阱 5 —— lib 模式给每个 C++ TU 无条件强制 `/FI<php_<target>_all_decl.h>`（无 yml 开关）**

`NativeCommandOptionsTrait.php:99-104`，`isBuildModeLib()` 分支里写死。后果：聚合头把 `php::operator|&==+*` 模板提到**所有第三方头之前** ⇒ 若桥接头写了 `using namespace php`，**Skia 的枚举位运算会重载歧义**（C2666 / C2661 / C2056）。

⇒ **永久纪律**：**`cpp-src/` 下的头文件绝不写 `using namespace php;`**，一律写全限定 `php::Int` / `php::Str`。（GUI 仓为此付了一个提交；**根因至今未修**。）

**陷阱 6 —— 共享 base 的路径写错 ⇒ tpc 崩溃而非报错**

被包含文件（`include:`）的 `sources` 指向一个在顶层目录下**不存在**的路径时：

```
exit=255
PHP Fatal error: Uncaught TypeError: TypePhp\Translator::getAbsolutePath():
  Return value must be of type string, bool returned
```

根因：路径不存在时 `realpath()` 返回 `false`，而调用点声明 `: string` ⇒ 未捕获 `TypeError`。
⇒ **症状是"编译器崩溃"而不是"路径不存在"** ⇒ `include:` 必须配一个路径存在性检查（§4.6 约束 3）。

**Windows GUI 子系统**：`no-console: true` 让 PE 头 subsystem = 2（WINDOWS），不弹黑框；tpc 会**自动补 `/ENTRY:mainCRTStartup`**（否则 CRT 找不到入口，链接/启动即挂）。代价：无 stdout/stderr，调试只能靠日志文件 / 弹窗 / 附加调试器（【手册】§6）。验证：`dumpbin /headers your.exe | findstr subsystem`。

### 16.6 规则清单

#### 16.6.1 `R1–R12`：从 GUI 仓移植的编译期规则

**权威清单**（对照 GUI 仓 `php tools/lint.php --list` 实测输出）：

| # | 规则名 | 判据（症状） | Drift 处置 | 服务 |
|---|--------|------------|-----------|------|
| R1 | `flex-parent` | 弹性项（`Expanded`/`Spacer`）不是 `Row`/`Column` 的**直接**子节点（静默不生效） | 直接移植 | G2 |
| R2 | `positioned-parent` | `Positioned` 不在 `Stack` 里（`left/top/…` 被忽略） | 直接移植 | G2 |
| R3 | `divider-in-row`（warn） | `Divider` 放在 `Row` 里（吃掉整行剩余宽度） | 直接移植 | G2 |
| R4 | `dup-key` | 同层兄弟 `Key` 重复（diff 复用错乱） | 移植，且**升格**：`Each` 的 key 改为**必填** | G2 |
| R5 | `dead-command` | 命令**全树无人接管**（点了没反应） | 移植，并**扩为跨 `CommandRouter` 的双向可达性校验** | G2 |
| R6 | `grid-unsized` | `Grid` 既没给 `columns` 也没给 `minCellWidth`（列数无从谈起） | 直接移植 | G2 |
| R7 | `table-span` | `Table` 某行 `colSpan` 之和超过列数（末尾格子被挤出） | 直接移植 | G2 |
| R8 | `intent-unhandled` | 动作全树无人接管（与 R5 **同构的第二条通道**） | ❌ **不移植** —— `Intent` 已删除，动作缝只有命令一条通道 ⇒ **与 R5 合并** | — 不移植 |
| R9 | `intent-enumeration` | 声明了动作常量却无 `handleIntent` 接管（`match` 漏分支） | ❌ **不移植** —— 同上 | — 不移植 |
| R10 | ⏭ GUI 仓尚未落地 | 业务编码知识（复合键分隔符 `"\x00"`）泄漏到 UI 层 | ✅ **移植并落地**（= §11.1 的"State 瘦身判据"）。★ Drift 相对 GUI 仓的**净增** | G2 |
| R11 | `dead-field` | 公开字段声明了但全仓库没人读（★ 未登记=error 挡新债 / 已登记=warn 留痕） | 直接移植 | A1 |
| R12 | `render-sync` | 渲染盒的配置属性在 `update()` 里漏同步（Widget 改了它、盒内停在首帧值） | 直接移植（对应 §13.2 的字段对齐） | A9 |

⇒ **生效 10 条**（R1–R7 + R10–R12；R8/R9 因 `Intent` 删除而合并进 R5）。
★ **删掉 `Intent` 的直接收益**：GUI 仓的三条规则（R5 / R8 / R9）在 Drift 里**塌缩成一条**（R5）。少一个概念 = 少两条 lint + 少一套"谁构造/谁携带参数/谁解构"。
★ **两级严重度**（`error` 挡新债 / `warn` 留痕）与白名单机制**一并移植**（R11/R12 各有白名单文件）。

#### 16.6.2 `W1–W8`：控件契约八项

**来源说明**：`W` 系列是 Drift 补定义的"一个控件从写法到渲染要过的检查"（GUI 仓无对应编号）。

| W | 检查项 | 判据 | 依据 | 服务 |
|---|--------|------|------|------|
| W1 | **单一造盒路径** | 渲染控件**有且只有** `createRenderObject()` 一个造盒入口；不得在 Element 之外 `new` 盒 | §13.2 | G1 |
| W2 | **盒字段对齐** | Widget 的每个配置字段都被 `createRenderObject()` 读到（R12 的"Widget 侧"） | §13.2 | A9 |
| W3 | **死字段** | 公开参数**声明了就必须被读**（= R11） | §10.3 | A1 |
| W4 | **溢出策略已声明** | 可能超约束的容器声明 `Overflow`；**外壳类控件（`AppBar`/`StatusBar`）必须有** | §13.1 C5 | G2 |
| W5 | **布局纯函数** | `layout()` 不读自身 `ox/oy`、不读 OS/时钟/随机源；**无界用 `INF` 不用 0** | §13.1 C2 / §14.1 | G3 |
| W6 | **Seal-on-Mount** | 挂载后不得写 Widget；`make()` 的必填参数已提供 | §15.2 / §15.4 | A11 |
| W7 | **违反 ⇒ 响亮失败** | 无界传给有内禀尺寸的子、约束违反、溢出未声明 ⇒ `Errors::report`（**Fatal**） | §13.1 C6 | G2 |
| **W8** | **录制栈平衡** | `DisplayList` 录制结束时命令栈深度 = 0（`save`/`restore`、`pushClip`/`popClip` 配对） | §7.2（缓冲复用的前提） | G3 |

> ★ **W 与 R 不是两套东西**：R 是"**树级**规则"（看父子关系、看命令可达性），W 是"**控件级**规则"（看单个控件的契约完整性）。W3 = R11、W2 = R12 的镜像 —— 同一个判据从两个视角看。实现时**同一个检查器**，只是报错分组不同。

#### 16.6.3 Drift 新增的 lint 规则（共 **17** 条）

| # | 规则名 | 判据（静态可判吗？） | 严重度 | 来源 | 服务 |
|---|--------|-------------------|--------|------|------|
| 1 | `internal-leak` | **非 `Drift\` 前缀**的代码引用了 `@internal` 类型/方法（**判据 = 标注 + 前缀，不是命名空间白名单**） | **error**；有 `@internal-use-allowed: 理由` 豁免 ⇒ warn | §10.1.3 | G2 |
| 2 | `scan-autoload-drift` | `project.yml` 的 `sources` 覆盖的命名空间 ≠ `composer.json` 的 `autoload.psr-4` | **error** | §10.2.5 | G2 |
| 3 | `export-surface-drift` | **仅 `-m lib`**：`@api` 面 ⊄ `<target>.stub.php` 的导出面（某个 `@api` 没进 ABI，或 `@api` 签名引用了被 `#[NoExport]` 级联排除的类型 ⇒ **签名不完整**） | **error** | §10.2.6 | G2 |
| 4 | `dead-subscription`（= A16） | `build()` 体内出现对 `Subject` 子类型实例的方法调用 ⇒ 该 `build()` 所属类必须实现 `SubscribingWidget`，或位于某 `SubscribingWidget` 子树内 | **error** | §11.3 / §21 A16 | A16 |
| 5 | `dead-command`（扩） | ① 全树 `command('x')` 发出的名字 ∈ `CommandRouter` 注册集；② 注册集里的名字**至少被一处发出**（**双向**：死命令 + 死注册） | **error** | §11.4.3 | G2 |
| 6 | `select-purity` | `select` 闭包返回类型必须是**标量 / enum / `readonly class`**；出现 `new` 非 readonly 对象 ⇒ 报错 | **error** | §11.3.3 | G2 |
| 7 | `stale-derived` | `Store` 里出现 `??=` 赋值的属性（缓存槽）⇒ 所有会修改其**依赖字段**的方法里必须有 `$this->xxxCache = null` | **error** | §11.3.4 | G2 |
| 8 | `subscriber-mark` | 声明了 `->select()` / `watch()` 的类必须 `implements SubscribingWidget` | **error** | §10.3 第 5 条 | G1 |
| 9 | `nested-subscribe` | 订阅出现在 `Each` 的 item builder 里（每列表项一个订阅） | **warn** | §11.3.2 纪律 2 | G2 |
| 10 | `cached-subscriber` | `SubjectBuilder` 实例被赋给字段并跨 `build()` 复用 | **error** | §11.3.2 纪律 1 | A4 |
| 11 | `alias-mutate` | 同一 `children([...])` 数组内出现两次同一根变量（`$t->a()`、`$t->b()`） | **error** | §15.6.1 | A12 |
| 12 | `nano-hygiene` | 出现**匿名类** / `yield`（或 `Generator`/`Fiber`）/ `getenv`·`putenv` / `eval`·`include`·`require` | **error** | §16.7 | G1 |
| 13 | `str-concat-loop` | 循环体内用 `.=` / `$s = $s . …` 拼接多字节字符串 | **error** | §2.4 纪律 2 | G2 |
| 14 | `no-using-php` | `cpp-src/**/*.h` 里出现 `using namespace php` | **error** | §2.4 纪律 3 | G1 |
| 15 | `stub-prefix` | 命名空间内调用全局 stub 函数（`platform_*` / `pool_*` / `win_*` …）未写前导 `\` | **error** | §16.5 陷阱 3 | G1 |
| 16 | `namespace-mismatch` | 文件的 `namespace` 声明与 PSR-4 路径不符 | **error** | §4.4 纪律 3 | G1 |
| 17 | `orphan-trait` | 一个 `.php` 文件只含 trait（不产出翻译单元） | **error** | §2.4 纪律 7 | G1 |

> ★ **每条规则必须标注服务对象**（**2026-10-14 裁决**，原 §23 O-11）：三张表的"服务"列 = 该规则服务的**公理**（G1/G2/G3）或**反模式**（A#），**不许留空**。§21 的反模式表同理（"守它的人/机制"列三选一，不许留空）。⇒ 两张表**互相锁死**：新增反模式必须问"谁来守它"，新增 lint 必须问"它守的是哪条公理" —— **守不住任何东西的规则 = A1 的规则版**。
> ★ **全部满足 G1 判据**（"编译器知道吗？"）：类型声明、前缀、标注、闭包签名、文件路径**都是编译期可见的** ⇒ 都该在构建期做，不靠人记。
> ★ **两级严重度 + 白名单**（`error` 挡新债 / `warn` 留痕）沿用 GUI 仓 R11/R12 已验证的机制，**不新发明**。
> ★ **量具双向自检**（§20 第 1 条）：这 17 条**每条**都要配"已知错误"用例 —— 既验该响会响，也验不该响不误响。
> ★ **诚实边界**（**2026-10-14 裁决**，原 §23 O-13）：`nano-hygiene` 只保证**源码纪律**，查不到 tpc 自己注入的 include（如 lib 模式的 `/FI` 聚合头）或链接期问题（PHPX 库路径）。⇒ **加了这条 lint ≠ 能切 nano**；nano 兼容性的最终判据只能是**跑一次真机 `--nano` 构建**。
> ★ 既然 nano 已明确**不作为前期重点**（§16.7.5），`nano-hygiene` 的定位是**零成本保留"将来可切 nano"的可选性**，**不是"nano 就绪"的证明**。
> ★ **未落地的 3 条，缺的都是"消费者"而不是工作量**（2026-10-15 记账）：`scan-autoload-drift` 要 `apps/*/project.yml`（还没有）· `no-using-php` / `stub-prefix` 要 `cpp-src/` C++ 桥接（M1 才建）。**没有消费者的规则只是审自己** ⇒ 到位的当天补，不预写。**落地与否以 `php tools/lint.php --list` 为准**（数字不写进本文件，写即过期）。

### 16.7 ★ `--nano`（无 VM 原生构建）评估

**结论先行**（**2026-10-14 裁决**）：**nano 不作为前期重点 —— M0–M5 明确不做**。**但采纳它的禁用清单作为 Drift 的源码纪律**（零成本，且 **Drift 现状除"网络"外已全部满足**）。即：**不换运行时，只借用它的"编译器强制"**，以零成本保留"将来可切"的可选性。

#### 16.7.1 `--nano` 是什么

`--nano` 把 **PHP 源码 + php-nano + PHPX 源码整体编译进产物**，产物**不链接 `libphp`、不含 Zend opcode 解释器**。

| 事实 | 出处 |
|------|------|
| 支持 `mode: bin` 与 **`mode: lib`**；**不支持 `ext`**（显式报错） | 【手册】§3 · `Translator.php:657-659` |
| **强制 NTS**：`PHP_ZTS = false` | `Translator.php:503-505` |
| **不消费宿主的 PHP/PHPX 运行时**；`getLibraryPaths()` → `[]`；`getLibraries()` → 只剩系统库 | `SourcePipelineTrait.php:602-606` · `NativeBuildConfigurationTrait.php:202-204/238+` |
| **要求 `cxx-std` 必须是 `c++17`**（其它值直接报错） | `Translator.php:661-663` |
| 需要额外 composer 包 **`swoole/php-nano`**（**本机未装**） | `ComposerNativePackage.php:78-82` |
| `--nano` 与 `--full-static` 互斥 | `Translator.php:664-666` |
| **两层开关**：`--nano` 总是开 `nanoPolicyMode`（语法策略）；只有 `NanoBuildBackend::composesRuntimeSources()` 为真才开 `nanoMode`（换运行时） | `Translator.php:498-507` |

#### 16.7.2 nano 禁止的东西（**编译器强制**，不是 lint）

| 类别 | 具体内容 |
|------|---------|
| **VM 入口** | `eval` · `include` / `include_once` / `require` / `require_once` |
| **匿名类** | `new class { … }` |
| **协程 / 生成器** | `Fiber` · `Generator` · `ReflectionFiber` · `ReflectionGenerator` · `yield` · `yield from`（理由："C++17 无标准栈切换 API"） |
| **shell** | 反引号执行 |
| **进程 / 环境** | `dl` `exec` `passthru` `popen` `proc_*` `shell_exec` `system` · **`getenv`** `putenv` · `set_time_limit` |
| **网络 / DNS / socket** | `fsockopen` · 全部 `stream_socket_*` · `stream_select` · `gethostby*` `gethostbyname*` `gethostname` · `dns_*` · `header*` · `mail` · `syslog` |
| **前缀禁用** | `pcntl_` `posix_` `socket_` `ftp_` `opcache_` |

#### 16.7.3 对 Drift 的正面价值（三条）

1. **可消除 §10.2.4 形态 B 的代价 #2** —— "库构建仍需 PHP 运行时与 PHPX ⇒ 不是自包含产物"。nano 下 `getLibraryPaths()` 直接返回 `[]`，PHPX 源码编进产物 ⇒ **形态 B 的 `.dll` 有望变成自包含**，部署从"多文件"缩回来。★ 这是那条代价的**唯一已知解法**。
2. **把"纪律"升级为"编译器拒绝"** —— 上面那张禁用表是**编译期报错**，不是 lint、不是 Code Review。与 G1 同向。
3. **与 Drift 已有的裁决方向一致** —— key 必填 / enum + 无 `default` `match` / 接口标记 / typed array 全都在往"编译期可判定"走；nano 是这条路的终点。

#### 16.7.4 对 Drift 的硬冲突（三条，都是今天的既成事实）

| # | 冲突 | 依据 |
|---|------|------|
| **①** | **nano 强制 NTS，而 §17 的 TaskHost 建立在 ZTS 之上** —— "worker 线程跑分配型 PHP，前置条件 = 入口先 `ts_resource(0)`"，而 `ts_resource()` 是 **ZTS 专属**机制 | `Translator.php:505` · §17.2 |
| **②** | **nano 要求 `cxx-std: c++17`，Drift 用的是 `c++20`** —— 显式报错，不是警告 | `Translator.php:661-663` · §16.4 |
| **③** | **nano 禁全部网络/DNS/socket，而 TaskHost 后台含"网络"、数据来源含 HTTP** | `CompilerBase.php:301-337` · §11.2 |

⇒ **任何一条都足以让 nano 今天不可用**；且 ① 会把 §17 这个**尚未完全定案**的问题提前锁死。

#### 16.7.5 裁决与落地

★ **2026-10-14 裁决**：**nano 不作为前期重点，前期（M0–M5）明确不做。** 理由：三条硬冲突（§16.7.4）今天全部成立，其中 ① 会把 §17 这个**尚未定案**的问题提前锁死；而它的唯一价值（让形态 B 自包含，§16.7.3）**不是当前的瓶颈** —— 形态 B 的"多文件部署"代价**改为接受**（§10.2 代价 2）。

- **不启用**（**M0–M5 明确排除**，形态 A 与形态 B 都走**非 nano**）。
- **但采纳其禁用清单作为源码纪律** —— 逐条核对 Drift 现状：

| nano 禁用项 | Drift 现状 | 判定 |
|------------|-----------|------|
| `eval` / `include` / `require` | 0 处 | ✅ 已满足 |
| **匿名类** | 0 处 | ✅ 已满足 |
| **Fiber / Generator / `yield`** | 明写"**零 Fiber、零协程是刻意选择**"；`yield` **不进核心** | ✅ 已满足 |
| `getenv` / `putenv` | 0 处 | ✅ 已满足 |
| 网络 / HTTP | **有** | ⚠️ **保留**（nano 兼容性让位于功能） |

  ⇒ **除"网络"外 Drift 已全部满足 nano 的约束** ⇒ 相当于**零成本保留了"未来切 nano"的可选性**。新增 lint 规则 **`nano-hygiene`**（§16.6.3 第 12 条）。
- **重估时机**：**不设**（**2026-10-14 裁决**）。原写"§17 定案之后"已作废 —— nano 已明确不做，不再挂在 §17 后面等。只有出现**"必须自包含分发"的明确需求**时才重新评估，且届时**先解 §16.7.4 的 ①/② 两条硬冲突**（NTS vs ZTS、`c++17` vs `c++20`）。

#### 16.7.6 一个必须记下的陷阱：过期注释

`SourcePipelineTrait.php:602-606` 的注释写着 "**Windows Nano deliberately leaves `nanoMode=false`**"，但 `NanoBuildBackend::composesRuntimeSources()` **无条件 `return true`**（不看平台名）。
⇒ **注释已过期，代码才是真的**：Windows 上 `--nano` **会**开 `nanoMode`。（与 §16.5 陷阱 6 的"过时注释"是同一类风险。）

---

## §17 异步与线程（重点 15）

### 17.1 先回答"要不要"

**UI 线程不跑协程。** 理由：

1. Win32 的消息泵是**阻塞等待**（`WaitMessage` / `PeekMessage`）；把协程调度器塞进同一线程，需要在 `pump()` 里"非阻塞轮询 + 让出"，与"阻塞等待不空转"冲突。
2. GUI 仓全仓零协程、零 Fiber、零线程，且**能跑通**（`tetris-win32`、`markdown-editor` 都是单线程泵）—— 说明协程不是 UI 的必要条件。
3. 【手册】§9.14：**协程入口必须 `catch (\Throwable)`**，异常不能穿透至其它协程或依赖外层 `try/catch`，否则可能 `terminate`。把这条约束引入 UI 热路径是净增风险。

★ 第 3 条在"不走协程"的裁决下**反而更容易守**：这条约束根本不进热路径。

### 17.2 裁决：异步收进 `TaskHost` 端口，两条后端

```php
namespace Drift\App;

interface TaskHost
{
    /** 提交后台任务；完成结果经 postEvent 回 UI 线程 */
    public function submit(Job $job, callable $onDone): void;
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
        │  后端 A：**线程池**（C++ `std::thread` + TSRM，【实测】可用）│
        │  后端 B：同步三态（M1–M3 默认，永远可用）                   │
        └───────────────────────────────────────────────────────────┘
```

★ **`submit()` 收的是 `Job` 类，不是 `callable`**（**2026-10-14 裁决**）：与 `CommandHandler` 必须是类**同一条设计语言**（凡是要跨边界的东西都是类 —— 可枚举、可单测、可静态可达性分析），且**绕开"闭包跨线程未验"这个开放项**。

#### 17.2.1 实测依据（探针 E，`probes/taskhost_thread/`）

| 问题 | 实测 |
|------|------|
| ① tpc 能否创建线程 | ✅ 能。`spawn_return_us=40~60`（worker 要跑 11–13ms）⇒ 不阻塞 |
| ② 两线程是否真并发 | ✅ 主线程自旋 `355867~432054` 次的同时 worker 推进 `19532` 个进度块 |
| ③ 跨线程队列 / 原子可用性 | ✅ `mutex`+`deque` 队列 + `std::atomic` 均正常（`pair` 顺序不定而 `sum=33` 恒定，3/3） |
| ④ Swoole 在 AOT 产物里的加载方式 | ⚠️ **不再是阻塞项** —— 线程后端不需要它 |
| ⑤ 线程能否跑 **PHP** | ✅ 能，**前置条件 = `ts_resource(0)`**；4 线程并发亦通过 |

**⑤ 的边界（关键）**：

| PHP 函数体 | 主线程 | worker（不补 TSRM） | worker（先 `ts_resource(0)`） |
|-----------|-------|-------------------|---------------------------|
| 纯整数算术 | — | **42** ✅ | — |
| 纯标量循环 1000 次 | `499500` ✅ | **499500** ✅ | — |
| 循环拼字符串 64 次 | `64` ✅ | **SIGSEGV 3/3** ❌ | **64** ✅ |
| 同上 × **4 线程并发** | — | — | **okCount 4 / tsrmFail 0 / Σ 256** ✅ |

⇒ 边界不在"是不是 PHP 代码"，而在**"碰不碰 Zend 运行时（分配 / 引用计数）"**；
⇒ **修法是一行**：worker 入口先 `ts_resource(0)`。

#### 17.2.2 后端 A —— C++ 线程池 + TSRM

```php
final class ThreadPoolTaskHost implements TaskHost
{
    public function submit(Job $job, callable $onDone): void
    {
        // C++ 侧：从池里取一个 worker（其入口已调过 ts_resource(0)），
        // 执行 job，结果压进跨线程队列；UI 泵每帧 drain 队列并调 $onDone。
        \pool_submit($job, $onDone);   // stub：pool_* → C++ php_pool_*（★ 前导 \ 见 §16.5 陷阱 3）
    }
}
```

- **UI 侧零改动**：Store 调 `$host->submit(...)`，回调里写 `$this->rows = ...`（走显式 notify），三态由 Store 持有。
- **`ts_resource(0)` 归 C++ 侧管**，是桥接层的职责 —— PHP 开发者不该知道 TSRM 存在。
- **⚠️ 仍未验（不假装已验）**：
  - **`ts_resource(0)` 之后的完整线程安全**。探针 E 只证明"分配 + 引用计数"可用；AOT 运行时是否还有**非 TLS** 的全局状态（`get_str()` 持久字符串表、`get_persistent_func` 缓存、opcache）在多 worker 下竞争 —— 未验。**线程池正式落地前必须补这一枪。**
- **⚠️ 线程池复用线程时**，线程退出/回收要配 `ts_free_thread()`（一次性进程可省，池化不可省）。

#### 17.2.3 后端 B —— 同步三态（M1–M3 默认）

```php
final class SyncTaskHost implements TaskHost
{
    public function submit(Job $job, callable $onDone): void
    {
        // 单线程直跑：跑完把结果回贴（下一 tick 生效）
        $onDone($job->run());
    }
}
```

**诚实边界**：后端 B 在大响应时**会短暂停顿**（`tetris-win32` 的 `usleep(16000)` 循环里同步 IO，帧就停了）。这是**平台端口两种后端的取舍**，不是 AOT 能力门 —— **换后端是组合根一行的事**：

```php
// bootstrap
$app = new UsersApplication(new Win32Platform(), new ThreadPoolTaskHost());
//                                                ↑ 换这一行
```

#### 17.2.4 为什么**不**走协程

协程没被"否决"，是被**降级为无关**：

- 协程解决的是"**一个线程内复用多个 IO 等待**"。TaskHost 要的是"**别占用 UI 线程**"。两件事不同。
- 想要"PHP 离开 UI 线程"，直接给线程补 TSRM 即可（已证）；协程既非必要也非充分。
- 想要"UI 线程上不阻塞地做 IO"，那是**事件循环归属**问题（§5.2）：得让 Swoole/Swow 接管整个 Win32 消息泵。代价极大，而 Windows 上 Swoole 仍在 RC。
- ⇒ **协程留作"未来若需在 UI 线程上复用 IO 等待"的备选**，与 TaskHost 解耦。

**⇒ 对"要不要下 PHP 8.4 用 Swow 验"的回答：不需要。** 要验的东西（PHP 能不能离开 UI 线程）已经验完，答案是"能，且不依赖任何扩展"。Swow 只支持 8.4 反而会把 Drift 钉在 8.4 —— 为一个**已被证伪的前提**付版本代价。

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
| **Vue** | 计算缓存 + 版本跳过（形状）· stop 句柄形态 · `v-for` 的控制流语义 | `reactive()` 运行期 Proxy（AOT 做不了）；`v-model` 自动双向（第二套语义）★ **Drift 自己一度造了 `Bound` = 自己的 `v-model`，已按 §23.2 O-16 删除** |
| **Aria** | 订阅生命周期纪律 · 相等门控 · **绝不静默** · 列表 diff 契约 · 编号契约 + 一致性套件 | 自动依赖追踪 · 把渲染外包给原生工具包 |
| **HTML/CSS** | 盒模型 + flex/grid **词汇**（Container / Padding / Stack / Grid / Wrap）· stretch 默认 · `mainAxisAlignment` · GridTrack `fr`/`minmax` · maxWidth 相对约束 · **容器查询** · border-box · 基线对齐 | **margin 合并** · **特异性/层叠** · 全局类名 · 运行期样式表 · **流式布局**（`display:block/inline` 隐式排列 = "两个 Text 横排还是竖排"式魔法，违反 G2） |

> **一句话**：Drift 站在 Flutter 那条路上（三树 + 显式 Key + 显式 setState），**表达层学 SwiftUI 的 modifier 链**，其余框架只借**局部机制**（Qt 的断连、WPF 的分层、Aria 的契约纪律、Svelte 的编译期化），不借范式。

---

## §19 路线图（M0–M5）

| 里程碑 | 内容 | 出口判据 |
|--------|------|---------|
| **M0 骨架** | PSR-4 分层 + 契约文件 + `tools/smoke.php` + **公共面按 `@api` 标注**（决定 lint 骨架） | 冒烟全绿；依赖方向 lint 有断言；`internal-leak` 双向自检过 |
| **M1 可显示** | Win32 Platform + RenderSurface(Skia CPU) + Canvas 接口 + 文本栈桥接 + DPI 缩放 + **`DisplayList` 录制**（决定渲染路径） | 一个窗口画出「Hello Drift」（§A.1），分数缩放下不糊 |
| **M2 三树 + 布局** | Element/State/调和 + RenderBox 两阶段布局 + 首批控件 + **fluent-first 全部落地** + **盒样式（`BoxStyle` + `BoxWidget`，§15.7）** + **焦点遍历顺序** + **光标形状（命中三方消费）** + **`SubscribingWidget` 接口** + **property hooks 进 Store** + **`Each` key 必填** | 布局 conformance 夹具全过；首批控件过 **W1–W8**；Tab 序有 conformance 夹具；`Each` 缺 key ⇒ 构建期报错 |
| **M3 数据缝/多页** | Store 三件套 + `SubjectBuilder` + **`select()`** + RouteTable/PageHost + **动作缝（`CommandRouter` + 冒泡链）** + **三态 enum** + **错误边界容器**（★ 2026-10-14 裁决：**归 M3** —— 见 §23.2 O-12）+ **AcceleratorTable** + **自绘 MenuBar/ContextMenu** + **应用内拖放** | §A.2 的完整应用可跑；局部态与跨页共享两条路径都能写；`Root` 只组合（lint 可查）；Ctrl+S 等加速键可用；§16.6.3 的 **17 条规则全部双向自检**过 |
| **M4 工具链 + 异步** | drift-preview / golden / conformance / drift-lint / **drift-doctor** 齐备 + **TaskHost 线程池后端** + **`notifyChanged` 线程断言** + **ThemeChanged / 深色模式** | 无头 preview + golden diff 可跑；后台 IO 不卡帧；**worker 里 notify ⇒ 响亮失败**；跟得上系统主题切换 |
| **M5 业务底座** | 虚拟化（含 §12.3 的 State 外置纪律）+ 脏矩形 + **WindowStateStore** + Windows UIA 桥接 | 长列表滚动不掉帧；子树崩溃不整树陪葬；窗口位置/尺寸跨启动保留 |

**M1 的探针门（先验后用，任一不过即退化）**：

| 探针 | 状态 / 若不过的退化路径 |
|------|----------------------|
| 命名参数 / `readonly` / backed enum / 箭头函数 | ⏳ 糖退化为位置参数工厂，契约不动 |
| **链式写法在 AOT 下的分派行为** | ✅ **已过**（探针 A/C）：类型不降级；热路径改类型化局部变量，设计不变 |
| `static` 返回类型（IDE 链式推断） | ⏳ 用 PHPDoc `@return $this` 替代 |
| **线程创建 + 跨线程队列 + worker 跑 PHP** | ✅ **已过**（探针 E）：`std::thread` 可建、跨线程队列可用、worker 跑分配型 PHP 可行（`ts_resource(0)`），4 线程并发已过 |
| 命名参数在 AOT 下的支持度（`Dock(top:, center:)`） | ⏳ 退显式工厂方法，API 形状不变 |
| **`-m lib` 分发形态**（§10.2） | ✅ ①②③ 已回答（产物 / 闭包跨 `.dll` / `#[NoExport]` 级联）；⏳ ④⑤⑥ 见 §24.2 |

---

## §20 验收体系

**五段验收**：`lint → preview → build → selftest → golden`。

**四条纪律**：

1. **量具双向自检**：每条规则配"已知错误"用例 —— 既验该响会响，也验不该响不误响。
2. **不留沉默的否决**：每个"不做"都要有结论 + 依据（防重开）。
3. **先验后用**：新 AOT 构造（闭包进类型化属性、嵌套数组、文本拼接、线程、封存）先写探针跑通再进 `src/` —— 否则得到"lint 全绿、真机 segfault"。
4. **每个契约能力必须在示例中出现过**（没出现 = 没交付）—— 对抗 GUI 仓"糖已有、采用率 0"。

**工效量具**（同一个"用户目录页"，测四项）：① `new` 关键字数 ② 内联 `TextStyle` 数 ③ 订阅样板行数 ④ **示例最短路径覆盖的契约比例**。

**★ 性能量具**（**2026-10-14 裁决**）：目前 §15.3 有"0.92 µs/节点"，但**没有目标预算**。补一张表，让"M5 脏矩形到底值不值得做"有判据：

| 阶段 | 目标 | 量什么 |
|------|------|--------|
| M1 | 单窗口静态页面 ≥ 60 FPS | 帧时间（`beginFrame` → `present`） |
| M3 | 1000 节点列表滚动 ≥ 60 FPS（M5 前允许掉帧，但要**有数**） | 帧时间 + `relayout` 全树耗时 |
| M5 | 1000 节点列表滚动 ≥ 60 FPS 且**帧时间与可见项数成正比**（而非与总数） | 帧时间 vs 列表总长 |

---

## §21 反模式表

| # | 反模式 | 后果 | 正确做法 | 守它的人/机制 |
|---|--------|------|---------|-------------|
| A1 | 声明了却从未被读的 API | 静默失效，比不提供更糟 | 死字段 lint（R11 / W3） | lint |
| A2 | 拿合法取值当哨兵（0 当无界 / 未知） | 安静地错 | **哨兵三档定则**（§15.2 纪律 4）：enum case / 负值 / bool 三态；无界用 `INF` | lint + review |
| A3 | 构建期魔法阈值（宽度 < N 折叠） | 改个标签就腐烂 | 布局期行为（Wrap/Scroll）；阈值问**本容器约束宽** | review |
| A4 | build 期订阅 / 忘了退订 | 订阅暴涨 / 僵尸更新 | 只 initState 订；State 订阅袋 + dispose 全退 | 运行期断言 + lint |
| A5 | 设同值也广播 | 白重建 | `===` 门控（`setIfChanged` / `select` 返回值） | 原语 + lint |
| A6 | 裸 `catch(...){}` 吞回调失败 | 缺陷永久静默 | 写进 `Errors` 出口 | review + `Errors` 出口 |
| A7 | 状态语义只写进某个 else 分支 | 其余取值静默失效（主按钮禁用仍满色） | 状态语义覆盖全部取值；**封闭域禁 `default`** | `UnhandledMatchError` |
| A8 | 用 Theme/Inherited 传业务数据 | 变了不刷新 | 业务数据走 `Subject` + `SubjectBuilder` | lint `dead-subscription` |
| A9 | Widget 字段与盒字段两条同步路径 | 首次正常、刷新变样，且静默 | 单一同步函数（`createRenderObject`）+ 字段对齐 lint | lint（R12 / W2） |
| A10 | 全局 UI 在一个类里堆 | 540 行 if-chain | 一页一文件 + `match` 路由表；**Root 只做组合** | lint（Root 判据） |
| **A11** | **挂载后修改 Widget**（链式误用） | 树状态与描述不一致，静默错乱 | **Seal-on-Mount**：封存后写入 ⇒ 响亮失败 | 运行期断言（Fatal） |
| **A12** | **糖自造状态路径**（链式与构造器分叉） | 两套真相源 | 一个类、一条链；`mutate()` 统一守卫；删除测试 | lint（`alias-mutate`）+ review |
| **A13** | **在 UI 线程跑阻塞 IO** | 整帧卡死 | `TaskHost` 端口（§17） | review |
| **A14** | **跨回收存活的状态放进 `State`**（滚动位置 / 选中 / 展开 / 草稿） | 虚拟化回收后静默丢失 | 外置到 Store（§12.3） | 纪律 + review |
| **A15** | **提供收益≈0 的公开 API**（如 `->seal()`） | 永久进入公共契约却不起作用 | 删除它；`seal()` 降为 `@internal` | review |
| **A16** | **`build()` 里读 `Subject` 的值，但该子树不在任何订阅者内** | **改了数据界面不动，且不报错** —— 最难查的一类 | 把读值的那层包进订阅控件；`Root` 只传 store 不传值 | lint `dead-subscription` |
| **A17** | **子树没有错误边界**（★ 真实症状由**帧循环异常策略**决定 —— 见 §23.2 O-12） | 一个坏页面带走整个窗口 | **帧循环异常策略 S3**（有边界 ⇒ 容器捕获；无边界 ⇒ Fatal 序列）+ **容器与纪律同里程碑（都在 M3）** | §23 O-12 |

---

## §22 术语表

| 术语 | 含义 |
|------|------|
| 三树 | Widget（不可变描述）/ Element（身份 + State 宿主 + 调和）/ RenderObject（几何 + 绘制） |
| **`BuildContext`** | `build()` 的入参：**当前渲染环境的只读快照**（本容器约束宽高 / 主题解析后结果 / 焦点）—— **无业务数据、无向上查找**（§3.4） |
| 值缝 / 动作缝 | 数据→UI 的唯一通道 / UI→业务的唯一通道 |
| 命令（`command`） | 动作缝的**唯一**载体：字符串标识 + `CommandArg`。全篇只有这一个名字 |
| **`CommandRouter`** | 应用级命令注册表（`名字 → CommandHandler` 类）；派发链的**第 ③ 环** |
| **`CommandHandler`** | 命令处理器**接口**（必须是类，不能是闭包） |
| **`CommandArg`** | 命令参数的值对象（`none()` / `id(int)` / `key(string)`） |
| Subject | 无 GUI 依赖的观察者基类（通知发出端） |
| SubjectBuilder | 专职订阅控件；`->select(fn)` 提供**订阅粒度切片** |
| **`SubscribingWidget`** | "**专职订阅控件**"的**接口判据** ⇒ 让 A16 的 lint 有判据 |
| **`select`** | 订阅切片函数；返回值必须是**标量 / enum / 不可变值对象** |
| Cell | State 层单值可观察单元；`setIfChanged` 带 `===` 门控 |
| `createRenderObject` | 渲染控件造盒的唯一入口（Widget → 盒的单一路径） |
| **Seal-on-Mount** | 链式构造后，挂载即封存；封存后写入 ⇒ 响亮失败 |
| ThemeScope | 显式主题 scope 链节点（谁生效查父链，不算特异性权重） |
| **`BoxStyle`** | 盒绘制样式的**不可变值对象**（背景 / 边框 / 圆角）；`null` = 无样式；§15.7 |
| **`BoxWidget`** | 需要盒样式的控件的**中间基类**（结构节点继承裸 `Widget` ⇒ 拿不到 `->background()`） |
| Offstage | 隐藏但保留挂载（跳过 layout/paint/hitTest，State 保留） |
| DisplayList | 录制下来的绘制命令序列（数据 ⇒ 可重放 / 可快照 / 可 golden） |
| 逻辑单位 / DIP | 密度无关像素；布局 / 控件 / 主题的唯一语言 |
| dpr | `devicePixelRatio = dpi / 96`；逻辑→物理的唯一定量化边界 |
| TaskHost | 后台任务端口；两条后端（**线程池** / 同步三态） |
| **Job** | 后台任务单元（**类**，不是闭包）；`TaskHost::submit()` 的入参 |
| **KeyChord** | 键位值对象（`KeyChord::ctrl('S')` / `::f(5)`）；加速键与菜单项共用 |
| **AcceleratorTable** | 应用级快捷键表；在**焦点之前**拦截 `KeyEvent` |
| **CursorShape** | 鼠标指针形状的 backed enum；由 `RenderObject` 声明、命中链取最深者 |
| **ThemeChanged** | 系统主题变更事件（深色模式 / 高对比度）；与 `DpiChanged` 同通道 |
| **WindowStateStore** | 窗口位置/尺寸/最大化状态的持久化；放 `Drift\App`，不进 `Platform` |
| **哨兵三档定则** | 未设置怎么写：枚举语义 ⇒ 加显式 enum case；float ⇒ 负值；bool ⇒ 三态 |
| **Errors 三级** | Fatal（release 也中止）/ Recoverable（release 降级）/ Diagnostic（只记日志） |
| **开放域 / 封闭域** | 开放域（string/int 键）允许 `match` 带 `default`；封闭域（enum）禁止（§12.1 第 2 条） |
| RT-N | 运行期红线编号（订阅生命周期 / 盒属性同步 / 通知异常隔离 / 相等门控…） |
| R-N / W-N | lint 规则编号（树级 / 控件级），§16.6.1 / §16.6.2 |
| ⏳ | 未实测的设计点 —— 落地前必须先探针（总表见 §24.2） |

---

## §23 优化提案（v2 修订期间新增）

> 用户要求"边写边找可优化点与新想法"。本章分两节：
> - **§23.1 已直接落地** —— 语义不变（写全已有裁决 / 消除歧义 / 补诚实边界）⇒ 按既定判据直接改，不等裁决。
> - **§23.2 待裁决** —— **语义会变**（新增原语 / 新增守卫 / 新增必填项 / 改变数据表示）⇒ 给方案 + 得失 + 推荐，**等拍板**。
>
> 判据沿用 `DECISIONS.md` 的那一条：**修正后设计语义变不变？**

### 23.1 已直接落地（语义不变）

| # | 位置 | 改了什么 | 性质 |
|---|------|---------|------|
| 1 | §16.6.3 | lint 规则条数**统一为 17 条**（原文 §16.5.3 正文说"九条"/"八条"、表里 10 行、§19 说"8 条"、§23.10 说"9 条"） | 消除自相矛盾 |
| 2 | §3.1 | 分层图补 **`Drift\Commands`**（§4.2 有、图里没有） | 消除图-文不一致 |
| 3 | §16.4 | `ignore:` 示例路径 `src/platform/macos` → **`../../src/platform/macos`**（原文按 yml 所在目录解析，指向的是应用自己的 `src/`，与注释"共享 src/ 里排除异平台目录"矛盾） | 修示例错误 |
| 4 | §11.1 | 视图层"命令 / **局部闭包**"补**界线表**：业务动作 ⇒ 命令；纯 UI 回调 ⇒ 闭包（否则与 §11.4 裁决 2"提交走命令不走闭包"表面冲突） | 消除歧义 |
| 5 | §21 A2 | "正确做法"从 `INF / null / -1` 改为指向 **§15.2 哨兵三档定则**（原文写 `null`，与 §2.2"类型不可变 ⇒ 哨兵值取代可空"冲突） | 统一口径 |
| 6 | §19 M1 | 探针门表里"链式写法在 AOT 下的分派行为"标 **✅ 已过**（§15.3 已实测，原文仍列在待验） | 消除过期状态 |
| 7 | §12.1 第 2 条 | 补 **开放域 / 封闭域**判据（解释为什么路由 `match` 允许 `default`、`LoadStatus` 的 `match` 禁止 `default`） | 消除歧义 |
| 8 | §12.3 | 补"**同一实例挂两处时 Element 身份由位置决定**，要稳定身份必须给不同 `Key`"（原文只说"复用合法"，没说身份怎么定） | 补语义缺口 |
| 9 | §13.1 C5 | 补溢出策略的**"默认值 + 强制显式"边界**（原文说"每个容器必须声明"，与 §15.4 的 `Column::make()` 无 `overflow` 矛盾） | 消除自相矛盾 |
| 10 | §15.4 | 枚举 case 命名统一为 **PascalCase**（原文 §12.2 写 `Overflow::clip`、§15.2 写 `TextRole::Title`） | 统一命名 |
| 11 | §12.2 | Root 的 lint 判据从"`$store->某方法()`"精确化为"**对 `Subject` 子类型实例的方法调用**"（原判据会误伤属性访问） | 修判据 |
| 12 | §7.2 / §16.6.2 | 补 **W8 录制栈平衡**（`save`/`restore` 配对）—— 它是 §7.2"缓冲复用"的**前提**，原文没写成可检查项 | 补守卫（低争议） |
| 13 | §14.2 | 补 **golden 必须绑定测试字体**的诚实边界（原文只说了"注入度量"，没说字体文件本身是第三个输入） | 补诚实边界 |
| 14 | 全文 | 过程记录（评审轮次 / 探针原始数据 / 被证伪的推断 / "上一版如何错" / §23 变更日志）全部移出到 `HISTORY.md`；版本考古语句 52 处删除 | 结构重排 |
| 15 | §2.4 | 新增 **编码纪律集中表**（10 条）—— 原文纪律散落在 §2.2 / §7.2 / §9 / §11.3.2 / §15.6.1 / §16.5，读者无法一次看全 | 结构改进 |
| 16 | §24.2 | 新增 **开放项总表** —— 原文 ⏳ 散落在 §8.3 / §10.2.4 / §17.2.2 / §19，无法一眼看到"还欠什么" | 结构改进 |
| 17 | §24.3 | 新增 **G2 例外登记表** —— §5.4 的"静默降级"是 G2 的显式例外，原文只有一句话，lint 无法据此豁免 | 补机制 |
| 18 | §A（新） | 端到端示例应用 + §9 声明但零示例的能力 + A1–A17 错对对照 + 工具链用法 | 补示例 |

### 23.2 提案表（✅ = 已裁决）

**总览**（争议度 = 我判断的"要不要用户自己想"；**✅ = 2026-10-14 已裁决**）：

| # | 提案 | 争议度 | 我的推荐 | 卡哪个里程碑 |
|---|------|--------|---------|-------------|
| ✅ **O-01** | `Errors` Fatal 在 GUI 里的**中止形态** | 中 | **A：定义"日志 + 错误框 + 退出码"** | M1（第一个 Fatal 就会踩） |
| **O-02** | **`BuildContext` 不得携带业务数据** | 中 | **A′：先定义 `BuildContext` 能力面（O-02a）；lint 很可能不必**（★ 见下方复核） | M2（`BuildContext` 定型时） |
| ✅ **O-03** | `TaskHost::submit()` 收 **`Job` 类**而非 `callable` | 低 | **A：收类**（绕开"闭包跨线程未验"） | M4 |
| ✅ **O-04** | 加速键键位用 **`KeyChord` 值对象**，不用字符串 | 低 | **A：值对象** | M3 |
| ✅ **O-05** | `select()` 返回 **`SelectedBuilder<T>`**，签名由类型系统强制 | 中 | ~~A~~ **不做**（★ **实测证伪**：PHP 无用户态泛型、tpc 不读 docblock ⇒ "编译错误"兑现不了，见探针 G） | M3 |
| ✅ **O-06** | `select` 返回**大数组**时的相等策略 | 中 | ~~B~~ **不做**（★ **实测证伪**：最坏 40 µs = 重建的 4.4%，见探针 F） | M3 |
| ✅ **O-07** | `CommandArg` 的**命名构造器**（`none/id/key`） | 低 | **A：加三个静态方法** | M3 |
| ✅ **O-08** | **golden 绑定测试字体**（已写成边界，未定实现） | 低 | **A：仓库内打包一份测试字体** | M4 |
| ✅ **O-09** | **性能预算表**（§20 补） | 低 | **A：按 §20 的表定目标** | M1 |
| ✅ **O-10** | 新工具 **`drift-doctor`** | 低 | **A：做**（六个陷阱全是环境问题） | M4 |
| ✅ **O-11** | 反模式 ↔ lint **双向索引 + 强制每条反模式有归属** | 低 | **A：做**（否则反模式表会腐烂） | M3 |
| ✅ **O-12** | 反模式 **A17：子树没有错误边界** | 中 | **A′：帧循环异常策略 S3 + 容器归 M3**（★ 已裁决并落地 —— 见下方 O-12 段的 ✅ 抬头） | M3 |
| ✅ **O-13** | `nano-hygiene` 的**诚实边界**（lint 查不到 tpc 注入的 include） | 低 | **A：写进规则说明** | M1 |
| ✅ **O-14** | `Subject` 的**通知批处理**（同 tick 多次 notify 合并为一次**回调派发**） | 中 | **不做**（★ 已裁决 —— 提案自己的场景实测 **1.9 µs = 帧预算 0.011%**；回头闸门 = 回调次数 **N×K ≳ 760**，见探针 H） | — |
| ✅ **O-15** | `CommandHandler` 的**依赖从哪来**（原方案只写了 `->on('x', XCommands::class)`，没说 handler 怎么拿到 store） | 中 | **E：`on()` 收实例**（★ 已裁决并落地 —— 零新 API / 零反射 / 零动态 `new`；A 的 `provide()` 放弃。技术前提已真机证实，见探针 I） | M3 |
| ✅ **O-16** | **`Bound` 的定位**（与 `SubjectBuilder::select` 的关系未定义；现签名**订阅不了**） | **高** | **A′：删 `Bound` 且删 `TextField::bind()`**（★ **已裁决并落地** —— 四层论据见下方 O-16 段的"横向对照"块） | M3 |
| ✅ **O-17** | **样式复用**（背景/颜色/字号）：基类字段 vs `BoxStyle` 值对象 vs trait | 中 | **A′：`BoxStyle` 值对象**（**只管盒样式**）+ 中间基类 `BoxWidget`；**不引入 `TextStyle`**；**删孤儿名 `ControlStyle`**（★ **已裁决并落地** → §15.7） | M2 |

---

#### O-01 `Errors` Fatal 在 GUI 里的中止形态

**✅ 已裁决（2026-10-14）**：按方案 A —— **标准中止序列**（① 写 `Errors.log`，含最近 100 条 Diagnostic 环形缓冲 → ② 走 **`Platform::fatalBox()`** 弹原生错误框 → ③ `exit(3)`），且 **错误框必须走平台端口抽象**（不直调 `MessageBoxW`）。★ **已落地到 §1.2 + §5.1**。

**问题本质**：§1.2 规定 Fatal 级"release 也中止"。但**桌面应用没有控制台**（`no-console: true` ⇒ 无 stdout/stderr）。`abort()` 的用户体验是**窗口闪退、什么都没留下** —— 这恰好是"静默"的另一种形态，与 G2 的意图相反。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|----|----|
| **A（推荐）** | 定义标准中止序列：① 写 `Errors.log`（带栈 + 最近 100 条 Diagnostic 环形缓冲）② 弹一个**原生错误框**（`MessageBoxW`，不依赖渲染栈 ⇒ 渲染栈本身可能已坏）③ `exit(3)` | 用户看得到、支持能拿到日志；错误框不依赖 Skia（避免"渲染坏了连框都弹不出"） | 多一条 `Platform::fatalBox()` 端口（或用 `MessageBoxW` 直调，则破坏"平台名只出现在组合根"） |
| B | 只在 debug 弹框，release 只写日志 | 零新端口 | release 下用户仍看到闪退 |
| C | 不中止，降级继续 | 无闪退 | **直接违反 G2**（Fatal 的定义就是"继续跑必然错"） |

**参考推荐**：**A**。理由：Fatal 的定义是"继续跑必然得到错误结果" ⇒ 必须停；但"停"必须**可诊断**，否则等于 A6（裸吞）的孪生形态。
**落地成本**：`Platform` 加一个 `fatalBox(string $title, string $body): void`（Win32 用 `MessageBoxW`，其它平台用 stderr + `abort`）。**1 天**。
**与既有裁决的冲突点**：§5.1"端口拆小"—— 但这是**基础能力**（每个平台都有），不是壳服务 ⇒ 进主接口合理。

---

#### O-02 `BuildContext` 不得携带业务数据

**问题本质**：§16.6.3 的 `dead-subscription` 判据是"`build()` 体内出现对 `Subject` 子类型实例的方法调用 ⇒ 必须实现 `SubscribingWidget`"。**但如果 `Subject` 是从 `BuildContext` 拿到的**（`$ctx->store()` / `InheritedWidget` 式），lint 就失效了 —— 因为"读值"和"订阅"可以都藏在 `$ctx` 后面。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| **A（推荐）** | **纪律**：`BuildContext` 只允许携带**渲染环境**（主题 / 焦点 / 度量 / 表面信息），**禁止携带业务数据**（`Subject` 实例）。lint：`BuildContext` 的实现类不得出现 `Subject` 子类型的属性/参数 | lint 判据恢复成立；`build()` 的输入面变窄 ⇒ 更好推理 | 深树传 store 必须显式穿参（但 §12.1 第 5 条已裁决"页面参数走共享 store + 显式传参"，本来就该这样） |
| B | 允许 `$ctx->store()`，lint 改为"看 `$ctx->store()` 是否在订阅者内" | 少写参数 | lint 判据变成"跨两层间接"，可靠性下降 |
| C | 不做约束 | 零成本 | `dead-subscription` 有静默漏口 ⇒ A16 又变成"文档承诺了、机制守不住" |

**参考推荐**：~~**A**~~ ⇒ **A′**（见下方复核发现：先补 §3.4 能力面；lint 判定**不需要**）。
**落地成本**：~~写一条纪律 + 一条 lint（`ctx-no-business`）· 0.5 天~~ ⇒ **0.5 天**（只补 §3.4 定义，不加 lint）。
**与既有裁决的冲突点**：无（§11.1 已裁决业务数据走 Subject + 显式传参）。

---

**★ 复核发现（2026-10-14 第二轮 —— 推荐结论需修正）**：

**① 本提案的落点选错了：真正的缺口是"`BuildContext` 的能力面从未定义"。**

全文检索 `BuildContext`：它**只在 §4.2 的目录清单里被列名**（`Widget` · `StatelessWidget` · `StatefulWidget` · `Element` · `State` · `BuildContext` · `Key`），在 `build()` 签名里出现，而**唯一被实际使用的成员是 `$ctx->width`**（§6.1 + §21 A3）。⇒ **它的能力面是一张白纸**，纪律没有可附着的对象。

**② 更要紧：§18 已经否决了 Flutter 式 `BuildContext.of()` 查找**（原文："`BuildContext.of()` 层层嵌套 ⇒ provider soup"）。⇒ 若把这一条一并钉死（`BuildContext` **只有**渲染环境、**没有**向上查找），则：

> **业务数据根本没有路径流进 `build()`** —— 它只能来自构造器注入的字段，而那种情况下 `dead-subscription` 的判据（"`build()` 体内对 `Subject` 的方法调用"）**本来就抓得到**。

⇒ **本提案推荐的 `ctx-no-business` lint 是多余的**：它防的是一个**不存在的通道**。

**③ 但 §12.1 第 5 条留了一个真实开口**：它裁决"页面参数走共享 store，不从根逐层钻参数"，同时 §12.2 要求 `Root` 只做组合。⇒ 中间层容器（`Dock` / `PageHost`）**怎么把 store 递给页面**？若答案是"经过 `BuildContext`"，则 ② 的前提被推翻。**⇒ 这个开口必须先回答，O-02 才能定案。**

**④ 修正后的建议（拆两步）**：

| 步 | 内容 | 语义 | 处置 |
|---|------|------|------|
| **O-02a** | **定义 `BuildContext` 的能力面**：只读的**渲染环境**（本容器约束 `width`/`height`、主题 token 解析、焦点状态、与 DPI 无关的度量）；**明确不含 `of()`/`find()` 式向上查找**（§18 已否决） | **不变**（补全一个从未写过的定义） | 可直接补 |
| **O-02b** | 基于 O-02a 决定 `ctx-no-business` 是否还需要（若 ② 成立 ⇒ **不需要**，降为一条**纪律**：实现类不得出现 `Subject` 子类型字段） | 变 | 等裁决 |

★ **修正后的推荐**：**先做 O-02a**；O-02b 很可能收在"**纪律即可、lint 不必**"—— 比原推荐 A 更省。

**★ 执行结果（2026-10-14 23:05）**：

- **O-02a 已执行** ⇒ `DESIGN.md §3.4`（新增节）：`BuildContext` = **只读渲染环境快照**，三条边界（只读 / 无业务数据 / **无向上查找**）。
- ★ **复核时留下的"开口"已闭合**：业务数据**在组合点直接注入构造器**（§12.1 第 2 条的 `UsersPage::make($this->store)`），`Dock` / `PageHost` 只收**已构造好的 Widget** ⇒ **store 不经过任何中间层** ⇒ 不需要 `BuildContext` 承载它。
- **O-02b 结论随之确定：`ctx-no-business` lint 不需要**（业务数据没有路径流进 `build()`）；边界 ② 以**纪律**形式保留。⇒ **本项完成，无需额外拍板。**

---

#### O-03 `TaskHost::submit()` 收 `Job` 类而非 `callable`

**✅ 已裁决（2026-10-14）**：按方案 A —— `TaskHost::submit(Job $job, callable $onDone)` 收**类**不收 `callable`。★ **已落地到 §17.2**（正文早已按此写，此处追认）。

**问题本质**：§17.2.2 的开放项是"**闭包（带捕获作用域的对象）跨线程调用未验**"。当前 `submit(callable $job, ...)` 把整个设计**压在未验项上**。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| **A（推荐）** | `submit(Job $job, callable $onDone)` —— `Job` 是接口（`run(): mixed`），参数由构造器注入 | ① **绕开未验项**（只传对象 + 调方法，与探针 E 验过的形态同构）② 与 `CommandHandler` 必须"是类"**同一条设计语言** ③ 可枚举、可单测、可静态可达性分析 | 写一个后台任务要多一个类（但本来 `CommandHandler` 也要） |
| B | 保持 `callable`，把"闭包跨线程"列为 M4 前必验探针门 | API 更轻 | 把一个**未验项**放进核心签名 ⇒ 若验不过要改 API（越晚越贵） |
| C | `callable` + 提供 `Job` 包装器 | 兼容两种 | 两套写法 = A12 的形态 |

**参考推荐**：**A**。`onDone` 仍是闭包，但它**在 UI 线程上被调用**（回贴之后），不跨线程 ⇒ 安全。若后续探针证明"闭包跨线程可用"，`Job` 仍然更好（可枚举/可测），不必回退。
**落地成本**：一个接口 + 改两处签名。**0.5 天**。
**与既有裁决的冲突点**：无。

---

#### O-04 加速键键位用 `KeyChord` 值对象

**✅ 已裁决（2026-10-14）**：按方案 A —— `KeyChord` 值对象（`KeyChord::ctrl('R')` / `::f(5)` / `::alt('F4')`），加速键与菜单项共用同一类型。★ **已落地到 §9.1 + §22**（正文早已按此写，此处追认）。

**问题本质**：`->bind('Ctrl+R', 'users.refresh')` 里的 `'Ctrl+R'` 是**字符串字面量**。拼错（`'Ctr+R'` / `'ctrl-r'`）⇒ **静默不生效**，正是 G2 要消灭的形态。而 §15.2 已经为"文本角色"用过"backed enum 代替字符串"的解法。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| **A（推荐）** | `KeyChord` 值对象：`KeyChord::ctrl('R')` / `::f(5)` / `::alt('F4')`。`bind(KeyChord, string)` | 拼错 = 编译错误；IDE 可发现；与菜单项 `->chord(...)` 共用同一类型 ⇒ 两处显示与行为**不可能不一致** | 一个值对象 |
| B | 保留字符串，加 lint 校验格式 | 少一个类型 | lint 只挡格式，挡不住 `'Ctrl+R'` 与 `'Ctrl+Shift+R'` 写反 |

**参考推荐**：**A**。
**落地成本**：一个值对象 + 键名解析表。**1 天**。
**与既有裁决的冲突点**：无（与 §15 的"枚举代替字符串"同向）。

---

#### O-05 `select()` 返回 `SelectedBuilder<T>`

**✅ 已裁决（2026-10-14）**：**不做**（用户批准）。★ 连带已改：§4.2 删掉孤儿名字 `SelectedBuilder`；§11.3.3 那句"更好的做法是用类型系统强制"换成实测结论 + 一条正面契约。

**问题本质**：§11.3.3 用"**lint 保证**有 `select` 必须有参数、无 `select` 必须无参数"。这是 G1 的反面 —— **类型系统本来就知道这件事**（`select()` 返回一个新类型即可）。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| **A（推荐）** | `SubjectBuilder::make($s): SubjectBuilder` / `->select(fn): SelectedBuilder` / `SelectedBuilder::builder(fn($v): Widget)` | "忘了给 `builder` 加参数" = **编译错误**（不是 lint）；`T` 可从 `fn($s): T` 的返回类型**推断** | 多一个类（但 §4.2 已列了它） |
| B | 保持单类 + lint | 少一个类 | 把编译期可判的事丢给 lint |

**参考推荐**：~~**A**~~（见下方复核发现，**推荐改为「不做」**）。
**落地成本**：~~0.5 天~~ ⇒ **真实成本 = 引入并维护一个静态类型检查器**（见复核发现 ④）。
**与既有裁决的冲突点**：~~无（§4.2 已预留 `SelectedBuilder`）~~ ⇒ 复核发现 ⑤：**§4.2 那行是孤儿名字，不是"预留位"**（本次已删）。

**★ 复核发现（2026-10-14 第二轮 —— 收益栏的前提被实测证伪，推荐改为「不做」）**：

**① 问题本质引用了不存在的东西。** 它说"§11.3.3 用 **lint 保证**有 `select` 必须有参数、无 `select` 必须无参数" —— **`grep` 全文，"必须有参数"只出现 2 次**：一次在 §11.3.3 的**结论句**（"更好的做法是用类型系统强制"），一次在本段自己。§11.3.3 **从未声称有这样一条 lint**，§16.6.3 的 17 条里也**没有**它。⇒ 与 O-02 同型：**防的是不存在的东西**。

**② "类型系统本来就知道这件事"是错的 —— PHP 的类型系统根本不知道。**
- `callable` 是**不透明类型**：`callable(T): Widget` 只能写在 docblock 里，语言层面无法表达"1 元闭包"；
- **用户态泛型不可用**（GUI 仓 `MEMORY.md` §0：真不行的只有 匿名类 / `$$var` / `extract()` / **用户态泛型**）。`StdList<T>` 是 **C++ 模板容器、不是 Zend Object** ⇒ 承载不了 `SelectedBuilder<T>` 这种带用户类型参数的形状。
⇒ `SelectedBuilder<T>` 的 `<T>` 是**文档**，不是类型。

**③ 真机实证（探针 G，`probes/select-arity/`，数据见 `probes/RESULTS.md §7`）**：

| 检验 | 结果 |
|------|------|
| PHP 运行时：5 个错误方向 | **4 个响亮**（`ArgumentCountError` / `TypeError`）/ **1 个静默**（★ 正是本提案针对的"有 `select`、`builder` 忘了形参"） |
| `tpc -m bin --dry` 编译带 `@template T` + `@param callable(T): string` 且**故意元数不符**的源码 | **`Dry run completed: 2 C++ source file(s) generated`** —— **零诊断** |
| 生成的 `.cc` | `arginfo` 全部来自**原生**签名，docblock 的 `callable(T): string` 一个字都没进去 |

⇒ **收益栏的"编译错误"兑现不了**：真实效果是"**从 lint 降级为注释**"。

**④ 成本栏低估了一个数量级，而且两难无解。** 成本栏写"多一个类（0.5 天）"。但要让 `<T>` 真的生效，需要引入**一个静态类型检查器**（phpstan 级：读 docblock 泛型 + `callable(T): R` 元数）—— 而**工具链六件套里没有它**。于是：
- **加检查器** ⇒ 它看 `@param callable(T): Widget` 就够了，**不需要 `SelectedBuilder` 这个类**（而且 `@template T` 与 `select()` 返回类型的**跨方法泛型绑定**是 phpstan 里更贵的一档）；
- **不加** ⇒ `SelectedBuilder` **零强制力**。
⇒ **A 在任一支都不成立。**

**⑤ "§4.2 已预留"不成立。** `SelectedBuilder` 在**整个项目里只出现 1 次**（就是 §4.2 那行目录），v1.0 归档里**连名字都没有**。⇒ 那不是"预留的设计位"，是**目录里的一个孤儿名字**。（本次已从 §4.2 删掉；将来真做 O-05 时连同定义一起加回。）

**⑥ 落点选错（第 3 次）。** 真正的缺口不在"要不要多一个类"，而在 **`SubjectBuilder` 这个"契约原语"全文没有类形状**：字段表、方法表、以及**Element 怎么调用 `builder`**（无 `select` 时传 0 参还是传 `null`？）**一处都没定义** —— §4.2 列了它、§11.3.3 用了它、§15.4 展示了它，却**没有 `final class SubjectBuilder` 的成员表**（对比：`StatusBar` 在 §A.2.11 有完整实体）。而"`builder` 怎么被调用"恰好**决定上面那张表里方向 ③′/④ 存不存在**。⇒ ★ **顺带发现：`SubjectBuilder` 缺类形状 ⇒ 归入 O-16 一起做**（同属 M3 的"订阅原语定型"，且 O-16 可能删 `Bound`、动原语集合，两件事必须一次做完）。**不单独编号。**

**⑦ 修正后的推荐：不做。** 与 O-06 同处置口径（都是"推荐被实测推翻"）。
★ 唯一保留的**纪律**（零成本）：`select` 之后 `builder` 的形参**必须**接住切片值；**不要**"只为了门控而 `select`"（那种写法真正的问题是"读了 store 却没订阅"，由 `dead-subscription` 守）。

---

#### O-06 `select` 返回大数组时的相等策略

**✅ 已裁决（2026-10-14）**：**不做**（用户批准）。★ 连带已改：§11.3.3 契约 3 换成实测结论（原"不是免费的"表述已删）。

**问题本质**：§11.3.3 规定相等门控用 `===`。对 `array`，`===` 是**逐元素严格比较** ⇒ 若 `select` 返回 1000 行数据，**每帧要比 1000 次**。这比"重建一次"更贵的情况完全可能。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| A | `select(fn, ?callable $equals = null)` | 灵活 | 多一个可选参数 = 多一条"什么时候该给"的决策规则（心智负担） |
| **B（推荐）** | 维持 `===`，但加 **lint `select-wide`**：`select` 返回 `array` 且**已知长度阈值**（配置项，默认 64）⇒ **warn**"考虑改为只 select 摘要（count / version / 首项 id）" | 零 API 变化；把问题**导向更好的写法**（选择"能代表变化的最小值"）而不是"更聪明的比较" | 需要配置一个阈值 |
| C | 改成按引用/版本号比较 | 快 | 要求 `Store` 维护版本号 = §11.3.4 明确否决的"手写脏标记"同类风险 |

**参考推荐**：~~**B**~~ ⇒ **不做**（★ 前提已被实测证伪，见下方复核发现）。
**落地成本**：~~一条 lint · 0.5 天~~ ⇒ **0**（不做）。
**与既有裁决的冲突点**：无。

---

**★ 复核发现（2026-10-14 第二轮 —— 推荐结论被实测证伪，改为「不做」）**：

**① 本提案的前提是错的。** 断言"每帧比 1000 次，**比重建一次更贵**的情况完全可能" —— **已实测**（探针 F，`probes/select-eq/`，数据见 `probes/RESULTS.md §6`）：

| 形态 | 耗时 | 占 1000 节点重建（920 µs） |
|------|------|--------------------------|
| 1000 对象 · 同一批实例（**列表页真实形态**，COW 命中） | **0.020 µs** | 0.002% |
| 1000 标量 · 全等（比到底） | 7.0 µs | 0.76% |
| **1000 嵌套数组 · 内容同但不同实例（真最坏）** | **40.3 µs** | **4.4%** |
| 门控不命中（首位即不同） | 0.029 µs | 0.003% |

⇒ **最坏也只有重建的 4.4%（差 23×）**；而**慢的场景恰恰是门控命中的场景**（付 7~40 µs 省下 920 µs 重建）⇒ **净赚 23~130×**。**两个方向都不亏。**

**② 原推荐 B 的 lint 判据本身不可实现。** 它要求"`select` 返回 `array` 且**已知长度阈值**" —— 而**数组长度是运行期事实**，静态分析拿不到 ⇒ 违反 G1（"编译器知道吗？"）。退一步改成"返回 `array` 就 warn"，则**文档自己的示例**（§15.4 / §A.2.9 的 `->select(fn($s): array => $s->visible())`）就会触发自己的 lint。

**③ 修正后的推荐：不做。** 既不加 lint、也不改契约。与 §23 O-14 同一处置口径 —— 但 O-14 是"没有实测证据"，本项是"**实测证伪**"，更硬。
★ 唯一保留的**纪律**（零成本）：`select` 返回数组时**应返回 `Store` 持有的稳定数组**（不要每帧新建），并优先选摘要。这条不靠 lint，靠 §11.3.3 的现有契约 + review。

---

#### O-07 `CommandArg` 的命名构造器

**✅ 已裁决（2026-10-14）**：按方案 A —— `CommandArg` 加命名构造器 `none()` / `id(int)` / `key(string)`，构造器保留（公开无害）。★ **已落地到 §11.4.3**（正文早已按此写，此处追认）。

**问题本质**：`new CommandArg(i: $u->id)` 需要记住"id 放 `i`、key 放 `s`"这个**位置约定**。命名构造器把这个约定变成 API。

**方案**：**A（推荐）** 加 `CommandArg::none()` / `::id(int)` / `::key(string)`；构造器保留（`@internal` 降级不必要，公开无害）。
**得**：调用点自解释（`->arg(CommandArg::id($u->id))`）；未来加类型时**只加静态方法**，构造器签名不动。
**失**：三个静态方法（纯转发，§10.3 第 6 条允许）。
**参考推荐**：**A**。**落地成本**：0.2 天。**冲突点**：无。

---

#### O-08 golden 绑定测试字体

**✅ 已裁决（2026-10-14）**：按方案 A —— **仓库内打包一份测试专用字体**，`golden` 工具强制使用，并**断言**实际字体 == 测试字体。★ **已落地到 §14.2 + §16.2**。

**问题本质**：`DisplayList` 是数据，但它引用的**字形**来自字体文件。`RasterCanvas` + 确定性 `TextMetricsProvider` 只解决了"同一份度量算法"，没解决"同一份字形"。换机器 ⇒ golden 位图不同 ⇒ golden 变成 flaky 测试。

**方案**：**A（推荐）** 仓库内打包一份**测试专用字体**（覆盖拉丁 + 常用 CJK 子集，或直接用一份开源字体），`golden` 工具**强制**用它（不给参数就用默认测试字体，并断言）。
**得**：golden 可复现；跨机器/CI 一致。
**失**：仓库多一个字体文件（几 MB，可只放子集）。
**参考推荐**：**A**。**落地成本**：0.5 天。**冲突点**：无。

---

#### O-09 性能预算表

**✅ 已裁决（2026-10-14）**：按方案 A —— 采用 §20 那张三行性能预算表（M1 单页 60 FPS / M3 1000 节点有数 / M5 帧时间与可见项数成正比）。★ **已落地到 §20**。

**问题本质**：§20 有"工效量具"，但**没有性能量具**。§15.3 有 0.92 µs/节点，但没有目标 ⇒ "M5 脏矩形到底值不值得做"没有判据（GUI 仓的门禁是"等症状"，但"症"没有定义）。

**方案**：**A（推荐）** 采用 §20 已写的那张三行表（M1 单页 60 FPS / M3 1000 节点有数 / M5 帧时间与可见项数成正比）。
**得**：M5 的脏矩形有 kill criteria；性能回归可被 CI 发现。
**失**：需要一台基准机（数字不可跨机比 ⇒ 只做**同机回归**，与 §15.3 的"只读量级"同口径）。
**参考推荐**：**A**。**落地成本**：0.5 天（写进 `drift-devtools` 的输出）。**冲突点**：无。

---

#### O-10 新工具 `drift-doctor`

**✅ 已裁决（2026-10-14）**：按方案 A —— **做** `drift-doctor`，一条命令输出"哪一项不满足 + 症状会是什么"。★ **已落地到 §16.2**（五件套 → **六件套**）。

**问题本质**：§16.5 的六个陷阱**全都是"环境配置错 ⇒ 报错指向别处"**：缺 `mbstring` 报 `Failed to startup module`、`include` 路径错报 `getAbsolutePath()` TypeError、CRT 不匹配报 `LNK2038`、缺 `icudtl.dat` 直接 abort、字体初始化失败报 `0xC0000005`。**每一个都会让第一个踩的人去查编译器 bug。**

**方案**：**A（推荐）** `drift-doctor` 一条命令检查：`PHPRC` 指向的 ini 里 `extension_dir` 是否绝对路径 / `mbstring` 是否可加载 / Skia include+lib 是否在预期位置 / `icudtl.dat` 是否可定位 / `cl.exe` 是否在 PATH / `c++20` 是否被支持 / 系统字体族是否可枚举。**输出"哪一项不满足 + 症状会是什么"**。
**得**：把六个"谜题"变成一次自检；新人上手成本大幅下降。
**失**：一个工具（但它是纯 PHP，且 §16.2 已有五个同类）。
**参考推荐**：**A**。**落地成本**：1–2 天。**冲突点**：无。

---

#### O-11 反模式 ↔ lint 双向索引

**✅ 已裁决（2026-10-14）**：按方案 A —— 两条硬规则：§21 每条反模式标注"守它的人/机制"（不许留空）+ §16.6 每条规则标注**服务哪条公理或反模式**（不许留空）。★ **已落地到 §16.6（三张表新增"服务"列）+ §21**。

**问题本质**：§21 有 17 条反模式，§16.6 有 27 条规则，但**两者没有互相指向**。后果：① 新增反模式时没人问"谁来守它"（反模式表会腐烂成一堆口号）；② 新增 lint 时没人问"它守的是哪条公理"。

**方案**：**A（推荐）** 两条硬规则：
1. **§21 每条反模式必须标注"守它的人/机制"**（lint 规则名 / 运行期断言 / 纪律 + review）—— 三选一，**不许留空**；
2. **§16.6 每条规则必须标注它服务哪条公理（G1/G2/G3）或哪条反模式**。

**得**：两张表互相锁死，都不会腐烂；且**新规则的"该不该做"有判据**（守不住任何东西的规则 = A1 的规则版）。
**失**：维护成本（但每次改动只多写一列）。
**参考推荐**：**A**。（§21 已按此格式写好。）
**落地成本**：0.2 天。**冲突点**：无。

---

#### O-12 反模式 A17：子树没有错误边界

**✅ 已裁决（2026-10-14）**：按 **A′** —— ① 定义**帧循环异常策略 S3**（有边界 ⇒ 容器捕获；无边界 ⇒ Fatal 序列）；② **容器与纪律同里程碑，都归 M3**（§19 的 M5 那处已删）；③ 加 A17 反模式。★ **已落地到 §19（M3 行）+ §21 A17 + §A.2 反模式表**。

**问题本质**：§19 把"错误边界容器"排在 M5，但**在此之前**，一个页面的 `build()` 抛异常（比如 `UnhandledMatchError`、`select` 里的业务代码抛）⇒ 整帧崩 ⇒ 整窗口黑。而 §21 的 A6 只覆盖"回调里裸吞异常"，**没覆盖"异常从 build 冒到帧末"**。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| **A（推荐）** | 加 A17；M2 先给**纪律**（"每个页面级 `build()` 必须包在边界内"），M5 做容器（显示"此页出错"占位 + 写 `Errors`） | 一个坏页面不带走整个窗口；与 A6 成对（一个管"吞"，一个管"漏"） | 容器是 M5 的，M2–M4 只有纪律 |
| B | 不列为反模式，等 M5 一起做 | 零成本 | M2–M4 的样板页面会示范"不包边界"的写法 ⇒ 之后要改全部示例 |

**参考推荐**：~~**A**~~ ⇒ **A′**（见下方复核发现：先定"帧循环异常策略"，容器与纪律必须**同里程碑**）。
**落地成本**：~~纪律 0.2 天；容器 M5~~ ⇒ 策略 0.2 天 + 容器 **1–2 天**（与纪律**同里程碑**）。
**与既有裁决的冲突点**：~~无（§19 已排 M5）~~ ⇒ **§19 里"错误边界"同时在 M3 和 M5**（见复核发现 ①）。

**★ 复核发现（2026-10-14）—— 前提与 §19 矛盾，且真正的缺口在别处**：

**① 本提案只读了 §19 的一半。** 它说"§19 把'错误边界容器'排在 M5，但**在此之前**…"。而 §19 里**"错误边界"出现在两处**：

| 里程碑 | 原文（节选） |
|--------|-------------|
| **M3 数据缝/多页** | … + **三态 enum** + **错误边界** + **AcceleratorTable** + 自绘 MenuBar/ContextMenu + 应用内拖放 |
| **M5 业务底座** | 虚拟化 + 脏矩形 + **错误边界容器** + `WindowStateStore` + Windows UIA 桥接 |

⇒ **§19 自相矛盾**（同一件事挂在两个里程碑、名字还不一致）。而 O-12 的**整条推论**（"M2–M4 只有纪律、容器在 M5"）**依赖只取 M5 那一处**。⇒ 与 O-02 / O-05 / O-06 同型的第 **4** 次：**前提没核全**。

**② 症状"整帧黑"是未验断言，而且很可能不准。** 全文检索"帧循环遇到**未捕获的 PHP 异常**怎么办" —— **一个字都没有**。§1.2 的 Fatal 序列覆盖的是"**框架自己 `Errors::report` 的 Fatal**"，不是"用户代码抛出的异常"。⇒ 真实症状取决于一个**从未定义**的策略：

| 若帧循环… | 真实症状 |
|-----------|---------|
| 让它冒到顶层 ⇒ 走 Fatal 序列 | **应用退出 + 错误框**（**不是**"整帧黑"） |
| 吞掉 + 跳过本帧 | **窗口冻结在上一帧**（半静默 —— G2 不容） |
| 什么都不做 | 进程直接挂；`no-console: true` 下**什么都不留下**（最坏形态） |

**③ 所以真正的缺口不是"要不要加 A17"，而是「帧循环对未捕获异常的策略」从未定义。** 定了它，A17 的形状才可推：

| 策略 | 内容 | 一个坏页面 | 代价 |
|------|------|-----------|------|
| **S1** | 未捕获异常 ⇒ **Fatal 序列**（日志 + 错误框 + `exit(3)`） | 带走整个窗口 | **0**（已有机制，只需接线） |
| **S2** | 未捕获异常 ⇒ **边界容器**捕获 ⇒ 该子树显示占位 + 写 `Errors` | 只坏那一块 | 容器 + 一条 lint（"页面级 `build()` 必须在边界内"） |
| **S3** | **S1 + S2 组合**：有边界走 S2、没边界落回 S1 | 只坏那一块 | 同 S2；★ **唯一"两种写法后果都可预期"的组合** |

⇒ **推荐 S3**（S2 是它的子集）。★ **明确不做"帧循环吞掉异常"那一路** —— 它是 G2 明令禁止的半静默（窗口冻着、用户不知道为什么）。

**④ 顺带：A17 的"纪律"在没有容器的里程碑里无法执行。** 判据列写"纪律（M2）+ 容器（M5）" —— 但 **M2 时容器不存在**，纪律要求"把页面 `build()` 包在边界内"却**没有可包的东西** ⇒ **空头承诺**（与 O-05"要加一个不存在的位"同型）。
⇒ **容器必须与纪律同里程碑**。★ 倾向 **M3**：容器本质是"一个包装 Element + try/catch"，**不依赖虚拟化/脏矩形**；而 M3 的验收标准"§A.2 的完整应用可跑"恰恰需要它（否则一个页面出错就把整个 demo 带走）。⇒ **建议容器归 M3，M5 那处删掉。**

**⑤ 修正后的推荐（A′）**：**加 A17，但把它挂到"帧循环异常策略（S3）"下**，容器与纪律**同里程碑（建议 M3）**，并**先修 §19 的自相矛盾**。★ 原推荐"加 A17"的方向对，但**症状与落点都要重写**。

---

#### O-13 `nano-hygiene` 的诚实边界

**✅ 已裁决（2026-10-14）**：按方案 A —— 在 §16.6.3 规则说明里写死"`nano-hygiene` 只保证**源码纪律**，最终判据是跑一次真机 `--nano` 构建"。★ **已落地到 §16.6.3**；且随 nano 降级（§16.7.5），本规则定位改为"零成本保留可选性"。

**问题本质**：`nano-hygiene` 只扫 **PHP 源码**。而 nano 的失败还可能来自 **tpc 自己注入的 include**（如 lib 模式的 `/FI` 聚合头）或**链接期**（PHPX 库路径）。⇒ 规则名容易被误读为"加了这条 lint 就能切 nano"。

**方案**：**A（推荐）** 在 §16.6.3 的规则说明里写死一句："`nano-hygiene` 只保证**源码纪律**；nano 兼容性的最终判据是**跑一次真机 `--nano` 构建**"（已写入）。
**得**：避免"lint 全绿 ⇒ 以为能切 nano"的错误推断。
**失**：无。
**参考推荐**：**A**（已在 §16.6.3 落地）。**落地成本**：0。**冲突点**：无。

---

#### O-14 `Subject` 的通知批处理

**✅ 已裁决（2026-10-14）**：**不做**。★ 唯一保留的**量化回头闸门**：回调次数 **N×K ≳ 760**（≈ 帧预算 1%，扇出单价 0.22 µs/订阅者/次）；真出痛点时首选形态 **C（`$store->batch(fn)`）**，见复核发现 ⑤。

**问题本质**：§5.2 已裁决"统一重建发生在帧末 `flush`" ⇒ **重建**已经批处理了。但 `notifyChanged()` 的**回调本身**仍会同步跑 N 次（一个 `Store` 上 5 个订阅者、一轮业务改了 3 个字段若调 3 次 notify ⇒ 15 次回调）。§11.3 的纪律是"改完手动调**一次**"，但这是**人的纪律**，不是机制。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|------|----|
| A | `Subject` 内置批处理：同一 tick 内多次 `notifyChanged()` 合并为一次回调（用 `dirty` 标志 + 帧末统一派发） | 机制化（不靠人记） | ① 改变"notify 是同步的"这个语义 ⇒ 有代码依赖"notify 后立刻回调"就会坏；② 与 §5.2 的"派发中产生的回调入队下一 tick"叠加后语义变复杂 |
| **B（推荐）** | **M3 先不做**；保留"改完调一次"的纪律 + `Cell` 门控。**M4 有了实测痛点再上**，且届时按 A 实现（那时 `notifyChanged` 已有线程断言，正好一并处理） | 不引入新语义；避免过早优化 | 纪律仍在人手里 |

**参考推荐**：~~**B**~~ ⇒ **不做**（★ 复核发现：提案自己的场景**已量化**为 1.9 µs）。
**落地成本**：~~0（现在）；若将来做，2–3 天~~ ⇒ **0**。
**与既有裁决的冲突点**：A 与 §5.2 第 3 条的"重入安全"需要重新对齐。

**★ 复核发现（2026-10-14）—— 前提可量化，量化后是「不做」，但理由要换**：

**① 本提案在总览表里的描述是错的。** 那行写"同 tick 多次 notify **合并为一次重建**" —— 而"**重建**合并"**已经**由 §5.2 裁决 3 完成（原文："统一重建发生在帧末 `flush`…回调里连点十次只重建一次"）。本提案真正要合并的是**回调派发**，不是重建。⇒ 已改（描述与 §5.2 撞车）。

**② 真机实证（探针 H，`probes/notify-fanout/`，数据见 `probes/RESULTS.md §8`）**：

| 形态 | µs/轮 | 占重建 | 占帧预算 |
|------|------:|------:|--------:|
| **5 订阅者 · 3 次 notify（★ 提案自己的场景）** | **1.900** | 0.21% | **0.011%** |
| 5 订阅者 · 10 次 notify | 5.199 | 0.57% | 0.031% |
| 50 订阅者 · 3 次 notify | 16.497 | 1.79% | 0.099% |
| **500 订阅者 · 3 次 notify** | **167.125** | 18.2% | **1.00%** |

⇒ **提案自己举的场景 = 1.9 µs = 帧预算的 0.011%**。要让它变成可测量的 1%，需要 **500 个订阅者** —— 而那正是 §11.3.2 纪律 2 禁止、lint `nested-subscribe` 守的形态。⇒ **能被 O-14 救的场景，已经被另一条规则挡住了。**

**③ 新判据（可复用）**：**回调扇出单价 ≈ 0.22 µs / 订阅者 / 次**（含闭包调用 + `select` + 门控）⇒ **N×K 次回调 ≈ N×K×0.22 µs；占帧预算 1% 需要 N×K ≈ 760**。这是"什么时候该回头做 O-14"的**量化闸门**，比"有实测痛点再上"可执行。

**④ A 方案的真实代价，比原文写的更具体。** 原文说"有代码依赖 notify 后立刻回调就会坏"（模糊）。**精确版**：§5.3 **已明确承认存在"非控件的纯业务订阅者"**（"订阅者不是控件（纯业务订阅者，比如日志 / 缓存失效）"）⇒ 把它们改成帧末派发，会让"改完数据 ⇒ 立刻读缓存"这类**同栈顺序依赖**失效。⇒ **A 不是"优化"，是"改一条业务代码可感知的语义"**。

**⑤ 若将来真要做，A 不是首选形态。** 更好的形态是 **C：显式批处理 `$store->batch(fn() => …)`** —— 期间 `notifyChanged()` 只记账、退出时派发一次：
- **得**：默认语义**不动**（同步仍是默认）；批处理由**业务代码自己声明**，不需要"帧末"这个框架概念渗进业务层；不受 §5.2 裁决 3 的 next-tick 队列叠加影响。
- **失**：多一个 API + 一条"什么时候该用"的决策规则。
⇒ **记录在此，供将来真出痛点时直接取用**（不必重新推演）。

**⑥ 修正后的推荐：不做**（同原推荐 B）—— 理由从"没有实测证据"升级为"**提案自己的场景实测 1.9 µs；可测量区间已被 `nested-subscribe` 挡住**"，并给出量化回头闸门（**N×K ≳ 760**）。

---

#### O-15 `CommandHandler` 的依赖从哪来

**✅ 已裁决（2026-10-14）**：**选方案 E** —— `on()` 收 **handler 实例**（不是类名）。依赖**就在构造点**（组合根是唯一的依赖落点），零新 API / 零反射 / 零动态 `new`。★ **已落地到 §11.4.3（注册段 + “必须是具名类的实例”段 + E 的三条代价）+ §A.2.5（`commands()` 删 `provide()`、`boot()` 补 `$src`）+ §A.2.10**。★ **放弃 A 的 `provide()`**，但保留其“依赖清单可 lint”的问题意识（将来若需要再取用）。

**问题本质**：§11.4.3 规定了"handler 必须是**类**"，并给了 `CommandRouter::make()->on('user.del', UserCommands::class)`。但 handler 显然需要依赖（`UserStore` / `SignupForm` / `UserSource`）—— **这些从哪来，原方案一个字没写**。这是 §A.2.10 写示例时暴露出来的**真实缺口**：示例代码不能自己发明机制。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|----|----|
| **A（推荐）** | `CommandRouter::make()->provide(类, 实例)->on(名字, handler类)` —— **应用级显式依赖登记**（就是一张 `类名 → 实例` 的表，按构造函数参数类型注入） | ① 依赖**显式可读**（组合根一行行列出来）② 不需要容器/反射（G1：不引入运行期魔法）③ handler 可单测（构造器注入）④ 与 §10.3 第 6 条"糖只转发"无关（这是**机制**不是糖） | `provide()` 是新 API（一行注册，但每个依赖都要写一次） |
| B | `->on(名字, fn() => new UserCommands($this->users, $this->app))` | 零新 API | **闭包不可枚举** ⇒ devtools 列不出 handler；且与"handler 必须是类"的裁决**直接冲突** |
| C | 引入一个通用 DI 容器（按类型自动解析） | 少写几行 | 运行期反射/自动装配 ⇒ 依赖关系**不可静态读出**；违反 G1 精神；且引入"隐式全局" |
| D | handler 通过 `Application` 上的 accessor 取（`$this->app()->store(UserStore::class)`） | 零注册 | 与 C 同类问题（隐式），且 handler 与 Application 耦合 |

**参考推荐**：~~**A**~~ ⇒ **A 或 E**（见下方复核发现：技术前提**已真机证实可用**，但 A 有两处未写的必填项，且漏了更简的 E）。
**落地成本**：`provide()` + "按类型取参数" + 一条 lint。**1.5 天**（原估 1 天，补两处必填项 + lint）。
**与既有裁决的冲突点**：无。★ 但要注意：**`provide()` 登记的是"应用级单例"**（`UserStore` / `SignupForm` 天然是）—— 若将来出现"每页一个 handler 实例"的需求，那属于 §12.1 第 5 条"页面就近接管"，走**树内 handler**（冒泡链第 ① ② 环），不扩 `provide()`。

**★ 复核发现（2026-10-14）—— 缺口真实、技术前提**实证成立**，但方案漏了一个更简的形态，且有两处未写的必填项**：

**① 缺口是真的**（复核确认）：§11.4.3 与 §A.2.10 的 handler 都写了 `__construct(private UserStore $users, private AppStore $app)`，而**这些实例从哪来，全文没有第二处交代**。⇒ 这是 §A.2 写示例时暴露的**真实缺口**，不是"文档没写全"。

**② ★ 技术前提：真机实证可用**（探针 I，`probes/dynamic-new/`，数据见 `probes/RESULTS.md §9`）。`->on('user.del', UserCommands::class)` 存的是**类名字符串** ⇒ 派发时必须 `new $className(...$args)` —— 这是 AOT 最容易被限制的形态（同族：匿名类 / `$$var` / `extract()` 都不可用）。实测**三项全过**（真机构建 + 运行）：

```
$ ./dnew.exe
constructed = UserCommands
handle()    = true
```

生成物是**原生翻译**（不是 eval）：`h = php::newObject(cls, tmp_var_2)` + `typephp_call_method_cached(...)`。⇒ **O-15 的前提成立**（与 O-05 相反：那次被证伪，这次被证实）。

★ 但两条路径代价不同：静态 `new C` 走 `get_persistent_class`（**持久类缓存**）；动态 `new $cls` 走 **按名查类（无缓存）** ⇒ **每次派发多一次类名查找**（方法调用有 `get_method_call_cache` 缓存）。

**③ ★ 方案漏了一个更简的形态 —— `on()` 收「实例」。** §11.4.3 给出的"handler 必须是**类**"的**三条理由**是：① 可枚举（devtools 列得出）② 可单测 ③ 死命令 lint 需要静态可达性（"闭包拿不到"）。**而"具名类的实例"三条全满足**（`get_class($h)` 可枚举 · 实例可单测 · `new UserCommands(...)` 字面量在调用点静态可见）。⇒ 于是有了第四个方案：

| 方案 | 内容 | 得 | 失 |
|------|------|----|----|
| **E（复核新增）** | `->on('user.del', new UserCommands($this->users, $this->app))` —— **收实例** | **零新 API、零反射、零动态 `new`**；依赖**就在构造点**（比 `provide()` 少一层间接）；组合根成为**唯一**的依赖落点（G2"一个概念一个落点"） | ① handler 在注册期即构造 ⇒ **单例**（须纪律：handler 无状态）② **多命令共用同一 handler 时要提变量**（`NavigationCommands` 被 3 个命令用 ⇒ `new` 写 3 次或提局部变量）③ **要改 §11.4.3 的既有裁决**（`on()` 第二参数从类名改成实例） |

**④ A 的两处未写必填项**（不补，A 就不是一个可实现的方案）：

| # | 缺什么 | 为什么必须写 |
|---|--------|-------------|
| **1** | **依赖解析的机制** —— "按构造函数参数类型注入"必须**知道参数类型** ⇒ 要么**反射**，要么**构建期生成的签名表** | ★ 而 A 的"得"栏 ② 却写着"**不需要容器/反射**" ⇒ **自相矛盾**。★ 修正：反射**可以用**（§2 的立场是"反射可用、只是不在渲染热路径用"），且它发生在**注册期一次**，不在热路径 ⇒ 措辞改为"**不需要运行期自动装配**；构造器解析在注册期做一次" |
| **2** | **handler 的生命周期** —— 每次派发新建 vs 注册时单例 | 它决定"handler 能不能持有跨命令状态"（能 ⇒ 有 bug 面；不能 ⇒ 天然无状态）。★ 推荐 **注册期解析一次参数表、派发期 `new`**：反射只做一次 + handler 天然无状态 |

**⑤ 还要补一条 lint（G1）**：反射失败的**症状是"首次派发才响"**（运行期，且晚）。⇒ 加规则 **`handler-deps-resolvable`**：**handler 构造器的每个参数类型都在 `provide()` 表里**（两侧都静态可读）⇒ 把失败提前到构建期。这是 A 方案"显式"承诺的兑现方式。

**⑥ 与既有裁决的关系**：`provide()` 只**登记引用**，不转移所有权 —— `Application::init()` 里 `new` 出来的 `$users` / `$form` / `$app` 仍归 `Application`。要写明，否则会被读成"两个真相源"。

**⑦ 修正后的推荐：A 或 E 二选一**（B/C/D 仍按原文否决）：
- **A** —— 保持既有裁决（`on(名字, 类)`）、多命令共用 handler **零重复**、依赖清单**集中一处且可 lint 死登记**；但必须补 ④ 的两处 + ⑤ 的 lint + 改 ② 的措辞；
- **E** —— 少一个原语、少一层间接、零反射；代价是改 §11.4.3、依赖清单隐式化、多命令共用时要提变量。
★ **倾向 A**（不动已裁决的形态；"应用级依赖清单"本身是个有价值的、可 lint 的概念）。

**⑧ ★ 第三轮复核又发现一处：§A.2.5 的 `provide()` 清单漏了 `UserSource`。** 原 `commands()` 只 `provide` 了 `UserStore` / `SignupForm` / `AppStore`，而 §A.2.10 的 `SignupCommands` 构造器要 **`UserSource $src`** ⇒ **按 A 走会“首次派发才响”**（正是 ⑤ 那条 `handler-deps-resolvable` lint 要抓的形态）。⇒ ★ **这条反而是 E 的优势**：E 在组合根显式 `new SignupCommands($this->form, $this->src)` ⇒ **漏参是编译错误**（不是 lint、不是运行期）。

---

#### O-16 `Bound` 的定位（与 `SubjectBuilder::select` 的关系未定义）

**✅ 已裁决（2026-10-14）**：按 **A′** —— **删 `Bound` 原语，且删 `TextField::bind()`**；表单控件的官方形态 = `SubjectBuilder::select(...)` 包住手写的 `value()/onChanged()`。★ **已落地到 §4.2 / §10.3 第 5·6 条 / §11.3.2 / §11.4.2（整节重写）/ §11.4.3 / §15.4 / §16.6.3 / §19 M3 / §21 术语表 / §A.2.8 / §A.2.9 / §A.2.10 / §A.2 反模式表**。

**问题本质**：§11.4.2 把 `Bound` 定为"**契约原语**，订阅**随控件自带**"，契约展开写成：

```php
Bound::make($form->name, fn(string $v): Widget => TextField::make()->value($v)->onChanged(...))
```

但 `$form->name` 是 **`string`**（property hook，§11.4.1）⇒ **`Bound` 收不到 `Subject` 引用 ⇒ 它订阅不了**。而"订阅"正是它存在的全部理由。
⇒ 对照 §A.2.11 的 `StatusBar`（**正确形态**）：`->source($subject)->select(fn($s): T => …)` —— **要订阅就必须持有 `Subject`**。

**三条附带证据**（都指向同一处）：

| # | 证据 | 说明 |
|---|------|------|
| 1 | §A.2.9 搜索框注释写「Bound 自带订阅（§11.4.2）」，**代码却手写** `TextField::make()->value()->onChanged()` | 注释与代码矛盾；且与 §11.4.2 的裁决方向**相反** |
| 2 | 形参名 `bind($cell)`（§10.3 / §15.4 / §11.4.2 三处）暗示 `Cell`，但 `Cell` 在 `Drift\State`（GUI 层），而 `SignupForm` 注释明写"**不认识任何 GUI 类型**" | 即使真传 `Cell`，也**违反"业务层零 GUI 依赖"** |
| 3 | §11.3.1 已明确禁止"把 `Store` 的每个字段都换成 `Cell`" | 方案自己堵死了 `Cell` 那条路 |

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|----|----|
| **A（推荐）** | **删掉 `Bound`**；`TextField::bind()` 转发到 `SubjectBuilder::select` 形态：`TextField::bind($form, fn(SignupForm $f): string => $f->name)` | ① 只留**一条**订阅路径（G2：一个概念一个落点）② `select` 已是裁决原语，**不新增原语** ③ 门控 / lint / `SubscribingWidget` 判据**全部复用** | 调用点比 `bind($form->name)` 长一点（要写 store + 切片闭包） |
| B | `Bound::make(Subject $s, callable $select, callable $builder)` —— 三参数 | 保住 `Bound` 这个名字 | **它就是 `SubjectBuilder::select` 的双参数糖** ⇒ 撞 §15.6.1"提供了却没作用"（`Container::$constraints` 同一类） |
| C | 让 `$form->name` 携带订阅源 ⇒ 引入"业务层可见的 `Cell`" | `bind($form->name)` 真能工作 | ① 业务层依赖 GUI 类型（违反 §11.4.1 注释与 §4.3 依赖规则）② 与 §11.3.1 的禁令直接冲突 |
| D | 保持现状，只把文档写清"`Bound` 需要 `Subject`" | 零改动 | 那 `Bound` ≡ `SubjectBuilder::select` ⇒ 仍是 B 的冗余 |

**参考推荐**：**A**。理由：**`Bound` 想解决的问题（订阅随控件自带）已经被 `SubjectBuilder::select` 解决**。留一个语义重叠的原语 = §18 从 Flutter 学来要避开的"两套写法"（= A12 的形态）。
★ 若担心"表单场景写起来啰嗦"，正确的加法是**给 `select` 加糖**（如 `->selectProp(fn(SignupForm $f): string => $f->name)`），而**不是**再立一个原语。

**落地成本**：删 `Bound`（§4.2 目录 / §11.4.2 / §15.4 表 / §10.3 第 5 条 / §A.2.8 / §A.2.11 / §16.6.3 的 `cached-subscriber` 规则）+ 改 `TextField::bind()` 的转发目标。**1 天**。
**与既有裁决的冲突点**：§10.3 第 6 条把 `TextField::bind()` 列为"变体工厂（纯转发）"的样板 —— 改转发目标后该条**仍然成立**，只是样板要换。

---

**★ 复核发现（2026-10-14 第二轮 —— 方向不变，但**论证与替代签名都要重写**）**：

**① "删掉 `Bound`"的方向对，但上面给的理由（"`select` 已覆盖它"）不成立。** `Bound` 想解决的是**双向绑定**（读 + 写），而 `SubjectBuilder::select` 只有**单向读**。两者**不是同一个东西**，不构成"两套写法"。

**② 真正该删的理由是：`Bound` 宣称的收益在 PHP 里做不到。** §11.4.2 的摩擦点对照表称 `bind()` 能把"字段名出现次数 3 → 1"。但 PHP 的属性**没有引用语义** —— 想让"一个表达式既读又写"，只能传**两个闭包**：

```php
Bound::make($form, fn(SignupForm $f): string => $f->name,
                   fn(SignupForm $f, string $v) => $f->name = $v)
```

⇒ `$f->name` 仍出现 **2 次**，与手写 `value() + onChanged()` **完全等价**（手写也是 2 次）。
⇒ **`Bound` 的净收益 = 0**，却带来一个额外公共类型 + 一条 `cached-subscriber` lint。⇒ 按 §15.6.1 的**删除测试**（删掉它，契约与测试原样完整）**应该删**。

**③ 上面 A 方案给的替代签名 `TextField::bind($form, fn($f) => $f->name)` 是错的 —— 它没有"写回"路径**（`onChanged` 往哪写？）。修正后只剩两种形态：

| 形态 | 写法 | 评价 |
|------|------|------|
| **A′（修正推荐）** | **不提供 `bind()`**；官方推荐 = `SubjectBuilder::select` 包住手写的 `value()/onChanged()`（即 §A.2.9 已在用的形态） | 一条订阅路径；`$form->name` 出现 2 次（与 `Bound` 等价）；**删除测试通过** |
| A″ | 保留 `TextField::bind()`，签名改为 **`(Subject $s, callable $read, callable $write)`**，内部构造 `TextField` | 少写一个 `SubjectBuilder`，但**多一个公共 API**，且 `$form->name` 仍是 2 次 |

**④ 修正后的推荐：A′** —— 删 `Bound` **且** 删 `TextField::bind()`，把 §A.2.9 已经在用的手写形态**提升为官方推荐**。
★ **连带必改**：**§11.4.2 的整张"摩擦点对照表"要删掉或重写** —— 它是按错误形态（`$form->name` 只传一个值）算出来的，**那张表本身就是这个缺口的一部分**。
★ 落地成本从 1 天 **上调到 1.5 天**（多了改 §11.4.2 整节 + §A.2.8）。

**★ 横向对照（2026-10-14 第三轮 —— "其他 GUI 框架怎么做到 1 次出现"，以及 Drift 为什么做不到）**：

**① ★ 先给结论：§18 已经把"双向绑定"这件事否决过三次了。** 重读 §18 的八家对照表，"绑定"在三行里**全部落在避开栏**：

| 框架 | 它的绑定手段 | §18 的处置 |
|------|-------------|-----------|
| **Vue** | `v-model` 自动双向 | **避开** —— 原文："`v-model` 自动双向（**第二套语义**）" |
| **WPF** | `{Binding Path=Name, Mode=TwoWay}` | **避开** —— 原文："**绑定表达式 + 路径解析**（需属性级 diff ⇒ 样板爆炸）" |
| **SwiftUI** | `$name` 投影值（`Binding<String>`） | **避开** —— 原文："**属性包装器**" |

⇒ **O-16 的"删 `Bound`"不是新裁决，而是把 §18 的既有裁决贯彻到 §11.4.2。** 方案自己在 §18 写了"避开 `v-model` 的第二套语义"，又在 §11.4.2 造了一个 `Bound` —— **与 O-12 同型的"方案自己犯自己的反模式"**。

**② 那三家靠什么成立？—— 三个语言特性，PHP 一个都没有。**

| 框架 | "1 次出现"靠什么 | 语言前提 | PHP 有吗 |
|------|----------------|---------|---------|
| **Vue** | `v-model="form.name"` ⇒ 编译成 `:value="form.name"` + `@input="form.name = $event"` | **模板是文本，编译期改写赋值表达式**（拿到 lvalue） | ❌ 没有宏 / 模板编译 |
| **Vue 3 · MobX · Solid** | `toRef(obj,'name')` / `observable.box` / `createSignal` ⇒ 一个**可传递的 get/set 对对象** | **`Proxy`，或"闭包捕获 lvalue"** | ❌ PHP 无 `Proxy`；闭包只能捕获**值** |
| **WPF · Qt · WinForms** | `{Binding Path=Name}` / `QDataWidgetMapper` / `DataBindings.Add("Text", …)` | **运行期反射按字符串路径读写属性** | ⚠️ **有**（反射可用）—— 但这正是 §11.4.1 与 §16.4 **明确否决**的"字符串键" |
| **SwiftUI** | `TextField(text: $name)` | **属性包装器的投影值由编译器生成** | ❌ PHP 无属性包装器投影值 |

**③ ★ 决定性实测：`&$form->name` 这条路被 §11.4.1 自己的裁决封死了。**
理论上还有第四条路 —— **PHP 引用**（`$r = &$form->name` 后 `$r = 'x'` 即写回，`$form->name` 只出现 1 次）。实测（PHP 8.5.11，探针 J，`probes/ref-semantics/`，数据见 `probes/RESULTS.md §10`）：

```
--- 1) by-ref function arg on hooked property ---
THROW Error: Indirect modification of SignupForm::$name is not allowed
--- 2) reference assignment to hooked property ---
THROW Error: Indirect modification of SignupForm::$name is not allowed
--- 3) same two on PLAIN (no hook) property ---
ok, plain='d'
```

⇒ **带 `set` hook 的属性禁止一切间接修改**（`&` 与 by-ref 传参都抛 `Error`）；而**同一类里的普通属性完全正常**。
⇒ ★ **两条路互斥，而 §11.4.1 已经选了 hooks**：

| 路线 | 写法 | "字段名出现次数" | 代价 |
|------|------|----------------|------|
| ① **§11.4.1 已选**：属性 hooks 自动 `notifyChanged()` | `$form->name = $x`（hook 里通知） | **2 次**（read + write 各一次） | 拿不到可写引用 ⇒ 绑定糖不可能 |
| ② 放弃 hooks，用裸属性 + 手写通知 | `$r = &$form->name` 可用 | **1 次** | **每次写值都要记得调 `notifyChanged()`** —— 纯人的纪律，G2 不容 |

⇒ **"字段名出现 1 次"与"写入自动通知"在 PHP 里是二选一。** §11.4.1 选了后者（正确：自动通知是**机制**，1 次出现只是**打字**）。⇒ **O-16 不是"文档漏了一个原语"，而是 §11.4.1 的必然推论。**

**④ 顺带：Flutter / React 两家根本没有绑定原语。** Drift 的正统形态就是它们：

| 框架 | 表单取值 + 回写 |
|------|----------------|
| **Flutter** | `TextField(controller: c)` 或 `value: x, onChanged: (v) => …`（`TextEditingController` 是**用户自己持有的显式对象**，不是框架的绑定原语） |
| **React** | 受控组件：`value={x} onChange={e => setX(...)}` |
| **Drift** | `SubjectBuilder::select(...)->builder(fn($v) => TextField::make()->value($v)->onChanged(...))` |

⇒ ★ **`SubjectBuilder::select` ≈ Flutter 的 `ValueListenableBuilder<T>` / `AnimatedBuilder`**（都是"持有可订阅源 + 切片 + 重建"）⇒ **A′ 是回归主流，不是能力退化。**

**⑤ 修正后的结论不变（A′），但论据从"净收益 0"升级为四层**：

| 层 | 论据 | 硬度 |
|----|------|------|
| **语言层** | PHP 禁止对带 hook 的属性间接修改（**实测**）⇒ "1 次出现"被 §11.4.1 的裁决在语言层封死 | **实测证伪** |
| **收益层** | 可行形态必须传 read + write 两个闭包 ⇒ `$form->name` **仍 2 次**（与手写等价）⇒ 摩擦点表 3→1 的算法用了**不可实现的形态** | 逻辑 |
| **结构层** | 即使做成糖，它是"**参数化的机制封装**"而非"**固定变体**" ⇒ **越出 §10.3 第 6 条"变体工厂"的判据**（糖只许是 `Button::primary` 那种固定变体）⇒ 留着就是 A12（两套写法） | 既有裁决 |
| **同源层** | §18 已把 Vue `v-model` / WPF 绑定表达式 / SwiftUI 属性包装器**全部列入避开** | 既有裁决 |

★ **连带必改清单（✅ 2026-10-14 已全部落地）**：§0 总览表 · §2 分层图 · §4.2 目录 · §10.3 第 5·6 条 · §11.3.2 纪律 1 · §11.3.3 图 · §11.4.2 **整节重写** · §11.4.3 反馈回路 · §15.4 变体工厂表 + 删除测试 · §16.6.3 `cached-subscriber` · §19 M3 · §21 术语表 · §A.2.8 · §A.2.9 · §A.2.10 · §A.2 反模式表。
★ **§11.4.2 的"摩擦点对照表"已删** —— 它是按**不可实现的形态**算出来的，那张表本身就是缺口的一部分。

---

#### O-17 样式复用：`BoxStyle` 值对象 vs 基类字段 vs trait 组合

**✅ 已裁决（2026-10-14）**：按 **A′** —— `BoxStyle` 值对象**只管盒样式** + 中间基类 `BoxWidget`；**不引入 widget 层 `TextStyle`**；**删孤儿名 `ControlStyle`**。★ **修正了原方案 A 的三处**：① 范围缩到"盒绘制样式"（`padding` 归 §13.1 几何面）；② 共享手段从 trait 改为**中间基类**（trait 混入在 AOT 下未验）；③ 明确 `BoxStyle` / `ThemeScope` / 主题 token 的**三层分工**。★ **已落地到 §4.2 目录 / §15.7（新增节）/ §16.4 / §19 M2 / §21 术语表**。

**问题本质**：背景 / 颜色 / 字号这类属性**多个控件都要**。若每个控件各自声明 `$background` / `$border` / `$padding` 字段与对应链式方法 ⇒ **重复代码**。直觉解法是"放基类"或"抽 trait"。**但两者都与 §15 的核心裁决有张力。**

**先拆面（一个面只用一种表达手段）**：「背景 / 颜色 / 大小」其实是**三件不同的东西**：

| 说法 | 真实归属 | 例子 | 面 |
|------|---------|------|----|
| 语义颜色 | **主题 token**（角色枚举） | `->danger()` · `->title()` | 主题面（§8 / §11.5） |
| 显式颜色 | **控件字段**（覆盖主题） | `->background($c)` | 样式面 |
| 字号 | **样式** | `->size(18)` | 样式面 |
| **宽 / 高 / 间距** | **布局约束**（盒模型） | `->width(200)` · `->padding(8)` | **几何面（§13.1）** |

★ **"大小"必须拆开** —— 字号是样式，宽高是几何。合并会重蹈 §6.1"两套坐标系混用"的坑。

**为什么不能把样式字段全放基类**（三条）：

| # | 理由 |
|---|------|
| 1 | **摧毁 §15.3 的性能依据**。`D+` 只贵 0.27 µs/节点，**前提是"只有真正用到的字段才存在"**。基类塞 12 个样式字段 ⇒ **每个实例都付**（含 `Each` / `Spacer` / `SubjectBuilder` 这些根本没有样式的）⇒ 节点分配量 ×2~3 |
| 2 | **"所有组件都需要"不成立**。45 控件里需要背景色的不到一半；`Column` / `Row` / `Each` / `Spacer` / `SubjectBuilder` **不需要** |
| 3 | **给结构节点加 `background()` = 死字段**（§15.6.1 否决 `Container::$constraints` 的同一条纪律；= §21 A1 的字段版） |

**trait 组合的额外风险**：
- §2.4 纪律 7 只验过"**trait 不单独成文件**"（`cl` 收到空文件名）；**"trait 混入多个类"在 AOT 下展开成什么，方案未验** ⇒ **探针门**。
- **trait 的字段在每个混入类里各复制一份** ⇒ 不省内存，只省打字。
- trait 方法要展开到每个使用类才算完整 ⇒ `api-surface.json` 的公共面**难以静态枚举**（§10.1）。

**方案**：

| 方案 | 内容 | 得 | 失 |
|------|------|----|----|
| **A（推荐）** | **`BoxStyle` 值对象**：控件上只有**一个** `?BoxStyle $box` 字段；`->background()` / `->border()` / `->padding()` 是它的 with-改写糖 | ① 一个字段取代 N 个 ② `null` = 无样式（**零成本**，符合 §15.2 哨兵定则）③ 不可变 ⇒ `===` 门控直接可用 ④ **只有真用样式时才付一次对象分配** | `->background($c)` 多一层对象改写（但只在真用时） |
| B | **`StyledWidget extends Widget`**：只有需要样式的控件继承它，结构节点继承裸 `Widget` | 不污染结构节点；语义正确（`Each` 拿不到 `background()`） | 多一层继承（只一层，不是 45×2）；"哪些算样式"要逐个定 |
| C | **trait 只提供方法、不提供字段**（字段仍只有 `$box`） | 糖可复用，且**不复制字段** | 需先验 trait 混入的 AOT 生成物（探针门） |
| D | 样式字段直接进 `Widget` 基类 | 最省打字 | 撞上面三条理由 ⇒ **否决** |

**参考推荐**：**A 为主体 + C 为可选糖**（A 定形状；C 只是把 `->background()` 那组方法抽出来，**字段仍只有 `$box` 一个**）。B 可与 A 并用（由 `StyledWidget` 承载 `$box`），但**不是必需**。
**落地成本**：`BoxStyle` 值对象 + 3~5 个糖方法。**1–2 天**。若走 C，**先加一个"trait 混入"探针**（0.5 天）。
**与既有裁决的冲突点**：无。★ 但 §16.4 的框架对照表里**已经出现过 `ControlStyle`** 这个名字（"角色枚举 + `ControlStyle` 值对象；不做字符串注册表"），而**全文没有它的定义、§4.2 目录清单里也没有它** ⇒ 本提案正好补上这个缺口，**但名字要一并统一**（`BoxStyle` 管盒样式 / `ControlStyle` 管控件整体外观，是**两层**还是**一层**，需一并定）。

**★ 横向对照（2026-10-14 第三轮 —— "样式放哪"，以及为什么两家框架做了相反选择）**：

**① ★ 最有价值的对照：WPF 真的把样式放在基类，而它这么做**有前提**。**
WPF 的 `Control.Background` / `Foreground` / `FontSize` 都是基类上的 `DependencyProperty` —— 看起来**正是被否决的方案 D**。但它付得起，因为 `DependencyProperty` 的值存在**每实例稀疏表**里，且最终值由**四层链**解析：`local value > style/template > inherited value > default`。⇒ **未设置的属性几乎零成本**（值来自属性默认值 / 样式 / 父级继承）。
⇒ ★ **"基类字段"本身不是问题，"基类字段 + 非稀疏存储"才是问题。** Drift 的字段是 C++ 结构体成员（**每实例都占**，§15.3 的 0.92 µs/节点就是这么来的）⇒ **D 在 Drift 的存储模型下才真正昂贵**。
⇒ **两个框架做了相反选择，因为存储模型相反**：

| 框架 | 存储 | 样式放哪 | 一致吗 |
|------|------|---------|--------|
| **WPF / WinForms / Qt** | 稀疏表 / 属性表 | **基类** | ✅ 一致 |
| **Flutter / Compose / SwiftUI** | 非稀疏字段 / 值类型 | **每族值对象 + 基类零样式** | ✅ 一致 |
| **Drift** | **非稀疏字段**（§15.3） | ⇒ **必须跟后者** | —— |

⇒ **硬结论：Drift 与 Flutter 同构（存储模型相同），不与 WPF 同构。**

**② Flutter 的答案：不是一个 `BoxStyle`，而是每族一个值对象，且 `Widget` 基类零样式字段。**

| Flutter 的类型 | 管什么 | 对应 Drift |
|---------------|-------|-----------|
| `BoxDecoration` | 背景 / 边框 / 圆角 / 渐变 / 阴影（**paint**） | **`BoxStyle`** |
| `TextStyle` | 字号 / 字重 / 颜色 / 字体族（**text**） | ⚠️ 见 ③ |
| `EdgeInsets` | padding / margin（**geometry**） | **§13.1 几何面**（**不是** `BoxStyle`） |
| `BoxConstraints` | 宽 / 高 | §13.1 |
| `Widget` 基类 | **只有 `key`** | `Widget` 基类**零样式字段**（§15.2 现状） |

★ **`Text` 在 Flutter 里拿的是 `style: TextStyle`，`Container` 拿的是 `decoration: BoxDecoration` —— 两个不同的值对象。** Compose 同理（`TextStyle` 是 `Text` 的参数，`Modifier.background` 才是盒）。CSS 同理（**文字属性可继承、盒属性不可继承** —— 两者**传播语义不同**）。
⇒ **把"背景"和"字号"塞进同一个 `BoxStyle`，是把两种传播规则塞进一个类型**（违反"一个面一种手段"）。

**③ ★ 而文本族根本不需要值对象 —— 因为 §15.2 已经解决了，且判据是"字段数"。**
§15.2 的 `Text` 已经是**每族自己的字段**：`$role`（`TextRole` enum）+ `$size` + `$bold`（**纯样式只有 2 个**），`$maxLines` / `$ellipsize` 是**布局行为**不是样式。
⇒ **判据（可复用）：族内"纯样式字段"≥ 4 个且常整体替换 ⇒ 值对象；否则散字段。** 盒族要 4~6 个（background / border / radius / shadow）⇒ 值对象；文本族 2 个 ⇒ **不动 §15.2**。
⇒ ★ **O-17 的正确范围要缩窄**：不是"所有样式复用"，而是"**盒样式字段 ≥4 的族**"。原方案把 `->size(18)` 也列进样式面（拆面表里对，方案描述里没落实）⇒ 已澄清。

**④ 立即模式 GUI 给出第三个面：样式作用域。** Dear ImGui 用 `PushStyleColor` / `PopStyleColor`（**样式栈**），egui 用 `ui.style_mut()`（**一个全局 style 结构 + 作用域覆盖**）。⇒ **"点覆盖"（值对象）与"面覆盖"（作用域）是同一件事的两个粒度** —— 而 Drift 的**面覆盖已经有了**：§8 的 `ThemeScope`（= SwiftUI 的环境值 / Qt 的 palette 继承）。
⇒ ★ **必须写清分工，否则又是"两套写法"**：

| 手段 | 粒度 | 何时用 |
|------|------|--------|
| **`ThemeScope`**（§8） | **面**（整棵子树） | "这一屏都用暗色 / 这一块字号都大一点" |
| **`BoxStyle`**（O-17） | **点**（单个控件） | "就这一个盒子要红底" |
| **主题 token**（`ColorToken` / `MetricToken`，§11.5） | 键 | 上面两者的**取值来源** |

**⑤ `ControlStyle`（§16.4 的孤儿名）应当删除，而不是补定义。**
它想表达"**控件整体外观**"。但 Drift 的主题模型已经是 **`ThemeData` + `ColorToken` / `MetricToken`（角色枚举 + token）** ⇒ **"控件整体外观" = "角色 → 具体值"的一次解析**，不是第三个类型。Flutter 里也没有 `ControlStyle`（有的是 `ThemeData` 上的 `TextTheme` / `ButtonTheme` 等切片）。
⇒ **一层还是两层？答案：都不是 —— 是"角色（键）→ 值对象（值）"的一层映射，`ControlStyle` 是多余的中间名。** 三个概念（角色 / token / 值对象），两个类型（`ColorToken` / `MetricToken` + `BoxStyle`）。
⇒ §16.4 那一格改为："**角色枚举 + token（`ColorToken` / `MetricToken`）解析为值对象（`BoxStyle`）；不做字符串注册表**"。

**⑥ 修正后的推荐（A′）**：

| 项 | 原推荐 | 修正 |
|----|--------|------|
| 形状 | A：`BoxStyle` 值对象 | ✅ **不变** |
| 范围 | （未限定） | ★ **缩到"盒样式"**（background / border / radius / shadow）；**`padding` 归 §13.1 几何面** —— 原方案描述把 `->padding()` 写成 `BoxStyle` 的糖，**与它自己的三层拆面表矛盾**，已修正 |
| 文本族 | （未提） | ★ **不引入 `TextStyle`** —— `Text` 纯样式只有 2 个字段，不达"≥4"阈值 ⇒ **§15.2 不动** |
| 共享手段 | A 为主体 + **C（trait）为可选糖** | ★ **改为"一个中间基类 `BoxWidget extends Widget`"**（持 `?BoxStyle $box` + 糖方法）。**不用 trait** —— §2.4 纪律 7 只验过"trait 不单独成文件"，**"trait 混入多个类"在 AOT 下展开成什么未验**（探针门）；而**继承已验**（§2.4）。不为了省打字引入未验机制 |
| 语义约束 | （未提） | ★ **`BoxStyle` 的合并只发生在"构造期、单一来源"**；主题 token 与显式样式的优先级**只在 `ThemeData` 解析一次** —— 这是 §18"避开 CSS 层叠 / 特异性"的必然推论 |
| 孤儿名 | 名字要一并统一（一层还是两层待定） | ★ **`ControlStyle` 删除**（见 ⑤） |

---

### 23.3 批量批准清单与依赖图

**✅ 已裁决（2026-10-14，**17 项全部收口**）**：`O-01` · `O-02` · `O-03` · `O-04` · **`O-05`** · **`O-06`** · `O-07` · `O-08` · `O-09` · `O-10` · `O-11` · **`O-12`** · `O-13` · **`O-14`** · **`O-15`** · **`O-16`** · **`O-17`**。

| 处置 | 项 | 说明 |
|------|----|------|
| **不做** | `O-05` · `O-06` · `O-14` | 全部**实测证伪**（探针 F / G / H） |
| **A′（修正形态）** | `O-12` · `O-16` · `O-17` | `O-12` = 帧循环异常策略 S3 + 容器归 M3；`O-16` = 删 `Bound` + 删 `TextField::bind()`；`O-17` = `BoxStyle` 只管盒样式 + `BoxWidget` |
| **方案 E** | `O-15` | `on()` 收 handler 实例 |
| **按推荐方案 A 落地** | 其余 10 项 | 条文已写进正文（各段的"✅ 已裁决"抬头里标了落点） |

★ **`O-16` 并入了复核时发现的缺口**：`SubjectBuilder` 全文无类形状（字段表 / 方法表 / "无 `select` 时 `builder` 传 0 参还是 `null`"）—— 见 §23.2 O-05 复核发现 ⑥。**该缺口随 O-16 的订阅侧落地一并处理**（M3）。

**仍待裁决（0 项）** —— 17 项提案全部收口。

**依赖图**（✅ = 已裁决，只待实现）：

```
M0 ── ✅ O-11（反模式↔lint 索引）—— 已完成
M1 ── ✅ O-01（Fatal 中止形态）· ✅ O-09（性能预算表）· ✅ O-13（nano-hygiene 边界）—— 已完成
M0 ── ✅ O-11 —— 已完成
M1 ── ✅ O-01 · ✅ O-09 · ✅ O-13 —— 已完成
M2 ── ✅ O-02（`BuildContext` 能力面）—— **已完成**（§3.4；O-02b 结论：lint 不必）
      ✅ O-17（**`BoxStyle` 只管盒样式 + 中间基类 `BoxWidget`**）★ 已裁决并落地 → **§15.7**（新增节）
      （随 M2 实现：盒族控件的 `?BoxStyle` 字段 + 3 个糖方法 + 主题解析）
M3 ── ✅ O-04 · ✅ O-05（不做）· ✅ O-06（不做）· ✅ O-07 · ✅ O-12 · ✅ O-15 · ✅ O-16
      ★ **O-16 的订阅侧落地时，一并补 `SubjectBuilder` 的类形状**（字段表 / 方法表 / `builder` 调用约定）
      ★ O-12 的**容器归 M3**（§19 的 M5 那处已删）
      （随 M3 实现：Store 三件套 + `SubjectBuilder` + `select()` + 动作缝 + 错误边界容器 + 加速键 + 自绘菜单 + 拖放）
M4 ── ✅ O-03 · ✅ O-08 · ✅ O-10 · ~~O-14~~（**不做**，实测 1.9 µs；回头闸门 = 回调次数 N×K ≳ 760）
      （随 M4 实现：六件套齐备 + TaskHost 线程池 + `notifyChanged` 线程断言 + ThemeChanged）
M5 ── （无裁决项；虚拟化 / 脏矩形 / `WindowStateStore` / UIA 桥接）
```

**必须成对做的（全部已落地或已明确归属）**：
- **`O-16` 内部**：`Bound` 去留 + `SubjectBuilder` 类形状 —— `Bound` 已删（§11.4.2 整节重写），**`SubjectBuilder` 类形状随 M3 的订阅侧一并补**。
- **`O-17` + M2 控件定型**：盒族控件的 `?BoxStyle` 字段必须与首批控件的字段表**同时定** —— 晚了要改全部控件（已随 §15.7 定形）。
- **`O-12` 容器 + 纪律**：**同里程碑（M3）**，不许拆开。

（`O-03 + M4 线程池` · `O-01 + 第一次 Fatal` · `O-02 + BuildContext` · `O-15 + §A.2 示例` 均已随 2026-10-14 裁决落地；`O-05 + O-06` 因**两者都不做**而整行解除。）

---

## §24 v2 变更记录 · 开放项 · 例外登记

### 24.1 v2 的 8 处文档内不一致修正（语义不变）

| # | 位置 | 原文 | v2 |
|---|------|------|-----|
| 1 | §16.6.3 | 正文"九条"/"八条"、表 10 行、§19"8 条"、§23.10"9 条" | **统一 17 条** |
| 2 | §3.1 | 分层图无 `Drift\Commands` | 补入 |
| 3 | §16.4 | `ignore: src/platform/macos` | `../../src/platform/macos` |
| 4 | §11.1 | "命令 / 局部闭包"无界线 | 补**业务动作 vs 纯 UI 回调**界线表 |
| 5 | §21 A2 | 正确做法写 `null` | 指向**哨兵三档定则** |
| 6 | §19 M1 | 链式探针门仍列待验 | 标 **✅ 已过** |
| 7 | §12.1 | 路由 `match` 有 `default` 却无解释 | 补**开放域 / 封闭域**判据 |
| 8 | §12.3 / §15.4 / §12.2 | Element 身份未定 / 枚举大小写不一 / Root 判据会误伤 | 分别补说明 / 统一 PascalCase / 精确化判据 |

（另有 §13.1 C5、§7.2 W8、§14.2 三处"补边界"计入 §23.1 的第 9 / 12 / 13 项。）

★ **第三轮复核追加（2026-10-14）**：§4.2 目录清单里的 `SelectedBuilder` 是**孤儿名字** —— 全项目只出现 1 次、任何地方都没有定义、v1.0 归档里连名字都没有 ⇒ **已删**（见 §23.2 O-05 复核发现 ⑤）。同一类问题：§11.3.3 原写的"更好的做法是用**类型系统**强制"被实测证伪 ⇒ 已改为诚实表述（见 §23.2 O-05 复核发现 ②/③）。

★ **第四轮追加（2026-10-14 晚，随 O-16 / O-17 裁决）**：
- §4.2 目录清单**缺 `Color`** —— 它被 §11.5（`color(ColorToken $t): Color`）与 §15.6（`Color::fromRgb`）引用，但**目录里没有定义** ⇒ **已补进 `Drift\Rendering`**（文档遗漏，语义不变）。
- §16.4 的 **`ControlStyle` 是孤儿名字**（全文无定义、§4.2 也没有）⇒ **已删**，该格改指向 `BoxStyle`（见 §23.2 O-17 复核发现 ⑤）。
- §19 里"**错误边界**"**同时在 M3 和 M5**（名字还不一致）⇒ 统一为"**错误边界容器**"、**归 M3**，M5 那处已删（见 §23.2 O-12 复核发现 ①）。

★ **第五轮追加（2026-10-15，M0 落地时）** —— 两处**会挡住正确实现**的文档缺口（都是"已有裁决没写全"，语义不变）：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 9 | **§4.1** | `composer.json` 把 `apps/<app>/bin/` **笼统挂一个前缀**，但 §4.5 的组合根在 `bin/<platform>/Bootstrap.php` 里声明 `namespace App\Hello;`（§A.1）⇒ 按 PSR-4 该声明应落在"前缀根"上，笼统挂 `bin/` 会要求命名空间是 `App\Hello\win32` ⇒ **§A.1 的例子在 PSR-4 下不成立**（`namespace-mismatch` 会报它） | 改为**按平台逐条列出** `bin/win32/` 与 `bin/macos/`；两个平台同时列出无害（非当前平台目录查不到） |
| 10 | **§4.3** | `Theme` 行的允许集**漏了 `Rendering`**，而 §4.2 把 `Color` 放在 `Drift\Rendering`、§15.7 又要求 `ThemeData` 是主题 token 的**取值来源** ⇒ `Theme → Rendering` 是**必需边**，缺了它 `dependency-direction` lint 会误报 | Theme 行补 `Rendering` |
| 11 | **§4.3** | `Text` 行的允许集只有 `Geometry`、**漏了 `Errors`**，但 §8.3 血账 1 已经裁决"`FontManager` 初始化失败走 `Errors::report` 而不是崩"，§8.2 坑 6 也要 Text 层响亮失败 ⇒ 缺这条会让"Text 层不许报错"与"G2 绝不静默"直接冲突 | Text 行补 `Errors` |
| 12 | **§8.1 / §7.1** | 缓存键钉死为 `(text, style, maxWidth, scale)`，但排版结果**还受**对齐 / 行数上限 / 省略号影响 ⇒ 若把它们做成 `layout()` 的参数就会**同键不同结果**（缓存撒谎） | 裁决：三者**进 `TextStyle`**（§15.7 说 widget 层的角色方法落成 `TextStyle` 的值）⇒ 方案给定的键**保持字面成立** |
| 13 | **§A.3.3** | `overrideTokens([ColorToken::Border => …])` **不是合法 PHP** —— 枚举实例是对象，**不能作数组键**（实测 `Fatal error: Cannot access offset of type ColorToken on array`，纯枚举与 backed 枚举都一样）。⇒ 这行示例照抄必然编译不过 | 改为值对象的 `withX()` 形态（与 §15.7 `BoxStyle::withX()` 同源）：**点覆盖** `withColor(ColorToken, Color)` / `withMetric(MetricToken, float)`；**面覆盖** `withHighContrast()`。**公共面无字符串键**（§16.4 否决字符串注册表；内部用 `->name` 作数组键只是因为 PHP 数组键不能是对象） |
| 14 | **§4.2** | 目录清单的 `Drift\Theme` 行**缺 `SystemTheme`**，但它被 §9.2 / §A.2.7 / §A.3.3 三处引用（`SystemTheme::Light` / `::Dark`）⇒ 与第 9 处（缺 `Color`）同类 | 补 `SystemTheme` |
| 15 | **§4.3** | `Widget` 行的不许依赖列写着 `Theme`，但 §3.4（2026-10-14 才定义）要求 `Drift\Widget\BuildContext` 有 `theme(): ThemeData` / `color(ColorToken)` / `metric(MetricToken)` ⇒ **两条裁决直接冲突**（`dependency-direction` 会报它，而删掉 `theme()` 又让 §3.4 的能力面少一半） | 照 `Input` 行的既有先例加**协议例外**：三个类型**仅限 `BuildContext` 的类型签名**可引用。★ 边界要说清：`ThemeData` 是"解析后的值"，`BuildContext` 只是**把它递给 `build()`**，不构成 Widget → Theme 的架构依赖，`ThemeScope`（真·主题节点）仍归 `Drift\Theme` 侧 |
| 16 | **§4.3 / §4.2** | `TextRole`（§15.2 定义、§11.5 要用）**不在 §4.2 目录清单里**，且"角色 → `TextStyle`"的解析该放哪一层方案没说 | 补进 `Drift\Text`（不是 `Drift\Widget`）：解析落 `ThemeData::textSize/textColor`，而 §4.3 只允许 `Theme → Text`、**不允许 `Theme → Widget`**。放 Widget 会让 Theme 依赖 Widget，直接撞 lint。另：**§15.7 纪律 3 因此有了第二个落点实例**（合并只发生一次） |
| 18 | **§4.2 / §4.3** | **§4.3 的 `Widget → Theme` 禁令与"控件要在造盒时把主题解析成值"直接冲突**。`Button::createRenderObject()` 必须引用 `ColorToken` / `MetricToken` 才能把变体解析成颜色 —— 而 lint 只放行了 `BuildContext.php` 一个文件的协议例外，扩例外会让它扩散（今天允许一个文件，明天整层都能依赖 Theme） | **`ColorToken` / `MetricToken` 从 `src/Theme/` 移到 `src/Rendering/`**。依赖方向天然成立：`Widget → Rendering` ✓、`Theme → Rendering` ✓、`Rendering` 自己不依赖 Widget/Theme ✓。**语义没说谎**：token 是"渲染资源的语义键"，与 `Color` 同层比与 `ThemeData` 同层更实。★ 解析仍只发生一次（§15.7 纪律 3），落点从 `RenderButton`（Layout，不许碰 Theme）移到 `Button::createRenderObject()`（Widget，经 `BuildContext`） |
| 17 | **§15.2 / §16.6.1 / §19 / §A.1 / §A.2** | **`ForEach` 不是合法 PHP 类名** —— `foreach` 是保留字，且 PHP 类名**大小写不敏感** ⇒ `class ForEach` 直接 `PHP Parse error: unexpected token "foreach"`（实测）。方案全文 6 处 `ForEach::make(...)` 照抄必然编译不过 | 类名改 **`Each`**（`Each::make(keyFn)->items(...)`）。★ 这是第 9 处"文档挡住实现"的同类，但**改了公共面** ⇒ 名字选择请你确认；若你更想叫 `Items` / `ListOf` / `EachItem`，改回来只是一次重命名（语义与 key 必填裁决都不变） |
| 22 | **§4.2** | `Drift\Routing` 行列了 **`Page`**，但全文对 `Page` 的唯一定义在 **§A.2.1 的 `App\Users\Store\Page`**（`fetchPage()` 返回的 DTO，三个字段 `ok/items/error`）—— 它是**应用私有值对象**，不是框架类型。这与第 10 处"目录表与定义对不上"同类，只是这次错在**层级**：照 §4.2 建 `src/Routing/Page.php` 会得到一个框架里没人用的空壳 | 从 `Drift\Routing` 行删掉 `Page`（该行的真实成员是 `RouteTable` · `PageHost` · `Offstage` + `NoPage`）。★ 应用私有 DTO 归 `apps/<app>/store/`，不进框架 —— §12.2 的"每页可独立预览"正要求页面只依赖自己的 store 类型 |
| 23 | **§A.1** | 样例的三处照抄会编译不过：① `protected function title()/buildRoot()` —— 基类 `Application` 把它们定义成 public（`commands()` 要能被 devtools 枚举），子类**收窄**可见性 ⇒ `PHP Fatal: Access level must be public`；② `use` 列表里漏了 `Center`（正文用了 `Center::make()` 却没 import），多了一个没用的 `BuildContext`；③ `Bootstrap` 调 `$app->run()`，而 `Application` **没有** `run()` —— §5.2 裁决 1 明确"循环归平台，`Frame` 不拥有它" | ① 改 `public`；② 补 `Center`、删 `BuildContext`；③ 改成平台驱动形态（`attach()` + `pump()` 循环里调 `frame()`）。★ `main.php` 与 `bin/<platform>/Bootstrap.php` **本轮不建**：它们要 `Drift\Platform\Win32\Win32Platform`（M1 的 Skia/Win32 桥），建出来只会是引用不存在类型的空壳（A15）。`apps/hello/src/HelloApplication.php` 已建且可无头预览（`--app`） |
| 21 | **§4.3** | `Routing` 行的允许集写着"Widget / Commands"，但 §12.1 第 7 条要求 `RouteTable` 的工厂**返回 `Widget`** ⇒ 签名必须引用 `Drift\Widget\Widget`。这正是 §4.2 目录表"Routing → Widget"那一列本来的意思，**依赖表漏抄了它**（与第 10 处"Theme 漏 Rendering"同类：允许集和目录表对不上） | `Routing` 行补 `Widget`。★ 顺带确认方向只有一条：`RouteTable` **不认 `Element`**、不挂树，它只造 Widget —— 挂载与保活住 `Drift\App` 与 `Drift\Widget\Offstage`，那样 Routing 才保持可单测（必须在浏览器外能跑） |
| 19 | **§11.3.1** | 样例写 `private Cell $hovered = new Cell(-1);` + `$this->hovered->setIfChanged($index)`，并注明"内部：=== 不等 ⇒ setState 标脏"。**PHP 的属性初始化器拿不到 `$this`**（`new Cell(-1)` 里没有 State 可引用）⇒ 照抄能得到一个"设了值但没人重建"的 `Cell`，正是 G2 要消灭的静默形态 | 绑定必须显式发生：`State::cell($initial)` 是**唯一创建入口**，`initState()` 里 `$this->hovered = $this->cell(-1);`。`Cell` 未绑定就 `setIfChanged()` ⇒ **Fatal**（不留下"设了没用"的值）。★ 顺带：`State` 的样例因此从"属性初始化器"变成"`initState()` 里赋值"，与 §11.3 RT-1 的"只 initState 订"是同一个时机 |
| 20 | **§4.3** | `Widget` 行的不许依赖列写着 `Commands`，但 §11.4.3 的派发链第 ①② 环要求 `Drift\Widget\State::handleCommand(string $name, CommandArg $arg): bool` —— 签名要碰 `Drift\Commands\CommandArg` ⇒ **与 §11.4.3 直接冲突** | 照 `BuildContext`/`Theme` 的既有先例加**协议例外**，且**只登记 `src/Widget/State.php` 一个文件**（层级放行会让"任何 Widget 都去依赖 Commands"合法化）。★ 边界：Widget 侧只碰这一个**参数类型**；链的其余部分住 `Drift\App`（那里 `Widget → Commands` 天然合法）。夹具 `src/Widget/UsesCommands.php` 守"例外不扩散" |

★ 附带确认：`main.php` / `tools/*.php` 这类**入口脚本**（无类型声明）不受 `namespace-mismatch` 管 —— PSR-4 管的是类文件。

### 24.2 开放项总表（全部 ⏳ 集中在此）

| # | 开放项 | 为什么开放 | 何时必须收口 |
|---|--------|-----------|-------------|
| 1 | **`ts_resource(0)` 之后的完整线程安全** —— AOT 运行时的**非 TLS** 全局状态（`get_str()` 持久字符串表 / `get_persistent_func` 缓存 / opcache）在多 worker 下是否竞争 | 探针 E 只证明"分配 + 引用计数"可用 | **线程池正式落地前**（M4） |
| 2 | **`-m lib` 的热路径跨模块性能差** —— 对照 §15.3 的 0.92 µs/节点 | GUI 仓 spike 只验了"对不对"，**从未量"快不快"** | 决定"形态 B 能否做性能敏感场景"时 |
| 3 | **`#[Native]` 类在 lib 模式下的实际限制** | 只知道"必须标 `#[NoExport]`" | 若想用 `#[Native]` 加速热路径对象 |
| 4 | **CRT / PHP 版本不匹配的实际症状** | 部分已知（`LNK2038`），lib 专属症状未验 | 第三方分发时 |
| 5 | **跨 `.dll` / 跨线程的"异步闭包"** | 同步闭包已验；异步未验 | 若走 O-03（`Job` 类）则**不再是阻塞项** |
| 6 | **ICU 打包策略** | §8.3 给了三档（打包进 exe / 退最小实现 / 自实现禁则） | 按体积预算选（M1 前） |
| 7 | **§17 线程模型是否最终定案** | 原与"能否切 `--nano`"互为条件；★ **nano 已明确不做（§16.7.5，2026-10-14）⇒ 该约束解除**，本条现在只等"线程池落地前的 TSRM 补枪"（同表 #1） | 线程池正式落地前（M4） |
| 8 | ~~**`--nano` 真机跑通**~~ | ★ **已移出开放项** —— 2026-10-14 裁决：nano 不作为前期重点（§16.7.5） | — |
| 9 | **`-m lib` 的 D4**（`*_arg_N_default_value()` 导出前缀） | 上游可能已连带解决 | 形态 B 首次真机构建时 |

### 24.3 G2 例外登记表（允许静默的场合）

★ §1.2 说"绝不静默"。**以下是显式例外** —— 登记在此，以免 lint 把它们误判为 A6：

| 场合 | 为什么允许静默 | 边界 |
|------|--------------|------|
| **`WindowStateStore::restore()` 失败** | 显示器拔了 / 分辨率变了 / 配置文件被删 —— 都不该让应用起不来 | **只降级到默认值**，且写一条 Diagnostic |
| **`ignore:` 指向不存在的路径** | tpc 有意跳过（源码注释说明） | 但 `include:` 的路径写错**会让 tpc 崩溃** ⇒ §4.6 约束 3 |
| **`Clipboard` / `Dialog` / `Tray` 返回 `null`** | "不支持"是**类型层面**的合法状态 | 调用方必须处理 `null`，不许当空操作吞掉 |
| **`select` 返回值相等 ⇒ 不重建** | 这是**门控**，不是吞错 | 门控判据必须显式（`===`），不许自定义"模糊相等" |

### 24.4 文档地图

| 文件 | 内容 | 什么时候读 |
|------|------|-----------|
| `DESIGN.md` | 方案本体（本文） | 想知道"现在是什么" |
| `§A`（本文附录） | 完整示例应用 + 能力示例 + 反模式对照 + 工具链用法 | 要动手写代码 |
| `DECISIONS.md` | 裁决档案（问题本质 / 得失 / 推荐 / 依赖图） | 想知道"某个决策为什么这样" |
| `HISTORY.md` | 历史、探针证据、**已作废结论清单** | 想复核一条结论 / 想引用证据 |
| `probes/RESULTS.md` | 探针原始数据 | 想看数字 |
| `architecture.html` | 六张架构图 | 想看图 |
| `REVIEW.md` / `REVIEW-2.md` | 两轮评审发现过程 | 想看"当初挑出了什么毛病" |
| `archive/DESIGN-v1.0-2026-10-14.md` | v1.0 全文 | 需要 diff 时 |

---

# §A 完整示例

> 本章是**可照抄的代码**。设计条文在 §0–§24，这里只回答"**具体怎么写**"。
> ★ 示例遵守全部纪律（§2.4）与全部命名规则（§15.4）；若发现示例违反某条裁决，**那是本文的 bug**。

## A.1 最小可运行（M1 的目标：一个窗口画出「Hello Drift」）

```
apps/hello/
  project.yml
  main.php
  bin/win32/Bootstrap.php
  src/HelloApplication.php
```

```php
<?php
// apps/hello/main.php
declare(strict_types=1);

namespace {
    /** ★ `-m bin` 硬要求：全局唯一 main()，且必须在全局命名空间（§4.5） */
    function main(): void
    {
        \App\Hello\Bootstrap::run();      // ★ 前导 `\`：命名空间内调全局符号（§16.5 陷阱 3）
    }
}
```

```php
<?php
// apps/hello/bin/win32/Bootstrap.php
declare(strict_types=1);

namespace App\Hello;

/** 组合根：全应用唯一提到平台实现的地方（§5.1） */
final class Bootstrap
{
    public static function run(): void
    {
        $platform = new \Drift\Platform\Win32\Win32Platform();
        $app = new HelloApplication();
        // ★ 循环归平台（§5.2 裁决 1）：`Application` **没有** run()，只提供
        //   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
        //   表面由平台给（`platform->surface($hwnd)`），帧时钟由平台驱动。
        $app->attach($platform->surface($platform->window()->createWindow(...)),
                     $platform->screen()->viewport());
        while ($platform->pump()) {
            $app->frame($platform->now(), $platform->screen()->viewport());
        }
    }
}
```

```php
<?php
// apps/hello/src/HelloApplication.php
declare(strict_types=1);

namespace App\Hello;

use Drift\App\Application;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

final class HelloApplication extends Application
{
    public function title(): string { return 'Hello Drift'; }

    public function buildRoot(): Widget
    {
        return Center::make()->child(
            Text::make('Hello Drift')->title()
        );
    }
}
```

```yaml
# apps/hello/project.yml
name: hello
mode: bin
platform: win32
optimize: 2
job: 16
cxx-std: c++20
build-dir: ../../build/hello
no-console: true

sources:
  - main.php
  - src
  - ../../src
  - ../../cpp-src
  - path: bin/win32
    if: PHP_OS_FAMILY == "Windows"

ignore:
  - ../../src/platform/macos

defines:
  - DRIFT_HELLO_ALL_DECL=<php_hello_all_decl.h>

include-paths:
  - ../../cpp/skia/include
link-paths:
  - ../../cpp/skia/out/Release-x64
link-libs:
  - user32.lib
  - gdi32.lib
  - ole32.lib
  - imm32.lib
  - comdlg32.lib
  - shell32.lib
  - advapi32.lib
cxx-flags:
  - /utf-8
  - /MT
  - /DUSE_SKIA
```

```bash
# 构建（★ 从仓库根调用 —— cxx-flags/ld-flags 相对 cwd，§16.5 陷阱 1）
export PHPRC="D:/TypePHP/typephp-windows-x64"        # ★ 从第一天就设（§16.5 陷阱 4）
cd D:/TypePHP/Drift2
tpc apps/hello/project.yml -O2 -m bin
./hello.exe
```

## A.2 完整应用：`users`（列表 + 搜索 + 删除 + 表单 + 异步 + 多页 + 菜单 + 快捷键）

### A.2.1 目录树

```
apps/users/
  project.yml
  main.php
  bin/
    win32/Bootstrap.php          # 组合根（Windows）
    macos/Bootstrap.php          # 组合根（macOS）—— 由 if: 二选一
  src/
    UsersApplication.php         # extends Application：title / boot / buildRoot / commands / accelerators
    UsersRoot.php                # 只做组合（§12.2）
    pages/
      UsersPage.php              # 列表 + 搜索 + 删除
      SignupPage.php             # 表单（property hooks + SubjectBuilder）
      SettingsPage.php           # 主题 / 光标 / 拖放 / 焦点序演示（§A.3）
    store/                       # ★ 业务层：零 GUI 依赖
      AppStore.php               # 当前页 + 状态栏 + 主题
      UserStore.php              # 行数据 + 三态 + 缓存 getter
      User.php                   # 值对象
      LoadStatus.php             # backed enum
      UserSource.php             # 数据源接口
      HttpUserSource.php         # 实现（走 TaskHost）
      MemoryUserSource.php       # 实现（测试 / 离线）
      SignupForm.php             # 表单（property hooks）
    commands/
      NavigationCommands.php
      UserCommands.php
      SignupCommands.php
      AppCommands.php
    widgets/
      StatusBar.php              # 应用私有专职订阅控件
      UserRow.php                # 列表行（纯展示，不吃 store）
```

### A.2.2 `composer.json`（解释器面：preview / lint / 单测）

```jsonc
{
  "autoload": {
    "psr-4": {
      "Drift\\":      "src/",
      "Drift\\Dev\\": "tools/",
      "App\\Users\\": ["apps/users/src/", "apps/users/bin/"]
    }
  }
}
```

### A.2.3 `project.yml`（同仓开发形态）

```yaml
name: users
mode: bin
platform: win32
version: 0.1.0
php-version: "8.5"
optimize: 2
job: 16
cxx-std: c++20
build-dir: ../../build/users
no-console: true

sources:
  - main.php
  - src
  - ../../src
  - ../../cpp-src
  - path: bin/win32
    if: PHP_OS_FAMILY == "Windows"
  - path: bin/macos
    if: PHP_OS_FAMILY == "Darwin"

ignore:
  - ../../src/platform/macos
  - ../../src/platform/win32

defines:
  - DRIFT_USERS_ALL_DECL=<php_users_all_decl.h>

include-paths: [../../cpp/skia/include]
link-paths:    [../../cpp/skia/out/Release-x64]
link-libs:
  - user32.lib
  - gdi32.lib
  - ole32.lib
  - imm32.lib
  - comdlg32.lib
  - shell32.lib
  - advapi32.lib
cxx-flags: [/utf-8, /MT, /DUSE_SKIA]
```

**SDK 分发形态（§10.2）**：应用侧把框架换成一行 stub：

```yaml
# apps/users/project.yml —— 形态 B（-m lib）消费侧
name: users
mode: bin
platform: win32
build-dir: ../../build/users
no-console: true
sources:
  - main.php
  - src
  - ../../sdk/drift.stub.php     # ★ 一行取代 ../../src + ../../cpp-src + Skia 路径 + link-libs
  - path: bin/win32
    if: PHP_OS_FAMILY == "Windows"
# ★ 不需要 include-paths / link-paths / link-libs（全烘进 drift.dll）
# ★ 但构建必须用源码树 tpc：php bin/tpc.php（§10.2.2）
```

### A.2.4 入口与组合根

```php
<?php
// apps/users/main.php
declare(strict_types=1);

namespace {
    function main(): void { \App\Users\Bootstrap::run(); }
}
```

```php
<?php
// apps/users/bin/win32/Bootstrap.php
declare(strict_types=1);

namespace App\Users;

final class Bootstrap
{
    public static function run(): void
    {
        $app = new UsersApplication(
            new \Drift\Platform\Win32\Win32Platform(),
            new \Drift\App\SyncTaskHost(),                        // M1–M3；换 ThreadPoolTaskHost() 即换后端
            new \Drift\App\WindowStateStore(\Drift\App\Paths::userConfig('users.window')),
        );
        $app->run();
    }
}
```

### A.2.5 `UsersApplication`

```php
<?php
// apps/users/src/UsersApplication.php
declare(strict_types=1);

namespace App\Users;

use Drift\App\Application;
use Drift\Commands\CommandRouter;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Widget\Widget;

use App\Users\Store\{AppStore, UserStore, SignupForm, HttpUserSource, UserSource};
use App\Users\Commands\{NavigationCommands, UserCommands, SignupCommands, AppCommands};

final class UsersApplication extends Application
{
    private AppStore   $app;
    private UserStore  $users;
    private SignupForm $form;
    private UserSource $src;          // ★ SignupCommands 也依赖它（§23.2 O-15 复核发现 ⑧）

    /** 在任何 build / 命令注册之前调用一次（依赖装配的唯一入口） */
    protected function boot(): void
    {
        $this->app   = new AppStore();
        $this->src   = new HttpUserSource($this->taskHost());
        $this->users = new UserStore($this->src);
        $this->form  = new SignupForm();

        $this->users->load();          // 首屏：进 Loading → 结果回贴（§11.5）
    }

    protected function title(): string { return '用户管理'; }

    protected function buildRoot(): Widget
    {
        return UsersRoot::make($this->app, $this->users, $this->form);
    }

    /** 动作缝注册表（§11.4.3）：可枚举、可 lint、可单测 */
    protected function commands(): CommandRouter
    {
        // ★ 方案 E（§23.2 O-15 裁决）：on() 收**实例** ⇒ 依赖就在构造点，不需要 provide() / 反射。
        //   多命令共用同一 handler ⇒ 提局部变量（不提会 new 出多个实例）。
        $nav  = new NavigationCommands($this->app);
        $user = new UserCommands($this->users, $this->app);

        return CommandRouter::make()
            ->on('nav.users',     $nav)
            ->on('nav.signup',    $nav)
            ->on('nav.settings',  $nav)
            ->on('user.del',      $user)
            ->on('users.refresh', $user)
            ->on('form.submit',   new SignupCommands($this->form, $this->src))
            ->on('app.quit',      new AppCommands($this->app));
    }

    /** 应用级快捷键（§9.1 路由链第 ① 环，在焦点之前） */
    protected function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::ctrl('N'), 'nav.signup')
            ->bind(KeyChord::f(5),      'users.refresh');
    }

    /** 系统主题变更 ⇒ 全树重建（§9.2） */
    protected function onSystemThemeChanged(\Drift\Theme\SystemTheme $t): void
    {
        $this->app->setSystemTheme($t);
    }
}
```

### A.2.6 `UsersRoot`（只做组合）

```php
<?php
// apps/users/src/UsersRoot.php
declare(strict_types=1);

namespace App\Users;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Routing\{PageHost, RouteTable};
use Drift\Widget\{Dock, AppBar, Overflow, NavButton};
use Drift\Widget\Menu\{MenuBar, Menu, MenuItem};
use Drift\Input\KeyChord;

use App\Users\Store\{AppStore, UserStore, SignupForm};
use App\Users\Pages\{UsersPage, SignupPage, SettingsPage};
use App\Users\Widgets\StatusBar;

final class UsersRoot extends StatelessWidget
{
    public function __construct(
        private readonly AppStore   $app,
        private readonly UserStore  $users,
        private readonly SignupForm $form,
    ) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->children([
                MenuBar::make()->menus([
                    Menu::make('文件')->items([
                        MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
                        MenuItem::make('新建用户')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
                        MenuItem::separator(),
                        MenuItem::make('退出')->command('app.quit'),
                    ]),
                    Menu::make('视图')->items([
                        MenuItem::make('设置')->command('nav.settings'),
                    ]),
                ]),
                NavButton::make('列表')->command('nav.users'),
                NavButton::make('注册')->command('nav.signup'),
            ]))
            // ★ 传 store + 切片函数；值不在这里读（§12.2）
            ->bottom(StatusBar::make()
                ->source($this->app)
                ->select(fn(AppStore $s): string => $s->statusLine()))
            ->center(PageHost::make()
                ->source($this->app)
                ->select(fn(AppStore $s): string => $s->currentPage())
                ->table($this->routeTable()))
            ->overflow(Overflow::Clip);
    }

    private function routeTable(): RouteTable
    {
        return RouteTable::make()
            ->route('users',    fn(): Widget => UsersPage::make($this->users))
            ->route('signup',   fn(): Widget => SignupPage::make($this->form))
            ->route('settings', fn(): Widget => SettingsPage::make($this->app));
    }
}
```

### A.2.7 `AppStore`（当前页 / 状态栏 / 主题）

```php
<?php
// apps/users/src/store/AppStore.php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\State\Subject;
use Drift\Theme\{ThemeData, SystemTheme};

/** ★ 业务层：不认识任何 GUI 类型（连 Drift\Widget 都不 import） */
final class AppStore extends Subject
{
    private string $currentPage = 'users';
    private string $statusLine  = '就绪';
    private SystemTheme $systemTheme = SystemTheme::Light;

    public function currentPage(): string { return $this->currentPage; }
    public function statusLine(): string  { return $this->statusLine; }
    public function systemTheme(): SystemTheme { return $this->systemTheme; }

    public function navigate(string $page): void
    {
        if ($page === $this->currentPage) { return; }     // ★ 相等门控：同页不广播
        $this->currentPage = $page;
        $this->notifyChanged();
    }

    public function say(string $line): void
    {
        $this->statusLine = $line;
        $this->notifyChanged();
    }

    public function setSystemTheme(SystemTheme $t): void
    {
        $this->systemTheme = $t;
        $this->notifyChanged();
    }
}
```

### A.2.8 业务数据：值对象 / 枚举 / 数据源 / Store / 表单

```php
<?php
// apps/users/src/store/User.php
declare(strict_types=1);
namespace App\Users\Store;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $email,
    ) {}
}
```

```php
<?php
// apps/users/src/store/LoadStatus.php
declare(strict_types=1);
namespace App\Users\Store;

/** ★ 封闭域 ⇒ 消费它的 match 禁止 default（§12.1 第 2 条） */
enum LoadStatus: int
{
    case Idle    = 0;
    case Loading = 1;
    case Ready   = 2;
    case Error   = 3;
}
```

```php
<?php
// apps/users/src/store/UserSource.php
declare(strict_types=1);
namespace App\Users\Store;

final readonly class Page
{
    /** @param list<User> $items */
    public function __construct(public bool $ok, public array $items = [], public string $error = '') {}
}

interface UserSource
{
    public function fetchPage(int $page): Page;
    public function delete(int $id): bool;
    public function submit(string $endpoint, array $payload): bool;
}
```

```php
<?php
// apps/users/src/store/HttpUserSource.php
declare(strict_types=1);
namespace App\Users\Store;

use Drift\App\{Job, TaskHost};

/**
 * ★ 走 TaskHost：M1–M3 是同步直跑；M4 换成线程池，本文件一字不改（§17.2）
 * ★ Job 是类不是闭包（§17.2）：可枚举、可单测、绕开"闭包跨线程未验"
 */
final class FetchPageJob implements Job
{
    public function __construct(private int $page) {}
    public function run(): Page { /* HTTP 取一页 ⇒ Page */ }
}

final class DeleteUserJob implements Job
{
    public function __construct(private int $id) {}
    public function run(): bool { /* HTTP DELETE ⇒ bool */ }
}

final class HttpUserSource implements UserSource
{
    public function __construct(private TaskHost $tasks) {}

    public function fetchPage(int $page): Page
    {
        $result = null;
        $this->tasks->submit(new FetchPageJob($page), function (Page $p) use (&$result): void {
            $result = $p;                       // ★ 回贴在 UI 线程执行（§17.2.3）
        });
        return $result ?? new Page(ok: false, error: 'pending');
    }
    // delete() / submit() 同构，略
}
```

```php
<?php
// apps/users/src/store/UserStore.php
declare(strict_types=1);
namespace App\Users\Store;

use Drift\State\Subject;

final class UserStore extends Subject
{
    public function __construct(private UserSource $src) {}

    /** @var list<User> */ private array $rows = [];
    private LoadStatus $status = LoadStatus::Idle;
    private string $query = '';

    // ── 派生值：缓存 getter（§11.3.4）────────────────────────────
    private ?array $visibleCache = null;             // ★ 缓存槽

    public function status(): LoadStatus { return $this->status; }
    public function rows(): array        { return $this->rows; }
    public function query(): string      { return $this->query; }

    /** 过滤后的行（每帧会被 select 调一次） */
    public function visible(): array
    {
        return $this->visibleCache ??= ($this->query === ''
            ? $this->rows
            : array_values(array_filter($this->rows,
                fn(User $u): bool => str_contains($u->name, $this->query))));
    }

    // ── 变更 ────────────────────────────────────────────────
    public function setQuery(string $q): void
    {
        if ($q === $this->query) { return; }         // 相等门控
        $this->query = $q;
        $this->visibleCache = null;                  // ★ 依赖变了 ⇒ 清缓存（lint `stale-derived` 守这一行）
        $this->notifyChanged();
    }

    public function load(int $page = 1): void
    {
        $this->status = LoadStatus::Loading;
        $this->notifyChanged();
        $r = $this->src->fetchPage($page);           // 同步三态后端：loading 态画不出来（§11.5 注）
        if ($r->ok) { $this->rows = $r->items; $this->status = LoadStatus::Ready; }
        else        { $this->status = LoadStatus::Error; }
        $this->visibleCache = null;                  // ★ rows 变了 ⇒ 清缓存
        $this->notifyChanged();
    }

    public function remove(int $id): bool
    {
        if (! $this->src->delete($id)) { return false; }
        $this->rows = array_values(array_filter($this->rows, fn(User $u): bool => $u->id !== $id));
        $this->visibleCache = null;                  // ★
        $this->notifyChanged();
        return true;
    }
}
```

```php
<?php
// apps/users/src/store/SignupForm.php
declare(strict_types=1);
namespace App\Users\Store;

use Drift\State\Subject;

/** ★ property hooks：字段名只写一次，且是「属性」⇒ 拼错 = 编译错误（§11.4.1） */
final class SignupForm extends Subject
{
    public string $name  = '' { set (string $v) { $this->name  = $v; $this->submitError = ''; $this->notifyChanged(); } }
    public string $email = '' { set (string $v) { $this->email = $v; $this->submitError = ''; $this->notifyChanged(); } }

    /** 提交结果属于业务状态 ⇒ 放 Store（§11.4.3） */
    public string $submitError = '';

    /** @return list<string> */
    public function validate(): array
    {
        $e = [];
        if ($this->name === '')  { $e[] = '姓名不能为空'; }
        if (! str_contains($this->email, '@')) { $e[] = '邮箱格式不正确'; }
        return $e;
    }

    /** ★ 唯一序列化出口 */
    public function toApiPayload(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    public function fail(string $msg): void { $this->submitError = $msg; $this->notifyChanged(); }
    public function reset(): void           { $this->name = ''; $this->email = ''; $this->submitError = ''; $this->notifyChanged(); }
}
```

### A.2.9 页面

```php
<?php
// apps/users/src/pages/UsersPage.php
declare(strict_types=1);
namespace App\Users\Pages;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Widget\{Column, Row, Text, Button, TextField, Each, Spinner, Padding, Spacer};
use Drift\State\SubjectBuilder;
use Drift\Commands\CommandArg;

use App\Users\Store\{UserStore, User, LoadStatus};
use App\Users\Widgets\UserRow;

final class UsersPage extends StatelessWidget
{
    public function __construct(private readonly UserStore $store) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(16)->padding(24)->children([
            Text::make('用户目录')->title(),

            Row::make()->spacing(8)->children([
                // ── 搜索框：官方订阅形态（§11.4.2）—— 不提供 bind() ──
                SubjectBuilder::make($this->store)
                    ->select(fn(UserStore $s): string => $s->query())
                    ->builder(fn(string $q): Widget =>
                        TextField::make()->value($q)->label('搜索')->placeholder('输入姓名')
                            ->onChanged(fn(string $x) => $this->store->setQuery($x))),
                Button::primary('刷新')->command('users.refresh'),
                Spacer::make(),
                Button::primary('新建用户')->command('nav.signup'),
            ]),

            // ── 列表：订阅点在这层，行内只吃值（§11.3.2 纪律 2）──────
            SubjectBuilder::make($this->store)
                ->select(fn(UserStore $s): LoadStatus => $s->status())
                ->builder(fn(LoadStatus $st): Widget => match ($st) {      // ★ 无 default（封闭域）
                    LoadStatus::Loading => Spinner::make(),
                    LoadStatus::Error   => Row::make()->spacing(8)->children([
                        Text::make('加载失败')->error(),
                        Button::primary('重试')->command('users.refresh'),
                    ]),
                    LoadStatus::Ready   => SubjectBuilder::make($this->store)
                        ->select(fn(UserStore $s): array => $s->visible())   // ★ 切片：只跟过滤结果
                        ->builder(fn(array $rows): Widget =>
                            Each::make(fn(User $u): int => $u->id)        // ★ key 必填
                                ->items($rows, fn(User $u): Widget => UserRow::make($u))),
                    LoadStatus::Idle    => Text::make('尚未加载')->caption(),
                }),
        ]);
    }
}
```

> ★ **已按 §23.2 O-16 裁决修正**（2026-10-14）：搜索框原先**直接读** `$this->store->query()` 而**没有订阅** —— 在 `StatelessWidget` 里这就是 `dead-subscription`（值变了不刷新）。现改为**官方订阅形态**：`SubjectBuilder::select(...)` 包一层 + 手写 `value()/onChanged()`。
> ★ 顺带：这也是 §11.4.2 那张"摩擦点对照"表**为什么要删**的活证据 —— 表里说"不用记得包 `SubjectBuilder`"，而**真实的正确写法就是必须包**。

```php
<?php
// apps/users/src/widgets/UserRow.php —— 纯展示，不吃 store（§11.3.2 纪律 2）
declare(strict_types=1);
namespace App\Users\Widgets;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Widget\{Row, Text, Button, Spacer};
use Drift\Commands\CommandArg;
use Drift\Input\CursorShape;
use Drift\Widget\Drag\Draggable;

use App\Users\Store\User;

final class UserRow extends StatelessWidget
{
    public function __construct(private readonly User $user) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        $u = $this->user;
        return Row::make()->spacing(12)->cursor(CursorShape::PointingHand)->children([
            Text::make($u->name)->body(),
            Text::make($u->email)->caption(),
            Spacer::make(),
            Button::danger('删除')->command('user.del')->arg(CommandArg::id($u->id)),
        ])
        // 应用内拖放：把这一行拖到「回收站」上（§12.4，M3，零平台端口）
        ->draggable(Draggable::make($u->id));
    }
}
```

```php
<?php
// apps/users/src/pages/SignupPage.php
declare(strict_types=1);
namespace App\Users\Pages;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Widget\{Column, Text, TextField, Button, Row, Spacer};
use Drift\State\SubjectBuilder;                 // ★ 不再需要 Bound（§23.2 O-16 裁决）

use App\Users\Store\SignupForm;

final class SignupPage extends StatelessWidget
{
    public function __construct(private readonly SignupForm $form) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(12)->padding(24)->children([
            Text::make('注册新用户')->title(),

            // ── 表单字段：官方订阅形态（§11.4.2）—— 每字段读一次、写一次 ──
            SubjectBuilder::make($this->form)
                ->select(fn(SignupForm $f): string => $f->name)
                ->builder(fn(string $v): Widget =>
                    TextField::make()->value($v)->label('姓名')
                        ->onChanged(fn(string $x) => $this->form->name = $x)),

            SubjectBuilder::make($this->form)
                ->select(fn(SignupForm $f): string => $f->email)
                ->builder(fn(string $v): Widget =>
                    TextField::make()->value($v)->label('邮箱')
                        ->onChanged(fn(string $x) => $this->form->email = $x)),

            // 提交错误也是业务状态 ⇒ 订阅回 UI（§11.4.3）
            SubjectBuilder::make($this->form)
                ->select(fn(SignupForm $f): string => $f->submitError)
                ->builder(fn(string $err): Widget =>
                    $err === '' ? Row::make() : Text::make($err)->error()),

            Row::make()->spacing(8)->children([
                Button::primary('提交')->command('form.submit'),
                Spacer::make(),
                Button::make('清空')->onPressed(fn() => $this->form->reset()),   // ★ 纯 UI 回调 ⇒ 闭包合法
            ]),
        ]);
    }
}
```

> ★ **已按 §23.2 O-16 裁决（A′）修正**（2026-10-14）：原稿用 `TextField::bind(...)` / `Bound::make(...)`，而 `Bound` 按现签名**订阅不了**（第一个参数是**值**不是 `Subject`）⇒ 两者已**删除**。现形态是官方推荐写法。
> ★ **诚实代价**：每个字段 4 行、且**必须记得包 `SubjectBuilder`**（漏了 ⇒ 值变了不刷新 ⇒ lint `dead-subscription` 守）。这是 PHP 语言层封死"1 次出现"之后的必然结果（§11.4.2 的四层论据）。

### A.2.10 命令处理器

```php
<?php
// apps/users/src/commands/UserCommands.php
declare(strict_types=1);
namespace App\Users\Commands;

use Drift\Commands\{CommandHandler, CommandArg};
use App\Users\Store\{UserStore, AppStore};

final class UserCommands implements CommandHandler
{
    public function __construct(private UserStore $users, private AppStore $app) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'users.refresh' => $this->refresh(),
            'user.del'      => $this->delete($arg),
            default         => false,          // ★ 不是我管 ⇒ 继续冒泡（bool 的语义在此）
        };
    }

    private function refresh(): bool
    {
        $this->app->say('正在刷新…');
        $this->users->load();
        $this->app->say('已刷新');
        return true;
    }

    private function delete(CommandArg $arg): bool
    {
        $ok = $this->users->remove($arg->i);
        $this->app->say($ok ? '已删除' : '删除失败');
        return true;
    }
}
```

```php
<?php
// apps/users/src/commands/NavigationCommands.php
declare(strict_types=1);
namespace App\Users\Commands;

use Drift\Commands\{CommandHandler, CommandArg};
use App\Users\Store\AppStore;

final class NavigationCommands implements CommandHandler
{
    public function __construct(private AppStore $app) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if (! str_starts_with($name, 'nav.')) { return false; }   // 继续冒泡
        $this->app->navigate(substr($name, 4));
        return true;
    }
}
```

```php
<?php
// apps/users/src/commands/SignupCommands.php
declare(strict_types=1);
namespace App\Users\Commands;

use Drift\Commands\{CommandHandler, CommandArg};
use App\Users\Store\{SignupForm, UserSource, AppStore, UserStore};

final class SignupCommands implements CommandHandler
{
    public function __construct(
        private SignupForm $form,
        private UserSource $src,
        private AppStore   $app,
        private UserStore  $users,
    ) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'form.submit') { return false; }

        $errs = $this->form->validate();
        if ($errs !== []) {
            $this->form->fail(implode('；', $errs));       // ★ 反馈走 Store，不走 State
            return true;
        }

        $ok = $this->src->submit('/api/signup', $this->form->toApiPayload());   // ★ 唯一序列化出口
        if (! $ok) { $this->form->fail('提交失败，请重试'); return true; }

        $this->form->reset();
        $this->app->say('注册成功');
        $this->app->navigate('users');
        $this->users->load();
        return true;
    }
}
```

> ★ **已裁决（2026-10-14，§23.2 O-15 方案 E）**：依赖**从构造点来** —— `on()` 收**实例**，所以依赖在组合根（§A.2.5 `commands()`）显式 `new` 出来 ⇒ **不需要 `provide()` 依赖登记、不需要反射、不需要动态 `new`**。上面两个 handler 就按 `new UserCommands($this->users, $this->app)` / `new SignupCommands($this->form, $this->src)` 装配。★ 代价：handler 是注册期单例 ⇒ **必须无状态**（跨命令状态属于 Store）。

### A.2.11 应用私有控件：`StatusBar`（专职订阅者）

```php
<?php
// apps/users/src/widgets/StatusBar.php
declare(strict_types=1);
namespace App\Users\Widgets;

use Drift\Widget\{StatefulWidget, State, BuildContext, Widget, Text, Row, Spacer};
use Drift\State\{Subject, Subscription, SubscribingWidget};

final class StatusBar extends StatefulWidget implements SubscribingWidget   // ★ 接口标记
{
    private ?Subject $source = null;
    /** @var null|callable(Subject): mixed */
    private $select = null;

    public function source(Subject $s): self  { $this->mutate(); $this->source = $s;  return $this; }
    public function select(callable $f): self { $this->mutate(); $this->select = $f;  return $this; }

    public function createState(): State { return new StatusBarState(); }

    public function getSource(): ?Subject { return $this->source; }
    public function getSelect(): ?callable { return $this->select; }
}

final class StatusBarState extends State
{
    private string $text = '';

    public function initState(): void
    {
        $w = $this->widget();
        if ($w->getSource() === null || $w->getSelect() === null) { return; }
        // ★ 订阅袋：dispose() 基类统一全退（§11.3 RT-1）
        $this->watch($w->getSource(), function (): void {
            $v = ($this->widget()->getSelect())($this->widget()->getSource());
            if ($v === $this->text) { return; }              // ★ 相等门控
            $this->setState(function () use ($v): void { $this->text = (string) $v; });
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Row::make()->children([ Text::make($this->text)->caption(), Spacer::make() ]);
    }
}
```

## A.3 能力示例集（§9 声明但原方案零示例的部分）

### A.3.1 加速键 + 菜单栏 + 上下文菜单

```php
// ① 应用级加速键（§9.1 路由链第 ① 环，★ 在焦点之前）
protected function accelerators(): AcceleratorTable
{
    return AcceleratorTable::make()
        ->bind(KeyChord::ctrl('R'), 'users.refresh')
        ->bind(KeyChord::ctrl('S'), 'form.submit')       // IME 组合期不生效；输入框独占键集优先
        ->bind(KeyChord::f(5),      'users.refresh');
}

// ② 菜单栏：自绘控件，放在 Dock::top（§5.1 裁决：原生菜单栏不做）
AppBar::make()->children([
    MenuBar::make()->menus([
        Menu::make('文件')->items([
            MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
            MenuItem::make('新建')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
            MenuItem::separator(),
            MenuItem::make('退出')->command('app.quit'),
        ]),
    ]),
])

// ③ 上下文菜单：右键手势 → GestureArena（§9.1）
Row::make()->contextMenu(
    ContextMenu::make()->items([
        MenuItem::make('删除该行')->command('user.del')->arg(CommandArg::id($u->id)),
        MenuItem::make('复制邮箱')->onPressed(fn() => $this->clipboard()->set($u->email)),
    ])
)

// ④ 输入框声明独占键集（编辑命令优先于加速键）
TextField::make()->claimKeys([KeyChord::ctrl('A'), KeyChord::ctrl('C'),
                              KeyChord::ctrl('V'), KeyChord::ctrl('X'), KeyChord::ctrl('Z')])
```

### A.3.2 光标形状（命中一次、三方消费）

```php
// 控件声明，EventLoop 在 WM_SETCURSOR 时问命中链，取最深声明者 ⇒ WindowHost::setCursor()
Row::make()->cursor(CursorShape::PointingHand)->children([...])   // 可点
Text::make('可选中文本')->cursor(CursorShape::IBeam)              // 文本
Column::make()->cursor(CursorShape::SizeAll)->draggable(...)      // 拖动把手
Button::primary('提交中…')->disabled()->cursor(CursorShape::Wait) // 忙碌
```

### A.3.3 主题切换 / 深色 / 高对比度

```php
// ① 主题 scope：谁生效查父链，不算特异性权重（§18 避开 CSS 层叠）
ThemeScope::make()
    ->theme($this->app->systemTheme() === SystemTheme::Dark ? ThemeData::dark() : ThemeData::light())
    ->child($page)

// ② 系统主题变更：走 screen() 通道，与 DpiChanged 同路（§9.2）
protected function onSystemThemeChanged(SystemTheme $t): void
{
    $this->app->setSystemTheme($t);        // 改 store ⇒ notify ⇒ 全树重建（不是 relayout）
}

// ③ 高对比度并入 ThemeData 作为 token 覆盖层
// ★ 原写的 overrideTokens([ColorToken::Border => …]) **在 PHP 里不合法**：
//   枚举实例是对象，**不能作数组键**（`Illegal offset type`）。
//   ⇒ 改成值对象的 withX()（与 §15.7 `BoxStyle::withX()` 同源）：
//     点覆盖用 withColor()，整棵子树一起换用 withHighContrast()。
$theme = ThemeData::light()->withColor(ColorToken::Border, ColorToken::Border->highContrastValue());
$themeAll = ThemeData::light()->withHighContrast();   // 面覆盖
```

### A.3.4 焦点遍历顺序

```php
// 默认 = 文档序（深度优先）；容器可整体跳过；需定制用 focusOrder
Column::make()->children([
    AppBar::make()->children([...])->skipFocus(),        // ★ 工具按钮组不进 Tab 序（显式）
    TextField::make()->focusOrder(1),
    TextField::make()->focusOrder(2),
    Button::primary('提交')->focusOrder(3),
])
// ★ lint：同一容器内混用「有 focusOrder」与「无 focusOrder」的子节点 ⇒ 报错
```

### A.3.5 应用内拖放（M3，零平台端口）

```php
// 源
Row::make()->draggable(Draggable::make(payload: $user->id, feedback: Text::make($user->name)))
// 目标
Container::make()
    ->dragTarget(DragTarget::make()
        ->onAccept(fn(int $id) => $this->users->remove($id))
        ->onHover(fn(bool $h) => $this->setState(fn() => $this->hot = $h)))
```

### A.3.6 虚拟列表 + 状态外置（M5）

```php
// ★ 虚拟化会 dispose 被回收项的 State ⇒ 跨回收存活的状态必须外置（§12.3）
final class UserListState extends State
{
    // ❌ 错误：private int $scrollOffset = 0;        // 滚出视口 ⇒ State 被 dispose ⇒ 位置丢失
    // ✅ 正确：放 Store
    public function build(BuildContext $ctx): Widget
    {
        return ListView::make()
            ->itemCount(count($this->store->rows()))
            ->itemExtent(48.0)
            ->scrollOffset($this->store->listScrollOffset())                  // ★ 外置
            ->onScroll(fn(float $o) => $this->store->setListScrollOffset($o))
            ->itemBuilder(fn(int $i): Widget => UserRow::make($this->store->rows()[$i]));
    }
}
```

### A.3.7 模态

```php
// 桌面主形态 = 外壳 + 内容槽 + 模态（不做导航栈，§12.1 第 6 条）
$this->application()->showModal(
    Modal::make()->title('确认删除')->child(
        Column::make()->spacing(16)->children([
            Text::make('删除后不可恢复，确定吗？')->body(),
            Row::make()->spacing(8)->children([
                Button::danger('删除')->command('user.del.confirmed')->arg(CommandArg::id($id)),
                Button::make('取消')->onPressed(fn() => $this->closeModal()),   // 纯 UI 回调
            ]),
        ])
    )
);
```

### A.3.8 窗口状态持久化

```php
// 组合根（§5.4）：启动读回、退出写回，各一次同步 IO；恢复失败静默降级到默认值
$state = new \Drift\App\WindowStateStore(\Drift\App\Paths::userConfig('users.window'));
$app = new UsersApplication(new Win32Platform(), new SyncTaskHost(), $state);
$app->run();
// ★ Application::run() 内部：$state->restore($window) → 主循环 → $state->persist($window)
```

### A.3.9 异步：换后端 = 组合根一行

```php
// M1–M3：同步（大响应时界面停顿，但 error 态可正确呈现）
$app = new UsersApplication(new Win32Platform(), new \Drift\App\SyncTaskHost(), $state);

// M4：线程池（UI 代码零改动）
$app = new UsersApplication(new Win32Platform(), new \Drift\App\ThreadPoolTaskHost(), $state);
//                                                 ↑ 只改这一行
```

## A.4 反模式对照（A1–A17：错 / 对 / 谁来守）

| # | ❌ 错 | ✅ 对 | 守它的人 |
|---|-------|-------|---------|
| A1 | `public int $constraints = 0;` 声明了从不读 | 删掉它；或真读它 | lint R11 / W3 |
| A2 | `BoxConstraints(maxWidth: 0)` 表示"无界" | `BoxConstraints(maxWidth: INF)`；`TextRole::Unset` 显式 case | 契约 C2 + review |
| A3 | `if ($ctx->width < 891) { 折叠(); }` | `Wrap::make()` / 问**本容器约束宽** | review |
| A4 | `initState()` 外调 `watch()` / 忘记 `dispose()` | 只在 `initState` 订；订阅袋 `dispose()` 全退 | 运行期断言 + lint |
| A5 | 每次 hover 都 `notifyChanged()` | `Cell::setIfChanged()` / `select` 返回值门控 | 原语 + lint |
| A6 | `catch (\Throwable $e) {}` | `Errors::report(...)` | review + `Errors` 出口 |
| A7 | `match ($st) { Ready => …, default => $rows }` | 列全所有 case（封闭域禁 `default`） | `UnhandledMatchError` |
| A8 | `ThemeScope` 传 `UserStore` | `SubjectBuilder` 订阅 | lint `dead-subscription` |
| A9 | `$box->w = $w; $box->h = $h;` 两处手写 | 只有 `createRenderObject()` 一条路 | lint R12 / W2 |
| A10 | `Root` 里 540 行 `if ($page === …)` | 一页一文件 + `RouteTable`；Root 只组合 | lint（Root 判据） |
| A11 | `$w = Text::make('x'); $tree->child($w); $w->title();` | 先修饰完再挂载（挂载即封存） | 运行期断言（Fatal） |
| A12 | 另写一套 `TextBuilder` 与 `Text` 分叉 | 一个类、一条链；`mutate()` 统一守卫 | lint `alias-mutate` + 删除测试 |
| A13 | `build()` 里 `file_get_contents('http://…')` | `TaskHost::submit(new FetchJob(...))` | review |
| A14 | 虚拟列表的滚动位置放 `State` | 放 `Store`（§12.3） | 纪律 + review |
| A15 | 提供 `->seal()` 却什么也不改变 | 删掉它，`seal()` 降为 `@internal` | review |
| A16 | `Root` 里 `Text::make($store->statusLine())`（无订阅者） | 传 store + `select`，让 `StatusBar` 内部订阅 | lint `dead-subscription` |
| A17 | 页面 `build()` 直接暴露给帧末；症状由**帧循环异常策略**决定（★ 见 §23.2 O-12） | 页面级包错误边界容器 | **纪律 + 容器（都在 M3）** |

## A.5 工具链用法

```bash
# ── 内环（秒级，不编译；§16.3）───────────────────────────────
php -l apps/users/src/pages/UsersPage.php
php tools/drift-lint.php apps/users                       # 规则见 §16.6
php tools/drift-preview.php apps/users --page users --svg out/users.svg

# lint 报错长什么样（每条都指出"症状 + 修法"，不是只报行号）
#   apps/users/src/pages/UsersPage.php:42  error  dead-subscription
#     build() 读了 UserStore 的值，但本类未实现 SubscribingWidget，也不在任何订阅者子树内。
#     ⇒ 数据变了这里不会重建。修法：把读值的那层包进 SubjectBuilder（或让本类 implements SubscribingWidget）
#
#   apps/users/src/store/UserStore.php:31  error  stale-derived
#     $visibleCache 依赖 $rows / $query；setQuery() 未清缓存 ⇒ 过滤结果会停在旧值。
#     ⇒ 在 setQuery() 里加 $this->visibleCache = null;
#
#   apps/users/src/pages/UsersPage.php:56  error  dead-command
#     'user.del' 已发出，但 CommandRouter 未注册任何 handler ⇒ 点了没反应。

# ── 单页预览（带参页面需要注入 store 夹具；§12.1 第 5 条）─────────
php tools/drift-preview.php apps/users --page users \
  --fixture apps/users/tests/fixtures/users_page.php     # 返回一个 UserStore（可离线 MemoryUserSource）

# ── 提交前（T2 级）─────────────────────────────────────────
export PHPRC="D:/TypePHP/typephp-windows-x64"
cd D:/TypePHP/Drift2
tpc apps/users/project.yml -O2 -m bin                    # 只建一个应用（§16.3）
php tools/drift-conformance.php                          # 合成树夹具
php tools/golden.php --update                            # 首次：生成基线
php tools/golden.php                                     # 之后：比对（★ 用仓库内测试字体）

# ── 形态 B（-m lib）：必须用源码树 tpc（§10.2.2）────────────
php bin/tpc.php ../../sdk/project.yml -O2 -m lib         # 库侧（源码树）
php bin/tpc.php apps/users/project.yml -O2 -m bin        # 消费侧
```

```php
<?php
// apps/users/tests/fixtures/users_page.php —— 单页预览夹具（带参页面的必要输入）
declare(strict_types=1);
namespace App\Users\Tests;

use App\Users\Store\{UserStore, MemoryUserSource};

return new UserStore(new MemoryUserSource([
    ['id' => 1, 'name' => '张三', 'email' => 'zhang@example.com'],
    ['id' => 2, 'name' => '李四', 'email' => 'li@example.com'],
]));
```

---

# §B 证据索引

本文每个结论的来源，按文档分工指向：

| 结论类型 | 去哪里核 |
|---------|---------|
| TypePHP 能力 / 限制 | `TypePHP使用手册.md` §3/§4/§6/§9/§10/§12；`examples/` |
| 探针实测数据 | `probes/RESULTS.md`（§1 类型化属性 / §2 clone-with / §3 fluent / §4 include-ignore / §5 线程 / §6 `===` 门控 / §7 闭包元数 / §8 notify 扇出 / §9 动态实例化 / **§10 属性钩子与引用**） |
| `-m lib` 实证链 | `HISTORY.md §3`（GUI 仓 spike + 六缺陷 + 发行版 diff + 时间线） |
| **已作废结论**（11 条） | `HISTORY.md §1` —— ★ 写新结论前先读这张表 |
| 环境坑与可复用手法 | `HISTORY.md §4/§5` |
| 每个裁决的得失权衡 | `DECISIONS.md` |
| 评审发现过程 | `REVIEW.md` / `REVIEW-2.md` |
| v1.0 全文（需要 diff 时） | `archive/DESIGN-v1.0-2026-10-14.md` |
| GUI 仓的历史决策史（含源码行号 / commit） | `D:\TypePHP\GUI\.workbuddy-ai\memory\`（按日期归档） |

★ **两个必须在写新结论前做的动作**：
1. `grep` 本文有没有写过（防"我撞上了"≠"文档没有"）；
2. 查 `HISTORY.md §1` 的作废表（防从旧文档或 GUI 仓过时注释里捡回已证伪的结论）。
