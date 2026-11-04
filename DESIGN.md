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
| 15 | **协程（Swoole/Swow）** | UI 线程**不跑协程**；异步收进 `TaskHost` 端口：**线程池后端**（C++ `std::thread` + `ts_resource(0)` —— ⛔ **2026-10-16 探针 K 实测：当前 tpc 下不可实现**，见 §17.2.2）/ **同步三态后端**（永远可用）。★ 协程**不需要**：要的是"别占 UI 线程"，线程直接给 | §17 |

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
| **C++ 线程（`std::thread` + `mutex` + `atomic`）** | **【实测】** 探针 E | `TaskHost` 后端 A 的物理基础。★ **探针 K（2026-10-16）把边界收窄了**：线程本身可用，但 **worker 线程跑不了 AOT PHP** —— `ts_resource(0)` 只是必要条件，缺的请求上下文（`php_request_cache`）**当前无可用入口**（§17.2.2 / 探针 K） |
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
| 6 | **`nano-hygiene`**：禁匿名类 / `yield`+`Generator` / `getenv`·`putenv` / `eval`·`include`·`require`（★ **`Fiber` 2026-11-04 解禁**，见 §16.7.7） | 保留"将来可切 `--nano`"的可选性（§16.7）。Fiber 例外：它是线性异步（`AsyncScope`）的唯一实现基础，而 nano 已明确不做 ⇒ 守它等于守一条不打算走的路 | lint（M1 起） |
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
│  Drift\Routing    多页面：RouteTable · PageHost · NoPage               │
│  Drift\Theme      主题：ThemeData · ThemeScope · SystemTheme · token   │
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
│                   · WindowStateStore · Paths                           │
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

| 目录 | 命名空间 | 文件 | 状态（2026-10-15 按 `src/` 核验） |
|------|---------|------|------|
| `src/App/` | `Drift\App` | ✅ `Application.php` · `Frame.php`（+ 1 个 `@internal` 实现文件 `FocusManager.php` —— 输入路由最小集，M3；§9.1 的完整链在 M4 的 `EventLoop`）；★ **2026-10-16 新落地三件**（任务 #8）：**`Job.php`** · **`TaskHost.php`** · **`SyncTaskHost.php`**（§17.2 的端口 + **后端 B**；§24.1 #35 点名的 `Job` 0 文件已清零）；★ **2026-10-18 P0**：**`Completion.php`**（`@internal` —— 队列里流动的完成事件，§17.4.2）+ **`DragController.php`**（`@internal`，拖动状态机，§9.1）；★ **P2**：**`Io/IoPoller.php`**（非阻塞 IO 每帧轮询，§17.4.4 —— `src/App/` 的第一个子目录）；★ **2026-10-31**：**`Io/Http.php`** + **`Io/HttpResponse.php`** + **`Io/HttpException.php`**（**阻塞**客户端，唯一的 HTTP 路径，§17.4.4.1 —— 在 `Job::run()` 里用，绝不在 UI 线程）；★ **P3**：**`NamedJob.php`**（可跨进程描述的任务）· **`ProcessWorker.php`**（worker 侧 JSON Lines 服务端）· **`ProcessTaskHost.php`**（常驻子进程后端，§17.4.5）；★ **2026-10-18 P0 补丁**：**`FrameClock.php`**（自适应帧时钟 —— `TimerHost` 的第一个生产调用者，§17.4.9）；★ **2026-10-18 M5**：**`Paths.php`**（@api —— **只做字符串组装**：配置目录由 `Platform::configDir()` 给，`Drift\App` 因此不碰平台，§5.1）+ **`WindowStateStore.php`**（@api —— §5.4：启动读回 / 退出写回**各一次同步 IO**、kv 文件、恢复失败**静默降级 + 一条 Diagnostic**；`position()` 返 `null` = 最小化不写；显示器夹取归壳）；⏳ `EventLoop.php` · `ThreadPoolTaskHost.php`（**⛔ 被 tpc 能力卡住** —— 探针 K，§17.2.2） | **缺 2**（M4） |
| `src/Platform/` | `Drift\Platform` | ✅ 9/9：`Platform` · `WindowHost` · `SurfaceProvider` · `InputSource` · `TimerHost` · `Screen` · `Clipboard` · `Dialog` · `Tray`（★ 2026-10-15 **端口层落地**；**同日 Win32 实现跟进**，见下行 —— `Dialog`/`Tray` 两个壳服务在 Win32 上返回 `null`（§24.3）；`Clipboard` **2026-10-21 已落地** `Win32Clipboard`（C 侧三桥 + 重试打开剪贴板），§24.2 #32；★ 2026-10-18 M5 **两个既有端口各加方法**：`WindowHost` +`position(): ?Point` / `place(Rect, bool)`（§5.4 的读写两侧）、`Platform` +`configDir(): string`（用户配置目录 —— 每个平台必然有，「没有」用**空字符串**表达，不用 `null`）） | ✅（M1） |
| `src/Platform/Win32/` | `Drift\Platform\Win32` | ✅ 8/8：`Win32Platform` · `Win32WindowHost` · `Win32Screen` · `Win32SurfaceProvider` · `Win32InputSource` · `Win32TimerHost` · `Win32RasterSurface` · **`Win32SkiaSurface`**（★ 2026-10-15 **首个平台实现落地**，配套 `cpp-src/drift_win32.{stub.php,cc}` 窗口桥 ⇒ §19 M1 出口判据兑现。★ `Win32RasterSurface` 是 `RenderSurface` 的**第 4 个**实现，走 `RasterCanvas` + `SetDIBitsToDevice` 位块传送（§7.3 的 M1 分期原文就是"CPU 光栅 + 位块传送"））。★ **2026-10-16 第二个渲染后端落地**（M1 遗留 ②）：`Win32SkiaSurface` = `SkiaCanvas` + **同一个** `drift_win_present()`，与 `Win32RasterSurface` **只差"造哪种画布"一行** ⇒ §7.2 裁决 1「后端可换、应用侧一行不改」兑现。后端由组合根 `new Win32Platform('skia' | 'soft')` 选（★ **2026-10-17 默认从 `soft` 翻成 `skia`**—— 软光栅的字来自位图字体，14px 上屏必然点阵锯齿，且 `Title=18px` 走放大路径；详见 §24.2 #19。`'soft'` 仍是显式可选的确定性测试后端）。真机已验：`hello.exe` 经 Skia 后端画出「Hello Drift」） | ✅（M1） |
| `src/Geometry/` | `Drift\Geometry` | `Point` · `Size` · `Rect` · `EdgeInsets` · `Scale` · `BoxConstraints` | ✅ 6/6 |
| `src/Rendering/` | `Drift\Rendering` | ✅ `RenderSurface` · `GpuContext` · `Canvas` · `DisplayList` · `Paint` · `Color` · `BoxStyle` · `RasterCanvas` · `RecordingCanvas` · **`SkiaCanvas`**（★ **2026-10-16 落地**（M1 遗留 ②）= §7.2 裁决 1 的第三个 `Canvas` 实现：`DisplayList` 的 Skia 回放器。**几何（行基线 / 笔位）全来自 PHP 的 `Paragraph`**，桥只逐字形 `drawSimpleText`（§7.2「画布只施加、不重算」）⇒ **不链 SkParagraph / SkShaper / ICU ⇒ 不需要 `icudtl.dat`**。坐标一律**设备像素**（量化只走 `Scale::quantizeRect()`），与 `RasterCanvas` 逐条同口径；**有意保留两处后端差异**（抗锯齿范围、描边实现）⇒ 像素级 golden **不能跨后端比**） · ✅ **P0（2026-10-20，D2 三层进 Canvas 契约）**：**`CanvasCapabilities`**（位掩码：Core 椭圆/线段所有后端必实现、Extended 渐变/虚线/裁剪路径 Skia 级必做而 Raster 可近似、Optional 路径/图/影/层只报 E-OP-UNSUPPORTED 不静默丢像素） · `Canvas` +9 成员（`capabilities()` + `drawEllipse`/`drawLine` Core + `pushClipPath` Extended + `drawPath`/`drawImage`/`drawShadow`/`pushLayer`/`popLayer` Optional） · 值对象 5 件：**`Path`**（fluent 几何数据）/ **`Gradient`**（色标等长升序，构造期失败）/ **`DashPattern`** / **`Shadow`** / **`ImageHandle`**；`Gradient`/`DashPattern` 是 **Paint source 属性**（正交维度，不为组合爆炸添 draw 兄弟） · `DisplayList` +8 opcode、槽位 5→6（`OP_DRAW_LINE` 要 5 操作数）；P1 `RasterCanvas`/`SkiaCanvas` 落椭圆/线段（描边居中口径） · ✅ **P3（2026-10-20，渐变/虚线真进后端）**：`RasterCanvas` 渐变逐像素取色（`Color::lerp` + `gradientColorAt`，矩形/椭圆填充与描边同源）+ 线段**真虚线**（弧长取模，弧长先 `/dpr` 换回**逻辑单位**与 `DashPattern` 同口径）；矩形/椭圆描边的虚线按 R2 **降级为实线**（不假装会沿描边路径走）⇒ 能力位升为 Core+Extended 四位。`SkiaCanvas` 走 **画布级 paint source**（`set_gradient` / `set_dash` / `clear_source` 三桥函数写进 slot，三个 draw 函数读 ⇒ 不改 draw 签名、不组合爆炸）；★ 本仓 Skia 是新 API：`SkShaders::LinearGradient/RadialGradient` + `SkGradient::Colors`（色标是 `SkColor4f`、位置必须**严格递增** ⇒ 只喂前 `count` 个，不能喂 PHP 侧补齐出来的重复值）+ `SkDashPathEffect::Make(SkSpan, phase)` 两参 · ✅ **P4（2026-10-21，Optional 层真进 Skia）**：`SkiaCanvas` 实现全部 6 个 Optional op（`drawPath`/`drawImage`/`drawShadow`/`pushLayer`/`popLayer`/`pushClipPath`）⇒ 能力位满 9 位；`RasterCanvas` 保持 E-OP-UNSUPPORTED（软光栅只做确定性基线，不假装）。复合数据过桥走**打包字节串**（桥 ABI 只认标量 + string）：`Path` 打包成 `verbs`（每命令 1 字节 MOVE/LINE/QUAD/CLOSE）+ `coords`（每命令 4 个 little-endian float）；`ImageHandle` 直送 RGBA 行优先字节串（C++ 侧按 `w*h*4` 校验长度后 `SkImages::RasterFromPixmapCopy`）。★ 本仓 Skia 是新 API：**`SkPath` 已剥离构造方法，必须走 `SkPathBuilder` + `detach()`**；`SkImage::RasterFromPixmapCopy` 在 `SkImages` 命名空间且是自由函数；`SkMaskFilter::MakeBlur` 在 `core/`。★ 阴影无独立原语 ⇒ 实现为「带模糊 mask filter 的圆角矩形」，`blur=0` 时关掉 mask filter（硬边）。· ✅ **`ToggleStyle`**（2026-10-21 —— 开关类的样式值对象：`accent` / `idle` / `mark` / `thumb` / `label` / `disabled` 六色 + `boxSize` / `gap` / `fontSize`；Widget 层经 `BuildContext` 一次合并（§15.7 纪律 3），Render 层只收值、不碰 Theme） · ✅ **`DividerStyle`**（2026-10-21 —— 分割线的全部形状：`Color $color` + `float $thickness`，**不要九字段样式对象**（§A15：字段只为今天的消费者存在）） · ✅ **`ProgressStyle`**（2026-10-21 —— 进度条的两色一高：`fill`（Accent）/ `track`（Border）/ `height`；**进度值不住样式**（值会变、样式不会）） | ✅（M1） |
| `src/Text/` | `Drift\Text` | `TextRole` · `FontManager` · `FontCollection` · `FontFace` · `TextShaper` · `TextLayout` · `Paragraph` · `TextMetricsProvider` · `TextStyle` · `TextInputClient` | ✅ 10/10（另有 **6 个未列入本表**的确定性实现：`DeterministicFontManager` · `DeterministicFontCollection` · `DeterministicShaper` · `DeterministicMetricsProvider` · `NotdefFontFace` · `BitmapFontFace` —— §8.2 坑 6 的“确定性参考实现”；★ **`BitmapFontFace` 降采样 2026-10-17 改出灰度覆盖度**（0..255 连续值，二值化曾经把抗锯齿倒在函数出口 —— §24.2 #17）；放大路径（≥16 1:1）不动）；★ **2026-10-17 新增 `TtfFace` + `FontLocator`**（**真字体度量面**：PHP 侧解析 `cmap`(f4/f12) + `hmtx` + `head`/`hhea`/`OS/2` ⇒ `hasGlyph()` 查**真字体覆盖**、步进取 hmtx、行度量取 `sTypo`（0.88/0.12，不是 hhea 的 1.448em）；`rasterize()` **委托**位图面 ⇒ `soft` 后端的字形逐字节不变。`FontLocator` 负责"字体放哪儿"这一处知识：`<cwd>/fonts` → `<cwd>/cpp/fonts` → 源码树 `cpp/fonts`） |
| `src/Layout/` | `Drift\Layout` | `RenderObject` · `RenderBox` · 各 `Render*` 族 · **`RenderSpinner`**（8 点 alpha 环）· **`RenderDock`**（五槽外壳布局）· **`RenderAppBar`**（chrome 顶条，`RenderFlex` 子类）· **`RenderScrollView`**（★ 2026-10-17 可滚动视口；★ 2026-10-18 加 `virtualExtent` / `sliceOrigin` 两个可选参数支持虚拟列表 §13.4，不传时逐字节同旧行为）· **`RenderPopupPanel`**（★ 2026-10-16 O-20 浮层面板自绘盒：投影（半透明黑垫层，外溢到矩形之外）/ 边框 / 底三层，`BoxStyle` 画不到 —— `PopupPanel` 控件消费它，菜单 / 浮层链路在用，smoke 34 条断言覆盖） + ✅ 1（**`RenderCustomPaint`**，2026-10-20 P2 —— 19 op 逃生舱的盒：给定轴夹进父区间（同 `RenderSizedBox`）、未给定轴吃满约束上限、上限不限 ⇒ 响亮失败（自绘区不能无穷大；消息带两条修法、不写内部编号）；`paint()` 把已对齐盒原点的画布 + 盒尺寸递给 `CustomPainter`；无子盒（组合留给 `Card`/`Padding`/`SizedBox`）；未给定轴的内禀尺寸 = 0（自然尺寸不猜）） | ✅（族 8 个：`RenderCenter` / `RenderFlex` / `RenderFlexible` / `RenderPadding` / `RenderProxyBox` / `RenderText` / `RenderButton` + `Overflow` · `FlexAlign`）+ ✅ 3（`RenderSpinner` / `RenderDock` / `RenderAppBar`，2026-10-16）+ ✅ 1（**`RenderScrollView`**，2026-10-17 —— `RenderProxyBox` 子类：滚动偏移**布局期进子盒 offset**（非 paint 期 translate，2026-10-17 padding 缺陷同族）+ 视口矩形裁剪 + `maxScroll` 夹取 + `Overflow::Scroll` 的第一次真实实现）+ ✅ 1（**`RenderPopupPanel`**，2026-10-16 O-20 —— 浮层面板自绘盒，投影 / 边框 / 底三层齐）· ✅ 1（**`RenderCard`**，2026-10-17 —— 卡片盒：底/边/圆角 + **内边距** + **三层递减柔和投影**（无 blur 的「廉价高斯」，外扩 3/2/1px × α .35/.60/1.00）；子约束**只 deflate 不 loosen**（与 `RenderPadding` 同口径，否则父把宽定死时内容缩在左边）；★ 不复用 `RenderPopupPanel` —— 面板的投影是「浮起来」的实心垫层，卡片要「贴地」的一点，共用会逼出 `mode` 开关） + ✅ 1（**`RenderSizedBox`**，2026-10-17 —— 定尺寸盒：`width`/`height` 各可空（**null = 该轴透传**，真语义不是哨兵），给定轴给孩子**紧**约束；★ 必须覆写 `intrinsicWidth/Height`（`Row`/`Dock` 量自然宽走内禀，漏了等于没定）；★ 父更紧时**夹进父区间**（`tight(100)` 里 `width(200)` ⇒ 100，否则 `BoxConstraints` 会抛「min 不得大于 max」）） + ✅ 1（**`RenderModalHost`**，2026-10-17 —— 模态宿主：**紧约束盖满** + 遮罩自绘（§A.3.7 的 `ModalBarrier` 是**职责**不是类型：遮罩没有独立存在意义）；点遮罩的判据 = 命中链上没有 `RenderPopupPanel`） + ✅ 1（**`RenderToggle`**，2026-10-21 —— 开关类三种形状共用的渲染盒：**`int $kind`（KIND_CHECKBOX=1 / KIND_RADIO=2 / KIND_SWITCH=3）+ 分支**，不是三个子类（tpc 去虚化会让"子类覆写"静默失效，§11 C6；同 `RenderSpinner` 用方向表而非 `cos/sin`）；布局 = 控件（方框 `boxSize` / 开关 `boxSize×1.9`）+ 间距 + 右侧标签（`RenderProxyBox` 子盒，`control+gap` 起）；**只用 Core / Extended 层 op**（`drawRect` / `drawEllipse` / `drawLine`）—— `drawPath` 是 Optional 层、软光栅显式报 `E-OP-UNSUPPORTED` ⇒ 勾是两条线段而不是一条路径，preview/golden/smoke 才不会炸；禁用态只换色不换几何） · ✅ **`RenderDivider`**（2026-10-21 —— 装饰性发丝线的叶子盒：`width()<=0` 吃满授予、`thickness` 定高；**无界 ⇒ 回落 `MIN_WIDTH=24`，绝不把 INF 放进坐标链**；`radius=0` ⇒ `drawRect` 一个 Core op 画完；**无语义角色**（§9.2：纯装饰不进语义树）） · ✅ **`RenderProgress`**（2026-10-21 —— 进度条叶子盒：**`SCALE=1000` 的整数千分比**（Widget 层夹取 `0..1` float 后转整数）⇒ 跨平台 golden 逐字节可比（§14.1）；轨道圆角矩形 + 填充矩形，`0%` 只画轨道、非零至少 1px 墨；`intrinsicWidth/Height` 必须给（`Row` 里配 `Expanded` 走弹性轴，读内禀的是固定轴）） |
| `src/Widget/` | `Drift\Widget` |（★ 2026-10-18 M5）**`LazyColumn`**（@api，虚拟列表，§13.4）· **`LazyColumnState`**（@internal，持偏移 + 定窗口）· **`Scrollable`**（@api 接口，`scrollBy()` —— 滚轮路由不再写死 `ScrollViewState`）· `Widget` · **`BoxWidget`** · **`SemanticsBuilder`**（★ 2026-10-18，@api：§9 语义树构建器，唯一的入口；"没人调就不建" ⇒ 零开销不是靠开关）· `StatelessWidget` · `StatefulWidget` · `Element` · `State` · `BuildContext` · `Key` · **`TextField`**（单行输入）· ✅ **`TextArea`**（★ 2026-11-03 落地，§15.2 + `DEBT` §21 —— **多行输入**：与 `TextField` 是**两个控件**不是 `maxLines` 开关，因为 `RenderTextField` 的几何前提就是单行（插入符 x 用 `advance(前缀)` 一次量得、选区一个矩形、高度恒一行），塞进去会让单行也背分支；`Enter` = 换行、`Ctrl+Enter` = 提交（没接 `onSubmitted()` 就不吞，放给加速键）、上下移**保持目标列**、`Home`/`End` 先到行首行尾再按到全文两端、垂直滚动靠 `Scrollable` 白送滚轮；编辑行为与单行共用 `EditableTextState` 基类 ⇒ 撤销 / 选区 / 按词跳零额外接线；★ 2026-11-03 滚动条落地（`DEBT` §22 —— 第三个 `ScrollBarHost` 实现，条的绘制收进 `ScrollBarGeometry::paint()` 三个盒共用；条栏宽 9px **常驻预留**（循环依赖不抖帧）；`lines(n)` 固定框高 —— 不叫 `maxLines`（那是 `TextStyle` 的省略号语义），没有它 `Column` 里 `maxScroll` 恒 0、条永远不出现））· **`Spinner`** · **`Dock`** · **`AppBar`** · **`NavButton`** · **`ErrorBoundary`** · **`ErrorPlaceholder`**；✅ **`PopupPanel`**（O-20 浮层族：菜单 / 浮层的**形体** —— 底 / 边框 / 投影 / 统一宽，2026-10-16 已落地）· ✅ **`ScrollView`**（★ 2026-10-17 可滚动视口：三段分工 `ScrollView`（声明）/ `ScrollViewState`（持偏移，`scrollBy()` 单一夹取入口）/ `ScrollViewBox`（产盒，@internal）—— 形态同 `TextField`；偏移进坐标链，滚轮经 `ScrollEvent` 路由到 `scrollBy()`；★ 2026-10-17 **滚动条落地**（`RenderScrollView::paint()` 在视口坐标系画胶囊形 thumb：长度 = 视口占比、位置 = 偏移比例、`maxScroll==0` 不画、`maxScroll<=0` 早退还是防 `appliedOffset()/max` 的 0/0 除零的结构必需））· ✅ **`Modal`**（★ 2026-10-17 落地，§A.3.7 兑现：`Modal` 只声明形体（`title`/`child`/`dismissible`/`minWidth`）、`Application::showModal()`/`closeModal()` 管生命周期；`dismissible=false` = **吞掉** ESC 与遮罩点击但**不关**；模态里的按钮派完命令**不会**把自己关掉（「关不关归应用」））· ✅ **`SizedBox`**（★ 2026-10-17 落地：`make($kid)->width(200.0)` / `->height()` / `->size(w,h)`；`Padding` 只能**加**内边距、永远不能小于内容，所以它顶替不了定尺寸 —— 示例台侧栏固定 200 宽即此用）· ✅ **`Card`**（★ 2026-10-17 落地：**盒样式 + 内边距 + 柔和阴影**，补的是`BoxWidget` 只有 background/border/radius、**没有 padding**，而 `Column`/`Row` 刻意不带盒样式的那个洞 —— 此前「带内边距的底色块」在 Drift 里**表达不出来**，所有示例只能把内容铺在白底上）· ✅ **`ModalHost`**（@internal 中间层：`ModalHost → Center → PopupPanel`，居中靠组合不靠开关） · ✅ **`AnimationController`**（@api，2026-10-21 —— §17.4.11 的 `0..1` 单值驱动器：`duration` / `curve` / `forward` / `reverse` / `jumpTo` / `stop` / `onCompleted`，推进靠 `tick(注入的帧时间戳)`，返回"还要下一帧吗"直接喂 `wantsFrame()`）· **`Curve`**（@api —— 四根二次曲线 `linear/easeIn/easeOut/easeInOut`，`int $kind` + `===` 比较链）· **`CustomPaint`**（@api，2026-10-20 P2 —— 19 op 回植的逃生舱：构造器 `(painter, ?width, ?height)` readonly 即终态，Widget 不可变 ⇒ 换画家 = 整体换新） · ✅ **`CustomPainter`**（@api，2026-10-20 P2 —— 自绘画家接口：`paint(Canvas, Size)` 纯函数（§14.1）+ `version()` 内容戳；方向表字面量禁 `cos/sin`（跨平台末位差异，`RenderSpinner` 同族）） | ✅ 8/8 + **`TextField`** + **`Spinner`** + **`Dock`** + **`AppBar`** + **`NavButton`** + **`ErrorBoundary`** + **`ErrorPlaceholder`** + **`PopupPanel`** + **`ScrollView`** + ⏳ **1**（另有 11 个控件 + 6 个 Element 实现；★ `TextField` **2026-10-15 已落地**（§24.1 #32 闭环）—— 此前全文 29 处引用、O-16 全部论据与 §A.2.9 旗舰表单都建在这个 0 文件的控件上；★ `Spinner` **2026-10-16 已落地** —— §11.5 三态 `Loading` 分支的目标形态，三段形态 + `RenderSpinner`（8 点 alpha 环），smoke 14 条断言 + M2LayoutPage 示例；★ `Dock`/`AppBar`/`NavButton` **2026-10-16 已落地** —— §12.1 五槽外壳 + chrome 顶条 + 导航按钮（`RenderDock`/`RenderAppBar` 两个新渲染盒，`DockElement`/`AppBarElement` 两个 `@internal` 实现），smoke 21 条断言 + ShellPage 示例；§A.2.6 `UsersRoot` 的控件面自此齐全（除 `Draggable`）；★ **`ErrorBoundary`/`ErrorPlaceholder` 2026-10-16 已落地**（O-12 A′ 的容器侧）：三层捕获（构造期 = `rebuild()` 的 try；build 期 = `settle()` 的 try；渲染期 = `RenderErrorBoundary` 的 `performLayout()`/`paint()` 兜底），捕获即换子树为占位（`updateChildren([ErrorPlaceholder])` 同一套 diff），`Frame::run()` 三段兜底走 Fatal 序列（S3 的"无边界"半边）；smoke 12 条断言 + `Errors` DIAGNOSTIC 留痕（debug 下 RECOVERABLE 会整局中止、与"只坏那一块"矛盾 ⇒ 不用它，持久化落盘等 M4）；★ **`ScrollView` 2026-10-17 已落地**（P1，§24.2 #15）—— 可滚动容器，`apps/users` 列表页已接线；★ **`Modal`/`ModalHost` 2026-10-17 已落地**（§24.2 #22 —— smoke 22 条 + 反例自检 5/5））· ✅ **`Checkbox` / `Radio` / `Toggle`**（★ 2026-10-21 —— §A.2 表单词汇的**选择类控件**，受控值与 `TextField` 同纪律：`checked`/`group` 由应用给，点击只回调 `onChanged(新值)`，值住应用 State；三兄弟共用抽象基座 **`ToggleBase`**（`RenderBoxWidget`）—— 字段 `protected` 不用 `private`（tpc 按运行时类型查属性表，父类 private 不进子类表 ⇒ 动态属性、值与语义全丢）；**`activate()` 住基类并按 `kind` 分支**而不是基类调子类虚方法（去虚化）；激活总入口是 `Application::activateFocusedWidget()`（指针按下 / 键盘 Space·Enter 同一条缝）与 `Button` 并列 ⇒ `Application` 只多一个 `instanceof ToggleBase` 分支；**不含三元表达式** —— `$on ? A : B` 两支类型不同会让 tpc 生成 `operator?:` 无重载的 C2678，只有 T2 构建看得见；单选组是"同一个 `group()` 值"而不是容器控件（没有第二次真相：组状态住 `group()` 传参）；`Switch` 这个名字不存在 —— `switch` 是 PHP 保留字，开关控件叫 `Toggle`） · ✅ **`Divider`**（2026-10-21 —— `make()->thickness(1.0)->width(0.0)`，分节原语；装饰 ⇒ 无语义） · ✅ **`Progress`**（2026-10-21 —— `make(0.42)->value()/height()/width()`，**受控值不自持状态**（同 Checkbox 纪律：不知道要转多久的用 `Spinner`）；`0..1` float 在 Widget 层夹取并转千分比（负数/NaN 归零、≥1 归满，**夹取不报错** —— 报错是「字段填错」的主场）；语义给 `role=progress` + `value=「42%」`（§9.2「状态」类，屏幕阅读器/UIA 的唯一非装饰价值）；**indeterminate 不做**（要它得先有动画相位机）） |
| `src/Widget/Drag/` | `Drift\Widget\Drag` | ✅ `Draggable` · `DragTarget` | ✅ **2026-10-16 已落地**（§A.3.5：两个**声明对象**（不是 Widget）+ `Widget::draggable()` / `dragTarget()` 两个声明位；状态机住 `Drift\App\DragController`（`@internal`），`Application::beginDrag()` / `updateDrag()` / `dropDrag()` / `cancelDrag()` 四个入口；★ **指针驱动 2026-10-17 已接线**（§24.2 #16）：`down` 落在 `Draggable` 上记阈值起点（**按下 ≠ 拖动**），`move` 过 6 逻辑像素阈值才 `begin`，`up` 放下；`feedback` 跟随浮层仍归 M4） |
| `src/Widget/Menu/` | `Drift\Widget\Menu` | ✅ `MenuBar` · `Menu` · `MenuItem` · `ContextMenu` | ✅ **2026-10-16 已落地**（§9.1 菜单行 + O-20 第二棵根渲染树）：`MenuBar` 纯组合（`Row`）· `Menu`/`MenuItem` **继承 `Button`**（命令 / 焦点 / 激活整套复用，同 `NavButton` 先例）· `ContextMenu` 是**声明对象**（无固定渲染形态 ⇒ 不是 Widget）；菜单打开 = `Menu::onOpen` 回调 → `Application::showMenu()`；关闭语义四条与右键最小集已接线（smoke 34 条）；★ **悬停高亮缺席**（要 `HoverHandler`，仍是 0 文件）—— 菜单项有光标变化、没有 hover 底色 |
| `src/Input/` | `Drift\Input` | ✅ `PointerEvent`（★ 2026-10-17 **四相位**：`PointerPhase`（Down/Move/Up/Leave，value = 桥 ABI 编号，`PointerEvent::fromBridge()` 是唯一映射读法）；`move` 驱动光标刷新 + 悬停派发 + 拖放过程，`up` 驱动拖放放下，`leave` 驱动悬停退出 + 拖放取消）· `KeyEvent` · `KeyChord` · `FocusNode` · **`InputEvent`** · `CursorShape`（★ 标记接口：`Platform::input()` 队列的元素类型，让 `poll()` 有单一返回类型 —— AOT 下比联合类型安全）· ✅ **`AcceleratorTable`** · ✅ **`ThemeChangedEvent`**（★ 2026-10-17 事件侧落地 + 界面重建接线，§24.2 #14 闭环）· ✅ **`ScrollEvent`**（★ 2026-10-17 滚轮通道：`x`/`y`/`delta` 逻辑字段，与 PointerEvent 同坐标系；`WM_MOUSEWHEEL` → 桥 → `Win32Platform::pump` → 队列 → `Application::scrollAt` 找最近视口调 `ScrollViewState::scrollBy`；★ **单位是"格"不是像素** —— 平台只做量化（WHEEL_DELTA/120），"一格值多少 px" 是框架策略（`Application::WHEEL_NOTCH_PX = 48` = 3 行 × 16px 行高，与 Windows 默认 `SPI_GETWHEELSCROLLINES=3`、浏览器每格 ~40–53px 同量级），在 `dispatchInput()` 换算；★ 修复前是 **1px/格 ⇒ "滑不动/不跟手"的根因**（一格 notch 只挪一个像素）；桥未暴露 SPI 系统值 ⇒ 常量 + 缺口登记）；✅ **`SemanticsTree`**（★ 2026-10-18 落地：§9 的数据层 —— `SemanticsRole` / `SemanticsNode` / `SemanticsTree` 三件住本层，`SemanticsBuilder` 住 `Drift\Widget`；控件在**构造 / 设值处给字段赋值**而不是覆写虚方法（§9 P3 去虚化陷阱）；smoke 12 条 + 反例自检通过；★ 2026-10-21 加 `Checkbox` / `Radio` / `Switch` 三个 case（选择类控件，§4.2）+ `Progress`（进度读数，§4.2））· ✅ `GestureArena`（@internal，2026-10-18 落地，形态见 §9.1）| **缺 0**（M3，路由链见 §9.1；★ **`AcceleratorTable` 2026-10-16 已落地**（§9.1 第 ① 环）：`make()->bind(chord, 命令名)`，重复绑同一组合 ⇒ Fatal（G2 冲突不静默）；`Application::accelerators()` 挂钩子，`dispatchInput()` 在焦点链**之后**查表（2026-11-03 顺序裁决：谁处理谁赢，`claimKeys()` 白名单已删）；让位规则 ①（IME 组合期）当前天然成立 —— 输入层还没有 `ImeEvent`，组合期按键根本不以 `KeyEvent` 到达；★ **`HoverHandler` 契约 2026-10-17 以 `Widget::hover(\Closure)` + `FocusManager::hoverTo()` 形态落地**（§24.2 #16）—— 声明住 Widget 基类（同 `dragTargetValue()` 模式）、派发住 `FocusManager`（沿命中链找最近声明者，**目标变化才通知** —— §9.1 语义 #5；O-20 三态里的 "Move" 不重复派发，无消费者不发明）；悬停目标离线（菜单关闭）由 `collect()` 显式退出；★ 2026-10-15 **输入层最小集落地** —— 四个值对象 + 路由住 `Drift\App\FocusManager`，见 §24.1 #38） |
| `src/State/` | `Drift\State` | `Subject` · `Subscription` · `SubjectBuilder` · `Cell` · `SubscribingWidget` | ✅ 5/5 |
| `src/Commands/` | `Drift\Commands` | `CommandRouter` · `CommandHandler` · `CommandArg` | ✅ 3/3 |
| `src/Theme/` | `Drift\Theme` | `ThemeData` · `ThemeScope` · **`SystemTheme`** | ✅ 3/3（★ 删掉孤儿名 `Theme`，见 §24.1 #26） |
| `src/Routing/` | `Drift\Routing` | `RouteTable` · `PageHost` · **`NoPage`**（`PageHost` 有一个实现文件 `PageHostState.php`） | ✅ 3/3 + 1 个实现文件（★ 删掉 `Page`，见 §24.1 #25；★ **`Offstage` 住 `Drift\Widget`** —— 原表把它挂在本行并标 ✅ 4/4，是 §24.1 #37 的唯一"标 ✅ 却无文件"） |
| `src/Errors.php` | `Drift\Errors` | 错误出口（★ 2026-10-15 补 `@api` —— §1.2 的中止序列第 ② 步**逐字要求**组合根调 `installFatalBox()`，而 `apps` 只许碰 `@api`，§4.3 末行） | ✅ |

★ **`cpp-src/` 不在 `src/` 内，不计入下面的清点**（2026-10-15 首次出现）：C ABI 桥目录。`<name>.stub.php` 是给 tpc 看的 **ABI 声明**（函数体全空），实现住在 `.cc` 里。★ **2026-10-30 改口径**：原写“两者成对、同名”，实际 tpc 只按扩展名收 `.cc`（`FileScanner::NATIVE_SRC_EXT`），stub 与 `.cc` **不必一对一** —— 一个 stub 可以对应多个 `.cc`（stub 只声明 PHP 可见的 ABI，实现按关注点分文件）。今天两对：`drift_win32.stub.php` + `drift_win32.cc`（建窗 / 消息泵 / 贴屏 / 定时器 / 剪贴板 / 对话框）· `drift_skia.stub.php` + `drift_skia.cc`（只画）。★ **目录按“平台边界”分**（2026-10-30，N5 起）：`cpp-src/core/` 放**零 `windows.h`** 的实现（线程池池体 `io_pool.*`），`cpp-src/win32/` 放平台实现（`io_wake.*` —— `PostMessageW` 到 `HWND_MESSAGE` 窗）。**依赖方向只能是 `win32 → core`**；反过来（core 引 win32）就是把平台差异重新焊死。三条纪律：① **桥不建控件、不做布局、不画任何东西**（§5.1 四条边界）；② `cpp-src/*.h` 绝不写 `using namespace php;`（§2.4 纪律 3）；③ **一帧只跨一次边界**（`drift_win32_present()` 贴整帧像素），不是 GUI 仓的"每条绘制命令过一次桥" —— 那是 2108 行的桥 vs 今天 600 行的差别。

★ **本表不穷尽，它回答"这一层该有什么"**：`src/` 实有 **185 个 PHP 文件**（其中 158 个含 `@api`），本表点名 **105 个**（**103 个有文件 / 2 个 0 文件**，2026-10-18 同口径复测：名字 = 表第 3 列枚举本体、括号旁注剥掉），**零矛盾** —— 修掉误挂在 `Drift\Routing` 的 `Offstage`（§24.1 #37）之后才成立；此前那句"零矛盾"本身是**假陈述**）；**80 个已实现文件未列入本表**：6 个封闭域 enum · 11 个控件 · 6 个 Element 实现 · `Layout` 侧 10 个 `Render*` 族成员 + `Overflow`/`FlexAlign` · `Text` 侧 14 个（含 §8.2 坑 6 的 6 个确定性参考实现）· `ButtonVariant`/`RenderBoxWidget` · `TextField` 的 2 个 `@internal` 实现（`TextFieldBox`/`TextFieldState`）· `Spinner` 的 2 个 `@internal` 实现（`SpinnerBox`/`SpinnerState`）· `Dock` 的 2 个 `@internal` 实现（`DockElement`/`AppBarElement`）· `App\FocusManager`（输入路由最小集，`@internal`）· `Errors.php`（★ 分类之和与总数有差，是手工分类的既有口径问题，待 `drift-doctor` 按 `src/` 自动清点取代）。（183 = 103 点名有文件 + 80 未点名，2026-10-18 同口径复测 —— ★ 分类明细那一串是 2026-10-16 的口径，此后的新增（菜单/拖放/浮层/滚动/Modal 五族的 `@internal` 实现与渲染盒）只并进总数、未重排分类；**明细归 `drift-doctor` 自动清点**；★ 上一轮这里写的 "52 个 … + Application/Frame" 两个数都错 —— `Application`/`Frame` 是**已点名**的，而族成员与 `Text` 目录的实现被漏数了）★ 漏登记的代价见 §24.1 #32/#33（`TextField` 全文 29 处引用却不在表里）；**穷尽清单归 `drift-doctor`（O-10）自动扫 `src/`**，本表是它的临时输入。<br>★ **上面这四个数可复现**（口径 = 名字出现在**表第 3 列的枚举本体**；括号里的旁注会被剥掉 —— 旁注会引到`FocusManager.php`（@internal 实现）· `DeterministicFontManager`（未列入本表）· `RenderSurface`（举例时提到别的层）甚至 `null`/`apps` 这类根本不是类型的词，不剥就虚高）：`drift-doc-src-audit` 技能的 `scripts/audit_refs.py --section 4.2`（本机未安装该技能时，用同口径脚本复测 —— 2026-10-16 即如此复测得 139/123/94）。**判据只能对着量具的数**，不接受手工同步（2026-10-16 手工同步曾把 131/117/100 三数全部写漂，复测才发现）。

### 4.3 依赖规则（lint 强制，违反即报错）

| 层 | 可以依赖 | 不许依赖 |
|----|---------|---------|
| `Geometry` | `Errors` | 一切 |
| `Platform` | `Geometry` / `Errors` / **`Rendering` / `Input` / `Theme`**（★ 只作**输出类型**，见下） | 上层任何东西 |
| `Text` | `Geometry` / `Errors` | Widget / Rendering / Platform |
| `Rendering` | `Geometry` / `Text` / `Errors` | Widget / Theme / Platform（表面由 App 注入。★ `ColorToken` / `MetricToken` 在这里 ⇒ **Widget 与 Theme 都能引用它们**，这是第 18 处缺口的修正） |
| `Input` | `Geometry` / `Errors`；**协议例外**：`Text\TextInputClient` 可被引用，但仅限类型签名（★ 2026-10-18 删掉 `Layout\SemanticsConfiguration`：语义走**标量字段**形态，不需要一个配置对象 —— src/ 与 tools/ 零引用，删它零成本） | Widget / Rendering |
| `Widget` / `Layout` / `State` | 下面全部；**协议例外**：`Theme\ThemeData` / `ColorToken` / `MetricToken` 可被引用，但**仅限 `BuildContext` 的类型签名**（§3.4）；`Commands\CommandArg` 可按**文件例外**引用（`Button::arg()` 携带命令参数，§11.4.3 —— 与 `State::handleCommand()` 签名同型，2026-10-16 补正：§A.2.10 / §A.3.7 样板要求控件带 arg，原表漏了这条边；lint 的 `FILE_EXCEPTIONS` 只登记 `Button.php` 一个文件） | Routing / Theme（**除该例外**）/ Commands（**除该文件例外**）/ App |
| `Commands` | Geometry / Errors / **State**（只做类型签名） | Widget / Layout / Rendering / Platform |
| `Theme` / `Routing` | Widget / Layout / State / Text / **Rendering** / Geometry | App |
| `App` | 全部 | apps |
| `apps` | Drift **公共 API**（标 `@api` 的） | Drift 内部实现（`@internal`，§10.1.4；有豁免标注 ⇒ warn） |

★ **为什么 `Platform` 行有三条指向"上层"的边（2026-10-15 补正）**：这三条全是**平台必须产出的输出类型**，不是平台想借用上层的功能 ——

| 边 | 强制它的签名 | 为什么不能更低 |
|----|------------|--------------|
| `Platform → Rendering` | `SurfaceProvider::surface(): RenderSurface`（§5.1 / §7.1） | `Rendering` 自己依赖 `Text`；把 `RenderSurface` 挪到 `Platform` 之下要先拆 `Text` |
| `Platform → Input` | `InputSource::poll(): ?InputEvent`（§9.1 路由链把平台输出逐字写成 `PointerEvent / KeyEvent`） | `Input` 依赖 `Geometry` + 协议例外 `Text\TextInputClient` |
| `Platform → Theme` | `Screen::systemTheme(): SystemTheme`（§5.1 注释 + §9.2） | `Theme` 依赖 `Widget`/`Rendering`，比 `Platform` 高得多 |

★ **为什么是层放行、不是按文件例外**：`Win32SurfaceProvider` / `Win32InputSource` / `Win32Screen` **各在一个文件里**，按文件放行等于给 `Platform\<Os>\` 每个文件都开一条 —— 那正是 §4.3 想避免的"例外扩散"。⇒ §4.3 原表把 `Platform` 行只写成"输入侧"（`Geometry`/`Errors`），漏了它的**出口**。

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

1. ★ **菜单不进 `Platform`**：原生菜单栏（`HMENU` + `SetMenu`）与自绘界面**风格割裂**（GUI 仓 manifest 教训：原生对话框退回 Win95 外观时与 Skia 界面并排非常突兀）。⇒ **菜单 = 普通 Widget**（`MenuBar` / `Menu` / `MenuItem` / `ContextMenu` 全走 §15 链式写法，放在 `Dock::top` 里），**零平台端口成本**。**原生菜单栏：明确不做**（风格割裂 + 无法与自绘主题统一 + 违反 §18）。★ **菜单“打开后浮在哪儿”归框架 —— 不归平台、也不归文档流**（2026-10-16 O-20 裁决，按方案 A）：`Menu` / `ContextMenu` 打开后挂**第二棵根渲染树** —— `Application` 持 `?Widget $menuWidget` + `?Element $menuElement`（与 `$root` 同构）；`Frame::run()` 的 settle / layout / paint 三段各加浮层分支（layout 用**无界约束**让面板自尺寸，再把渲染盒原点钉到锚点、夹进客户区）；`dispatchInput()` 的指针事件**先选根树**（浮层开着 ⇒ 优先归浮层；点浮层外 ⇒ 关且吞掉该次按下）。**零平台端口**。模态（`showModal()`，§12.1 第 6 条）复用同一槽位：紧约束盖满 + 面板自居中 + `ModalBarrier` 遮罩。★ **菜单半边 2026-10-16 已实现**：`Application::showMenu()` / `closeMenu()` + `Frame::run()` 的浮层 settle/paint 两段（layout 段住 `Application::placeMenu()` —— 它要锚点与客户区，见该方法的 docblock）+ `dispatchInput()` 的选根 / ESC / 右键三处；`RenderPopupPanel`（投影画到矩形外、统一宽按最宽项实测）+ `PopupPanel`（形体）；smoke 34 条断言覆盖六条关闭语义与落位。**模态仍未实现**（`Modal` 仍是 0 文件）。
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
        $platform = new \Drift\Platform\Win32\Win32Platform();
        // ★ 后端挂在**源**上，不挂 Application（§11.7）：换同步/线程池 = 改这一行。
        $src = new \App\Users\Store\HttpUserSource();
        $app = new UsersApplication($src);

        // ★ 循环归平台（§5.2 裁决 1）：`Application` **没有** run()，只提供
        //   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
        //   ★ `surface()` 返回 `?SurfaceProvider`（"不支持"在类型层表达，纪律 2），
        //     而 `attach()` 要的是 `RenderSurface` ⇒ **必须收窄一次**。
        $handle = $platform->window()->createWindow('Users', 900, 640);
        $platform->window()->show($handle);            // ★ 不 show 就永远看不到画面
        $provider = $platform->surface($handle);
        if ($provider === null) {
            \Drift\Errors::report('平台没给出渲染表面');   // Fatal ⇒ 不返回
            return;
        }
        $screen  = $platform->screen();
        $surface = $provider->surface();
        // ★ 首帧前定尺寸：`attach()` 内部就会跑一帧，尺寸为 0 那一帧会被丢弃。
        $surface->resize(
            (int) round(900.0 * $screen->scale()),
            (int) round(640.0 * $screen->scale()),
            $screen->scale(),
        );
        $app->attach($surface, $screen->viewport());
        while ($platform->pump()) {
            $app->frame($platform->now(), $screen->viewport());
        }
        // ★ 窗口状态读回 / 写回（`WindowStateStore`，§5.4）：启动一次、退出一次同步 IO。
    }
}
```

### 5.2 事件循环归属：循环属于平台，派发属于框架

```
OS 消息泵（`Platform::pump`，阻塞等待，不空转）
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
2. **`TaskHost` 是平台能力**（C++ 线程池）。**【实测】**（探针 E）：`std::thread` 可建（31–60 µs 返回，不阻塞）、`mutex`+`deque` 跨线程队列可用、`std::atomic` 可用。★ **探针 E 的"worker 能跑 PHP"只成立于纯标量/字符串子集**；**探针 K（2026-10-16）证明该子集之外全崩**（类/对象/属性访问，单线程即 SIGSEGV）⇒ 后端 A **当前不可实现**（§17.2.2）。**M1–M3 用后端 B（同步三态）**；线程池留作端口上的目标形态，等 tpc 补钩子。
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
    public function __construct(private string $path) {}   // 用户目录下的 kv 文件；'' = 平台没给目录 ⇒ 整体退化为空操作
    public function restore(WindowHost $w, int $handle): void;   // 无记录/读坏 ⇒ 用默认值，不报错
    public function persist(WindowHost $w, int $handle): void;   // 退出时一次
}
```

★ **两个端口方法（2026-10-18 随 store 一起落地，形状先定在这里）**：

```php
interface WindowHost
{
    /** 客户区左上角的**屏幕逻辑坐标**
     *  · `null` = 当前位置无意义（**最小化**）⇒ 别记
     *  · **窗口已销毁** ⇒ 给"最后已知几何"（壳缓存：每次活窗口查询顺手刷新 + WM_DESTROY 抓最后一下）
     *    —— `persist()` 就跑在窗口死透之后的退出路径上，问死句柄 = 永远问不到
     */
    public function position(int $windowHandle): ?Point;

    /** 一次原子还原几何（`Rect` + 最大化）；**显示器夹取归平台** */
    public function place(int $windowHandle, Rect $rect, bool $maximized): void;
}
```

★ **三处易错的顺序 / 归属**（都已在实现里踩过或绕过）：

1. `restore()` 必须在 `surface->resize()` **之前** —— resize 定首帧尺寸，而首帧就该是
   还原后的尺寸。放后面 = 用户先看到一帧默认尺寸再被拽过去。
2. resize 用还原后**读回**的 `clientSize()`（不是常量）⇒ 窗口大小与画面尺寸永远一致。
3. `Paths::userConfig($baseDir, $name)` 的 `$baseDir` 由组合根从 `Platform::configDir()`
   传进来 —— 目录是平台能力（§5.1），而 `getenv('APPDATA')` 在 PHP 侧被 §16.7 禁掉。
4. **退出路径上窗口已经死了**：WM_CLOSE 里壳是**同步** `DestroyWindow`，等
   `while (pump())` 跳出、`persist()` 开问时，`GetClientRect` 对死句柄必然失败 ⇒
   首版实现**一个字都写不出去**且无任何报错（静默到只能靠逐点落盘的诊断才看出来）。
   解法在壳侧：`drift_win32.cc` 维护最后已知几何缓存（活窗口查询顺手刷 + WM_DESTROY
   抓最后一下），死句柄查询回填它。`position()` / `clientSize()` / `isMaximized()`
   三个查询同此路径；最小化 ⇒ 缓存作废（坐标是屏幕外的），保留上一份状态。

**三条纪律**：

1. **只在启动 / 退出各一次同步 IO**（不在帧内）⇒ **不需要 `TaskHost`**。
2. **恢复失败必须静默降级到默认值**，不报错 —— 这是少数**允许静默**的场合（显示器拔了、分辨率变了、配置文件被删，都不该让应用起不来）。★ 这是 G2 的**显式例外**，写进 §24.3 例外登记表以免被 lint 误判。
3. **存储后端可换**（文件 / 注册表 / 平台偏好），但**不进 `Platform` 端口** —— 因为换它不影响"谁能依赖谁"的分层，只影响一个 `Drift\App` 内部实现。

★ **2026-10-18 落地形态**：kv 文本文件（`v`/`x`/`y`/`w`/`h`/`max` 六行，带格式版本号；
版本不符或任一项不是数 ⇒ 整份降级）。落点 `%APPDATA%\Drift\<app>.window`
（壳用 `SHGetKnownFolderPath` 取目录、**只给路径不创建**；`shell32.lib` 已进
`build/base.yml`）。smoke 12 条夹具覆盖：往返 / 坏字段 / 版本不符 / 缺文件不刷
Diagnostic / 空路径空操作 / 最小化不写 / `max=0` / 非正尺寸。
★ **真机验证（2026-10-18，`users.exe` + probe-grab）**：RUN1 搬到 (400,300,1000x700)
⇒ 优雅关窗 ⇒ 状态文件 `x=408 y=331 w=984 h=661 max=0`（客户区原点含 8px 边框 +
31px 标题栏，与逻辑换算一致）；RUN2 启动 `rect=400,300,1000,700` —— 位置/尺寸
**逐像素**还原，无残留进程。`showcase` 两个 Bootstrap 接线相同。

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
| Per-monitor DPI | `Screen::scale` 变化 ⇒ `DpiChanged` 事件 ⇒ **触发 relayout，不重建窗口**；窗口跨屏拖动 = scale 变更 = 布局事件。★ **落地（2026-10-16）**：进程 DPI 感知 = **Per-Monitor V2**（`cpp-src/drift_win32.cc` 的 `enablePerMonitorDpi()`，`GetProcAddress` 动态取以避开 `WINVER`）；`Screen::scale` 走 `GetDpiForWindow`（**每显示器**，退 `GetDeviceCaps` 为降级）；外框走 `AdjustWindowRectExForDpi`。★ **relayout 不需要显式触发**：布局本就每帧全树跑（§13.3）⇒ `dpi` 事件在 `Platform` 层被丢弃（`Win32Platform::pump()`），表面尺寸/缩放由随行的 `WM_SIZE` ⇒ `resize` 事件驱动（§24.1 #43） |
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
   - **`SkiaCanvas`** —— **`DisplayList` 的回放器**（✅ 2026-10-16 落地，M1 遗留 ②）：**几何（行基线 / 笔位）全来自 PHP 的 `Paragraph`**，桥（`cpp-src/drift_skia.{stub.php,cc}`）只逐字形 `drawSimpleText` ⇒ **不链 SkParagraph / ICU**（`icudtl.dat` 不需要）；GUI 仓实测 `SkCanvas::MakeRasterDirectN32` 画到离屏 bitmap、`SetDIBitsToDevice` 贴到 HWND（贴屏仍只走 `drift_win_present()` 一处）；
   - `RasterCanvas` —— **软光栅**：无头预览、CI、测试（无 OS 依赖 ⇒ §14 确定性的物质基础）。
2. **`DisplayList` = 绘制命令即数据**。`paint()` 的产物不是"立即画到屏上"，而是录制成命令序列，由 surface 执行。收益：可重放（golden）、可快照（devtools 传输）、可跨进程（将来的 Inspector）、为多线程光栅铺路。★ 这是 G3 在渲染侧的落点。
   > **★ `DisplayList` 总是录制，生产路径也走它**（不是只在 dev 录制）。**理由**（三条）：
   > ① §14.1 第 2 条已经承诺"渲染是纯函数 ⇒ 录制成 `DisplayList` ⇒ 可重放、可 golden 比对" —— 只在 dev 录制会让这条承诺**在生产上不成立**；
   > ② §20 第 1 条"量具双向自检"的核心就是"量具与生产走同一条路" —— 只在 dev 录制 ⇒ golden 通过 ≠ 生产正确；
   > ③ **成本可控**：GUI 仓的教训是"**每帧全画**"（不取 Flutter 的 `shouldRepaint`）⇒ 本来就没有"跳过绘制"的优化可损失；`DisplayList` 只是把"直接画"变成"先记录再画"，多出的成本是**内存 + 一次函数调用转发**。
   > **★ 配套契约（必须，否则反而变慢）**：**缓冲复用 —— 每帧零分配**（复用同一块 `DisplayList` 缓冲，不 `new`）。一条命令约 16–32 字节，一帧几千条 ⇒ 几十 KB/帧。
   > **★ 配套断言**：录制结束时**命令栈深度必须为 0**（`save`/`restore` 配对），否则缓冲复用会把上一帧的未闭合栈带到下一帧。写入 W 规则（§16.6.2 W8）。
   > **★ 与 §7.3 的分期不冲突**：M1 = "录制 + 立即回放"；M5 的脏矩形 = "按需重放"（同一份 `DisplayList`，只是不再全量重放）。
3. **坐标约定不变**（GUI 仓验证过）：父级在绘制子盒前已 translate 到盒原点，`paint` 内部以 (0,0) 为盒左上角作画；`save/restore`（变换栈）与 `pushClip/popClip`（裁剪栈）是**两条独立的栈**：`restore()` 不还原裁剪、`popClip()` 不动偏移，W8 分别计深 —— 2026-10-17 修掉软光栅把裁剪并进变换栈的偏离（同一份命令流两后端出不同像素）后，三个实现同解此契约。
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
   → ★ **2026-10-16 实测**（报告 `SKIA-BUILD.md`）：ICU **甩不掉** —— `skshaper.lib` 硬引用 `SkUnicodes::ICU::Make()`（缺它即 `LNK2019`）⇒ 用了 SkParagraph 就没有"不带 ICU"的选项；且它的**边际体积只有 ~1.86 MB**（4.03 → 5.89 MB，含 HarfBuzz/SkShaper/SkParagraph 整条文本栈）。
   → Drift 对策（**收敛为一条**）：把 ICU 数据（`icudtl.dat`，778 KB）**打包进 exe**（`embedded-files` 或 PE 资源，【手册】§10）⇒ 交付仍是单文件。★ 但当前预编译包**没有干净路径**（ICU 公开头未随包、`SkUnicode_icu.h` 只暴露 `Make()`）⇒ 若一时做不成，**先接受"与 exe 同目录分发"**（ICU 按 **cwd** 找文件），不阻塞 `SkiaCanvas`。原第 ② 档"退最小实现"**否掉**（省不到 1 MB，不值得自实现断行规则）；原第 ③ 档（自实现禁则）降为"若将来去掉 SkParagraph"的备选。
   → ⏳ **开放项**（§24.2 #6）：剩"`icudtl.dat` 能否内嵌"一项待验。

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
| **手势** | `GestureArena`：多个识别器可同时竞争（拖动中的 tap 让位），胜出者独占直到手势结束；识别器是**无状态的判定函数 + arena 成员资格**，不是每控件一份定时器。★ **2026-10-18 已落地**：`GestureArena` + `GestureRecognizer`（`Drift\Input`，@internal）—— `Application` down 分支仅当命中链有 `Draggable` 声明者时开 arena（成员 Tap + Drag），Drag 过阈值 claim ⇒ Tap 让位，无人 claim ⇒ up 第一个成员胜（Flutter 同口径）；激活因此从 down 推迟到裁决，修"拖可拖控件时命令在 down 先响"，零平台改动、可头侧断言 |
| **指针捕获** | 按下即捕获（Win32 只把鼠标消息发给光标下的窗口 ⇒ 不捕获则按下态永不释放、拖拽永不结束）；`WindowHost::setCapture()` |
| **光标形状** | `RenderObject` 声明 `cursor`（`CursorShape` backed enum：`Default`/`IBeam`/`PointingHand`/`SizeAll`/`SizeNS`/`SizeWE`/`Wait`/`NotAllowed`）；`EventLoop` 在 `WM_SETCURSOR` 时问**命中链**，取最深声明者 ⇒ `WindowHost::setCursor()`。**不给控件开自绘光标的口子**（自绘光标要自己画、自己跟帧，得不偿失） |
| **快捷键 / 加速键** | `Drift\Input\AcceleratorTable`，挂在 `Application` 上（**应用级数据，不是平台能力** ⇒ 零新端口）。`EventLoop` 在**焦点链之后**查表（★ 2026-11-03 顺序裁决，见下）。冲突处理两条：① **IME 组合期不吃快捷键**（否则中文输入会误触）—— 当前天然成立（组合期按键不以 `KeyEvent` 到达）；② **编辑命令优先**。★★ **2026-11-03 顺序倒过来（破坏性）**：原来是"加速键先 + `FocusNode::claimKeys()` 白名单让位"。白名单是**逐键列举**的 ⇒ 列不全就是死键（`Table` 非编辑态的 `Ctrl+A`/`Ctrl+C` 一直是死码 —— 应用一绑那两个键表里就没反应），而"只在编辑时才抢"这种**动态让位**静态声明根本表达不了。现在：⓪ 编辑命令环（`Ctrl+C/X/V`，要平台剪贴板 ⇒ 只能在 `App` 层）⇒ ① **焦点链**（`keyboard()` 声明者 → `EditableText`）⇒ ② 加速键 ⇒ ③ 动作激活。"谁处理谁赢"：控件认得就吃、认不得返回 `false` 自然落给加速键，**不需要声明也就不会漏**。参照 WPF/WinForms（焦点优先）与 Flutter（`Shortcuts` 在树里、内层赢）；Qt 的 `QShortcutMap` 是加速键优先，但要靠 `Qt::WidgetShortcut` 上下文解决同一个问题 —— 本仓不引那张上下文表。★ 判据 `tests/Feature/Input/KeyRoutingPriorityTest.php` 三条**成对**验（控件吃自己的 / 不吃认不得的 / 认领但此刻不处理时放行）。★ **2026-10-21（#50）**：`Ctrl+C/X/V` 的四条门控（焦点是编辑中的 `TextFieldState` / 平台递了剪贴板 / C·X 有选区 / V 剪贴板有文本）任一不满足 ⇒ 整族**回落**（不吞键 ⇒ 没有剪贴板时 `Ctrl+C` 仍能被应用绑成"复制选中行"）。★ **2026-11-03 撤销/重做落地**：`Ctrl+Z` / `Ctrl+Y` / `Ctrl+Shift+Z` 归 `TextKeymap`（与其余编辑键同一张表），栈住在 `TextDraft` 本身（快照式，非逆操作），收益面是**所有**用它的控件 —— 输入框、表格单元格编辑、`ComboBox` 搜索框，零额外接线。★ 与 `C/V/X` **不**同路：那几个要平台剪贴板，必须在 `App` 层；撤销纯文本状态，§4.3 允许 `Input` 看见 `Text`。指针框选（down/move/up 三相位）同住 `Application` |
| **菜单** | **自绘控件**（`MenuBar` / `Menu` / `MenuItem` / `ContextMenu`），零平台端口。原生菜单栏**明确不做**（理由见 §5.1）。★ **打开形态 = 第二棵根渲染树**（O-20 已裁，机制详见 §5.1 裁决 1）：浮层挂 `Application`（`?Widget $menuWidget` + `?Element $menuElement`，与 `$root` 同构），paint 在主树之后（**绘制顺序即层叠，无 z-index**），指针事件**先选根树**；关闭语义四条：点浮层外 ⇒ 关且**吞掉该次按下** / 抬起在浮层上 ⇒ 命令分发**之后**才关 / **ESC 在 `dispatchKey` 之前**拦 / 浮层开着时**滚轮吞**。上下文菜单入口 = **右键最小集**（`PointerEvent` button；★ 不依赖 `GestureArena` —— 它 0 文件，“已支持”是旧文档误记，2026-10-16 随 O-20 改正。★ **实现已落地**（2026-10-16）：`dispatchInput()` 指针分支先选根树 + 沿命中 Element 父链找最近的 `contextMenuValue()` 声明者；**位移阈值消歧缺席** —— 那要 `move` 事件（M4），当前 `down` 即弹） |
| **焦点** | `FocusNode`；焦点是**渲染态**（不放业务值） |
| **焦点遍历顺序** | 默认 = **文档序（深度优先）**，与 Flutter / Qt 一致。容器可 `->skipFocus()` 整体跳过（如 AppBar 工具按钮组，**显式**，不隐式）。需定制时用 `->focusOrder(int)`，同一父容器内**稳定排序**。★ **lint 判据**：同一容器内混用"有 `focusOrder`"与"无 `focusOrder`"的子节点 ⇒ 报错（否则隐式相对顺序有歧义，属 G2） |
| **IME** | `TextInputClient` 协议（§8.4）；caret / anchor / 撤销栈归属 State 的渲染态域（GUI 仓 V1 结案结论：外部不可能知道"用户点到哪、按了几个左箭头"） |
| **无障碍** | ★ **数据层已落地**（2026-10-18）：`SemanticsTree` 由 `SemanticsBuilder` 遍历 **Element 树**生成（不是 RenderBox 树 —— 一个控件可能产出多个盒，走渲染树会重复命中同一个控件）；节点承载角色 / 标签 / 动作 / 取值 / 状态；**树是数据** ⇒ 可序列化 ⇒ 探针可断言（smoke 12 条）。★ **声明形态与原规格不同**：原写"从 RenderObject 的 `SemanticsConfiguration` 生成" ⇒ 改为**控件在自己的构造 / 设值处给 `Widget::semantics()` 赋值字段**（下面的 P3 陷阱），故 §4.3 `Input` 行的 `Layout\SemanticsConfiguration` 例外已删。Windows UIA 桥接 **P2 数据流已落地**（2026-10-19：`SemanticsTree::toArrays()` → `WindowHost::pushSemantics` → `drift_win_uia_push` → C provider，真机跨进程读到语义 Name；树导航 `Fragment` 已落地（P3，真机 FindAll Descendants 遍历整棵语义树）；`RaiseAutomationEvent` 已落地（结构变化，count 变触发，真机收到 2 次；焦点变化，`SemanticsBuilder::build(root, focused)` 打标、push 时焦点索引变触发，真机 Tab 切焦点收到 5 次）） |

> **P3 陷阱**：语义**不能**靠覆写 `protected` 钩子实现（子类覆写可能被去虚化 ⇒ **静默不跑**）。语义写成**字段/构造参数** —— GUI 仓的 `SemanticsBuilder` 已是这个形态。

> ★ **落地现状（2026-10-15）**：链上的**最小集**已跑通 —— `Drift\Input\{KeyChord, KeyEvent, PointerEvent, FocusNode}` 四个值对象 + `CursorShape`（鼠标指针形状 backed enum，命中链消费）+ `Drift\App\FocusManager`（路由宿主）。它兑现了三件事：
> ③ **焦点链**（`Tab`/`Shift+Tab` 按文档序；`skipFocus` 整棵子树剪枝；`focusOrder` 同容器稳定排序）；
> **命中测试**（`RenderBox::hitTest` 深度优先取最深盒；越界 ⇒ `null`）；
> **命中 → 焦点**（★ 命中的是**最深的盒**，焦点链收的是**声明可聚焦的 `Element`** —— `TextField` 是 `StatefulWidget`、自己不产盒，两者**常不是同一个节点** ⇒ 必须沿父链上溯到最近的焦点链成员，否则"点得到、却给不了焦点"且**不报错**）；
> 以及 §9.1 让位规则 ②（**编辑命令优先于全局快捷键**）—— ★ **2026-11-03 换实现**：原来是声明面（`->claimKeys()` / `FocusNode::claims()`），现已删；改由**路由顺序**表达（焦点链先、加速键后），"谁处理谁赢"。
> **仍未落地**：① IME 组合期 · `GestureArena`（★ 语义的**数据层**（`SemanticsTree` + `SemanticsBuilder`）**2026-10-18 已落地** —— `Button` / `TextField` / `ScrollView` / `LazyColumn` / `Modal` 五个控件带角色；UIA **桥接**仍 0 文件）（★ `CursorShape` 已随 M1 输入层落地、`AcceleratorTable` 与菜单 / 拖放的输入侧 2026-10-16 已落地，**指针四相位与 `HoverHandler` 契约 2026-10-17 已落地**（§24.2 #16）—— 菜单项悬停底色自此可写，见 §4.2 `src/Input/` 行"缺 2"、§753）。
> ★ **`FocusManager` 是路由宿主的第一个形态**：§4.2 把"事件路由"归 `EventLoop`（`Drift\App`，M4）；M3 先把它落成独立类，M4 的 `EventLoop` 到位后由它**吸收/扩展**（§4.2 App 行已登记为 `@internal` 实现文件）。

**用法示例**（完整版见 §A.3）：

```php
// 加速键：应用级注册，零平台端口
// ✅ `AcceleratorTable` 已落地（2026-10-16，§4.2 `src/Input/` 行）—— 形状即此。
//   让位规则 ②（编辑命令优先）在 `Application::dispatchInput()` 接线：
//   ★ 2026-11-03 起靠**顺序**：焦点链（`keyboard()` → `EditableText`）先跑，
//     控件认得的键它吃、认不得的才落到加速键。`claimKeys()` 白名单已删。
//   ★ `Ctrl+C/X/V` 仍在更前面的 ⓪ 环 —— 它要平台剪贴板（`State` 拿不到平台），
//     门控不满足整族回落（见 §24.2 #32）。
final class UsersApplication extends Application
{
    public function accelerators(): AcceleratorTable
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

**★ `SubjectBuilder` 的类形状**（2026-10-15 补 —— §23.2 O-05 复核发现 ⑥ 的挂账项：这个 `@api` 契约原语此前**只有用法、没有成员表**。已按 `src/State/SubjectBuilder.php` 核验）：

| 成员 | 签名 | 说明 | 标注 |
|------|------|------|------|
| `make` | `static make(Subject $source): self` | **`source` 必填** —— 订阅一个“没东西”没有意义；缺了 ⇒ `Errors::report`（响亮失败，不建一个空订阅者） | `@api` |
| `select` | `select(callable(Subject): mixed $f): self` | 切片函数；**可省**（省了 ⇒ 整个 `Subject` 都是依赖，任何通知都重建） | `@api` |
| `builder` | `builder(callable(mixed): Widget $f): self` | 由选出的值造子树；**必填**（没给 ⇒ `Errors::report`：订了却没有子树 = 一个空盒子） | `@api` |
| `source()` / `selectFn()` / `builderFn()` | `(): ?Subject` / `(): ?callable` / `(): ?callable` | 给 `SubjectBuilderState` 读的三个口子；`null` = 没给 | `@internal` |
| `createState()` | `(): State` | 返回同文件的 `SubjectBuilderState`（`@internal`，不进公共面） | `@api` |

★ **三条调用约定**（住在 Element/State 侧，不是控件自己的方法）：
1. **订阅只在 `initState()` 做一次**（§11.3 RT-1 / A4）；`build()` 体内**不订阅** —— 否则每重建一次多订一份。
2. **首次取值不过门控**：`initState()` 里直接调一次 `select` 把旧值槽填上 —— 否则第一帧的子树拿不到值。
3. **门控比的是两次 `select` 输出**（不是 `Store` 新旧值）：相等 ⇒ `markDirty()` 不调、`builder` 不被调用。

★ **与 §A.2.11 `StatusBar` 的分工**：`SubjectBuilder` 是**框架原语**（切片 + 门控 + 订阅袋 + dispose 全退都是现成的）；`StatusBar` 是**手写同等机制**的应用私有控件 —— 只在“订阅之外还要做点别的”时才自己写。★ 反过来也成立：需要订阅时**先问能不能用 `SubjectBuilder`**，别默认手写（§20 纪律 4：契约能力必须在示例里出现过）。

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
    /** @return iterable<string, CommandHandler> `命令名 => handler 实例` 的字典 */
    public function commands(): iterable
    {
        $nav  = new NavigationCommands($this->app);          // 多命令共用 ⇒ 提局部变量（E 的代价）
        $user = new UserCommands($this->users, $this->app);

        return [                                             // handler 是**具名类的实例**，不是闭包
            'nav.users'   => $nav,
            'nav.signup'  => $nav,
            'user.del'    => $user,
            'form.submit' => new SignupCommands($this->form, $this->src),
        ];
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

> ★ **2026-10-30 更新**：下面的手写三态已被 `AsyncCommand` + `AsyncValue` 取代
> （`LoadStatus` 已删除）。同一份状态机在 `UserStore` 里被手写第二遍，是重复的
> 不是分工 —— 收敛过程见 `docs/ASYNC-DX-2026-10-30.md` §8。当前形态：
> Store 持**一条** `AsyncCommand`，构造器订一份（命令换值 ⇒ 我变）；
> 四态由 `AsyncValue` 携带（**含载荷与消息**），"失败时行还是上一次的"由
> `if ($value->hasData())` 一句话表达，不再需要 `applyPage()` 那层注释来守。
>
> ```php
> final class UserStore extends Subject
> {
>     public function __construct(private UserSource $src) {
>         // 命令换值 ⇒ 我变 ⇒ 页面照旧只订 Store 一个
>         $this->page->subscribeFor($this, fn() => $this->absorb());
>     }
>
>     private AsyncCommand $page;   // idle / loading / data / error + 载荷 + 消息
>     private array $rows = [];
>
>     public function load(int $p = 1): void { $this->page->run($p); }
> }
> ```
>
> ★ 下面这段原文保留作**历史记录**，不要再照它写新代码。
>
> ★ **2026-10-31 追加两条**（见 `docs/ASYNC-DX-2026-10-30.md` §10）：
> ① `AsyncValue` 的**载荷可跨状态存活**（`carriedFrom()` / `hasValue()` /
>    `isRefreshing()`）⇒ 刷新与翻页时列表**不再闪空**，`fold()` 把"刷新中"路由到
>    `data` 支（`loading` 支因此只表示首屏）。
> ② **"读 Store 的地方必须在 `select` 之内"这条纪律终于有了判据**：`UsersPage` 的
>    「刷新」按钮原先直接在 `build()` 里读 `$store->page()->isRunning()`，而页面是
>    `StatelessWidget`（`build()` 只跑一次）⇒ 首屏正在取数那一刻的 `true` 被永久
>    浇筑进控件，按钮**再也不亮**。见 `tests/Feature/Widget/UsersRefreshButtonTest.php`。
>
> ★ **2026-10-31 追加**（见 `docs/ASYNC-DX-2026-10-30.md` §13）：一条命令**两个动作** ——
> `run($arg)` 是**替代**（翻页 / 刷新，一次只看一份数据），`append($arg)` 是**追加**
> （加载更多 / 无限滚动，结果接在后面）。追加需要造命令时给一条合并规则
> （`asyncCommand($task, $combine)`），没给就 `append()` 会当场抛 —— 静默退化会让
> "加载更多"吃掉前面的行且**不报错**。两个动作共享同一套去重 / 作废 / 取消 / 重试 /
> 卸载兜底，所以**不另开类**（论证见 `docs/ASYNC-ARCH-2026-10-31.md` §10.2）。

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
>
> ★★ **2026-10-18 已兑现**：`apps/users` 装上 `ProcessTaskHost`（§17.4.5）之后，`loading` **真的看得见**了 ——
> 真机探针（`tools/probe-async.php`）的数字：窗口刚出现时客户区墨量 **0.0%**（只有一个小 Spinner），
> 1.2s 慢 HTTP 回来后 **8.3%**（15 行列表）。⇒ 上面这条从"三态的缺陷"降级为"**同步后端专属**的边界"：
> 三态本身没问题，是"同步 IO + 帧末统一 flush"让它无帧可画。

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

   ★ **框架内的封闭域 enum 清单**（“禁 `default`”这条判据的落点 —— 没有清单，判据不可逐条核验；2026-10-15 按 `src/` 核验，这 11 个全是 `@api`）：

   | enum | 住哪 | 取值 | 谁消费 |
   |------|------|------|--------|
   | `TextAlign` | `Drift\Text` | `Start` / `Center` / `End` / `Justify` | `TextStyle::withAlign()` |
   | `FontSlant` | `Drift\Text` | `Normal` / `Italic` / `Oblique` | 度量 / face 选择 |
   | `FontWeight` | `Drift\Text` | `Thin`(100) / `ExtraLight`(200) / `Light`(300) / `Regular`(400) / `Medium`(500) / `SemiBold`(600) / `Bold`(700) / `ExtraBold`(800) / `Black`(900) | `TextStyle::withWeight()` |
   | `TextRole` | `Drift\Text` | `Unset`(0) / `Title`(1) / `Heading`(2) / `Body`(3) / `Caption`(4) / `Error`(5) | `Text` 的角色糖（`->title()` 等） |
   | `FlexAlign` | `Drift\Layout` | `Start` / `Center` / `End` / `Stretch` | `RenderFlex` 交叉轴对齐 |
   | `Overflow` | `Drift\Layout` | `Clip` / `Scroll` / `Wrap` / `Report` | `RenderFlex` 溢出策略 |
   | `PaintStyle` | `Drift\Rendering` | `Fill` / `Stroke` | `Canvas` 画笔 |
   | `ButtonVariant` | `Drift\Widget` | `Default` / `Primary` / `Danger` | `Button` 变体 |
   | `ColorToken` | `Drift\Rendering` | `Pane` / `Chrome` / `Text` / `Muted` / `Border` / `Accent` / `Danger` / `Hover` / `Pressed` | 主题 token 键（§11.5） |
   | `MetricToken` | `Drift\Rendering` | `Padding` / `Gap` / `Radius` / `BorderWidth` / `FontSize` / `FontSizeSmall` / `FontSizeTitle` / `FontSizeHeading` / `RowHeight` | 主题 token 键（§11.5） |
   | `SystemTheme` | `Drift\Theme` | `Light` / `Dark` | §9.2 系统主题 |

   ★ **`UnhandledMatchError` 是 PHP 内置类，不是 Drift 的**（2026-10-15 在 8.5.11 实测：`match` 无匹配臂且无 `default` ⇒ 抛 `\UnhandledMatchError`）⇒ “封闭域禁 `default`”这条纪律**不需要框架写一行代码**就成立；Drift 要做的只是**别在封闭域上写 `default`**。★ 因此 `UnhandledMatchError` **不会**出现在 §4.2 目录表里 —— 它不是框架类型。
   ★ **`TextRole::Unset` 是 §15.2 哨兵三档定则“枚举语义 ⇒ 加显式 enum case”的落点**：未设置不是 `null`、不是 `-1`，是一个**必须被 `match` 处理掉**的合法 case。

   ★ **两个规模档，不是两套语义**：小应用直接在 `Root` 里 `match`（零分配、零新概念）；需要 **devtools 列举 / 单测 / 动态装配**时才升级为 `RouteTable`（表是数据，值是页面工厂闭包）。二者产出的是同一件事 —— "名字 → 页面 Widget"，`match` 只是它的最简形态。§12.2 用的是后者。

3. **外壳 + 内容槽**：`Dock(top:, left:, right:, bottom:, center:)`；外壳**持久**（不随页面重建），只有 `center` 槽换；内容区必须可滚动（⇒ `ScrollView`，§24.2 #15 已落地；`apps/users` 列表页是样板）。
4. **`Offstage` 原语**：隐藏但保留挂载 —— 跳过 layout / paint / hitTest，Element / State 全部保留 ⇒ "切走再切回要不要保留状态"的答案是**保留**，且代价为零。
   - ★ 为什么不是 `IndexedStack`：它"全部 layout、只 paint 一个"，而 layout 是性能瓶颈，隐藏页也排一遍是反方向。
   - ★ 为什么不是 `Stack + Opacity(0)`：隐藏页照样参与 layout，且 Stack 取最大子节点 ⇒ 隐藏页能把可见页撑歪。
5. **页面间引用判据**（优先级从高到低）：**① 共享数据源**（各自订阅同一个 Subject，页面互不认识）→ **② 命令**（跨树、要复用、要审计）→ **③ 按 Key 取 State**（命令式、数据表达不了时）。页面参数走共享 store（`selectedId`），**不从根逐层钻参数**。
   - **⚠️ 诚实边界（代价两条）**：
     ① **参数会残留** —— 导航离开后 `selectedId` 还在 store 里，回到列表页时"上次选中的那行"仍然高亮。要清就得显式清（或在页面 `dispose` 时清），这是**手写的**，框架不管。
     ② **带参页面无法独立预览** —— §12.2 宣称的"每页可独立预览"对**不带参页面**成立；带参页面必须先喂一个假 store（`selectedId = 1`）才能挂载。⇒ 工具链的"单页预览"要支持**注入 store 夹具**，这不是免费的（§A.5 给了夹具样例）。
   - **为什么仍然选这条路**（而不是"从根逐层钻参数"）：钻参数的代价是**每一层中间容器都要知道它不关心的参数**（`Root` → `Dock` → `PageHost` → 页面），改一个参数要动 N 个签名；而残留/预览是**两个局部问题**，各有一个局部解法。
6. **导航栈不做**：桌面主形态 = 外壳 + 内容槽 + **模态**（`Application::showModal()`）；只有出现真实“多级钻取”需求才做 Navigator —— 不需要栈的应用硬上栈 = 多一个必然出错的全局状态。★ **模态的机制 = 浮层槽位**（O-20 已裁）：`showModal()` 把内容挂上第二棵树、**紧约束盖满**客户区、`ModalBarrier` 半透明遮罩（`Color::fromRgba` / `Paint::withAlpha` 契约层支持 alpha；光栅后端是否真混色**未验** ⇒ 实现第一天验）、面板控件自己居中；**关不关仍归应用**（`Modal` 只发命令，绝不自己 `closeModal()`）。
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
            ->overflow(Overflow::Clip);
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
| C5 | **溢出策略是契约** | 每个容器必须声明行为：`clip / scroll / wrap / report`；**外壳类容器必须有溢出策略**（框架侧如 `AppBar`，应用侧如 `StatusBar` —— “外壳类”是**类别判据，不是类清单**，见 §24.1 #36），且必须是布局期行为而非宽度阈值 | 窄屏工具栏溢出缺陷 |
| C6 | **违反 ⇒ 响亮失败** | 无界约束传给有内禀尺寸的子、约束违反、溢出未声明 ⇒ `Errors::report`（**Fatal 级，release 也中止**） | G2 |

★ **C5 的"每个容器"的边界**（消歧）：`Overflow` 有**默认值**（`Overflow::Clip`），只有"可能超出给定约束"的容器（`AppBar` / `StatusBar` / `Row` / `Dock` 槽 / 自绘容器）**必须显式声明**；`Text` / `Icon` / `Padding` 这类"尺寸由内容或子决定、不会超"的容器用默认值即可。判据：**这个容器在约束不足时，是否必须"选择一种舍弃方式"？** 是 ⇒ 显式声明。（★ 这条在 v2 之前是隐性的，见 §23.1 第 9 项。）

### 13.2 Widget → RenderBox 构造契约（RT-2 的根治）

渲染控件提供 `createRenderObject(): RenderBox`；Element 挂载时调用它建盒；`(type,key)` 未变而配置更新时**用新 Widget 重新调用同一函数**造新盒替换。

Widget 不可变（§15 的 Seal-on-Mount 保证）⇒ 参数只会整体换新，**不存在"字段级半同步"** —— GUI 仓"两份手写字段赋值 ⇒ 首次正常、刷新变样，**且静默**"因此结构上不可能。字段对齐由 lint 比对 Widget 字段集与 `createRenderObject` 读到的字段集（W2 / R12）。

### 13.3 relayout 脏边界

Element 层有脏边界（脏边界重建：干净分支一次 build 都不跑）；**Render 层没有**（`relayout()` 永远全树）。列为 **M5+ 与脏矩形成对做** —— 单独做任一个，瓶颈只是搬到对面（GUI 仓自己定的门禁：等症状）。★ 2026-10-18 量具补齐（§13.5）：生产 4.5ms/帧无症状 ⇒ 继续驻留，重估触发条件见 §13.5

### 13.4 虚拟化（M5）：`LazyColumn`

**缺口（实测）**：`Each` 是全量列表 —— `items()` 在**构建期**把每个 item 造成一个
Widget，1000 行 ⇒ 1000 个 Widget / Element / 盒。单帧成本（`_build/bench-list3.php`，
Card+Row 两段文本的行，只量 mount+layout）：

| 行数 | mount | layout | 单帧 |
|---|---|---|---|
| 200 | 3.1ms | 14.1ms | ~17ms |
| 1000 | 19.5ms | 75.8ms | **~96ms（10fps）** |
| 2000 | 46.5ms | 152.7ms | **~200ms（5fps）** |
| 20000 | 480ms | 2313ms | ~2.8s |

⇒ 约 **0.076ms/行，线性增长**。这就是 M5 出口判据「长列表滚动不掉帧」的缺口：
**视口外的行仍在构建和布局**（绘制侧已裁，所以 SVG 体积不随行数涨）。
**同一个量具也纠正过一个误会**：`SvgSurface::beginFrame/endFrame` 有 ~500ms
的固定开销（与内容无关）⇒ 量列表规模时**不要走 SVG 面**，否则 100 行也显示 1 秒。

**做法**：`LazyColumn::make($itemExtent)->items($items, $builder)` —— 只造**视口
覆盖到的行**，其余行既没有 Widget 也没有 Element 也没有盒。同一张数据表
（20000 行）下仍是 **12 行 / 0.2ms**。示例台 `BigListPage` 是它的**真实消费者**。

| 决策 | 取值 | 理由 / 被否方案 |
|---|---|---|
| 行高 | **必填** `itemExtent` | 定高才能 O(1) 算 `first = floor(offset / extent)`。变高行要一份行高测量缓存 + 数据变化时的失效策略 —— 今天**没有真实消费者**逼它（§24.2 #25：未被执行的分支等于未实现）。行高由 `SizedBox` 强制，内容比它高被裁、比它矮留白（与 Flutter 的 `itemExtent` 同语义） |
| 窗口何时算 | **build 期** | 框架是 `build → settle → layout`，布局期拿不到「还没造的 Widget」。视口高度取**上一帧**盒的实测高度；首帧还没有盒 ⇒ 退回 `ctx->height`（窗口高），它是任何嵌套视口的**上界** ⇒ 首帧一定造多不少，不会白 |
| 它是什么 | **自己就是滚动容器** | 窗口依赖偏移、偏移依赖视口，两者都在它这里 ⇒ 用法上**替换** `ScrollView + Column + Each`，而不是与它们嵌套 |
| 行 key | **绝对行号** | 窗口滑动一格时第 k 行仍是第 k 行 ⇒ diff 只增删两端各一个 Element，中间全复用；用窗内序号做 key 会让整窗 key 一起变 ⇒ 每滚一格重建全部行（行内 State 全丢） |
| 偏移存哪 | `State` | §12.3 的判据是「承载它的 Element 会不会被回收」—— 被回收的是**行**，滚动容器本身不随滚动消失（判据成立）。行内的 UI 态会丢，必须外置 Store |
| 滚轮 | `Scrollable` 接口 | `Application::scrollAt()` 原来写死 `instanceof ScrollViewState` ⇒ 每加一种滚动容器都要改框架核心。改为 `instanceof Scrollable`（`scrollBy(float)`），框架不再逐个认控件 |

**复用而不是重写**：`RenderScrollView` 加两个构造参数 —— `virtualExtent`（完整内容的
主轴长度）与 `sliceOrigin`（这扇窗在完整内容里的起点）。`maxScroll` 按
`virtualExtent` 算（滚动条长度才对，按窗长算会得到 280 这种"只有一屏"的谎），子盒
偏移变成 `-(offset − sliceOrigin)`。**不传 ⇒ `null` / `0.0` ⇒ 普通 `ScrollView`
的路径逐字节不变**（两个参数都有默认值，且按「必选在前」排）。

**无界主轴的两种情况**（守卫只抓第二种）：`RenderFlex` 量自然尺寸的**第一遍**本来
就会递无界主轴（`RenderFlexible` 的注释原话）—— 那是合法中间态，LazyColumn 的自然
高就是当前窗口，松手让它跟着窗口走。**外层还有一个滚动视口**才是真用错：内容被外层
的无限约束吞掉、窗口永远只有一屏、而滚动归外层管 ⇒ 看起来能跑，实际永远看不到第 21
行以后。判别法 = 沿父链找 `RenderScrollView`（`Expanded` / `Column` / `Card` 都不算）。
★ 这条守卫**上线一刻就抓到自己的样例页错**：`BigListPage` 最初写成
`Expanded(LazyColumn)`，而 Card 按内容自尺寸 ⇒ 量自然尺寸那遍递无界主轴 ⇒ 一开
始写成"一律 Fatal"时直接崩；细看才发现两种无界必须分开。

**判据**（smoke ㊷，8 条）：窗口行数与总行数无关（20000 行仍 5 行）/ `maxScroll` 按
完整内容 / 窗起点进坐标链（滚 400 ⇒ 子盒 offsetY −8）/ 命中跟随窗（视口 y=10 ⇒ 绝对
y=−8）/ 上内边距进 sliceOrigin（pad.top 24 ⇒ 子盒 +24）/ **绘制透明**（offset 0 与
400 下与全量列表 SVG 逐字节一致）/ 滚轮经 `Scrollable` 路由（一格 48 ⇒ 偏移 48）/
应用层窗滑两行（起点 40 ⇒ offsetY −8）。

★ 反例自检三发：窗口算成"全部行" ⇒ 前两条红；`sliceOrigin` 恒置 0 ⇒"offsetY −8"红；
`maxScroll` 按窗长算 ⇒ 上界红（280 而不是 1119760）。

★ **踩坑（本轮最贵的 20 分钟）**：smoke 里补 `use Drift\Widget\ScrollView;` 却漏了
`ScrollViewState` ⇒ `instanceof` 右侧的类在当前命名空间**根本不存在**，PHP 的
`instanceof` 对不存在的类**恒 false 且不报错** ⇒ 全量那一侧根本没滚，"绘制不一致"
被误读成虚拟化的缺陷（这是同类陷阱在本仓第 3 次复发）。揪出来的工具是**诊断值**
`offset e=-1`，不是再跑一遍。⇒ 仍未修的是 lint 缺这条规则（§24.1 已知缺口）。

---

### 13.5 帧成本量具与 M5 脏矩形的去向（2026-10-18）

§3194 要的"一张表"（判据：**M5 脏矩形到底值不值得做**）。两个路径分开量 —— 生产与
确定性测试走**不同后端**（`Win32SurfaceProvider::DEFAULT_RENDERER = 'skia'`，2026-10-17 翻的）：

| 路径 | 后端 | 帧成本（900×640，users 列表页） | 消费者 |
|---|---|---|---|
| **生产** | Skia（真 TTF + C++ 光栅） | **中位 4.5ms**（best 3.8 / worst 9.1，80 帧实测） | exe |
| 确定性 | PHP 软光栅（`RasterCanvas`） | 优化前 **233ms/帧** ⇒ 优化后 **29.9ms/帧**（Card+Row+2Text×12） | golden / smoke / preview |

**结论：脏矩形继续驻留，§13.3 的"等症状"门禁保持有效。** 生产 4.5ms/帧离 60fps 预算
（16.6ms）有 3.7× 余量 ⇒ 今天做脏矩形属于"没有症状先吃药"，且 §13.3 明写它必须与
relayout 脏边界**成对**做（单独做一个只是把瓶颈搬到对面）。

★ **重估触发条件**（写下来免得无限期搁置）：生产帧中位 > 8ms、或最差帧 > 30ms、或
高分屏（≥2000px 宽）下中位 > 12ms —— 任一成立就重新立项，且**必须成对做**。

★ **复核记录（2026-11-03）**：三条触发**均未成立**。用 `--pages` 档量 27 个**真实页面**
（不是合成的 2000 节点树 —— 那会把分母算错）：最重的 `TablePage` 全量重排
**1,531.7µs**，对 8,000µs 的触发线有 **5.2×** 余量；而它的重建帧是
settle 319 / layout 1,653 / paint 1,851µs ⇒ **即使 layout 全省也只省掉 43%**。
复核命令固定为 `php tools/frame-tax-probe.php --pages --check`，别再靠记忆判断"该不该做"。

★ **复核记录（2026-11-04，12k 合成档）**：`frame-tax-probe --scale` 量 12,003 节点 ——
首布局 1,026.4ms / 稳态帧 8.7µs / 全量重排 49.8ms（**4.15µs/节点**）。
8,000µs 那条线在**这个规模**上触线 6.2×，但稳态帧仍只有 8.7µs，且上一段 27 个真实页的
三条生产触发均未成立 ⇒ **结论不变**：脏矩形与 relayout 边界继续驻留、成对才做。
合成档触线**不算立项**；三道门（真实消费者 / AOT 口径 / 成对）与五维评估见
[`DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md`](docs/DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md)。

**软光栅为什么慢、修了什么**（逐图元定位，见 `_build/bench-canvas-prims.php`）：

- 瓶颈**不在字形**。曾假设在 `BitmapFontFace::rasterize()`（每帧每字形重算缩放）并加了
  `(码位, 像素尺寸)` 记忆化缓存 —— 实测收益仅 ~8%，**假设被证伪**；缓存保留
  （纯函数 + `GlyphBitmap` 是 `final readonly`，零语义风险）。
- 真瓶颈在 `RasterCanvas::rasterize()` 的**矩形填充**：半透明路径每像素 8 次 `round()`
  （Card 的三层阴影 = 12 张卡 2.31s/帧），不透明路径每像素 4 次 `chr()` + 4 次字符串
  偏移写。
- 修法三件（**输出逐字节不变** —— golden 三场景 md5 逐个比对通过，`_build/golden-raster.php`）：
  ① **行区间化**：圆角矩形逐行算连续墨区间（中部行整段在内、上下边带行从两端向内扫，
     谓词仍逐点判 `insideRoundRect`）⇒ 圆角从"每像素判一次"变成"每行扫 ≤ radius 个"；
  ② **不透明**：预计算四个单字节串，逐像素**原地**写（PHP 字符串偏移写在 refcount==1 时
     in-place，O(1)）；
  ③ **半透明**：`(r,g,b,alpha)` → 四条 256 项目的**预混合表**，每像素 4 次查表。
- ★ **试过并回退**：整行一次 `substr_replace` —— 它对整块像素串是 **O(整串)**拷贝
  （900×640×4 = 2.3MB/行）⇒ 全幅矩形反而慢一倍、小卡片慢 32×。PHP 字符串没有
  "slice 原地写"原语，大缓冲上一切"整段替换"都是这个下场。
- ★ 字形的 `blendBitmap()` **未动**：它的 alpha 每像素都在变（覆盖度 0..255），吃不到
  预混合表的红利（一个键只摊一个像素，建表比直接算还贵）。
- ★ 软光栅的**固定客户**只有 golden / smoke / preview（生产早已翻 skia）⇒ 这次优化的
  价值在测试与工具链速度，不在用户可见性能。

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
2. **clone-with 贵 3.4×** —— 因为 `clone($this, [...])` 不编译成内联拷贝，而是"构造 `php::Var` 属性数组 + 运行时调用 + 新对象"（生成 C++ 实证，见 `probes/RESULTS.md` §2）。
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
| **加速键与编辑命令冲突** | 运行时守卫已做（`AcceleratorTable::bind()` 同组合绑两次 ⇒ Fatal；**焦点链先于加速键** ⇒ 控件认得的键应用收不到 —— 2026-11-03 起由**顺序**保证，不再靠白名单） | **静态求交不再需要**：`claimKeys()` 已删，没有「声明的交集」可求。剩下要盯的是「控件是否吞了本该全局的键」—— 那是运行期行为，归 `KeyRoutingPriorityTest` 那三条成对判据 |
| **跨回收存活的状态放进了 State** | 无 | **纪律 + review**（§12.3；M5 虚拟化的前置条件，静态难判） |
| **`build()` 里读 `Subject` 但不在订阅者内**（静默不更新） | 无 | **lint `dead-subscription`** |
| **派生值忘了清缓存**（静默旧值） | 无 | **lint `stale-derived`** |
| **业务引用 `@internal`** | 无（平铺 namespace 无从判定） | **lint `internal-leak`**（error + 可显式豁免） |
| **`sources` 与 `composer autoload` 不一致** | 无 | **lint `scan-autoload-drift`**（SDK 模型独有） |

### 16.2 工具集（全部 PHP 单语言、单一入口）

> ★ 旧称"**六件套**"（§0 一页结论沿用至今）；**实际件数以本表为准 —— 今天 8 行**。名称与行数脱节是历史遗留，不改 §0 是因为那个词已经被全文多处引用（改名是纯机械劳动，等 `drift-doctor` 出清单时一并收口）。

| 工具 | 职责 | 关键性质 |
|------|------|---------|
| `drift-lint` | 编译期校验：**R1–R12 移植（清单见 §16.6.1）+ Drift 新增 17 条**（§16.6.3） | **量具双向自检**（该响会响、不该响不误响 —— "从不报错的检查器与正确输出一样"） |
| `drift-preview` | 无头跑完整 layout + paint（软光栅后端）→ SVG / PNG | **不编译、不依赖 OS**（GUI 仓 V7 实测：layout 可在 `php.exe` 跑通）；支持单页预览 + **store 夹具注入**（§A.5） |
| `drift-devtools` | 树转储 / 语义树 / 快照 `{schema,trees,semantics}` / 几何普查（多断点）/ 溢出探针 | 快照是数据 ⇒ 可 diff、可进 CI |
| `drift-conformance` | 合成树夹具 + 期望数据；**新实现必须过套件** | 夹具不依赖示例应用 |
| `golden` | `DisplayList` 回放比对 + 几何对账 | G3 的验收形态；★ **必须绑定测试字体**（§14.2） |
| **`drift-doctor`** | 环境自检：`PHPRC` 指向的 ini / `extension_dir` 是否绝对路径 / `mbstring` 可加载 / Skia include+lib 位置 / `icudtl.dat` 可定位 / `cl.exe` 在 PATH / `c++20` 支持 / 系统字体族可枚举。**输出"哪一项不满足 + 症状会是什么"** | §16.5 的六个陷阱**全都是"环境配置错 ⇒ 报错指向别处"**（缺 `mbstring` 报 `Failed to startup module`、CRT 不匹配报 `LNK2038`、缺 `icudtl.dat` 直接 abort、字体初始化失败报 `0xC0000005`）⇒ 值得一个自检入口。**★ 2026-10-14 裁决**（原 §23 O-10） |
| **`drift-codegen`** | 把 `src/` + 各 `apps/<app>/src` 交给 **tpc 翻译**一遍（`--dry`，只生成 C++，不编译）⇒ `php -l` 与 lint 都放过的 AOT 专属缺陷在这一层现形 | **★ 2026-10-15 裁决（原 §23 O-14 方案 A）**：T1 档常驻门（实测数秒）。① **必须用源码树 `bin/tpc.php`** —— 修复只在源码树，发行版 `tpc.exe` 不含（拿它跑只会得到已修好的假警报）；② `--dry` **也仍校验工具链** ⇒ 用 `cmd.exe` 垫的假 `cl.exe`/`link.exe`（`--compiler` 直指它，比改 `PATH` 干净）；③ `PHPRC` 指向**私有 ini 副本**（`extension_dir` 改成绝对路径 —— 发行版 ini 里是相对 `./ext/`，跟着 cwd 漂）；④ 每次**先删 `build/`** 再跑 —— 留缓存的话第二遍全是 `[cached]`，"每个文件都翻译过"这条断言就成了空话，**门不能信缓存**；⑤ 七条断言：退出码 / `Dry run completed` / 无 `skip:`·`unsupported syntax` / `convert:` 集合 ↔ 自枚举的源文件集合（双向）/ `prepare completed` 计数相符 / `backend` 行里是我们传的编译器 / 产物清单行数相符。**退出码 0=通过 · 1=未通过 · 2=门没跑成**（"没跑"与"通过"绝不能同码，G2） |
| **`drift-build`** | **真机构建**：调 `cl.exe` / `link.exe` 出一个能双击运行的 exe（`php tools/drift-build.php [应用名]`，默认 `hello`） | **★ 2026-10-15 新增（P0-3）**：把"**Windows 上跑 tpc 的四个坑**"固化成工具 —— ① `PHPRC` 指向**私有 ini 副本**（`tpc.exe` **不加载**同目录的 `php.ini`，而 `php.exe` 会 ⇒ 缺 `mbstring` 直接 Fatal）；② 私有 ini 的 `extension_dir` 改**绝对路径**（发行版写的是相对 `./ext/`，跟着 **cwd** 漂）；③ `PHPX_HOME`（不设 ⇒ `phpx directory not found`）；④ `INCLUDE`/`LIB`/`PATH` 三件套（`vcvars64.bat` 干的事，而 bash 里 `call` 不了它 ⇒ 直接按 MSVC/SDK 目录拼）。**判据 = 退出码 0 且 exe 存在** ⇒ 所以**构建前先删旧 exe**（否则"tpc 失败 + 上一轮产物还在"会被读成成功）。**与 `drift-codegen` 的分工**：那个 `--dry` 只到翻译层、用假 `cl`、秒级；这个**真编译**、分钟级 ⇒ **不进内环**（§16.3），只在"要出那个 exe"或"验证 AOT 专属问题"时跑。★ 它同时是 §19 M1 出口判据的**执行入口** |

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
# ⚠️ **本块是"目标形态"（Skia + SDK 分发）**，不是当前可构建的配置 ——
#    2026-10-16 校正：真实形态见本块**之后**的「★ 真实形态」小段（共享 base，已验证可构建）。
name: users
mode: bin
platform: win32
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

> ★ **真实形态（2026-10-16，两个 exe 均构建通过）**：`apps/users/project.yml` 只留四类项，
> 其余编译参数抽到 `build/base.yml`（§4.6）：
>
> ```yaml
> name: users
> version: 0.1.0
> info:
>   Author: Drift
>   Description: Drift 框架 M3 出口判据 —— §A.2 的完整应用
> include: ../../build/base.yml     # ★ 真键；tpc 的 ProjectYamlLoader 支持 include（实测）
> sources:                          # ★ list 型项**整体替换** ⇒ 不放 base
>   - .
>   - ../../src
>   - ../../cpp-src
> ignore: [build, tests]            # ★ 同样要写全（只写 tests 会顶掉 base 的 build）
> ```
>
> `build/base.yml` 放"两边**完全相同**"的项：`mode` / `platform` / `no-console` /
> `cxx-std: c++20` / `cxx-flags` / `ld-flags` / `link-libs` / `ignore: [build]` / `build-dir`。
> ★ **`c++20` 已生效**（2026-10-16，`SkiaCanvas` 落地）：Skia 的私有头用 `std::countl_zero`
> （`<bit>`，C++20 起才有）⇒ C++17 报 C2039。base.yml 里那段（**12 个 lib** 按依赖序、**`/MT`**、`/DUSE_SKIA`、
> `/I"cpp/skia"`、`GR_GL_FUNCTION_TYPE`、`advapi32.lib`）已逐条记在 `build/base.yml` 注释里，
> 并附 **2026-10-16 实测校正**（报告 `SKIA-BUILD.md`）—— 原写"`/MD` 而非 `/MT`"是**错的**：
> `/MT` 实测可用（tpc 的 `/MD` 可被用户 flag 覆盖），且 `/MT` **换不到"免 CRT"**。

### 16.5 八个必须知道的陷阱

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

**陷阱 7 —— 会被继承的类，`private` **构造提升属性**在子类实例上会退化成"动态属性"**

AOT 按**运行时类型**查属性表，而父类的 `private` 不进子类属性表 ⇒ 父类方法在**子类实例**
上访问它时，生成代码走的是"**创建动态属性**"那条路。症状两级：

```
Deprecated: Creation of dynamic property Drift\Widget\NavButton::$label is deprecated
Fatal error: Uncaught ArgumentCountError: Drift\Layout\RenderButton::__construct():
             Argument #1 ($label) not passed        ← 动态属性值是 NULL，传给 string 形参
```

★ **判据**：**凡"会被继承的类"，其构造提升属性一律 `protected`**（普通声明属性不受影响 ——
它们进了子类布局；`Element::$box` / `RenderBox::$size` 早就是 `protected`，同一条纪律）。
★ **只有真机能看见**：解释器面 496 条 smoke 断言、`lint` 0 error、codegen 0 skip **全绿**
（本轮就撞上了：`Button::$label` 与 `RenderFlex::$vertical/$spacing/$padding/$crossAlign/$overflow`）。
⇒ **纪律**：新增"被继承的控件/盒"时，构造提升属性写 `protected`，别写 `private`。

**陷阱 8 —— `drift-preview` 夹具写顶层 `return` ⇒ AOT 直接中止**

```
Fatal error: Unsupported statement: Stmt_Return in apps/users/.../memory_source.php:13
```

夹具必须写顶层 `return`（`drift-preview` 的约定），而 AOT 编译器的判据里**没有 `Stmt_Return`**
⇒ 夹具一旦落在 `sources` 扫到的目录里就构建失败。⇒ **夹具放 `tests/fixtures/` 并在 `ignore:` 里排掉**
（约定来自 §16.7 的示例）。

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
★ **2026-10-16 核验（`php tools/lint.php --list` 实测）**：上表"直接移植"是**计划不是现状** —— R1–R12 里**实际在 lint 的只有 R5**（`dead-command`）；R1–R4 / R6 / R7 / R11 / R12 均无 lint 实现（R4 的 key 唯一性改走运行期 Fatal，smoke `eachdup` 用例）。★ W 侧对应守卫（W1/W2/W3 的静态检查）现由 `tools/M2Conformance.php` 覆盖（W2 为弱形式）；R11/R12 完整形态随 `drift-conformance` 归 M4。⇒ **教训同 O-19**："已移植"是自述，判据只能对着 `--list` 数。
> ★ **2026-10-16 再补两处"守卫空转"（`apps/users` 一落地就当场露馅）**：
> ① `dead-command` 的 `commands()` 提取拿 `=>` 与**原始字符串**比较，而 `=>` 是
>   **`T_DOUBLE_ARROW` 令牌** ⇒ 恒假 ⇒ 那条声明式注册路径**从来没生效过**（七条命令
>   全被误报"死命令"）；② `subscriber-mark` 见 `->select(` 就报（见上表判据修正）。
> 两条修完都做了**反例自检**（临时违规文件必须先被抓到），证明规则仍在干活。

#### 16.6.2 `W1–W8`：控件契约八项

**来源说明**：`W` 系列是 Drift 补定义的"一个控件从写法到渲染要过的检查"（GUI 仓无对应编号）。

| W | 检查项 | 判据 | 依据 | 服务 |
|---|--------|------|------|------|
| W1 | **单一造盒路径** | 渲染控件**有且只有** `createRenderObject()` 一个造盒入口；不得在 Element 之外 `new` 盒 | §13.2 | G1 |
| W2 | **盒字段对齐** | Widget 的每个配置字段都被 `createRenderObject()` 读到（R12 的"Widget 侧"） | §13.2 | A9 |
| W3 | **死字段** | 公开参数**声明了就必须被读**（= R11） | §10.3 | A1 |
| W4 | **溢出策略已声明** | 可能超约束的容器声明 `Overflow`；**外壳类容器必须有**（“外壳类”是类别判据，框架侧 `AppBar` / 应用侧 `StatusBar`，见 §24.1 #36） | §13.1 C5 | G2 |
| W5 | **布局纯函数** | `layout()` 不读自身 `ox/oy`、不读 OS/时钟/随机源；**无界用 `INF` 不用 0** | §13.1 C2 / §14.1 | G3 |
| W6 | **Seal-on-Mount** | 挂载后不得写 Widget；`make()` 的必填参数已提供 | §15.2 / §15.4 | A11 |
| W7 | **违反 ⇒ 响亮失败** | 无界传给有内禀尺寸的子、约束违反、溢出未声明 ⇒ `Errors::report`（**Fatal**） | §13.1 C6 | G2 |
| **W8** | **录制栈平衡** | `DisplayList` 录制结束时命令栈深度 = 0（`save`/`restore`、`pushClip`/`popClip` 配对） | §7.2（缓冲复用的前提） | G3 |

> ★ **W 与 R 不是两套东西**：R 是"**树级**规则"（看父子关系、看命令可达性），W 是"**控件级**规则"（看单个控件的契约完整性）。W3 = R11、W2 = R12 的镜像 —— 同一个判据从两个视角看。实现时**同一个检查器**，只是报错分组不同。

> ★ **守卫现状（2026-10-16 建 `tools/M2Conformance.php` 后）**：**W1/W2/W3** ⇒ conformance 静态扫描（token 级，量具带反例自检；W2 为弱形式，R11/R12 完整形态归 M4）· **W4** ⇒ `RenderFlex` 构造参数必填（结构性，漏声明编译不过）+ 夹具断言全部显式 `Report` · **W5/W6/W7/W8** ⇒ smoke 单测（Layout / Seal / Rendering 段）+ 夹具整体断言（两帧几何稳定 / 平移无关 / 零 Errors 记录 / 录制栈归零）。★ 该运行器的 W4 断言**当场抓到一处真框架缺陷**：`RenderFlex` 未覆写 `overflow()` ⇒ 查询返回 `RenderBox` 默认的 `Clip` 而行为是构造参数的 `Report`（判定面说谎）—— 已补覆写。

#### 16.6.3 Drift 新增的 lint 规则（设计 **17** 条 · 实到 **14** 条）

| # | 规则名 | 判据（静态可判吗？） | 严重度 | 来源 | 服务 |
|---|--------|-------------------|--------|------|------|
| 1 | `internal-leak` | **非 `Drift\` 前缀**的代码引用了 `@internal` 类型/方法（**判据 = 标注 + 前缀，不是命名空间白名单**） | **error**；有 `@internal-use-allowed: 理由` 豁免 ⇒ warn | §10.1.3 | G2 |
| 2 | `scan-autoload-drift` | `project.yml` 的 `sources` 覆盖的命名空间 ≠ `composer.json` 的 `autoload.psr-4` | **error** | §10.2.5 | G2 |
| 3 | `export-surface-drift` | **仅 `-m lib`**：`@api` 面 ⊄ `<target>.stub.php` 的导出面（某个 `@api` 没进 ABI，或 `@api` 签名引用了被 `#[NoExport]` 级联排除的类型 ⇒ **签名不完整**） | **error** | §10.2.6 | G2 |
| 4 | `dead-subscription`（= A16） | `build()` 体内出现对 `Subject` 子类型实例的方法调用 ⇒ 该 `build()` 所属类必须实现 `SubscribingWidget`，或位于某 `SubscribingWidget` 子树内 | **error** | §11.3 / §21 A16 | A16 |
| 5 | `dead-command`（扩） | ① 全树 `command('x')` 发出的名字 ∈ `CommandRouter` 注册集（★ 注册集含**两条**来源：`->on('x', h)` 与 `Application::commands()` 的字典键）；② 注册集里的名字**至少被一处发出**（**双向**：死命令 + 死注册） | **error** | §11.4.3 | G2 |
| 6 | `select-purity` | `select` 闭包返回类型必须是**标量 / enum / `readonly class`**；出现 `new` 非 readonly 对象 ⇒ 报错 | **error** | §11.3.3 | G2 |
| 7 | `stale-derived` | `Store` 里出现 `??=` 赋值的属性（缓存槽）⇒ 所有会修改其**依赖字段**的方法里必须有 `$this->xxxCache = null` | **error** | §11.3.4 | G2 |
| 8 | `subscriber-mark` | **自己**调 `$this->select()` / `watch()` 的类必须 `implements SubscribingWidget`（★ 2026-10-16 修正判据：原先见 `->select(` 就报，把"配置一个专职订阅者"—— `SubjectBuilder::make($store)->select(...)` / `StatusBar::make()->source()->select(...)` —— 判成违规，而 §A.2.9 的官方写法正是那样） | **error** | §10.3 第 5 条 | G1 |
| 9 | `nested-subscribe` | 订阅出现在 `Each` 的 item builder 里（每列表项一个订阅） | **warn** | §11.3.2 纪律 2 | G2 |
| 10 | `cached-subscriber` | `SubjectBuilder` 实例被赋给字段并跨 `build()` 复用 | **error** | §11.3.2 纪律 1 | A4 |
| 11 | `alias-mutate` | 同一 `children([...])` 数组内出现两次同一根变量（`$t->a()`、`$t->b()`） | **error** | §15.6.1 | A12 |
| 12 | `nano-hygiene` | 出现**匿名类** / `yield`（或 `Generator`/`Fiber`）/ `getenv`·`putenv` / `eval`·`include`·`require` | **error** | §16.7 | G1 |
| 13 | `str-concat-loop` | 循环体内用 `.=` / `$s = $s . …` 拼接多字节字符串 | **error** | §2.4 纪律 2 | G2 |
| 14 | `no-using-php` | `cpp-src/**/*.h` 里出现 `using namespace php` | **error** | §2.4 纪律 3 | G1 |
| 15 | `stub-prefix` | 命名空间内调用全局 stub 函数（`platform_*` / `pool_*` / `win_*` …）未写前导 `\` | **error** | §16.5 陷阱 3 | G1 |
| 16 | `namespace-mismatch` | 文件的 `namespace` 声明与 PSR-4 路径不符 | **error** | §4.4 纪律 3 | G1 |
| 17 | `orphan-trait` | 一个 `.php` 文件只含 trait（不产出翻译单元） | **error** | §2.4 纪律 7 | G1 |
| 18 | `instanceof-missing-use` | `instanceof` 右侧短名既不在本文件 `use` 列表、也不解析到同命名空间/全局项目类，但该短名在本仓**某个命名空间下存在** ⇒ 恒 false（PHP 不报未导入类的名，静默死分支） | **error** | 2026-10-19（静默缺陷模式，咬过 ≥3 次：ThemeChangedEvent / SemanticsBuilder / RenderFlex Stretch） | G1 |
| 19 | `class-missing-use` | 同一判据，另外三处**加载时不报错**的语境：`new X` / `X::`（含 `X::class`）/ `catch (X $e)`。前两者运行期 `class not found`，`catch` 则**永远不命中** | **error** | 2026-11-02（真事故：`WatermarkStore` 漏 `use DownloadResultsJob` ⇒ 用户点"下载结果图"报 `class 'App\Watermark\Store\DownloadResultsJob' is undefined`；而 lint/smoke/phpunit 全绿 —— 该分支只在 `$host !== null`（真机）可达） | G1 |

> ★ **每条规则必须标注服务对象**（**2026-10-14 裁决**，原 §23 O-11）：三张表的"服务"列 = 该规则服务的**公理**（G1/G2/G3）或**反模式**（A#），**不许留空**。§21 的反模式表同理（"守它的人/机制"列三选一，不许留空）。⇒ 两张表**互相锁死**：新增反模式必须问"谁来守它"，新增 lint 必须问"它守的是哪条公理" —— **守不住任何东西的规则 = A1 的规则版**。
> ★ **全部满足 G1 判据**（"编译器知道吗？"）：类型声明、前缀、标注、闭包签名、文件路径**都是编译期可见的** ⇒ 都该在构建期做，不靠人记。
> ★ **两级严重度 + 白名单**（`error` 挡新债 / `warn` 留痕）沿用 GUI 仓 R11/R12 已验证的机制，**不新发明**。
> ★ **量具双向自检**（§20 第 1 条）：这 17 条**每条**都要配"已知错误"用例 —— 既验该响会响，也验不该响不误响。
> ★ **诚实边界**（**2026-10-14 裁决**，原 §23 O-13）：`nano-hygiene` 只保证**源码纪律**，查不到 tpc 自己注入的 include（如 lib 模式的 `/FI` 聚合头）或链接期问题（PHPX 库路径）。⇒ **加了这条 lint ≠ 能切 nano**；nano 兼容性的最终判据只能是**跑一次真机 `--nano` 构建**。
> ★ 既然 nano 已明确**不作为前期重点**（§16.7.5），`nano-hygiene` 的定位是**零成本保留"将来可切 nano"的可选性**，**不是"nano 就绪"的证明**。
> ★ **未落地的 4 条，缺的都是"消费者"而不是工作量**（2026-10-15 记账，2026-10-16 复核补 1 条）：`scan-autoload-drift` 要 `apps/*/project.yml`（还没有）· `export-surface-drift` 要 `-m lib` 分发工程（还没有）· `no-using-php` / `stub-prefix` 要 `cpp-src/` C++ 桥接（M1 才建）。**没有消费者的规则只是审自己** ⇒ 到位的当天补，不预写。**落地与否以 `php tools/lint.php --list` 为准**（数字不写进本文件，写即过期）。
★ **`--list` 里还有一条本表没列的**：`dependency-direction`（§4.3 依赖规则的守卫，"层之间只能沿 §4.3 的允许方向依赖"）—— §16.6.1 之前就有的骨架规则，2026-10-16 补登于此。实到 14 = 本表 17 − 未落地 4 + 表外 1。★ **2026-10-19 补第 18 条**：`instanceof-missing-use`（表外新增，同 `dependency-direction` 先例）—— 静默恒 false 缺陷模式，本项目唯一反复咬人的一类 bug，一条静态规则一劳永逸防患一类；夹具 `apps/InstanceofBad.php`（漏 use）+ `apps/InstanceofGood.php`（use / 全限定 / 变量右侧三正确形态不误响）。★ **2026-11-02 补第 19 条**：`class-missing-use`（与第 18 条**同一套判据、另外三处语境** —— 共用 `Project::symbolFqcn()` / `symbolsByShortName()` 与 `SourceQuery`，不各建一份索引）；夹具 `apps/ClassMissingUseBad.php`（new / `::` / catch 各一条）+ `apps/ClassMissingUseGood.php`（use 过 / 全限定 / 内建类 catch / `self::` 与变量左侧四正确形态不误响）。★ 落地当天它在真实仓库里抓出**第二处**：`apps/bench/src/M0Nullable.php` 的 `Errors::report()` 同样漏 `use Drift\Errors` —— 真出事时不是给出诊断，而是 `class not found` 崩掉。

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
| **`Generator` / `yield`** | `yield` **不进核心**（懒序列走显式分页/窗口结构） | ✅ 已满足 |
| ~~**Fiber**~~ | **2026-11-04 解禁**：`AsyncScope` 要用它 | ⚠️ **放弃此项**（§16.7.7） |
| `getenv` / `putenv` | 0 处 | ✅ 已满足 |
| 网络 / HTTP | **有** | ⚠️ **保留**（nano 兼容性让位于功能） |

  ⇒ **除"网络"外 Drift 已全部满足 nano 的约束** ⇒ 相当于**零成本保留了"未来切 nano"的可选性**。新增 lint 规则 **`nano-hygiene`**（§16.6.3 第 12 条）。
- **重估时机**：**不设**（**2026-10-14 裁决**）。原写"§17 定案之后"已作废 —— nano 已明确不做，不再挂在 §17 后面等。只有出现**"必须自包含分发"的明确需求**时才重新评估，且届时**先解 §16.7.4 的 ①/② 两条硬冲突**（NTS vs ZTS、`c++17` vs `c++20`）。

#### 16.7.6 一个必须记下的陷阱：过期注释

`SourcePipelineTrait.php:602-606` 的注释写着 "**Windows Nano deliberately leaves `nanoMode=false`**"，但 `NanoBuildBackend::composesRuntimeSources()` **无条件 `return true`**（不看平台名）。
⇒ **注释已过期，代码才是真的**：Windows 上 `--nano` **会**开 `nanoMode`。（与 §16.5 陷阱 6 的"过时注释"是同一类风险。）

#### 16.7.7 `Fiber` 解禁（2026-11-04 裁决）

★ **`Fiber` 从 `nano-hygiene` 的禁用清单里移除，`Generator` / `yield` 保留。**

三条理由，缺一条都不够：

1. **它守的那条路已经放弃。** §16.7.5 明写 nano 在 M0–M5 不做、**重估时机"不设"**。
   禁令的定位（§16.6.3）是"**零成本**保留将来可切的可选性"——前提是零成本。
2. **它不再零成本。** `Fiber` 是 `AsyncScope`（线性异步，§17.2.5）的唯一实现基础，
   而后者是 `apps/watermark` 那种"N 步流水线被拆成状态机 + 回调 + 序列化三份"的唯一解法。
   **一条禁令挡住一个真问题，就不再是纪律而是障碍。**
3. **实测通行。** `probes/fiber_scope` 在 AOT `-m bin` 产物里 14 条边界全绿
   （跨帧 suspend / 操作 tpc 编译类 / `throw`+`finally` / 回调栈恢复 / 多 Fiber /
   嵌套 / 深栈 / 传对象 / 局部消化 / `getCurrent` / **真实 `AsyncScope` 三步流水线** /
   取消 / 异常穿越），三组变异精准报红。
   ★ 早有一条被忽略的对应记录：`docs/design-v7/11-编译器约束映射.md` §5.4
   写着"`--nano` 不支持 Fiber ⇒ 走 `-m bin`"——**两者本就互斥**，本仓走的一直是 `-m bin`。

**代价（诚实记账）**：放弃"将来切 nano 消除多文件部署"这一条可选性。
它本来就是 §10.2 代价 2 里被**明确接受**的那笔账（"形态 B 必须接受多文件部署，不再指望 nano 消除它"），
所以这不是新增损失，只是把已接受的事实在源码纪律上对齐。

**判据**：`tools/Lint/fixtures/apps/NanoBad.php`（5 条 error，钉规则仍然有效）+
`NanoGood.php`（`Fiber` 三种形态不报）。`php tools/lint.php --selftest` 的
`nano-hygiene 该响 5 / 不该响 0` 是这件事唯一可执行的证明。

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
        │  后端 A：**线程池**（⛔ 探针 K：当前 tpc 下不可实现，§17.2.2）  │
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
| ⑤ 线程能否跑 **PHP** | ⚠️ **只对纯标量/字符串子集成立** —— 见下方边界表；★ **2026-10-16 探针 K 已把边界收窄**（类/对象/属性全崩，单线程即崩） |

**⑤ 的边界（关键）**：

| PHP 函数体 | 主线程 | worker（不补 TSRM） | worker（先 `ts_resource(0)`） |
|-----------|-------|-------------------|---------------------------|
| 纯整数算术 | — | **42** ✅ | — |
| 纯标量循环 1000 次 | `499500` ✅ | **499500** ✅ | — |
| 循环拼字符串 64 次 | `64` ✅ | **SIGSEGV 3/3** ❌ | **64** ✅ |
| 同上 × **4 线程并发** | — | — | **okCount 4 / tsrmFail 0 / Σ 256** ✅ |

⇒ 探针 E 当时的读数：边界不在"是不是 PHP 代码"，而在**"碰不碰 Zend 运行时（分配 / 引用计数）"**；修法是一行 —— worker 入口先 `ts_resource(0)`。

★★ **2026-10-16 探针 K（`probes/taskhost_tsrm/`）把这条边界**大幅收窄** —— 上表的"修法是一行"只成立于**纯标量 + 字符串**。一旦 PHP 碰**类条目 / 对象 / 属性 / 静态属性**，**哪怕只有一个 worker 线程也立刻 SIGSEGV**（`threads=1` 对照列，3/3 稳定）：

| 构造面 | th=1 | th=4 | 根因（读生成的 C++ 得到） |
|---|---|---|---|
| 函数 `static` 局部变量 | ✅ | ✅ | AOT 把它编译成 `THREAD_LOCAL` ⇒ 天然每线程独立 |
| 类静态属性 | ❌139 | ❌139 | worker 上做**类名解析**失败（主线程先摸一遍 ⇒ 命中持久缓存 ⇒ ✅） |
| 字符串字面量 intern | ✅ | ❌127 | ★ **唯一真正的并发问题**：intern 路径不并发安全 |
| 类常量 + enum case | ❌139 | ❌139 | `typephp_read_property_cached(…, get_property_cache(…))` ⇒ `php_request_cache` 空指针 |
| `new` 对象 + 数组 | ❌139 | ❌139 | 同上（请求级缓存缺失） |
| `spl_object_id` | ❌139 | ❌139 | 同上 |

⇒ **根因**：生成的扩展里有 `static THREAD_LOCAL php_request_cache_storage *php_request_cache = nullptr;`，它**只在模块 RINIT 里被 `new`**，而 `get_class()` / `get_property_cache()` / `get_method()` 等全走它 ⇒ **没跑过 RINIT 的线程上全是空指针解引用**。
⇒ **两条"补请求上下文"的路都断了**：`php::request_init()`（公开 API，可调、安全）**不建** `php_request_cache`；完整 RINIT（`zm_activate_*`）**自己就 SIGSEGV**（它最后一步是 `php::eval("main();")`，把"建请求上下文"与"启动 main"绑死了）。
⇒ **结论：后端 A 在当前 tpc 运行时下不可实现**，需 tpc 补一个"每线程进入请求"的入口（详见 §17.2.2）。

#### 17.2.2 后端 A —— C++ 线程池 + TSRM（⛔ 2026-10-16 实测：当前 tpc 下**不可实现**）

> **状态**：下面这段是**目标形态**（端口契约不变），**现在跑不了**。实测见 §17.2.1 末段与 `probes/RESULTS.md` §11（探针 K）。

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
- **✅ 已验（2026-10-16，探针 K）—— 结论是负面的**：
  - **`ts_resource(0)` 之后的完整线程安全**这一枪已补（`probes/RESULTS.md` §11）。**探测到的不是"竞争"而是"缺上下文"**：
    1. **类 / 对象 / 属性访问在 worker 线程上必崩**（`threads=1` 即 SIGSEGV）—— 根因是生成的扩展里 `static THREAD_LOCAL php_request_cache_storage *php_request_cache` **只在 RINIT 里被 `new`**；
    2. **字符串字面量 intern 路径确实不并发安全**（单线程 ✅ / 4 线程 ❌ `zend_mm_heap corrupted`）—— 这是唯一真·竞争；
    3. **两条"补请求上下文"的路都断了**（`php::request_init()` 不建请求缓存；`zm_activate_*` 自己就崩）。
  - **⇒ 后端 A 落地前必须先有 tpc 侧的改动**（精确到接口，不是"优化一下"）：
    > 一个**每线程"进入请求"的入口**：(a) 分配该线程的 `php_request_cache`，(b) 调 `php::request_init()`，(c) **不启动 `main()`**；配套一个对称的"离开请求"入口。
    > 最省事的等价做法：把生成物 RINIT 的**前两步**与**最后那一步 `php::eval("main();")` 拆开**，各自导出。
  - **⇒ 因此 M1–M3 只上后端 B**（§17.2.3）。`TaskHost` **端口照建** —— 端口存在的意义正是"换后端是组合根一行的事"，tpc 补上钩子后后端 A 即可接入，**页面与 Store 一行不改**。
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

#### 17.2.5 2026-10-18 复测（探针 L）：Swoole 线程的真实边界 + 方案谱系

> 探针 K 判死的是「**裸 `std::thread` + `ts_resource(0)`**」。Swoole 6.x 的 `Swoole\Thread`
> 是专为 ZTS 设计的封装（自己给新线程建 PHP 执行环境）⇒ 重新实测，不沿用旧结论。
> 数据与复跑方式：`probes/RESULTS.md` §12（`probes/taskhost_swoole/`）。

**实测三条（决定一切）**：
1. **Swoole 线程在 AOT 产物里能跑**：扩展可加载（`extension=curl` 顺序在前）、线程可建、
   worker 里对象/构造提升属性/数组/`spl_object_id` **全通**（探针 K 在裸线程上 `threads=1` 即崩的那一批）。
2. **但 worker 看不到 tpc 编译的类**：`new \App\Xxx()` ⇒ 干净的 `Class not found`（不是崩）；
   只有 `require` 业务**源文件**才跑得起来（解释执行）。
3. **它不轻**：线程创建 + 首脚本编译 **30–36 ms**；同一段 100 万次累加，AOT **0.12–0.27 ms**
   vs 解释 **11.6–14.7 ms** ⇒ **55–95×**。另测出 AOT exe 的进程能力：`$_SERVER['argv']`/`getenv`/
   `proc_open` 全可用（`$argv` 编译期被拒），进程 spawn **275 ms/次**。

**方案谱系（按"线程里跑哪份代码"分类，这是唯一有效的分类轴）**：

| 后端 | 线程里跑 | 业务代码形态 | 单价 | 隔离 | 依赖 | 现在能做？ |
|---|---|---|---|---|---|---|
| **A. 同步**（现状）`SyncTaskHost` | UI 线程 | AOT | 0 | 无 | 无 | ✅ 已用 |
| ~~**B. 分帧/时间片** `SlicedJob`~~ | UI 线程（帧间切片） | AOT | 切片粒度 | 无 | 无 | ❌ **已删**（§17.4.3：结构上不可达，且与 `TaskHost` 重复） |
| **C. C++ 线程池 + C++ 原语**（用户方案） | **纯 C++（无 PHP 对象）** | 每条任务写 C++ stub | µs 级投递 | 同进程 | 无（`cpp-src` 机制已有） | ✅ **不受探针 K 限制** |
| **D. Swoole 线程池** | **解释执行业务 PHP 源码** | 源码分发 + 双份实现 | 30ms 首建 + 55–95× 解释 | 同进程 | swoole+curl DLL | ✅ 实测可跑，但代价高 |
| **E. 常驻子进程池** | **同一 AOT exe**（`--job` 分支） | AOT（快） | spawn 275ms 一次 + IPC ~0.1ms | **进程级**（崩了不拖死 UI） | 无 | ✅ 前提全验（⑦） |
| **F. tpc 补钩子 + 原生线程池** | AOT | AOT | µs | 同进程 | 需改 tpc | ⏳ **入口已有、上下文仍缺**（2026-10-18 追测：lib 已导出 `typephp_<name>_library_request_startup`（直调 RINIT、可重入计数），但裸 `std::thread` 与 Swoole 线程 + FFI 两枪都 SIGSEGV —— 缺的是 **ts_resource + EG 初始化**，不是 RINIT）|

**推荐（分层，不是二选一）**：
- ~~**B + A 打底**：可分片的长计算走 `SlicedJob`~~ —— **2026-10-31 撤回**：
  分片在结构上不可达（页面拿不到 `Application`），且与 `TaskHost` 重复七件事。
  今天的口径是 **A + E**（同步调用方线程 / 常驻子进程池）；真要"本线程切片"，
  做的是帧调度器的**优先级档**，不是第二种作业类型（§17.4.3）。
- **E 做通用后端**：真·后台任务（HTTP/文件/慢查询）用**常驻子进程池**跑同一 AOT exe ——
  业务代码零改动、崩溃隔离、零扩展依赖。★ 关键：**不是每任务 spawn**（275ms 不可接受），
  而是进程池 + JSON-line 管道（`proc_open` + 每帧 `stream_select` 0 超时非阻塞读）。
- **C 做基础设施原语**：图片解码/压缩/哈希/大文件 —— 只碰 C++ 数据，µs 级投递，
  与"PHP 逻辑在 UI 线程"不矛盾（C++ 线程只碰 C++ 数据）。这是用户方案的正确位置：**不是通用后端，是算力下沉层**。
- **D 不做默认**：解释执行慢 55–95× + 源码分发 + DLL 硬依赖，与"单 exe 静态分发"冲突；
  保留为**可选后端**（用户已装扩展时可开，适合 IO 等待型）。
- **F 长期最优**（线程里跑 AOT 代码），但依赖 tpc 上游。★ 2026-10-18 追测修正了边界：**入口已经有**（`typephp_<name>_library_request_startup()` = 直调 RINIT，lib 模式导出且带引用计数），缺的是"给新线程建 Zend 上下文"（`ts_resource` + EG/executor globals）—— 实测裸线程与 Swoole+FFI 各崩一次；主线程走 C ABI 正常（lib 形态本身没问题）。⇒ 要解锁 F，请上游加 `php::thread_init()`/`thread_shutdown()` 之类，RINIT 复用现有入口即可。

★ **为什么不能"在 worker 里改 Store 然后 notify"**（§17.2 第 ① 处的线程断言）依然成立：
无论哪个后端，**订阅者回调都只能在 UI 线程跑** —— 后端只负责"把结果交回来"，改 Store 的动作在 UI 线程的 `onDone` 里。

#### 17.2.6 是否一定需要线程：**不是** —— 先把"阻塞"和"并行"分开

> 一句话：**GUI 的异步是调度问题，不是并发问题**。绝大多数"要放到后台"的需求是
> **在等**（网络/文件/DB），不是在**算**；"在等"应该用**事件驱动**，而不是开一条线程去等。

**两个问题必须分开**：
- **响应性**（UI 不能卡）：需要的是**让出**（分帧 / 事件 / 协程）——全部在 UI 线程内完成。
- **吞吐量**（算得更快）：只有 **CPU 密集且不可切分** 时才需要真并行。

**判断树（按任务性质，从上往下第一个命中即答案）**：

```
耗时 ≤ 一帧预算（~4ms）────────────────────── SyncTaskHost（现状）
只是"稍后做"（重试/动画/超时）─────────────── TimerHost（已有：SetTimer→WM_TIMER→每轮 frame）
在等 I/O（网络/文件/DB）──────────────────── 非阻塞 I/O + 每帧 poll（★ 零线程、天然多路）
CPU 可切分（过滤/排序/解析/布局预计算）─────── ⏳ 无实现（★ 原 `SlicedJob` 已删，§17.4.3）
CPU 不可切分（解压/解码/加密）
   ├─ 慢一点可接受 ─────────────────────────── 常驻子进程池（总时长↑，UI 流畅）
   ├─ 必须真快 + 数据是纯 C++ ───────────────── C++ 线程池（NativeJob，µs 投递）
   ├─ 必须真快 + 要跑业务 PHP ───────────────── 常驻子进程池（E，跑 AOT 代码）
   └─ 不可信代码 / 会崩 ─────────────────────── 子进程池（进程隔离，崩了不拖死 UI）
真需要的"线程跑 AOT 代码"───────────────────── F（等上游 `php::thread_init()`，见 §17.2.5）
```

**非线程方案清单（按推荐顺序）**：

| 方案 | 适用 | 代价 | Drift 现状 |
|---|---|---|---|
| ~~**分帧切片**（`SlicedJob.step()`）~~ | 可分片计算 | 总时长变长 | ❌ 已删（§17.4.3）；需求由帧调度器优先级档承接，不是作业类型 |
| **非阻塞 I/O + 每帧 poll** | HTTP/文件/DB | 需要非阻塞封装（`stream_set_blocking(false)` + `stream_select` 0 超时，或 C++ 侧 IOCP） | 未验（下一步第一枪） |
| **TimerHost** | 动画/重试/超时 | 无 | ✅ 已有（`Win32TimerHost`） |
| **Fiber（PHP 用户态协程）** | 顺序逻辑 + 等待 | 不解决 CPU 阻塞（只在显式让出点切换）；**IO 必须已非阻塞** | ✅ 2026-10-18 实测：AOT 产物里 `start/suspend/resume/isTerminated` 全正常，**零阻塞** |
| **常驻子进程池** | 重活 / 隔离 | IPC 序列化、内存翻倍 | 前提已验（`$_SERVER['argv']`+`proc_open`） |
| **C++ 线程池（纯 C++）** | 解码/压缩/哈希 | 每条任务写 C++ | 不受探针 K 限制 |
| **Swoole 线程** | 池化 + IO 等待 | 解释执行慢 55–95× | 实测可跑但跑不了 AOT 类 |

**线程的真实代价（不只是性能）**：
1. **数据所有权崩了**：Drift 的 `Store` / `Subject` / `setState` / 树 diff **全部假设单线程**；
   worker 改 Store ⇒ 订阅者在 worker 上跑 ⇒ 静默数据竞争（§17.2 第 ① 处就是为它准备的断言）。
2. **上下文**：本轮实测——lib 的 `library_request_startup` 在裸线程 / Swoole+FFI 两枪都 SIGSEGV。
3. **崩溃半径**：线程崩 = 整个进程崩；进程池崩 = 只丢一个任务。
4. **调试**：竞态不可复现，且 AOT 下没有动态调试器。

**推荐（Drift 的路线，2026-10-31 修订）**：
1. **非阻塞 I/O 打底**（`IoPoller`，长连接 / 自定义协议 / 流式）。
   ~~高频 HTTP 的正解是 `curl_multi` 接帧循环~~ —— **已判死**：`curl_multi_init()`
   调用一次，进程退出即 `exit 139`（段错误，与 swoole 无关，见 §17.4.4.1）。
   ⇒ **HTTP 只走一条路**：worker 进程里的阻塞 `Http`（§17.4.4.1）。
2. **`TimerHost` 承担"稍后执行"**（已有，只缺一个 `Application` 侧的 id→回调登记）。
3. **进程池**承担"重活 + 隔离"（当前唯一有真实业务的后端）。
4. **C++ 原语**承担"解码/压缩"。
5. **线程（F）是最后手段**，只在"必须真快 + 必须共享内存 + 不能进程化"同时成立时才考虑。
6. ~~`SlicedJob` 分帧~~ —— **已删除**（§17.4.3）：结构上不可达 + 与 `TaskHost` 重复。
   "本线程切片"若真有需求，做的应是帧调度器的优先级档。

★★ **本节 2026-10-31 对账**（原文写于 2026-10-18，两处结论已被后续实测/裁决推翻）：
① **`SlicedJob` 整条路线删了**；② **`Fiber` 不能用于 UI 侧** —— AOT 的 lint（`NanoHygiene`）
明文禁 `Fiber` / `Generator`（"C++17 无栈切换"），下面那段"协程的正确用法"里的
实测结论对**解释执行**成立，对**AOT 产物**不成立。今天是"协程只做 `Job::run()` 的实现细节"
（见 `ASYNC-ARCH-2026-10-31.md` 第八轮裁决）。

**协程的正确用法（2026-10-18 实测）**：**用 PHP `Fiber`，不要用 Swoole 协程**。
- Swoole 协程依赖**它自己的 reactor**：`Swoole\Event::dispatch()` 实测**阻塞到下一个事件**（50ms 定时器 ⇒ 单帧 57ms），UI 帧被卡住；且协程耗尽后再 dispatch 在 AOT 下会崩（tpc 的扩展调用路径）。Swoole 没有"0 超时 pump reactor"的 API。
- ★ **hook 是 Swoole 协程让出的前提，也是它不能用的原因**：`Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` 实测可开（`getHookFlags=2143287295`），它把 `fread`/`stream_socket_client`/`stream_select`/`file_get_contents` 换成协程版；但**替换是全局的** —— 开启后非协程上下文调用 `stream_select` 直接抛 `Swoole\Error: API must be called in the coroutine`（实测）。Drift 的 UI 线程代码不可能整体跑在协程容器里 ⇒ **不能开 hook**。
- ★ **Fiber 不会让出阻塞 IO**（用户指出，实测确认）：Fiber 不 hook、不抢占，`fread` 阻塞时整线程就阻塞。Fiber 只提供**让出点** —— 正确用法是 **IO 先非阻塞**（`stream_set_blocking(false)`），读不到 ⇒ `Fiber::suspend()`；UI 帧里 `stream_select(读集, 0)` 判可读 ⇒ `resume()`。实测闭环：`[J3] select_ready=1 log=["round1:not-ready","got:hello"] terminated=yes`（全程不开 hook）。
- PHP `Fiber` 是**用户态**的：`Fiber::suspend()` 立即把控制权还给调用方，**何时 resume 完全由 UI 帧循环决定** ⇒ 天然与 Win32 消息泵共存。AOT 实测：`[I] suspended=need-io / fiber-resumed:io-ready / terminated=yes`。
- ★ Fiber 只解决**控制流**，不解决"在等什么"：恢复条件要自己判（`stream_select` 0 超时 / TimerHost 到期）；且它**不能让出 C++ 层的阻塞调用** ⇒ IO 必须先 `stream_set_blocking(false)`。
- 正确形态 = **UI 帧循环当调度器**（约 100 行）：每帧 `select(读集, 0)` → 就绪 fd → `resume` 对应 Fiber；Fiber 内数据不够 ⇒ `suspend('need-more')`；TimerHost 到期 ⇒ 恢复等超时的 Fiber。**零线程、零扩展、零 TSRM**。

★ **一个反直觉的结论**：线程池不是"异步"的同义词。它在**并行吞吐**上赢，在**响应性**上并不比事件驱动强——
而 GUI 最贵的通常是等待，不是计算。

#### 17.2.7 成熟 GUI 怎么处理同一件事（2026-10-18 横向核对官方文档）

> 目的：确认 Drift 的 `pump()` + 单 UI 线程 + `TaskHost` 是不是"自创"，以及缺哪一环。
> 来源：Flutter `docs.flutter.dev/perf/isolates`、Qt `doc.qt.io/qt-6/threads-qobject.html`、
> WPF `learn.microsoft.com/.../threading-model`、JUCE `ThreadPool` 类文档（均为官方页）。

| 框架 | UI 线程模型 | 后台执行单元 | **结果回注机制** | I/O 模型 | 线程安全手段 |
|---|---|---|---|---|---|
| **Flutter/Dart** | 主 isolate 的 event loop（60Hz paint 事件） | 后台 isolate（**独立内存，只能消息传递**） | `Port` 消息 → 主 isolate 的 event queue | 平台侧 event loop（epoll/IOCP）+ async | 物理隔离（想共享也做不到） |
| **Qt** | 主线程 `QCoreApplication::exec()` | `QThread`（可带自己的事件循环） | **queued connection**：slot 在**接收者线程**的事件循环里执行（`postEvent` 线程安全） | `QTcpSocket`/`QSocketNotifier`（事件驱动） | `QObject::thread()` 亲和性；GUI 只能主线程；跨线程访问要 mutex |
| **WPF** | UI 线程 + `Dispatcher`（10 个优先级的工作项队列） | 线程池（`Task.Run`） | `InvokeAsync`/`BeginInvoke` 把工作项**排队到 Dispatcher**；`async/await` 经 `SynchronizationContext` 回 UI 线程 | IOCP 完成端口（.NET 线程池） | `DispatcherObject.VerifyAccess()`：不在创建线程 ⇒ **抛异常** |
| **Godot** | 主线程 + 帧边界 flush 的 `MessageQueue` | `WorkerThreadPool` / `Thread` | `call_deferred()` → MessageQueue（帧边界回主线程） | `load_threaded_request` 等后台加载 API | 子线程碰场景树/Node 直接报错；Array/Dictionary 非线程安全 |
| **JUCE** | 消息线程（`MessageManager`） | `ThreadPool` + `ThreadPoolJob::runJob()` | `MessageManager::callAsync` / `AsyncUpdater` 回消息线程 | 由调用方在消息线程轮询/回调 | GUI 只能在消息线程访问 |

**三条共同的结论**（不是 Drift 的发明）：

1. **UI 线程唯一 + 事件循环**：没有任何一个框架让后台线程直接改 UI。Drift 的 `while (pump()) { frame(); }` 与它们同构。
2. **跨执行单元一律"结果入队 → UI 帧 drain"**：Qt 的 queued connection、WPF 的 Dispatcher、Godot 的 MessageQueue、Dart 的 Port——**没有一个直接调回调**。
   ★ 这正是 Drift 当前唯一的**结构性缺口**：`SyncTaskHost::submit()` 是 `$onDone($job->run())`（同步直调），没有队列。
3. **I/O 一律事件驱动**，没有"每任务一线程"：Qt 的 QSocketNotifier、WPF 的 IOCP、Flutter 的平台 event loop、Godot 的后台加载 API。
   ⇒ Drift 的"每帧 `select(0)` + Fiber"是把"事件循环的一轮"放进 `pump()`，与它们同一模型。

**"计算放哪"的三种主流答案，Drift 各占一半**：
- **拷贝/消息（Actor）**：Flutter isolate（可变对象**拷贝**）、Godot（复制数据给子线程）⇒ 对应 Drift 的**进程池 / C++ 原语**。
  ★ 所以"进程池 + 序列化"不是妥协——Flutter 的 isolate 是同一取舍（业务代码是 AOT 编译的，本来也无法共享 PHP 对象图）。
- **共享内存 + 锁 + 亲和性**：Qt worker thread、WPF 线程池 ⇒ Drift 的**线程后端（F）**如果要上，必须同时有 `VerifyAccess` 式的断言。
- **单线程分片**：WPF 文档明说"`await` 不加 `Task.Run` 就是单线程分段计算"；Dart 的 microtask 同理。
  ★ **这一档我们做错过一次**（`SlicedJob`，2026-10-31 删除）：WPF/Dart 的分段**不是一种作业类型**，
  而是**调度器的一个优先级档**（`Dispatcher.BeginInvoke(Background)` / microtask 队列）——
  "能单线程就别多线程"这句话讲的是**调度**，不是**契约**。抄形式不抄层次，就会得到
  一套和 `TaskHost` 重复的实现，且页面还调不到它（§17.4.3）。

**Drift 的差距清单（按落地顺序）**：

| # | 缺口 | 参照 | 说明 |
|---|---|---|---|
| ① | **`TaskHost` 缺"结果队列 + 每帧 drain"** | Qt queued / WPF Dispatcher / Godot MessageQueue | 任何异步后端的前提；现在 `SyncTaskHost` 的同步直调把它掩盖了 |
| ② | **`notifyChanged` 缺线程断言** | WPF `VerifyAccess()` | 等 ① 落地后一起加（否则是死代码） |
| ③ | ~~**`SlicedJob`（单线程分片）**~~ | WPF 单线程分段 / Dart microtask | ❌ 2026-10-31 删除：抄了形式没抄层次（那是调度器档位，不是作业类型），且零可达消费者 |
| ④ | **非阻塞 I/O + Fiber 调度器** | Godot MessageQueue + `select(0)` | 覆盖 HTTP/文件，与 §17.2.6 的结论一致 |
| ⑤ | **进程池 / C++ 原语** | Flutter isolate（拷贝语义） | 覆盖"不可切分计算"与隔离 |

★ **最重要的一条**：`TaskHost` 的接口本身要补一档——`submit(Job, onDone)` 只说了"结果怎么回来"（回调），
没说"在**哪个线程的哪一帧**回来"。成熟框架的回答统一是：**队列 + UI 线程 drain**。这是落地异步后端前的第一件事。

### 17.4 落地蓝图（2026-10-18 定稿草案）

> 依据：§17.2.5–17.2.7 的实测与横向核对。目标不是"再加一个后端"，而是**先把结构补齐**，
> 之后换后端只改组合根一行（§17.2.3 的承诺才真正成立）。

#### 17.4.1 三条不变量

1. **回调只在 UI 线程的帧内执行** —— 任何后端都不许直接调 `onDone`。
2. **结果只能经队列到达 UI** —— 后端负责"放进队列"，`Application` 每帧 drain。
3. **I/O 只走非阻塞 + 每帧 poll** —— 不开 Swoole hook（§17.2.6 实测会全局协程化）、不用 Swoole 协程（`dispatch()` 阻塞 57ms）。

#### 17.4.2 端口扩展：`TaskHost` 补"队列 + drain"

```php
namespace Drift\App;

interface TaskHost
{
    /** 提交任务；$onDone/$onError 一定在 UI 线程的帧内被调用（见 drain） */
    public function submit(Job $job, callable $onDone, ?callable $onError = null): void;

    /** UI 线程每帧调用一次：把已完成的结果逐个交给 $onEach（$onEach 在 UI 线程执行） */
    public function drain(callable $onEach): void;
}
```

`Completion`（`@internal`）承载"值 / 异常 / 两个回调"：

```php
final class Completion
{
    public function __construct(
        public readonly mixed $value,
        public readonly ?\Throwable $error,
        public readonly \Closure $onDone,
        public readonly ?\Closure $onError,
    ) {}
}
```

★ **`drain` 的调用点必须在 `settle()` 之前**（`Frame` 的 ① 步就是 settle）：

```php
// Application::frame() —— 顺序不能反
$this->tasks?->drain(fn (Completion $c) => $this->complete($c));  // ① 回调里 setState 只标脏
$root->settle();                                                  // ② 帧末统一重建（§5.2 裁决 3）
$box->layout($constraints);                                       // ③
$box->paint($canvas);                                             // ④
```

反了会怎样：回调排在 settle 之后 ⇒ 那次 `setState` 要等**下一帧**才生效（多一帧延迟，且"loading→ready"会闪一下空帧）。

**错误通道**：`Job::run()` 抛异常 ⇒ 后端把 `$error` 放进 `Completion`；UI 侧若没给 `$onError`，默认 `Errors::report()`（Fatal 序列）。
这与三态里的 `Error` 分支一一对应，不新增"第四个状态"。

#### 17.4.2.1 UI 线程的实体：身份 / 队列 / 断言

★ 先澄清一个误解：**"实现 UI 线程"不是"新开一个线程"**。Win32 的窗口消息只能由**创建窗口的线程**泵
（`GetMessage` 所在线程 == `CreateWindow` 所在线程）⇒ UI 线程就是组合根 `Bootstrap::run()` 所在的线程
（当前是进程主线程）。把它搬到子线程只会让生命周期更难管、且没有任何收益。

要补的是**三件基础设施**（成熟框架都有，Drift 缺前两件）：

| 件 | 做什么 | 参照 |
|---|---|---|
| **身份** | `drift_thread_id()`（C++ 桥 → `GetCurrentThreadId`）；`attach()` 记下 `uiThreadId` | Qt `QObject::thread()` |
| **队列** | `post(callable)` + 帧首 `drain()`（P0 的结果队列是它的第一个消费者） | WPF Dispatcher / Godot MessageQueue |
| **断言** | `assertUiThread($where)`：不在 UI 线程 ⇒ 响亮失败（不给"静默竞争"留缝） | WPF `VerifyAccess()` |

```php
// ① 桥：cpp-src/drift_win32.stub.php 加一行空体（tpc 读签名）
function drift_thread_id(): int {}

// ① 实现：cpp-src/drift_win32.cc —— 无状态，读 TEB，不是 syscall
Int php_drift_thread_id() { return (Int) GetCurrentThreadId(); }

// ② Application：attach 记身份，frame 首行断言
private int $uiThreadId = 0;
public function attach(...): void { $this->uiThreadId = drift_thread_id(); ... }
public function frame(...): void { $this->assertUiThread('frame'); ... }

/** @internal */
public function assertUiThread(string $where): void
{
    if (drift_thread_id() !== $this->uiThreadId) {
        Errors::report("UI 线程断言：{$where} 跑在非 UI 线程（数据竞争的前兆）");
    }
}
```

**断言放在哪（按性价比）**：

1. `Application::frame()` 首行 —— 每帧一次，成本 = 读一次 TEB + 一次整数比较。
2. `Application::drain()` 首行 —— 队列只能由 UI 线程消费。
3. `State::setState()` —— **树变更的唯一入口**，也是 WPF `VerifyAccess()` 的等价物。
4. `Subject::notifyChanged()` —— §17.2 第 ① 处点名的那条（"在 worker 里改 Store"）。

★ **第 3/4 条现在不要加**：没有异步后端时它们是死代码（纪律：从未被消费的分支 = 未实现）。
等 P0 的队列接上、P3 的进程池落地（那时才有真正的非 UI 线程）再一起加。

**队列的三种实现（按后端）**：

| 后端 | 队列 | 说明 |
|---|---|---|
| P0 同步 / P1 分帧 | 纯 PHP 数组 | `post` 与 `drain` 同线程，无需锁 |
| 线程后端（未来） | C++ `std::mutex + std::deque<Completion>` | `drain()` 时一次 `swap` 出来，UI 线程不长时间持锁 |
| P3 进程池 | worker 的 stdout 管道 | UI 帧 `stream_select(0)` 非阻塞读，攒够一行 JSON 就入队 |

**为什么 `getmypid()` 不够**：同进程所有线程的 pid **相同** ⇒ 区分不了 UI 线程与 worker。

★ **2026-10-18 已落地最小地基**：`drift_thread_id()`（stub + `php_` 实现，一行 `GetCurrentThreadId`）、`Application::uiThreadId()/assertUiThread()/uiThreadId` 字段、`frame()` 首行断言、解释器替身（`tools/autoload.php`，固定 `1`）。验证：smoke **672/0**（+3 条）、`drift-build users` 7.3MB、真机窗口 OK（断言在真机放行、无 Errors.log）。
必须有 `GetCurrentThreadId()`（读 TEB，无 syscall，纳秒级）；这也是为什么它要落在 C++ 桥，而不是 PHP 层。

**验收判据**（smoke）：
- `attach()` 后 `uiThreadId != 0`；`frame()` 里断言通过（主线程）。
- 用一个**纯 C++ 短命线程**（只取 `GetCurrentThreadId`，不碰任何 PHP 对象）取 id ⇒ 与 `uiThreadId` 不同，
  证明"身份可区分"；同时验证"在非 UI 线程调 `assertUiThread` 会响亮失败"这条路径。

★ **2026-10-18 已落地**（P0）：`Completion`（`@internal`）+ `TaskHost::submit(Job, onDone, ?onError)`
+ `drain(callable $onEach)`；`SyncTaskHost` 改走**队列**（`run()` 仍同步、回调延到 drain）；
`Application` 加 `taskHost()` 注入点（**attach 时建一次并缓存** —— 它持有队列）、`tasks()` 读法、
`frame()` 首行之后的 `pumpTasks()`（**在 settle 之前**）、`complete()`（失败无人接 ⇒ `Errors::report`）。
验证：smoke **688/0**（+16：端口八条 + 应用层六条）、lint 305 文件 0/0、codegen 205/0skip、
`drift-build users` 7.4MB + 真机窗口 OK；三连反例自检全中（drain 挪到 settle 之后 ⇒ 同帧重建红，
诊断值 `builds 1→1 seen=0`；drain 不清空 ⇒ 重复交付红；改回同步直调 ⇒ "当帧不回调"红）。
#### 17.4.3 ~~`SlicedJob`（单线程分片）~~ —— **2026-10-31 删除**

```text
结论：整个分片链（SlicedJob / submitSliced / pumpSliced / SLICE_BUDGET_MS /
      smoke ㊳ 段 / 两个夹具）已删除，-372 / +72 行。
```

两条判据，按分量排（完整论证见 [`docs/ASYNC-ARCH-2026-10-31.md`](docs/ASYNC-ARCH-2026-10-31.md) §12）：

| # | 判据 | 事实 |
|---|---|---|
| 1 | **结构上不可达** | `submitSliced()` 只挂在 `Application` 上；页面的依赖注入是 `AppScope`，`Application` 在它**之上** ⇒ 业务页面**写不出来**。真实调用者只有冒烟用例与 bench 相位键 —— 判据在给一个业务碰不到的能力打分 |
| 2 | **与 `TaskHost` 是同一件事的两套实现** | 提交 / 队列 / 每帧预算 / 取消 / 错误五件事各一套，差别只有"活儿在哪跑"。"在哪跑"是**执行体**的属性，不该写进**作业契约** |

★ 成熟 GUI 的形态（Flutter `scheduleTask(idlePriority)`、Chromium `TaskPriority::BEST_EFFORT`、
WPF `DispatcherPriority.Background`、Qt、Godot）**没有一家把"帧间切片"做成第二种作业类型** ——
作业一种，**预算与优先级属于调度器**。我们反过来做，重复是设计里长出来的。

★ **删掉的是特例的实现，不是特例的需求**：`Future::cancel()` 从此全仓一律是"不交付"
（原先只有分片这一路"真停"），语义反而统一了。真出现"必须在本线程算、且可切分"的需求，
正解是给帧调度器一个**优先级/空闲预算**档，**不是**复活本接口。

★ **保留的范式**：4ms 帧预算仍在，且现在只有一套 —— `PumpHost::TASK_BUDGET_MS`
（`TaskHost::drain()` 的注释已改为自述，不再拿 `SlicedJob::step()` 当先例）。

<details>
<summary>历史原文（2026-10-18 P1 落地时的契约，仅作记录）</summary>

```php
interface SlicedJob
{
    /** 干一部分；返回 true = 还没完（下一帧继续），false = 完 */
    public function step(int $budgetMs): bool;

    /** step() 返回 false 后读，作为 submit 的结果 */
    public function result(): mixed;
}
```

`Application::submitSliced($job, $budgetMs = 4)` + `frame()` 里的 `pumpSliced()`
（在 settle 之前；swap 语义；`step()` 抛 ⇒ 走错误通道且出队）。取消由
`Future` 的钩子把分片**真的摘出队列**（此后不再 `step()`）。

</details>

#### 17.4.4 `IoPoller`（非阻塞 I/O，P2）

```php
namespace Drift\App\Io;

final class IoPoller
{
    /** 监听可读/可写；返回 token（用于 unwatch） */
    public function watch($stream, bool $forRead, callable $callback): int;

    public function unwatch(int $token): void;

    /** 每帧调用：stream_select(读, 写, 异常, 0 超时) ⇒ 逐个回调 */
    public function poll(): void;
}
```

- 回调里业务代码自己做"读到多少、够不够一条消息"的状态机（与 Node/Qt 的 readyRead 同构）。
- **`Fiber` 是可选包装**（`Awaitable::read($stream)`）：等真的出现"顺序代码"需求再加；
  第一版**不引入**通用 Fiber 调度器（避免"没有消费者就发明机制"）。
- 已验证前提：`stream_socket_pair` + `stream_set_blocking(false)` + `stream_select(..., 0)` 在 AOT 产物里全部可用（probes §12.3 `[J3]`）。

★ **2026-10-18 已落地**（P2）：`Drift\App\Io\IoPoller` —— `watch($stream, $forRead, $cb): int` /
`unwatch($token)` / `poll()`（**`stream_select(..., 0)` 不阻塞**）；`Application::io()` 读法 +
`frame()` 里 `pumpIo()`（**在 settle 之前**，排在 `pumpTasks()` 之前 —— 外部数据先到）。
★ 回调里 `setState()` 与后台结果回调**同级**（同一帧、同一线程）。
★ **本次刻意不做**：通用 Fiber 调度器（`Awaitable::read()` 等真有"顺序代码"消费者再加）、
写缓冲与粘包处理（协议层的事：轮询器只负责"可读了"）。
验证：smoke **708/0**（+10：无数据不回调且不阻塞 / 写入后回调一次且读得到 / unwatch 后不再回调 /
多监听只回调就绪的 / IO 回调改 Store 同帧重建）；三连反例自检全中（不看就绪结果 ⇒ 无数据也回调；
unwatch 空操作 ⇒ 两条红；poll 挪到 settle 之后 ⇒ `builds 1→1 seen=0`）。
★★ **诚实边界**：AOT 面只到**翻译层**（codegen 207 全翻译）—— `apps/users` 不用 IO，
所以"resource 存属性 + AOT 下 stream_select"**没有真机运行证据**；这条与 P3（进程池的管道就是 resource）
一起验，已登记为缺口。

#### 17.4.4.1 `Http`（**阻塞**客户端，worker 侧，2026-10-31）

```php
namespace Drift\App\Io;

final class Http
{
    /** 阻塞；只在 Job::run() 里调（那一段在 worker 进程） */
    public static function get(string $url, array $opts = []): HttpResponse;
    public static function post(string $url, string $body = '', array $opts = []): HttpResponse;
    public static function request(string $method, string $url, array $opts = []): HttpResponse;
}
```

- **为什么它和 `IoPoller` 并排却不冲突**：`IoPoller` 在 **UI 线程**上永远不能阻塞，
  `Http` 在 **worker** 里永远不需要非阻塞 —— 两者不是新旧，是两件事。
- **为什么 `curl_multi` 那条路走不通**（原文档把它写成"高频 HTTP 的正解"）：
  本机 PHP 8.5.11 ZTS + curl 8.22.0，**只要调用过一次 `curl_multi_init()`，进程退出就段错误**：

  ```bash
  php -r '$mh = curl_multi_init(); echo "ok\n";'   # 打印 ok，然后 exit 139
  ```

  `curl_multi_close()` 后也一样；`php.exe` 与 `typephp/php.exe` 两个入口都复现；
  **没有 swoole** 也复现（旧记录把它归因于"与 swoole 同产物"，那条归因是错的）。
  ⇒ 接进帧循环就意味着"应用退出时必崩"，进不了闸门。
- **为什么不是 A2 池加 `http.request` op**：exe **+6~8 MB**（`libcrypto-3-x64.dll` 6.94 MB
  是大头），且变成第二套 TLS / 第二套根证书来源（同一 URL 两个结果）。
- **今天的两处消费者**：`apps/users` 的 `FetchUsersJob`、`apps/showcase` 的 `FetchRowsJob`
  —— 这两个文件在 2026-10-31 之前各自手写了一份 30 行的 curl（同样的超时、状态码判断、
  body 截断），现在各剩一行 `Http::get(...)->json()`。
- **判据**：`tests/Unit/App/HttpTest.php`（参数面 / 传输失败面无网络）+ 冒烟 ㊶c
  （真 `php -S`：GET 200、POST 的动词与 body 真的发出去了、404 抛且带状态码、
  `expectOk=>false` 当数据、跟随重定向后 `url()` 是最后一跳）。
- **刻意不做**：连接复用 / 并发 / 流式下载 / cookie 罐 / 重试 / 代理开关。
  今天唯一的用法是"取一页 JSON"，这几样每一个都是真实的策略选择，真需要时形状在
  **worker 里**（`curl_share_*` 或 `IoPoller`），不在这里。

#### 17.4.5 `ProcessTaskHost`（P3：重计算 + 隔离）

- **常驻池**（不是每任务一进程：实测**每任务一进程的往返** 275ms/次，不可接受）。
- 协议：stdin/stdout 各一行 JSON —— `{"id":1,"job":"user.fetch","arg":{...}}` / `{"id":1,"ok":true,"value":...}`。
- worker = **同一个 AOT exe** 的 `--drift-worker` 分支（`$_SERVER['argv']` 实测可用；`$argv` 不可用）。
- UI 侧每帧 `drain()` 里非阻塞读 stdout（`stream_select` 0 超时），**不在 drain 里阻塞**。
- 崩溃隔离：worker 死 ⇒ 只丢该任务，UI 收到 `onError`，可拉起新 worker。

★ **2026-10-18 已落地**（P3）：`NamedJob`（`name()` + `args()`，跨进程可重建）+
`ProcessWorker::serve($registry)`（`php://stdin/stdout` 行协议，异常变 `{ok:false}` 不许打死 worker）+
`ProcessTaskHost($command, $registry, $maxWorkers = 1)`（**懒启动 + 常驻 + 可扩池**；`drain()` 每帧非阻塞读 stdout/stderr；
`reap()` 检测进程死亡 ⇒ 在途任务走 `onError` + 下一帧自动重启）。
★ **实测**：首次 spawn + 往返 **256ms**、热态**往返 23.5ms**（本地 php.exe）——
对比"每任务一进程"的 275ms/次，常驻池的价值就在这个数上。
★★ **两条必须说清的代价**：① **异常类不可还原** —— worker 把 `RuntimeException: 消息` 拼成字符串，
UI 侧收到的是一个新的 `RuntimeException`（跨进程没有"同一个异常对象"这回事）；
② **单个 worker 是串行的**（`fgets` 一行 → 跑 → 回一行，它没有线程，见 §17.2.5）—— 要并行只能
   **多起进程**：`maxWorkers`（2026-10-18 补齐，**默认 1 = 与第一版逐字节一致**）。满载**排队**、
   不无界起进程（一个 worker 是一整套 AOT 运行时，几十 MB）。
   ★ **收益随任务变长逼近 N×**（本地 php.exe，3 条任务，`_build/bench-pool.php` 实测）：

   | 任务时长 | 池 1（串行） | 池 2 | 池 3 |
   |---|---|---|---|
   | 200ms | 943ms | 744ms | 593ms |
   | 600ms | 2122ms | 1569ms | 1020ms |
   | 1200ms | 3894ms | 2747ms | 1664ms（理论 ≈1600，已到顶） |

   ⇒ **更正（2026-10-18 复测，`_build/measure-spawn.php`）**：本节此前写"`proc_open` 同步 275ms/个，
   3 个 worker 的首帧要付 3 次"，**这是错的** —— 275ms 是"每任务一进程"的**往返**，
   不是 `proc_open` 的阻塞成本。拆开实测：父进程侧 `proc_open` **≈35ms/个**（3 个合计 **108ms ≈ 7 帧**），
   另外 **~240ms 是子进程自己的 boot，在后台并行进行、不占 UI 线程**。
   ⇒ 即便 200ms 的短任务，3 个 worker 也从 943 降到 593ms —— **开池不亏**。
   ★ 真实代价只剩**一次性提交突发**（3 个同时起 ≈ 105ms 的掉帧）⇒"每帧最多起一个"仍值得做（**没做**）。
   默认值保持 1 的理由不是成本，而是**并发度是应用的意图**，框架不该替它猜。
验证：smoke **734/0**（P3 段 17 条：懒启动 / 真进程算对 / 串行排队 / **池 3 条并行** /
**池上限排队** / 任务失败不打死 worker / 崩溃 ⇒ onError / 崩溃后自动重启 / shutdown）；
★ 踩坑：`static fn() => $sink !== []` 按值捕获**创建时**的空数组 ⇒
`$until` 恒 false ⇒ 每次 pump 等满 deadline（smoke 从 10s 涨到 78s）—— 判据闭包必须**收参数**。
★★ **AOT 运行面已验（2026-10-18 当天补齐）**：`apps/users` 的组合根按 `$_SERVER['argv']` 分流
（`--drift-worker` ⇒ `ProcessWorker::serve(JobRegistry::make())`，不进消息循环），
并真的用上了 `ProcessTaskHost`（首屏取数走后台，后端是 `php -S` 的慢 mock）。真机证据（`tools/probe-async.php`）：
- **进程数 = 2**（主 + worker）⇒ `proc_open([$argv[0], '--drift-worker'])` 在 AOT 下成立；
- **Loading 墨量 0.0% → Ready 8.3%** ⇒ 数据真的是"后到"的（不是启动时就有）；
- **关窗后残留 0** ⇒ stdin 关闭让 worker 正常收工，不留孤儿。
★ 顺带把 P2 登记的"resource + `stream_select` 在 AOT 下未验"也覆盖了：worker 里是 `file_get_contents`
（真 socket）拿到 15 行 JSON。★ 仍未验的是"worker 崩溃后自动重启"在 AOT 下的表现（解释器侧 smoke 已验）。
★★ **两个实现级事实（2026-10-18 同一台机器实测，都已落进代码）**：
① **`proc_open` 的句柄必须是 `socket` 而不是 `pipe`**：Windows 的 `select()` 只吃 socket，
   PHP 对 pipe 走"逐句柄猜"的退化路径 ⇒ `pipe + stream_select(0)` = **13.6ms/次**、
   `pipe + 非阻塞 fread` = **411ms/次**（"非阻塞"标记在 pipe 上等于没用）；
   换成 `['socket']` 之后是 **0.008ms / 0.001ms**。⇒ `drain()` **29.4ms → 0.013ms**、
   `frame()` 平均 **34.5ms → 4.24ms**。用 pipe 的版本在界面上就是稳定的 30fps 掉帧，
   而且**看不出是谁的错**（帧耗时里只有"慢"）。
② **AOT 产物里 curl 是能用的**（`curl_init` / `curl_exec` / `curl_setopt_array` + `CURLOPT_*`
   常量全部工作，实测 `code=200`）—— 之前 `drift-build` 只启 `mbstring` 是**保守选择**，
   不是能力限制。⇒ 运行时 ini 改为 **`mbstring + openssl + curl`**；`FetchUsersJob` 改用 curl
   （连接超时与总超时分离、能拿 HTTP 状态码、HTTPS 证书校验是一等公民）。

★ **"UI 会不会卡"的答案（实测）**：同步形态下一次 1.2s 的 HTTP = **1208ms 界面冻结**
（一帧都画不出来）；后台形态下 `attach` **116ms** 回到事件循环，此后每帧 `frame()` **4.24ms**、
`drain()` **0.013ms**（60fps 预算 16.7ms 的四分之一）。
#### 17.4.6 分阶段与判据

| 阶段 | 内容 | 判据（先写夹具再实现） |
|---|---|---|
| **P0** ✅ | `TaskHost` 队列 + `drain`；`SyncTaskHost` 改走队列；`Application::frame` 接线 | smoke：① submit 当帧**不**调 onDone ② 下一帧调 ③ onDone 里 setState **同帧**生效 ④ Job 抛异常走 onError ⑤ 无 TaskHost 时行为不变 |
| **P1** ✅ | `SlicedJob` + `submitSliced` | smoke：100 万次累加切 10 帧完成；每帧 `step` 耗时 < 5ms；结果与一次性算相同；中途取消不泄漏 |
| **P2** ✅ | `IoPoller` | smoke：socket pair 写端稍后写 ⇒ 可读回调在 poll 里触发（不阻塞）；unwatch 后不再回调 |
| **P3** ✅ | `ProcessTaskHost` | 真机：10 个任务并发、UI 帧不掉；kill worker ⇒ onError 且自动补员 |
| **P4** | `NativeJob`（C++ 线程池，`QueueUserWorkItem`） | 解码/哈希类任务，µs 级投递；线程里只碰 C++ 数据 |

★ 每个阶段都**独立可交付**：P0 之后即使不做 P1–P4，框架的异步语义也已经正确（只是"能力少"，不是"结构错"）。

#### 17.4.7 明确不做（以及理由）

- **不开 `Runtime::enableCoroutine`**：hook 是全局替换，开了之后非协程上下文的 `stream_select` 直接抛错（§17.2.6 实测）。
- **不用 Swoole 协程**：`Event::dispatch()` 阻塞到下一个事件（50ms 定时器 ⇒ 单帧 57ms），且协程耗尽后再调会 Fatal。
  ★★ **2026-10-18 复核：本条与上一条的旧证据都已推翻** —— `Swoole\Event` 不在官方 Windows 支持矩阵
  （Windows 走 IOCP），用它是**用错 API**。正确的 `Coroutine\Scheduler` / `Co\run` 模型实测
  1.2s HTTP 期间 tick 稳定 ~16ms、`longGaps(>50ms)=0`（探针 M/N，`probes/RESULTS.md`）。
  ⇒ 协程档（P2）从「不做」改为「待评估」，本节的结论在重做之前**不要引用**。
- **不做每任务 spawn 进程**：275ms/次（probes §12 ⑧）。
- **不做"线程里跑 AOT 代码"**：需要上游 `php::thread_init()`（§17.2.5 追测）。
- **不引入通用 Fiber 调度器**（第一版）：没有"顺序代码"消费者之前，它是过度设计。

#### 17.4.8 已知风险 / 回退

- **P0 是语义变更**（onDone 从"同步立即"变成"下一帧"）：当前 `TaskHost` **零生产消费者**（只有文档示例）⇒ 安全；但要在 DESIGN 与 `SyncTaskHost` 注释里写死这一点，避免后人当 bug 改回去。
- **P0 的 drain 与错误边界**：回调里抛异常不能拖垮帧循环 ⇒ `complete()` 内 try/catch，走 `Errors::report`（与 §5.2 的 S3 一致）。
- **P3 的 exe 分流**：GUI 程序带 `--drift-worker` 时要**不进消息循环**（`main()` 里先判参数）。
- 回退：每个阶段都是"新增 + 接线"，P0 的回退就是把 `drain` 去掉、`SyncTaskHost` 改回直调；不影响既有页面。

#### 17.4.9 帧时钟：`FrameClock`（P0 补丁，2026-10-18）

**缺陷（不是优化）**：平台的 `pump()` 阻塞在 `GetMessageW` 上 —— 没有输入事件时循环一直睡。
于是 `drain()` 只在"用户动了鼠标"时才跑 ⇒ **后台结果只能等下一条消息捎带**。
`TimerHost`（`SetTimer` → `WM_TIMER`）早已实现，但**全仓零调用者**，所以这条通路一直是死的。

| 决策 | 取值 | 理由 |
|---|---|---|
| 帧间隔 | **15ms**（不是 16） | `SetTimer` 量化到 ~15.6ms 系统 tick；请求 16 落到**第二**个 tick（31ms ⇒ 32fps）。探针 M 实测同源（`sleep(0.016)`→31.6ms、`sleep(0.008)`→16.5ms） |
| 何时跳 | `Application::wantsFrame()` | = `IoPoller::watchCount()>0` ∨ `TaskHost::pending()>0` ∨ **有动画在跑**（§17.4.10）。三件事都做完 ⇒ 停表 ⇒ **空闲 CPU 归零** |
| 谁来判 | **框架** | `TaskHost` 新增 `pending()`（在途 + 排队）：平台不知道"还有没有活"，只有框架知道 |
| 循环在哪 | **仍在组合根** | `FrameClock` 只做"起/停定时器 + 跑一帧"，`while ($platform->pump())` 一个字没动（§5.2 裁决 1） |

**卡顿护栏**：上膛期间量**两帧间隔**（不量帧内耗时 —— 那要读环境时钟，违反 G3）。
> 60ms ⇒ `DIAGNOSTIC`（进环形缓冲，随任何一次中止落盘，事后取证）；> 500ms ⇒ `RECOVERABLE`（dev 中止）。
> ★ 精度边界：`GetTickCount64` 粒度 ~15.6ms ⇒ 只判**量级**（"卡了一下"/"没卡"），不判精确耗时。

**判据（带反例自检）**：`tools/probe-async.php` —— **全程不发送任何输入**，
只量客户区**列表区**（y 220–640）的墨量。

| 构建 | Loading 列表区 | 2.5s 后列表区 | 结论 |
|---|---|---|---|
| 摘掉 `pace()` / `tick()`（反例） | 0.0% | **0.0%** | 界面永远停在 Loading ⇒ FAIL |
| 接上 `FrameClock` | 0.0% | **9.3%** | **1.94s** 自己切到 Ready（≈ worker 启动 + mock 的 1.2s + 一帧）⇒ OK |

★★ **同日抓到的量具假绿（两次）**：
① 旧判据量**整窗**墨量 ≥ 8%。实测**错误态**（"加载失败" + "重试"）也有 8.6% —— 导航条与卡片
边框占大头，列表那点字淹在里面 ⇒ 对"取数失败"也成立。现在只认列表区（错误态 ≈ 0%）。
② 旧探针把 `PHPRC` 指到只写 `opcache.enable=0` 的临时 ini，**覆盖**了 exe 同目录那份
（`drift-build` 写的，含 `extension=curl`）⇒ **worker 子进程**没有 curl ⇒ `curl_init()` 未定义
直接 Fatal ⇒ 界面本来就停在错误态。现在：临时 ini **以 exe 同目录那份为底**，只追加 opcache 关掉。
③（同一支量具的另一面，2026-10-18 重写时踩到）**客户区非空 ≠ 已画过第一帧**：窗口刚
`ShowWindow` 时应用还没画，`BitBlt` 抓到的是**那块屏幕上的残留**（实测列表区 7.2%，下一帧 0.0%）
⇒ 会把"首帧前的垃圾"读成"一开始就有内容"。探针加 `--settle=200`（远小于 mock 的 1.2s）。

★ 只有 `apps/users` 接了帧时钟（唯一有异步消费者的应用）。`hello` / `showcase` 的
`wantsFrame()` 恒 `false` ⇒ 接不接行为相同；它们有异步时抄组合根那三行即可。

#### 17.4.10 动画入口：`State::tickFrame()`（2026-10-18）

**缺陷（同样不是优化）**：`SpinnerState::tick()` 从写下来那天起**零调用者** —— 相位永远停在 0，
"转圈图"是一张静止的弧。这不是"动画没做"，是**框架没有"每帧推进"的入口**：`frame()` 依次做
IO / 任务 / settle / layout / paint，**没有任何一处问过"有没有东西要动"**。

| 决策 | 取值 | 理由 |
|---|---|---|
| 钩子放哪 | `State::tickFrame(float $timestampMs): bool` | 动画相位是纯 UI 态（§11.5），本来就住 `State`；Widget 是不变的声明 |
| 返回值 | `bool` = "**我还要下一帧**" | 直接喂 `wantsFrame()`；**不需要注册 / 注销** —— 卸载的元素不在树里，天然不再被调用 |
| 谁来遍历 | `Application::tickAnimations()` | 在 `pumpSliced()` 之后、`Frame::run()` **之前** —— `tickFrame()` 里的 `setState()` 由紧随的 settle 段消费，**同帧生效**（放到 settle 之后会慢一帧） |
| 走几棵树 | **两棵**（`$root` + `$menuElement`） | 与 `focus->collect()` / `placeMenu()` 同一个坑：只走主树 ⇒ 菜单里的 `Spinner` 永远不转，而且**不报错** |
| 是否短路 | **不短路** | 已知 `$busy` 为真也要走完兄弟子树，否则"多个 Spinner 只有一个在转" |

**为什么不是回调注册表**（`onFrame()` / `cancelFrame(id)`）：注册表必须**配对注销**，
`dispose()` 漏调一次就是**永久空转 + 泄漏**，还要防"回调持有已卸载的 State"。树遍历的
生命周期天然正确（元素卸载 ⇒ 不再被访问）。代价是每帧 O(树) —— 与同帧的 `layout()` 同阶
（§13.3 本就全树重排），不是新增量级。

**判据**（`probe-async.php` ⑦⑧，量"帧间变化**像素数**"）：

| 构建 | Loading 窗口（每 70ms 一格） | 切换那一格 | Ready 后 | 结论 |
|---|---|---|---|---|
| `$this->animating = false;`（反例） | **全 0** | 41838 | 0 | ⑦ FAIL（画面完全不动） |
| 接上 `tickAnimations()` | **116 ×15（15/15 格）** | 41838 | **0** | ⑦⑧ OK |

★★ **量具的第三次假红（同日）：采样周期撞上了动画周期。** 最初用 100ms 采样，判据是
"前 8 格每格都得有变化"，跑出 **6/8** 就报红 —— 但界面明明在转。根因不是应用：`Spinner`
的相位以 **8 帧**为周期（8 × 15.6ms ≈ 125ms），而 `Sleep(100)` 加抓图开销 ~8ms ⇒ 实际
间隔漂到 ~108ms，偶尔正好落 **8 帧** ⇒ 相位回到原点 ⇒ 相邻两格**像素完全相同**。
修法两条，都在量具侧：① 间隔改 **70ms**（≈4.5 帧，与 8 帧周期不成整数倍，撞不上）；
② 判据按**实测的切换时刻**算 Loading 窗口（`crossedAt / interval`），不写死"前 8 格"
—— 写死就是在赌时序。

★★ **为什么用"变了多少个像素"而不是百分比**：`Spinner` 只有 24×24，占整窗 0.1% 不到 ——
百分比会被四舍五入成 0.0，"在转"和"不转"长得**一模一样**（本轮第三次假绿风险）。
绝对计数是二值信号：转 ⇒ 116；不转 ⇒ **精确 0**（`BitBlt` 同像素同值）。

★ **⑧ 的必要不充分**：帧间变化 0 只证明"没有像素在变"，**不能**证明"定时器停了"
（空转但不重绘也是 0）。CPU 归零的正面证据是 `FrameClock::armed` 在 Ready 后为 `false`；
进程 CPU 时间采样本轮没做 —— 记在这里，别把 ⑧ 当充分条件。

★ **⑦ 的阈值是统计量，不是"每格都必须动"**：`movingCount >= max(3, 60% × Loading 格数)`。
反例是 **0 格**，与下限（实测 9~10）差一个量级 —— 留出余量是因为采样与帧时钟本来就不是
同步的，要求"每格必动"等于在赌两者不共振（上面那条假红就是这么来的）。

#### 17.4.11 插值原语：`AnimationController` + `Curve`（2026-10-21）

**缺陷（不是"没做动画"）**：§17.4.10 把**帧驱动**铺好了（`tickFrame()` → `$animating`
→ `wantsFrame()` → 帧时钟起停），`Spinner` 也真的在转。但除此之外**没有第二个动画** ——
任何过渡（淡入、滑动、进度条跑到一半）都只能在 State 里手搓三件套：
**起手时间戳 + 上次值 + 完成标志**。抄第二遍时三件套就会漂：谁忘了"完成标志"
于是每帧重跑收尾逻辑，谁把墙钟当时间戳于是解释器与真机对不上，而**两个都不报错**。

| 决策 | 取值 | 理由 |
|---|---|---|
| 放哪一层 | **`Drift\Widget`** | 它只被 `State` 持有，且 §4.2 的 Widget 行就是"声明式 UI 基座"。新开 `Drift\Animation` 要同时改 §4.2 表、§4.3 允许集、lint 的 `LAYERS` —— 三处改动换一层只有一个文件住的地方 |
| 值的形状 | **单值 `0..1`** | 不是 `Vec4` / 不是"任意属性插值"。单值能喂所有已有控件的数值入口（`Progress::value()` / `SizedBox::width()` / `Text` 的内容），而通用插值要先有层语义（§17.4.11 下文） |
| 时间基准 | **注入的帧时间戳** | `tick(float $timestampMs)` 由 `tickFrame()` 传入（G3：不读环境时钟）⇒ 同一棵动画树在"解释器 smoke"与"AOT 真机"里推进**完全一致**，夹具能预测到第几帧到终点 |
| 起点在哪 | **第一帧才钉**（`startPending`） | `forward()` 手里没有时间戳。用墙钟记起点 = 起手那一帧就吃掉一个 15ms 间隔，短动画整体快一档，且解释器/AOT 两侧还各差一点 |
| 反向时长 | **按剩余距离缩** | `reverse()` 从 `0.6` 回 `0` 只跑 `0.6 × duration` ⇒ 速度恒定。从中间被打断再回退若用满时长，速度会突然慢一截 |
| 终点那一帧 | **返回 `true`，下一帧才收口** | 调用方写的是 `if ($busy) { markDirty(); }`。终点帧若返回 `false`，终点值就没人标脏 ⇒ 画面永远停在 99%，**且没有任何报错**。多花一帧（15ms）换"调用方不可能写错" |
| 完成回调 | **一次性槽**，触发即清空 | 与 §17.4.10 拒掉的"帧回调注册表"不是一回事：注册表要**配对注销**（漏一次 = 永久空转 + 泄漏）；这个随 State 一起死，不存在注销问题 |
| `jumpTo` | **不放完成回调** | 打断的语义是**取消**。把跳转当完成，会让"打断"顺手把副作用放一遍（回弹 / 上报 / 换页） |

★ **`Curve` 为什么是 `int $kind` + `===` 比较链**（同 `ToggleBase` 的裁决）：
`map()` 是纯函数表，没有多态需求；而 tpc 会把只有一个实现的虚方法**去虚化**。

★★★ **本仓 `src/` 此前零处 `switch`，而它过不了 AOT**：tpc 给
`switch ($this->kind)` 生成的属性读**不加 `php::toInt()`**，落成
`php::Int tmp_var_0 = this_.attr(...)` ⇒ **C2440**（`Variant` → `Int`）。
**解释器 + smoke 全绿，只有 T2 构建看得见。**⇒ 与 §2 的 `?:`（两支类型不同）、
§24.2 的类常量同名，同属"AOT 比 PHP 严"的清单。

**边界（EVAL §7 第 4 项的前提，明确不做）**：`Opacity` / `Transform` 这类
"整棵子树一起动"的包装要先有层合成语义。本轮给的是**值** —— 喂给已有控件的数值
入口就够动；真要做子树过渡，先补 `RepaintBoundary`。

**判据**：

| 层 | 判据 | 结果 |
|---|---|---|
| ① 控制器算术 | smoke **21 条**（曲线端点 / 入参夹取 / 中点手感 / 起手钉起点 / 半程 / 终点精确 / `onCompleted` 一次性 / `jumpTo` 不回调 / 反向缩时长 / 已在终点幂等 / `duration<=0` 不除零） | 全过 |
| ② **链条** | `State::tickFrame` → `animating` → `wantsFrame()` → **`builds` 真的增长** → 收口后回落且**不再增长**。① 全对而 ② 断了 ⇒ 界面静止且不报错 | 全过 |
| 反例自检 | 抽掉 `tickFrame` 的 `markDirty` / 抽掉 `Curve::map()` 的夹取早退 / 终点帧改成直接 `finish()` | **3/3** |
| T2 真机 | `probe-grab --samples=14 --interval=50 --motion` 点「播放」：`c1..c8` 全 > 0（7969 / 4702 / 2915 / 3965 / 3018 / 2201 / 2379 / 2382），`c9..c13` **精确 0** | 动 ⇒ 停，两头都对 |

★★ **同轮抓到的第二条（页面级）**：`SizedBox::height()` 小于子内容会 **Fatal 掉整帧**
—— `RenderText` 报「画出的尺寸超出父容器给的可用空间：实际 8.0 × 50.4，最多只能用
8.0 × 0.0」。真机现象是**点进页面界面一动不动、也没报错**（帧被中止 ⇒ 旧画面留着）。
定位靠 `Errors::setLogPath()` + `Frame->run()` 的栈帧，不是靠盯屏幕。
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

| 里程碑 | 内容 | 出口判据 | **当前状态**（2026-10-15 按 `src/` + `tools/` 核验） |
|--------|------|---------|------|
| **M0 骨架** | PSR-4 分层 + 契约文件 + `tools/smoke.php` + **公共面按 `@api` 标注**（决定 lint 骨架） | 冒烟全绿；依赖方向 lint 有断言；`internal-leak` 双向自检过 | ✅ **已完成** —— PSR-4 分层 / `@api` 标注（76 类型）/ `smoke.php`（315 ok）/ lint 骨架（14 条落地）全在  |
| **M1 可显示** | Win32 Platform + RenderSurface(Skia CPU) + Canvas 接口 + 文本栈桥接 + DPI 缩放 + **`DisplayList` 录制**（决定渲染路径） | 一个窗口画出「Hello Drift」（§A.1），分数缩放下不糊 | ✅ **出口判据已兑现**（2026-10-15）—— `apps/hello` 经 `tpc -m bin` **真机构建**出 `hello.exe`（1.5 MB），运行后窗口（640×400 客户区）画出居中的「Hello Drift」（截图核对；执行入口 `php tools/drift-build.php hello`）。<br>★ **落地路径与本文原计划有一处偏差，如实记下**：表面走 **`Win32RasterSurface`**（`RasterCanvas` 软光栅 + `SetDIBitsToDevice`），**不是** `RenderSurface(Skia CPU)`。理由：§7.3 的 M1 分期原文就写"CPU 光栅 + 位块传送"，且 `SurfaceProvider` 这层抽象存在的意义正是"**后端可换**"（换 Skia 只改 `Win32SurfaceProvider` 一处，应用侧一行不改）⇒ `SkiaCanvas` 归 M4+。<br>★ **分数缩放（2026-10-16 修）**：原实现把进程设成 **system-DPI-aware（V1）**，并错误论证"跨屏会发 `WM_DPICHANGED` ⇒ 无差别"（§24.1 #43）—— V1 进程跨屏会被系统**位图拉伸**（= 糊）且**收不到**该消息。已改为 **Per-Monitor V2** + `GetDpiForWindow` + `AdjustWindowRectExForDpi`。真机探针断言 **感知级别 == PER_MONITOR_AWARE_V2**（**带反例**：V1 必须 FAIL）；分数 dpr（1.25 / 1.5 / 2.0）的量化算术在 smoke 有断言。<br>★ 仍未验（不阻塞 M1 判据）：**真·跨屏拖动**（本机单屏 100% DPI ⇒ 只验了"感知级别正确"，没验"跨屏后的实际画面"，§24.2 #11）、跨模块调用性能。<br>★ **`SkiaCanvas` 2026-10-16 已落地并真机验**（M1 遗留 ②）：`Win32SkiaSurface` 接进同一个 `SurfaceProvider`，后端由组合根 `new Win32Platform('skia')` 选（**默认仍 `soft`**）⇒ 真机 `hello.exe` 经 Skia 后端画出「Hello Drift」（484 个非白像素 + 抗锯齿灰阶；软光栅对照 597 个纯色、无灰阶）。`SkiaTextMetricsProvider`（真字体重排版）仍归 M4。<br>★ 平台侧 `Drift\Platform` 9 + `Win32` 8 + `cpp-src/` 4 + `apps/hello` 4 个文件全在；渲染侧（`RenderSurface`/`Canvas`/`DisplayList`/文本栈/`RasterCanvas`）齐  |
| **M2 三树 + 布局** | Element/State/调和 + RenderBox 两阶段布局 + 首批控件 + **fluent-first 全部落地** + **盒样式（`BoxStyle` + `BoxWidget`，§15.7）** + **焦点遍历顺序** + **光标形状（命中三方消费）** + **`SubscribingWidget` 接口** + **property hooks 进 Store** + **`Each` key 必填** | 布局 conformance 夹具全过；首批控件过 **W1–W8**；Tab 序有 conformance 夹具；`Each` 缺 key ⇒ 构建期报错 | ✅ **已完成（2026-10-17 收口核验）** —— **四条出口判据全部兑现**：① `tools/M2Conformance.php` **14 ok / 0 FAIL**（2026-10-17 实跑；W1–W8 + §9.1 Tab 序夹具）⇒「布局 conformance 夹具全过 / 首批控件过 W1–W8 / Tab 序有夹具」三条一并兑现；② **`Each` 缺 key ⇒ 构建期报错**以**构造参数必填**形态兑现（`Each::__construct(callable $keyFn)` —— 比「报错」更强：缺 key 的 Each 根本造不出来，G1 结构化必填），运行期**重复 key** 响亮失败（smoke `eachdup` 用例：exit 3 + `Errors.log` 留痕；`1` 与 `'1'` 不算重复的反面也钉住）。<br>历史沿革：三树/调和/两阶段布局/13 个控件/fluent-first/`BoxStyle`+`BoxWidget`/`SubscribingWidget`/property hooks/`Each` key 全在；**`TextField` 2026-10-15 已落地**（§24.1 #32 闭环）；**`FocusNode`（焦点遍历顺序）2026-10-15 已落地** —— `FocusManager` 的 Tab 序（默认文档序 / `skipFocus` 整体跳过 / `focusOrder` 同容器稳定排序）可跑；**`Spinner` 2026-10-16 已落地**（§11.5 `Loading` 分支；三段形态 + `RenderSpinner` 8 点 alpha 环，smoke 14 条断言 + M2LayoutPage 示例）；**`CursorShape`（命中三方消费）随 M1 输入层已落地**（enum + 整条命中链 `Widget→RenderObject→FocusManager→dispatchInput→Win32 setCursor` 接好，smoke 有光标断言，见 §753）；★ **conformance 夹具已建**（2026-10-16，`tools/M2Conformance.php`）—— W1–W8 静态 + runtime 判据集（token 级量具带反例自检），`M2LayoutPage` 即夹具页，M2 出口判据「夹具全过 / 首批控件过 W1–W8 / Tab 序有夹具」首次有可跑落点；常驻门 `drift-conformance` 仍归 M4（§16.2）  |
| **M3 数据缝/多页** | Store 三件套 + `SubjectBuilder` + **`select()`** + RouteTable/PageHost + **动作缝（`CommandRouter` + 冒泡链）** + **三态 enum** + **错误边界容器**（★ 2026-10-14 裁决：**归 M3** —— 见 §23.2 O-12）+ **AcceleratorTable** + **自绘 MenuBar/ContextMenu** + **应用内拖放** | §A.2 的完整应用可跑；局部态与跨页共享两条路径都能写；`Root` 只组合（lint 可查）；Ctrl+S 等加速键可用；§16.6.3 的 **17 条规则全部双向自检**过（`apps/users` 过全部 lint；两条修过的规则已做反例自检） | ✅ **M3 出口判据已兑现（2026-10-16）** —— `apps/users` 完整样板已建（20 个 PHP 文件，`apps/users/`）：① **§A.2 的完整应用可跑** —— **真机**：`tpc apps/users/project.yml -O2 -m bin` 出 `users.exe`（2.1MB，`Build successful`），运行后窗口 900×640 起来、客户区非白像素 11%（4 个"删除"按钮的 `rgb(209,36,47)` 清晰可辨）、进程保持消息循环、无 `Deprecated` / 无 Fatal；**无头侧** smoke 18 条断言；② **局部态与跨页共享两条路径都能写**（`SignupForm` property hooks / `AppStore` + `StatusBar` 专职订阅）；③ **`Root` 只组合**（`rootBuilds()===1` + 全树过 lint）；④ **Ctrl+S 可用**（加速键第 ① 环 ⇒ `form.submit`）；菜单 / 拖放 / 错误边界全在线。旧状态存档：值缝**M3 的 0 文件已清零** —— `Widget\Menu` 四件（`MenuBar`/`Menu`/`MenuItem`/`ContextMenu`）与 `Drag` 两件（`Draggable`/`DragTarget`）**2026-10-16 已落地**（自绘菜单 + 应用内拖放兑现；错误边界容器与 `AcceleratorTable` 同日落地，见 §4.2 各行注）；★ **多页外壳 2026-10-16 已落地**（`Dock` 五槽 + `AppBar` + `NavButton`，smoke 21 条 + ShellPage 示例；§A.2.6 `UsersRoot` 控件面齐全，只差 `Draggable`）；★ **命令激活键盘侧已接线**（焦点按钮 Enter/Space ⇒ `dispatch()` 带 `CommandArg` —— §9.1；指针激活等 M4 的 `PointerEvent::up()`）；§A.2 样板刚对齐（O-18），`apps/users` 未建；★ **浮层机制已裁（O-20，按 A）** —— `Menu` / `ContextMenu` / 模态（`showModal()`）共用第二棵根渲染树，实现不再被“不存在的原语”卡住  |
| **M4 工具链 + 异步** | drift-preview / golden / conformance / drift-lint / **drift-doctor** 齐备 + **TaskHost 线程池后端** + **`notifyChanged` 线程断言** + **ThemeChanged / 深色模式** | 无头 preview + golden diff 可跑；后台 IO 不卡帧；**worker 里 notify ⇒ 响亮失败**；跟得上系统主题切换 | ⏳ **部分** —— `drift-lint` / `drift-preview` / `drift-codegen` 三件在；`golden` / `conformance` / `drift-doctor` 未建。★ **`TaskHost` 端口已建**（2026-10-16：`Job` + `TaskHost` + `SyncTaskHost`，后端 B）—— **线程池后端 A 被 tpc 能力卡住**（⛔ §17.2.2 / §24.2 #1），故 M4 的"后台 IO 不卡帧"这条出口判据**当前不可达**，需先等 tpc 补"每线程进入请求"的钩子。★ **`ThemeChanged` 事件通路已建（2026-10-17）**：零件盘点——查询侧早已齐（`ThemeData::forSystem(SystemTheme)` 值对象 + `Win32Screen::systemTheme()` 实时读注册表 `AppsUseLightTheme` 的桥 `drift_win_system_dark` + `advapi32.lib` 早已链接（`build/base.yml` 注释预留））；本轮补**事件侧**——`ThemeChangedEvent` 空值对象（`src/Input/`，载荷为空：新主题由消费者实时查 `Screen::systemTheme()`，事件只回答"变了"）+ C++ `WndProc` 的 `WM_SETTINGCHANGE` 分支（过滤 `lParam == L"ImmersiveColorSet"`—— 时区/语言/显示设置也发这条，必须过滤）+ `toArray` 的 `kEvTheme` ⇒ "theme" 映射+ `Win32Platform::pump()` 翻译入队 + `Application::dispatchInput` 显式消费分支（不落 default 丢弃）。真机验证：`drift-build hello`（C++ 编译通过，107.4s）+ 窗口探针（消息循环未受影响）。★ **2026-10-17 已闭环（§24.2 #14）**："主题变了界面跟着变"的最后一段是**接线不是新机制** —— `Element::applyContext()` 本就是"换一份渲染环境（**主题切换的唯一入口**）"，且已实现两种兑现时机（无状态立即重建 / 有状态只标脏等帧末 flush）+ 递归只走一遍，但 src/ 里**零生产调用者**。本轮加 `Application::onSystemThemeChanged()`（§A.2.5 的应用钩子，无参 —— 框架不持平台 ⇒ 应用自己重读系统主题）+ `applyNewContext()`（根 + 浮层两棵树下发 `withTheme()`），并在 `dispatchInput` 的 ThemeChangedEvent 分支按"**先钩子、再下发**"接线。★ 同轮抓到一个**死分支**：`Application.php` 漏 `use Drift\Input\ThemeChangedEvent;` ⇒ `instanceof` 解析到 `Drift\App\` ⇒ 恒 false 且不报错 ⇒ 上一轮加的消费分支从未执行过（"instanceof 漏 use"第二次咬人）。⇒ M4 出口判据"跟得上系统主题切换"**三段（查询 / 事件 / 界面重建）全部兑现**。  |
| **M5 业务底座** | 虚拟化（含 §12.3 的 State 外置纪律）+ 脏矩形 + **WindowStateStore** + Windows UIA 桥接 | 长列表滚动不掉帧；子树崩溃不整树陪葬；窗口位置/尺寸跨启动保留 | 🔶 **部分** —— **虚拟化已落地**（2026-10-18，§13.4 `LazyColumn` + `Scrollable` + `RenderScrollView` 的 `virtualExtent`/`sliceOrigin`；10000 行单帧 96ms → ~0.2ms，示例台 `BigListPage` 为消费者）；★ **`WindowStateStore` 已落地**（2026-10-18，§5.4：`Paths` + `WindowStateStore` + `WindowHost::position()/place()` + `Platform::configDir()` + 壳 3 原生；两个应用各 2 行接线，落盘 `%APPDATA%\Drift\<app>.window` 的 kv 文件，smoke 12 条夹具）；⏳ 脏矩形（重估触发见 §13.5）· **UIA 桥接 P2 数据流已落地**（2026-10-19：`WindowHost::pushSemantics` + `drift_win_uia_push` + C provider，真机跨进程读到语义 Name；树导航 Fragment 已落地（P3，真机 FindAll Descendants 遍历整棵语义树）；`RaiseAutomationEvent` 已落地（结构变化，count 变触发，真机收到 2 次；焦点变化，`SemanticsBuilder::build(root, focused)` 打标、push 时焦点索引变触发，真机 Tab 切焦点收到 5 次）)；★ 语义树（`SemanticsTree` —— UIA 桥接的硬前置）2026-10-18 已落地  |

★ **状态列是 §20 纪律 4 在里程碑层的落点**：出口判据写“要做”不算数，`src/` 里有没有才算。§4.2 已按同一口径加了逐类型状态列（点名 94 个：**79 有文件 / 15 个 0 文件**） —— **两处必须同步维护**（否则又会出现“文档说已落地、代码还没写”的分裂）。★ `drift-doctor`（§16.2 工具集之一，O-10 已裁“做”）正是该**自动**输出这张表的工具 ⇒ 手工列只是它的**临时输入**，别为它新造机制。

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
| **A17** | **子树没有错误边界**（★ 真实症状由**帧循环异常策略**决定 —— 见 §23.2 O-12） | 一个坏页面带走整个窗口 | **帧循环异常策略 S3**（有边界 ⇒ 容器捕获；无边界 ⇒ Fatal 序列）+ **容器与纪律同里程碑（都在 M3）** | §23 O-12（★ **容器 2026-10-16 已落地**：`ErrorBoundary` 三层捕获 + `ErrorPlaceholder` 占位 + `Frame` 无边界 Fatal 半边，smoke 12 条） |

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

> **§23 优化提案（v2 修订期间新增）已拆分到 [`docs/_archive/design-proposals.md`](docs/_archive/design-proposals.md)。** 含 §23.1 已落地清单、§23.2 提案表（O-01~O-17）、§23.3 批量批准清单与依赖图。

## §24 v2 变更记录 · 开放项 · 例外登记

> **§24.1 v2 文档内不一致修正记录已拆分到 [`docs/_archive/changelog-v2.md`](docs/_archive/changelog-v2.md)。**

### 24.2 开放项总表（全部 ⏳ 集中在此）

| # | 开放项 | 为什么开放 | 何时必须收口 |
|---|--------|-----------|-------------|
| 1 | ~~**`ts_resource(0)` 之后的完整线程安全**~~ | ✅ **2026-10-16 已收口（探针 K，结论负面）** —— 不是"竞争"而是"**缺请求上下文**"：类/对象/属性访问在 worker 上必崩（`threads=1` 即 SIGSEGV，根因 = `php_request_cache` 只在 RINIT 里建）；字符串 intern 路径确实不并发安全。**⇒ 转成对 tpc 的能力请求**：需"每线程进入请求"的入口（分配请求缓存 + `php::request_init()`，**不启动 main()**）。详见 `probes/RESULTS.md` §11 / §17.2.2 | **tpc 提供该入口后**（届时才谈后端 A） |
| 2 | **`-m lib` 的热路径跨模块性能差** —— 对照 §15.3 的 0.92 µs/节点 | GUI 仓 spike 只验了"对不对"，**从未量"快不快"** | 决定"形态 B 能否做性能敏感场景"时 |
| 3 | **`#[Native]` 类在 lib 模式下的实际限制** | 只知道"必须标 `#[NoExport]`" | 若想用 `#[Native]` 加速热路径对象 |
| 4 | **CRT / PHP 版本不匹配的实际症状** | 部分已知（`LNK2038`），lib 专属症状未验 | 第三方分发时 |
| 5 | **跨 `.dll` / 跨线程的"异步闭包"** | 同步闭包已验；异步未验 | 若走 O-03（`Job` 类）则**不再是阻塞项** |
| 6 | **ICU 打包策略** —— ★ **2026-10-16 实测收口一半**：ICU **不可省**（`skshaper.lib` 硬引 `SkUnicodes::ICU::Make`）+ 边际仅 **~1.86 MB** ⇒ "退最小实现"档**否掉**；`icudtl.dat`（778 KB）必须随 exe 分发（**按 cwd 找**，缺失即 `check(fUnicode)` abort）。**剩一项待验**：能否内嵌进 exe —— `udata_setCommonData` 在 `icu.lib` 里，但 **ICU 公开头未随包**（`include/unicode/` 为空）、`SkUnicode_icu.h` 只暴露 `Make()` ⇒ 当前无干净路径，需自建 PE 资源注入机制（未实测）。详见 `SKIA-BUILD.md` | 见 `SKIA-BUILD.md` | M4 落地 `SkiaCanvas` 时（**"内嵌"可先接受"同目录分发"，不阻塞**） |
| 7 | **§17 线程模型是否最终定案** | 原与"能否切 `--nano`"互为条件；★ **nano 已明确不做（§16.7.5，2026-10-14）⇒ 该约束解除**。★ **2026-10-16：TSRM 补枪已完成（同表 #1，探针 K），结论是后端 A 不可实现** ⇒ **线程模型事实上已定案 = 只上后端 B（同步三态）**，后端 A 转为"tpc 能力请求"挂起 | ✅ **已定案**（2026-10-16） |
| 8 | ~~**`--nano` 真机跑通**~~ | ★ **已移出开放项** —— 2026-10-14 裁决：nano 不作为前期重点（§16.7.5） | — |
| 9 | **`-m lib` 的 D4**（`*_arg_N_default_value()` 导出前缀） | 上游可能已连带解决 | 形态 B 首次真机构建时 |
| 11 | **真·跨屏拖动未验** —— 进程 DPI 感知已是 **Per-Monitor V2**（`tools/probe-window.py` 直接断言过，带反例），但**本机单屏 100% DPI** ⇒ "跨到另一块不同 DPI 的显示器后画面是否正确重排 / 不糊"**从未实测** | 需要**第二块不同 DPI 的显示器**（或改系统 DPI 后重启），当前环境不具备 | 有双屏 / 可改 DPI 的环境时（探针已带感知级别断言；跨屏后的**画面**仍需人肉看） |
| 10 | **`TaskHost` 端口目前没有 AOT 消费者** —— `Job` / `TaskHost` / `SyncTaskHost` 三件已落地（2026-10-16），但设计里它们的**第一个消费者 `HttpUserSource`（§A.2.8）归 M4**，`apps/users` 在 M3 只发 `MemoryUserSource` ⇒ 端口的 **AOT 可达性**只被 `drift-codegen` 门覆盖到"翻译层"，**没有真机使用点**（AOT 只编译**可达**代码 ⇒ 没人用就可能整块不生成） | ① 三条：`tpc` 会为 `sources` 里的文件做翻译，故语法/类型层有门；② **真机使用点缺失**是结构性缺口，不修 tpc 补不了；③ 注意这与 #1 不同 —— #1 是"线程池不能用"，本条是"端口没人用" | `HttpUserSource` 落地时（M4）；或先给 `apps/users` 接一个**本地 Job 源**（会动 `MemoryUserSource` 的构造签名 ⇒ 需先裁决） |
| 12 | **Skia 预编译库的分发方式 + 无条件链接的代价** —— 包约 **141 MB**（`include/` + `modules/` + `src/` + `out/Release-x64/*.lib`）⇒ **不宜入库**（Drift2 只引用它的头与 lib）。选项：仓外固定路径（`cpp/skia` 软链 / 环境变量）/ 下载脚本 / git-lfs / submodule。★ **第一档已落地**（2026-10-16）：本地固定路径 `cpp/skia`（141 MB）+ `cpp/fonts`（21 MB，Noto Sans SC / OFL），两者都在 `.gitignore` 里；做法 = "从上游参考实现仓拷一份到本地"，与 §4.6 的 `include:` 同规 —— **exe 必须以仓库根为 cwd 启动**（两者都按相对路径查找）。★ **无条件链接的代价已实测**（2026-10-16）：`hello.exe` 无 Skia **2.483 MiB** ⇒ 含 Skia **6.240 MiB**（**+3.76 MiB / +151%**），而 Skia 现在是 `build/base.yml` 的**无条件项** ⇒ 每个应用都付这笔。★ **"按应用 opt-in"已裁决（2026-10-16）：不做**，理由三条：① **Skia 是终局、不是可选增强** —— 位图字模自己写着"只作测试夹具"（`tools/font-gen-glyphs.py` docstring）、真塑形（`SkiaTextMetricsProvider`）要 HarfBuzz（PHP 侧无等价物）、`RenderPopupPanel` 的投影必须外溢到矩形之外 ⇒ opt-in 机制只会存续到 M4 再被丢掉；② **过渡期零成本** —— `hello` / `users` 都是开发夹具，不发布，没人受益；③ **绝对量不算大** —— 3.76 MiB 对桌面 GUI 产物是小数目（对照上游参考仓 demo exe **12.76 MiB**）。★ 形态 B（`-m lib`，§10.2.6 的 SDK 形态）落地后 Skia 在 `drift.dll` 里**只编一次**、多应用摊薄 ⇒ "每个应用 +3.76 MiB"只对形态 A（单 exe）成立 | 二进制体积远超源码仓的合理范围（141 MB 不入库）；分发第一档已落地 | 分发第一档已定（本地固定路径）；**opt-in 已裁决：不做**（2026-10-16，理由三条见左）；第三方取包随形态 B 的 SDK 分发 |
| 13 | **Skia 拆成独立动态库（"框架 DLL 不带 Skia"）** —— 用户提案（2026-10-16）：真实应用由安装程序分发 DLL，能否把 Skia 编成独立 DLL、不每次链进核心 DLL。**已分析，结论：现在不做，等触发条件。** ★ **机制先例已在仓内**：`cpp-src/drift_win32.cc::enablePerMonitorDpi()` 已用 `LoadLibrary` + `GetProcAddress` 动态取 `SetProcessDpiAwarenessContext` / `SetProcessDpiAwareness`（三级回退，书面理由"动态取不碰编译宏"），且该路径**真机探针验过**（V2 断言带反例）⇒ "懒加载可选 DLL"是既有手法，不是新机制。★ **两条技术路线**：① tpc `-m lib` 分包 —— **import 表加载期解析 ⇒ 用软光栅的应用也必须带这个 DLL，否则进程起不来** ⇒ 可选性反而更差，且带"必须源码树 tpc"硬约束（§10.2.2）；② **纯 C DLL + 懒加载**（推荐形态）—— 桥内 `LoadLibrary`、首次用 Skia 才解析 8 个函数指针，软光栅应用永不加载、可以不部署，绕开 tpc lib 模式与源码树 tpc 约束，ABI 只有 8 个 C 函数 ⇒ 跨版本极稳。★ **已量过的收益**：省构建时间 **无收益**（剥掉 Skia 的构建 14.7s vs 含 Skia 的 11.5 / 15.4 / 16.7s，同一区间噪声内 —— Skia 是预编译 `.lib`，链接器按需抽取）；省磁盘 **单应用零收益**（DLL 还是那 3.76 MiB，只换容器），**多应用共装一台机器才真收益**；独立升级 Skia 收益弱（钉死的预编译包 + 8 函数 ABI 极稳）。★ **代价（诚实写清）**：① 跨 DLL 调用 —— 与 §10.2.4 代价 1 的"PHP 热路径丢内联"**不同类**（我们单次调用的主体是 Skia 自己的光栅化工作），但 `pushClip` / `popClip`（`save` / `clipRect` / `restore` 是便宜操作）会被放大 ⇒ **未测**；② 失败变晚 —— 缺 DLL 在首次建画布时才响，须做成**响亮 Fatal**（G2 兼容），软路径永不触发；③ **第二条构建路径** —— DLL 要用 `cl /LD` 单独构建，`drift-build.php` 的 T2 闸门覆盖不到它。★ **为什么现在不做**：三个前提今天一个都没有 —— 框架还不是 DLL（Drift2 目前 **0 个 lib 工程**，全部 `mode: bin`）、SDK 分发形态未立项、混装场景不存在（0 个真实应用）⇒ 按"每加一层能力先问现在谁真用它"：今天 **0 个消费者** | 无消费者（0 个真实应用）；新构建路径脱离 T2 闸门；跨 DLL 退化未测 | **触发条件（写死）**：① 形态 B（`-m lib`）立项 —— 框架成为 DLL 后"Skia 在不在框架 DLL 里"才成为真问题；② 或一台机器需**混装**带 / 不带 Skia 的应用。★ 真做时用**纯 C DLL + 懒加载**（不碰 tpc lib 模式），失败做成响亮 Fatal |
| 14 | ✅ **已闭环（2026-10-17）—— 是接线、不是新机制**：~~全树重建机制（主题变更 / DPI 变更 ⇒ Element 树标脏重建）~~。**更正原判断**：`Element::applyContext()` 本就是"换一份渲染环境（**主题切换的唯一入口**）"，且已实现两种兑现时机（无状态立即重建 / 有状态只标脏等帧末 flush）+ 递归只走一遍 —— **缺的只是生产调用者**（src/ 零调用，只有 smoke 夹具在调）。本轮加 `Application::onSystemThemeChanged()`（§A.2.5 钩子，无参）+ `applyNewContext()`（根 + 浮层下发 `withTheme()`），dispatchInput 按"先钩子、再下发"接线；不跟随系统主题的应用因 `applyContext()` 的 `===` 幂等短路 ⇒ **零重建成本**。★ 同轮抓到死分支：漏 `use Drift\Input\ThemeChangedEvent;` ⇒ `instanceof` 恒 false（第二次咬人）。DPI 变更同路（`Screen::scale` 变了走同一个 `applyNewContext()`）。~~原记载：环境变了必须有人标全树脏（根上递归 `state()->markDirty()`，下一帧 settle 逐个重建；State 经 Element diff 保留）—— 该描述被 `applyContext()` 的既有实现取代~~ —— `Frame::run` 每帧 `settle()` 只对**标记脏**的 StatefulElement 跑 `rebuild()`（§13.3 性能纪律：widget 树不是每帧全重建）⇒ 环境变了必须有人**标全树脏**（根上递归 `state()->markDirty()`，下一帧 settle 逐个重建；State 经 Element diff 保留 —— `ThemeScopeElement` docblock 的"切主题不丢 State"语义正依赖此）。与 §A.2.5 的 `onSystemThemeChanged()` 应用钩子（§24.1 记：`Application` 上尚不存在）是同一需求的框架侧 / 应用侧两半。★ **A15 判据**：今天 0 个消费者（hello / users 都不跟随系统主题）⇒ 按"没有消费者不预先发明"留待；真需要时**触发条件**：任一应用声明跟随系统主题（`ThemeScope::followSystem` 形态或应用自读 `Screen::systemTheme()` 后自标脏） | 无消费者（A15）；新增框架机制 | **应用真需要跟随系统主题时**；与 `onSystemThemeChanged()` 钩子一并对齐（§A.2.5 对齐时） |
| 15 | ✅ **已落地（2026-10-17）—— 可滚动容器（P1）**，★ flex 分母缺陷**已裁决并修复**（同日，用户选方案 1「现在修」）。**落地形态**：`ScrollView`（声明）/ `ScrollViewState`（持偏移，`scrollBy()` 单一夹取入口）/ `ScrollViewBox`（产盒，@internal，`ProxyElement` 认它挂子）+ `RenderScrollView`（`RenderProxyBox` 子类）+ `ScrollEvent`（`Drift\Input`，`x`/`y`/`delta` 三逻辑字段）+ C++ `WM_MOUSEWHEEL`（坐标 `ScreenToClient` —— 滚轮消息给的是**屏幕**坐标，与鼠标按键消息不同；delta 传原始值）→ `toArray` "wheel" → `Win32Platform::pump`（÷dpr 转逻辑、÷120 收成格）→ 队列 → `Application::dispatchInput` 的 ScrollEvent 分支（命中 → 沿命中链找**最近**的 `ScrollViewState` → `scrollBy`；浮层开着时同指针分支三段：先归浮层、轮在浮层外不穿透 —— §9.1"浮层开着时滚轮吞"兑现，且长菜单自己也能滚）。★ **坐标链纪律从第一行就对**：偏移在**布局期**写进子盒 `offsetX/offsetY`（不是 paint 期 translate）—— 2026-10-17 padding 缺陷的同族风险，命中区与绘制区因此恒一致；`maxScroll` = 内容主轴 − 视口主轴（滚不到头、滚不出界 —— 越界是"内容飞走"的静默形态）；命中按**视口 size** 判定 ⇒ "裁掉的部分点不到"是结构保证。★ 夹具：smoke 17 条（布局层 11：视口 / maxScroll / 偏移进 offset / 命中跟随 / 越界夹取 / 无界不滚 / 横向；应用层 6：滚轮全链 / 上下界夹取 / 视口外不滚），**反例自检 = 换回 paint 期 translate 恰好 5 条 FAIL**（诊断值精确显示腐败形态）。`apps/users` 列表页已接线（`Dock` top=头部、center=`ScrollView`，种子 15 用户 ⇒ maxScroll=64）⇒ 长列表真能滚。真机验：`drift-build users`（6.7MB，67.2s）+ 窗口探针（标题「用户管理」、12s 存活）。★ **flex 分母缺陷已修复（2026-10-17，用户裁决方案 1「现在修」）**：`RenderFlex::performLayout` 的 flex 分母旧口径 `array_sum($mains)` **含弹性子的自然尺寸** ⇒ 双重扣减 —— 内容比视口长时 `Expanded` 拿 `tight(0)`（整块不可见）、内容比剩余短时白留没人要的死空间。修法：分母只含**非弹性子**自然尺寸（`$free = $maxMain − sum(非弹性子 mains) − $gap`），弹性子整组拿走全部剩余。**影响面核验**：当时生产代码零个 `Expanded` 用法（只有 `UsersPage` 文档块提到它）、M2 conformance 与既有 smoke 夹具用 `Spacer`（自然尺寸 0 ⇒ 两口径同解）⇒ 既有行为零变化；非弹性子溢出时 `$free < 0` ⇒ 弹性子拿 0、C5 溢出处理照旧。夹具：smoke 三条（弹性内容比视口长 ⇒ 拿剩余 200；比剩余短 ⇒ 填满 200；非弹性子溢出 ⇒ 拿 0 + 盒夹到 300），**反例自检 = 换回旧公式恰好 A/B 两条 FAIL**（诊断值 `100 / 0` 与 `100 / 160` 精确显示两种腐败形态；C 守卫照旧通过 ⇒ 爆破半径恰为两个 bug 模式、无其他夹具受影响）。`users` 页 `Dock` 形态保留（§12.1 第 3 条"外壳 + 内容槽"是语义分工，不是绕缺陷）。真机：`drift-build users` 6.7MB/18.0s + 窗口探针 OK。★ **2026-10-17 滚动两缺口收尾**（用户报"没滚动条 / 不跟手"）：① **滚动条 = 纯视觉层**（不碰滚动机制）—— thumb 画在**视口坐标系**（`save/restore` 包住内容的 translate 再画；画在 translate 之后 ⇒ 被偏移带走、offset=max 时整条飞出视口，被 smoke 的 sv2 当场抓到 —— offset 小的用例有墨、offset=max 的没有，单看一条会误判成位置公式错）；② **"不跟手"根因 = 步长**（1px/格），不是缺插值动画 —— 滚轮本离散，48px/格后与浏览器同量级，**不做**新动画机制（触摸/惯性等 M4 事件类型）；③ 参数序踩 **tpc 比 PHP 严**：可选参数排在必选参数前，解释器只 Deprecated、tpc 预处理器直接 Fatal ⇒ 解释器三门全绿也过不了 codegen；④ 夹具：smoke +6（thumb 贴顶/贴底/中段不随内容平移/不溢出不画/横向 + 每格 48px 与累加），**反例自检三连**（translate 回来 ⇒ 2 红；删换算 ⇒ 2 红且诊断值 `offsetY=-1/-2`；删早退 ⇒ 0/0 Fatal 响亮失败）；⑤ 真机：`probe-scrollbar.py` thumb 6×394 连续竖条 + 圆角端，滚 1 格 thumb 下移 37px（= 48/64×50 travel）⇒ 步长端到端生效。| 有真实消费者（users 页已消费）；flex 分母已修，`Column+Expanded` 亦可用 |
| 16 | ✅ **已落地（2026-10-17）—— 指针四相位（move/up/leave 通路）**，用户批准方向①。**形态**：`PointerPhase` enum（Down/Move/Up/Leave，**value = 桥 ABI 编号**，与 `CursorShape` 同纪律）+ `PointerEvent` 扩 phase（四工厂 + `fromBridge()` 唯一映射读法；**相位住本类而非四个事件类** —— 四相位同坐标系、同命中链、同三方消费，对照 `ScrollEvent` 的无相位才独立成类）+ C++ `WM_MOUSEMOVE`/`WM_{L,M,R}BUTTONUP`/`WM_MOUSELEAVE`（`PendingEvent.d` 相位字段 + `pushPointer()` 四相位共用壳 + **`TrackMouseEvent(TME_LEAVE)`** —— 不请求跟踪就没有离开事件，hover 的标准 Win32 模式）+ `Win32Platform::pump` 按 phase 翻译（÷dpr 同口径）。**三个消费者**：① **悬停派发**（§9.1 语义 #5）—— `Widget::hover(\Closure)` 声明（基类，同 `dragTargetValue()` 模式）+ `FocusManager::hoverTo()` 派发（沿命中链找最近声明者，**目标变化才通知**；Element 实例跨重建稳定（§13.2 diff 复用实例）⇒ 悬停 setState 不自激；悬停目标离线由 `collect()` 显式退出 —— 菜单关闭不再留高亮）；② **拖放指针驱动**（§A.3.5）—— `down` 在 `Draggable` 上记阈值起点（**按下 ≠ 拖动**，`DRAG_THRESHOLD` = 6 逻辑像素），`move` 过阈值才 `begin` 并刷新目标悬停，`up` 放下交出 payload，`leave`/ESC 取消；③ **光标刷新**（move 与 down 同一条命中链，返回 cursor 由组合根喂 `setCursor`）。★ **浮层开着时四相位都先归浮层**（与 down 同一条不穿透纪律）：命中 ⇒ 菜单项悬停 / 光标；miss ⇒ move/leave 只退悬停、**不关浮层**（关只归 down）。★ **诚实边界（两条）**：① 无 `SetCapture` ⇒ 拖出窗口收不到后续 move/up ⇒ **`leave` 即取消**（不卡死、不放下；跨窗拖放等真有消费者时）；② move 的 button 恒 0（按住态由应用自己的 pending 记录跟踪，A15 不发明线上语义）。★ 夹具：smoke 13 条（相位 ABI 值与 fromBridge 映射 2 + 悬停 enter/不重复/leave 4 + 光标 1 + 拖放阈值/begin/drop/清空 4 + leave 取消 2），**反例自检 = 去掉 move 分支的悬停与拖放消费恰好 8 条 FAIL**（诊断值 `enter=0`/`dragging=false` 精确显示腐败形态；光标夹具照过 ⇒ 爆破半径恰为去掉的两个消费者）。真机：`drift-build users` 6.8MB/70.0s + 窗口探针 OK。★ **Button 激活仍住 down**（M3 P0 修复的行为不动）：up 服务拖放，不做 press/release 视觉（A15 无消费者）。 | 有真实消费者（悬停与拖放两条链各可直接被应用消费；菜单项高亮是 `HoverHandler` 的原定消费者） |
| 17 | ✅ **已闭环（2026-10-17）—— 软光栅小字锯齿**：`BitmapFontFace::rasterize()` 降采样路径（`pixelSize < unitsPerEm`，即全部界面小字 11–14px）**把连续覆盖度二值化成 0x00/0xFF** —— 面积覆盖度 `$ink / $area` 明明已算出，抗锯齿信息在函数出口被倒掉。★ **整条链本来就为灰度设计**：`GlyphBitmap` 契约"取值 0..255 连续覆盖度"、`RasterCanvas::blend()` 的 `alpha = 色alpha × 覆盖率`（注释原话"抗锯齿的边缘与半透明色走同一条混合路径"）——违约者只有 BitmapFontFace 一个。★ 实证：users 页预览 SVG 全页仅 5 种纯色（无任何中间灰）；探针逐字 @12/13/14px 查 coverage 只有 {0,255}。★ 修法 = 一行（阈值行换 `chr(round(/*255))`）+ 注释订正；**放大路径（≥16 1:1）完全不动** ⇒ 既有位图逐字节不变。★ 影响面核验：生产消费者仅 `RasterCanvas::blendBitmap`；smoke P1/P2 conformance 的期望侧（`skiaExpectedInkBounds`）与实测侧（`deviceInkBounds`）**同口径且扫同一张 rasterize 产物** ⇒ 灰度后两侧同步同变、断言不受影响；`SkiaCanvas` 不走本路径（C++ `SkFont::kAntiAlias` + 真 TTF）⇒ 本缺陷是软光栅（默认）后端专属。★ 夹具：smoke 3 条（降采样值域含 0<v<255 / 尺寸不随灰度变 / **端到端** blendBitmap 上屏出现混合像素 —— 钉消费链不钉实现），**反例自检 = 换回二值化恰好 2 条 FAIL**（诊断值「不同值=2 中间灰度值=0」「混合像素=0」精确显示腐败形态；尺寸夹具照过 ⇒ 爆破半径恰为两个判据）。真机：`drift-build users` 6.8MB/17.6s + 窗口探针出现 **896 个中间混合像素**（rgb(215,63,73)，红×白过渡）。 | 软光栅后端全部小字（真机 UI 正文、演示、预览）；修复前真机文字肉眼锯齿 |
| 18 | ✅ **已闭环（2026-10-17）—— Dock 无界主轴取内容自然高**（用户批准"按推荐的继续"即 A 方案）。**最小复现**：`php _build/repro_dock_inf.php` —— `BoxConstraints(0,240,0,INF)` 下 Dock 返回 `Size(240, INF)`，`bottom` 槽 `offsetY = INF`、`left/center/right` 三槽高 INF ⇒ 后续绘制链出现 INF 坐标（`RasterCanvas` cast NAN 告警，画面不崩但坐标烂）。**触发形状**：Dock 处在无界主轴容器内（`ScrollView` 给内容的就是无界高——"先量你到底有多长"），或无界居中的外壳。**对照契约**：`RenderFlex` 同条件下返回**内容自然尺寸**（`ScrollView(Column)` 的 ListPage 预览正常即证）⇒ Dock 是漏了这条回退，不是新语义。**暴露路径**：示例台 `apps/showcase` 布局页 Dock 演示（2026-10-17）。**建议修法（未实施）**：`RenderDock::performLayout` 无界高时 top/bottom 取自然高、中带三槽按 `intrinsicHeight` 取自然高 ⇒ `size.height = topH + midH + bottomH`；**有界路径逐字节不变**（只加 is_infinite 分支）。**影响面**：有界窗口下 Dock 全部既有用法（users 列表页 / ShellPage / NavApplication）零变化；变更只在无界约束。**待裁决点**：① 是现在修（改核心原语，需 T1+T2）还是先挂着（示例台 Layout 页 Dock 演示已暂缺并如实登记）；② 修后是否要给 smoke 加"Dock 无界高 ⇒ 自然尺寸"夹具。 | 无界主轴下的 Dock（ScrollView 内容 / 无限约束容器）；受损面 = 绘制坐标（非崩溃，无声劣化）。**已修**：`RenderDock::performLayout` 加 `is_infinite($maxH)` 分支 —— 无界时 midH = left/center/right 的 `intrinsicHeight` 最大值、`selfH = topH + midH + bottomH`、`bottomY = topH + midH`；**有界路径逐字节不变**（reject 夹 0 时 bottom 仍贴底 `$maxH - $bottomH` 这一既有行为也原样保住）。夹具：smoke 4 条（自身高 112 / bottom offsetY 97 / 中带三槽铺满 77【SolidBox 的 intrinsic 与 box 故意不同 ⇒ 同时钉住"midH 真来自 intrinsicHeight"】/ 有界复跑数值不动），**反例自检 = 换回旧公式恰好 3 条 FAIL**（诊断值精确 `INF`；"有界不动"那条照过 ⇒ 爆破半径恰为三个新判据，既有 599 条零影响）。示例台闭环：`apps/showcase` 布局页的 Dock 演示块曾因此缺陷暂缺，修复后恢复并留注脚（"示例台逼出核心缺陷、修复回填示例"）。 |
| 19 | ✅ **已闭环（2026-10-17）—— 默认后端从 `soft` 翻成 `skia`**（用户报"字全是锯齿，界面完全没法看"）：`BitmapFontFace` 的降采样灰度（#17）只治了一半 —— **源数据本身是二值的**（CJK `16×16`、拉丁 `5×8`，见 `tools/font-gen-glyphs.py` 的 `GRID=16` + `COVERAGE=0.5`），且 `Title=18px > unitsPerEm=16` 走**放大路径**（最近邻）⇒ 上一轮的灰度在那档完全用不上。★ 实测对比（同一份代码、同一屏、2× 放大）：soft 版 `Layout` / `cross:Start` 是点阵块，且 `馈/五/块/演` 缺字（字模表 781 字，扫描器当时已能扫到 865 字—— 生成链没重跑）；skia 版真 TTF + `SkFont::kAntiAlias` 全部清晰，且**缺字自动消失**（`NotdefFontFace` 的 codepoint 仍被 `SkiaCanvas::drawParagraph` 发出去，真字体有全字集）。★ 改动三处：① `Win32SurfaceProvider::DEFAULT_RENDERER = 'skia'`，并把解析抽成 `resolveBackend()` **显式认下 `'soft'`**（否则 `new Win32Platform('soft')` 会被默认值**静默吞掉** —— "软光栅仍可选"就成空话）；② `cpp-src/drift_skia.cc::ensureFont()` 候选顺序改成 **exe 目录优先**（`<exe>/fonts` → `<exe>/cpp/fonts` → cwd 相对 `cpp/fonts` → `fonts`）—— 翻默认之后"找不到字体 ⇒ 一个字都不画"从**可选后端的小坑**变成**默认形态的大坑**，而旧的"cwd 必须是仓库根"是个暗含前置；③ `windows.h` 必须排在 `phpx.h` **之后**（先到会把旧 winsock.h 带进来 ⇒ `php_network.h` 报 C2027 未定义 `timeval`，实测踩过）。★ 夹具 smoke 3 条（默认=skia / 显式 soft 不被吞 / 未知值退回默认），**反例自检 = `resolveBackend` 换回"只认 skia" ⇒ 恰好第 2 条 FAIL（诊断值"实际 skia"），另两条照过**。★ 真机：`drift-build showcase` 6.5 MB + 窗口探针 OK（非白 16.6%，`#009688` 7426px，DPI V2）；**分发形态验证**：exe + `fonts/` 放临时目录、从 `C:/Windows` 启动 ⇒ 灰阶 28（有字）；移走 `fonts/` ⇒ 灰阶 9 且"布局全对、零墨"（正是缺字体的特征形状）。★ 遗留（未做，独立项）：`advance` 仍来自位图（中文 `17/16 = 1.0625em`、拉丁 `6/8 = 0.75em`，真值 ≈ 1.0em / ≈ 0.5em）⇒ skia 版**字距偏松**；修它要动 `glyphAdvance` 的度量来源、会改变行宽 ⇒ 单独立项。 | 真机全部界面（默认后端）；修复前中文/英文全是点阵锯齿且缺字 |
| 20 | ✅ **已闭环（2026-10-17）—— 步进去掉 `+ 1` 间隙，字距回归真字体**：`BitmapFontFace::glyphAdvance()` 原式 `(位图宽 + 1) / unitsPerEm` 让**每个字多占 1/unitsPerEm** —— 中文 `17/16 = 1.0625em`、拉丁 `6/8 = 0.75em`。而真字体（Noto Sans SC，`ImageFont.getlength` 实测 @1000px）中文正好 `1.0em`、拉丁平均 `0.55em`（`a` 0.563 / `i` 0.275 / `m` 0.926 / **空格 0.224**）⇒ 默认后端翻成 Skia（#19）之后，字距偏松成了唯一剩下的观感问题（`cross: Start` 那种"冒号后一个空洞"最刺眼）。★ 修法两处：① 公式去 `+ 1` ⇒ advance **恰等于字身宽**，位图首尾相接（既不重叠也不留缝）；② **空格字模 5 列 → 2 列**（`0.25em` ≈ 真值 0.224）—— 空格是"松"的最大单源（旧值是真值的 **3.3 倍**），且它没有墨 ⇒ 收窄零风险。★ 影响面（语义变化，不是回归，夹具同轮回填）：`Text · 行尾空白被修剪` 期望 55 → 45、`IME · 光标 x` 58 → 54（文本变窄的直接后果）。★ 反例自检：把 `+ 1` 加回去 ⇒ **恰好这 3 条 FAIL**（诊断值 55 / 58 精确显示旧语义），其余 602 条零影响。★ 未做（遗留）：拉丁字模仍是**固定 5 列**（`i` 真值 0.275em vs 现在 0.625em）⇒ 窄字母（`i`/`l`/`.`）仍偏宽；根治要按真 advance 变宽 + 引入 `bearingX`（`GlyphBitmap` 契约 + 两后端绘制偏移），另立项。 | 真机全部文本（默认 skia 后端）；修复前满屏字距偏松，`cross: Start` 最明显 |
| 21 | ✅ **已闭环（2026-10-17）—— 拉丁字模换成"位图宽 == 真 advance"的 32 网格生成物**：手写 `LATIN_GLYPHS` 是**固定 5 列**（`i` 与 `m` 同宽），步进恒 `5/8 = 0.625em`，而真值是 `i` 0.275em / `m` 0.926em ⇒ #20 修完空格与中文之后，"拉丁字母间距不匀"是最后一处（`cross: Start` / `flex` 可见）。★ **修法零代码逻辑改动**：`glyphAdvance()` 返回 `位图宽 / unitsPerEm`，所以只要位图宽取自真字体，步进就**天然正确**—— 新增 `tools/font-gen-latin.py`（32px 渲染 + 50% 覆盖度二值化 + 宽度 = `round(advance)`）生成 `src/Text/LatinGlyphData.php`（95 字 / 58 KB），`latinFace()` 的 upem `8 → 32`、ascent/descent `7/1 → 28/4`（= 0.875/0.125，与 CJK 面**逐字段相同** ⇒ 可混排），手写表退役（`…` 由 CJK 表提供，真值 1.0em）。★ `bearingX` **链路本来就通**（`GlyphBitmap` 第 3 字段 + `RasterCanvas::blendBitmap` 已在用），本轮**仍传 0** —— 因为位图左沿就是 pen（宽度 = advance），真字体的 LSB 体现为**位图内的空列**。★ 夹具回填 6 处：conformance 的"墨迹左沿 == penX"放宽成 `≥`（`H` 左边本来有 1 列空白；精确值由"并集 bbox == 独立算式"那条钉住）、`'A'` @14px 位图宽 `9 → 8`、`'Hello'` 行宽 `45 → 35`、IME 光标 x `54 → 53/52`。★ **反例自检 = 把拉丁 advance 退回固定 `5/8` ⇒ 恰好 3 条 FAIL**（诊断值 45 / 54）。★ 顺带修一处夹具写法：期望值过去在 `$cond` 与诊断串里**各算一次**（重复 rasterize 整段文本），改成先落变量。 | 真机全部拉丁文本（`cross:Start` / `Hello` / 按钮标签）；修复前 `i` 与 `m` 等宽 |
| 22 | ✅ **已闭环（2026-10-17）—— `Modal` 薄糖（§A.3.7 的机制承诺兑现）**：O-20 把槽位（第二棵根渲染树）付清了，但 `Modal` 一直是 0 文件 —— `showModal()` 只有名字没有实现。**落地形态**：`Modal`（StatelessWidget，`title`/`child`/`dismissible`/`minWidth` 默认 320）+ `ModalHost`（@internal，`ModalHost → Center → PopupPanel`）+ `RenderModalHost`（盖满 + 遮罩自绘）+ `Application::showModal()`/`closeModal()`/`isModalOpen()`/`modal()`。★ **盖满有两道保险**：`placeModal()` 传 tight + 盒自己"有界轴取 max" —— 只打掉一道，`constrain()` 会把尺寸拉回上限，夹具看不出来（反例要两道同打才红）。★ **点遮罩的判据 = 命中链上没有 `RenderPopupPanel`**（模态盒盖满 ⇒ `hitsMenu()` 恒真，只能看命中链）；诚实边界：投影留白 6px 也算"面板内"。★★ **同轮抓到并修一个真缺陷**：`dispatch()` 的"命令分发后关浮层"（§9.1 语义 #2）**对模态也生效** ⇒ 模态里的按钮派完命令就把自己关掉，违反 §A.3.7「关不关归应用」，且"校验失败就留在框里"这种正确写法直接做不到。修法 = 加 `&& $this->modal === null`。★ `isMenuOpen()` 语义修正：模态占槽位时返回 false（"槽位空不空"与"菜单开没开"是两个问题）。★ 遮罩混色实测（§A.3.7 点名的"实现第一天验"）：`RasterCanvas` 白底 × alpha 90 ⇒ `rgb(165,165,165)`、128 ⇒ 127、64 ⇒ 191 —— 数学正确。★ 夹具 smoke 22 条（结构 / 盖满 / 居中 / 混色 / 四角覆盖 / 面板在遮罩之上 / 点面板不关 / ESC / 遮罩点击 / dismissible 两种 / 命令不自杀 / 与菜单互斥），**反例自检 5/5**（alpha=0 / 两道盖满保险全打 / 无条件关浮层 / hitsModalPanel 恒真 / dismissible 恒真）。T1：lint 293/0 · smoke 628/0 · codegen 198/0skip。 | 真机全部"确认 / 表单"类交互（§12.1 第 6 条的桌面主形态）；此前只能靠菜单浮层凑 |
| 23 | ✅ **已闭环（2026-10-17）—— 度量接回真字体（§8.2 坑 6「**一套**测量实现」的兑现）**：用户提问"文字要自己定义吗？没有完整的库吗？"—— 诊断出的真实形状是**两个源**：画（字形）`skia` 后端 = 真 TTF 全字集、`soft` = 位图子集(864 字)；**排（宽度）永远走位图** `glyphAdvance`，表外字退 `NotdefFontFace` 按宽/窄猜 1.0/0.5em。⇒ 真机"没定义的字符**画得出但宽度是猜的**"（长句错位、换行点算错），`soft` 后端画不出。★ 设计早就写了"一套测量实现，两个后端"，但 `SkiaTextMetricsProvider` 一直 0 文件 ⇒ 参考实现被当成了唯一实现；**库一直在（Skia 的 `SkFont`），只是没让度量走它**。★ 三条路：A 桥加 `measure_advance`（最小改动，但两后端度量分叉 + 布局不再纯函数）· **B PHP 侧解析 TTF（采纳）** · C 预生成全字集字宽表（1MB+ 字面量，仍是有限集）。**落地**：`TtfFace`（`cmap` f12/f4 二分 + `hmtx` + `head`/`hhea`/`OS/2`，约 400 行纯字节运算）+ `FontLocator` + `Application::metrics()` 改为"真字体优先、位图作字形源"（并**缓存** —— 现在要读一次 11MB 文件）。★ **行度量取 `sTypo` 不取 `hhea`**：Noto Sans SC 的 hhea 是 `1160/288`（1.448em，给 CJK 顶部留白）、sTypo 是 `880/-120`（1.0em）—— 用 hhea 会让所有行高 +45%。★ 实测零偏差（@1000px 对照 `ImageFont.getlength`）：中文/标点 1.0 · `a` .563 · `i` .275 · `m` .926 · 空格 .224；`Ω` .758（notdef 会猜 .5）⇒ 判据取 Ω 才能区分"接了 TTF"与"静默退回位图"。★ **影响面 = 0 条既有夹具变化**（627 条全过）：上一轮 §24.2 #21 让位图宽 = 真 advance ⇒ 两边数值本就重合，本轮收益集中在**表外字符**。★ 诚实边界：不解析 `GPOS`（无 kerning/ligature）· 不做 bidi/断行禁则（要 ICU）· **不做字体回退**（emoji / 扩展 B 仍缺，`cmap` 没有就是没有）。★ 夹具 smoke 9 条（定位 / upem / sTypo 度量 / hmtx 步进 / cmap 覆盖 / 字形委托 / TTF 有但位图没有 ⇒ 不画 / 找不到字体 ⇒ null / **集成**：`Application::metrics()` 排 Ω = 11px 而非 7px），**反例自检 4/4**（换回 hhea / cmap 恒 0 / 不注册 TTF / hmtx 恒返回 upem）。T1：lint 295/0 · codegen 200/0skip。真机：`drift-build showcase` 6.7MB/65.1s + 截图正常（灰阶 28）。 | 真机全部长文本 / 数据驱动文本（用户名、服务端返回内容必然含表外字符）；修复前"画得出、宽度是猜的" |
| 24 | ✅ **已闭环（2026-10-17）—— 界面质感：`Card` + 示例台三层卡片外壳**：用户原话「现在的界面为啥看着比较生硬，没有质感，和 LayUI 官网的示例，界面差距非常大」。★ 根因**不是配色**，是三条结构缺失：① 全白背景、块与块之间只有间距没有边界（无分组）；② `BoxWidget` 没有 `padding`、`Column`/`Row` 刻意不带盒样式 ⇒ **「带内边距的底色块」表达不出来**，所有示例只能把内容直接铺在白底上；③ 没有阴影、圆角只有按钮才有。**落地**：`Card`（`BoxWidget` 子类：默认底 `Pane` + 边 `Border` 1px + 圆角 2 + 内边距 16，显式 `->background()` 则整套让位）+ `RenderCard`（三层递减柔和投影）+ `ProxyElement` 认 `Card`；示例台外壳 = **浅灰背景板 `0xF2F3F5` / 白色导航面板 / 内容槽**（`Row::cross(Stretch)` 让侧栏拉满高），内容页两种形态 —— 文档页（文本/按钮/表单/列表/反馈）= **一整块白面板 + 面板内滚动**，布局页 = **每个 demo 一张卡**（dashboard 形态）。★★ **同轮揪出一个真缺陷**（见 #25）：`Row::cross(Stretch)` 一用就真机第一帧 FATAL。★ 夹具 smoke 12 条（盒是 RenderCard / 内边距真进盒尺寸 / 内容原点 / 内禀宽含 padding / 无子盒 / 默认边框 / 默认圆角 / 显式 background 让位 ×2 / 阴影三层递减 / 阴影有边界 / shadow=0 不画），**反例自检 5/5**。T1：lint 297/0 · smoke 648/0 · codegen 202/0skip；T2：`drift-build showcase` 6.8MB + 六页截图。| 真机全部页面（示例台六个页面）；修复前「白底白块、无分组、无层次」 |
| 25 | ✅ **已闭环（2026-10-17）—— `RenderFlex` 的 `FlexAlign::Stretch` 主轴/交叉轴写反**：示例台为了让左侧导航拉满高，第一次在生产代码里用 `Row::cross(FlexAlign::Stretch)` ⇒ 真机第一帧 `RenderFlex 溢出：自然主轴 16 超过可用 0`（整窗空白 + FATAL 框）。★ **根因**：`childConstraints()` 的两个 stretch 分支把轴**写反了** —— `Row` 给了**紧宽**（`(mainMax, mainMax, 0, crossMax)`）、`Column` 给了**紧高**，与 `placeChildren()` 里`Stretch => 0.0`（交叉轴偏移 0）以及 Flutter 同名属性的语义都相反。后果：非弹性的侧栏卡被撑到整行宽 ⇒ 同级 `Expanded` 的 `$free = 940 − 940 = 0` ⇒ 内容区整片 0 宽。**修法**：两个分支改为拉**交叉轴**（`Row` ⇒ 紧高、`Column` ⇒ 紧宽），并给第二遍 `tightMain()` 补上水平镜像（弹性子也要拉满，否则同一行高度参差）。★★ **潜伏原因（本轮最重要的教训）**：`FlexAlign::Stretch` 在生产代码里**零用法** ⇒ 这条分支是**从未被执行过的死代码**，而「写反」不会报错 —— 直到新用法面（示例台）踩上去。这与 §24.2 反复出现的「AOT 只编译可达代码 ⇒ 用法面变宽才暴露缺陷」是同一类：**任何从未被消费的分支都等价于未实现**。★ 夹具 smoke 6 条（Row+Stretch 高拉满 / 宽仍自然 / 默认 Start 不拉伸 / Column+Stretch 宽拉满 / 弹性子也拉满 / `Card` 紧宽约束下内容撑满），**反例自检 4/4**。★ 遗留缺口（本轮暴露，未做）：**没有「定尺寸」原语**（`SizedBox` 那类）⇒ 侧栏宽度只能由内容自然宽决定，导航项右边缘参差；`Button` 的修饰符也无法把边框/圆角**显式设回 0**（`hasBorder()` 判真假 ⇒ 回退主题值），所以侧栏做不出 LayUI 的扁平菜单项。 | 任何用 `cross(Stretch)` 的布局（本次为示例台外壳）；此前零消费者 |
| 26 | ✅ **已闭环（2026-10-17）—— 定尺寸原语 `SizedBox` + 扁平按钮 `ButtonVariant::Flat`**（#25 遗留的两条缺口，用户选「A1 + B1」）：① **`SizedBox`** —— `width`/`height` 各可空（`null` = 该轴透传），给定轴给孩子紧约束；★ 必须覆写 `intrinsicWidth/Height`（`Row`/`Dock` 量自然宽走内禀，不覆写则定尺寸在「量」这一路完全看不见 ⇒ 静默）；★ 父约束更紧时**夹进父区间**（`tight(100)` 里 `width(200)` ⇒ 100；不夹会让 `BoxConstraints` 抛 Fatal）。② **`ButtonVariant::Flat`** —— 透明底 / 无边框 / 字色 `Text`。★ 为什么不是改 `BoxWidget` 的合并规则：那是「用户设了非 null/非零就用用户的」，`->border($c, 0.0)` 与 `->radius(0.0)` 表达不出「我要 0」；要修得把 `BoxStyle`（`@api` readonly 值对象）从「null/0 = 未设」改成显式标志，是**语义变更** ⇒ 另立项，本轮用变体表绕过（语义写在变体里，比改值对象便宜）。★ 示例台接线：`SizedBox(Card(nav))->width(200)` + 导航列 `cross(Stretch)`（宽度已定死 ⇒ 安全，拉的是 180 而非整行 940）+ 未选中 `Flat` / 选中 `Primary` + 显式墨绿 ⇒ 侧栏从「一排带边框按钮」变成 LayUI 菜单项形态。★ 夹具 smoke 9 条（定宽且孩子拉满 / 未定轴透传 / 内禀宽 == 定宽 / 父更紧听父 / 无子盒 = 定值 / Flat 盒样式 isNone / Default 仍有边框 / Flat 仍占位 / 集成：侧栏 200 ⇒ 导航项 180），**反例自检 4/4**。T1：lint 299/0 · smoke 663/0 · codegen 204/0skip；T2：`drift-build showcase` 6.8MB + 截图。★ 遗留：`RenderButton` 的 `setOffset()` 是**死代码**（`paint()` 只用 `insets`）⇒ 按钮标签实际上恒左对齐，「居中」只在盒宽 == 标签宽时成立；改成读 offset 会改变所有被拉伸按钮的标签位置，未做。| 示例台侧栏（固定 200 + 扁平菜单项）；`SizedBox` 亦可做绝对空隙与 `Dock` 左槽定宽 |
| 30 | ✅ **已闭环（2026-10-21）—— 分节原语（Divider / Progress）**：§A.2 表单词汇里的「装饰/状态」两族最小形态。`Divider` = 一个 `Color` + 一个 `thickness`（**不造九字段样式对象**，§A15），装饰 ⇒ **不给语义角色**；无界约束回落 `MIN_WIDTH=24`（坐标链禁 INF）。`Progress` = 受控读数（值由应用给），**跨平台确定性优先**：`0..1` float 在 Widget 层夹取后转**整数千分比**（`SCALE=1000`，§14.1）⇒ golden 逐字节可比；`0%` 只画轨道、非零至少 1px 墨（否则「有进度看不见」）。smoke 15 条（含千分比夹取表 11 例、42% 边界像素、400px 线不断）+ 反例自检 3/3 + T2 AOT 构建 + 真机四条像素断言（发丝线 1660px 不断 / 2px×240 定宽 / 58% 条填充到 57.9% / 0% 条零 accent）。示例台 `FeedbackPage` 已接线（含 `Expanded` 配 progress 的弹性轴用法）。
| 28 | ✅ **已闭环（2026-10-21）—— 选择类控件（Checkbox / Radio / Toggle）**：§A.2 表单词汇里「唯一没落地的三族」（此前表单页只有 TextField + Button）。形态裁决：**一个基类 + 一个渲染盒 + `int $kind` 分支**（不是三套三层 —— 布局同构、命中同构，三个子类会撞 tpc 去虚化）；**受控**（`checked`/`group` 外给，点击只回调新值）；**激活走 `Application::activateFocusedWidget()` 单一入口**（与 Button 同缝）；**只画 Core/Extended op**（软光栅也要能跑 preview/golden）。真机已验：复选框取消勾选、单选换选（中号→大号）、开关翻转、禁用项点了没反应。`fact(smoke) 13 条 + T2 AOT 构建 + 真机四类点击`。 |
| 27 | ✅ **已闭环（2026-10-20）—— 19 op + CustomPaint 回植（D2 裁决 + R2，用户「架构优先、可破坏兼容」）**：来源 `D:/TypePHP/Drift3/design-v6`，评审稿 `docs/BACKPORT-19OP.md`、跨平台决策 `docs/_archive/CROSS-PLATFORM.md`。★ **0 文件清单清零**：此前 `Canvas` 只有 7 个绘制面且 19 op 引用 0 文件（§4.2 孤儿名），`CustomPaint` 只活在评审稿里。★ **P0 契约**：op 分 Core/Extended/Optional 三层 + `Canvas::capabilities()` 位掩码（CustomPaint 拿到画布查一次，不在每像素查）；渐变/虚线设计成 **Paint source 属性**（SOLID/LINEAR/RADIAL 正交维度，不为组合爆炸添 draw 兄弟）；5 个新值对象 + `DisplayList` 槽位 5→6。★ **P1 双后端**：`RasterCanvas` 逐行谓词跨度（椭圆）/ 胶囊体谓词（线段）+ `drift_skia` 桥两函数；两后端 Optional 方法全部显式失败（E-OP-UNSUPPORTED —— 不静默丢像素，那正是「矩形全对、内容没了」最难查的形状）。★ **P2 逃生舱**：`CustomPainter`（`paint` + `version` 内容戳）+ `CustomPaint` + `RenderCustomPaint`；示例台新增「自绘」页（仪表盘 + 走势折线，只画 Core 层 ⇒ 双后端同图），**「19 op 够不够用」实测结论**：表盘/指针/刻度/网格/折线**够**（ellipse+line+rect）；圆弧刻度**勉强够**（无 arc op ⇒ 11 根线段拼，程序啰嗦）；折线下面积填充**不够**（等 P4 `drawPath`）；画布内文字**绕开**（Paragraph 构造是重路径 ⇒ 刻度数字放控件层）。★★ **途中抓到并修一个 tpc 编译器缺陷**（上游 `1a9d77d1`）：方法体内获取「表达式求值的类常量」时 `findNativeClassConst` 内联含未声明 `tmp_var_N` 的初值串 ⇒ C2065/C2593（Drift2 实证：RecordingCanvas 返回 `ALL_MASK`）；修为值串含 `tmp_var_` 或 arrayExpr 非空 ⇒ 回落 Zend 查表。★ 夹具：smoke 781 → 820/0（+39：能力位 / 5 值对象构造 / Paint 正交维度 / LINE 6 槽定位 / 两端墨迹包围盒精确到像素 / 三个显式失败子进程 exit=3 / CustomPaint 11 条）；golden 旧 4 场景逐字节不变 + `ellipse-line`(f2f2be4b) / `custompaint`(c5a989c4) 两个新基线；T2：bench + showcase AOT 构建运行 ✓。★ **两条实测教训**：① smoke 的 `inkBounds` 写死扫 320×240 —— 夹具必须用这个尺寸，否则 PHP 越界读返回 '' ⇒ ord=0 被当墨迹；★ **P3 渐变/虚线（2026-10-20）**：渐变不是新 draw 方法而是 **paint source** 的实证——`Paint::withGradient()` / `withDash()` 一挂，`drawRect`/`drawEllipse`/`drawLine` 原样成立，op 数一个没涨。★ 顺手修一个**潜伏的 Raster 描边 bug**：`rasterize` 的洞分支在**每一行**都横向挖洞且没判断该行是否落在洞的纵向范围内 ⇒ **直角描边矩形缺上下两条边**（golden 的 prims 场景是圆角，`insideRoundRect` 的收紧间接救了它，所以基线一直没红）；夹具坐标踩中才暴露（描边矩形 y=2 宽 3 ⇒ 顶边带在 y∈[2,5)，采 y=1 在框外、采 y=3 才在带内 —— **夹具坐标必须对齐描边几何**）。★ 新 Skia API 三连坑（构建期才暴露，T1 抓不到）：`SkGradientShader` 已改 `SkShaders::LinearGradient/RadialGradient`、色标改 `SkColor4f`、位置必须严格递增、`SkDashPathEffect::Make` 改两参 `SkSpan` ⇒ **凡是新调 Skia API，必须过一次 T2 构建**。★ 虚线口径：两侧都按**逻辑像素**（Raster 弧长 `/dpr`，Skia 送设备空间前 `×dpr`），否则 dpr=2 时两个后端视觉长度差一倍。★ 夹具：smoke 820 → 831/0（+11：Raster 线性/径向渐变真采样、线段虚线有空洞、描边虚线降级无空洞、DisplayList 回放不丢渐变、Skia 桥 5 条 conformance）；golden 新增 `gradient-dash`(0efb0d39) 基线，旧 5 场景逐字节不变。★★ **P4 Optional 层（2026-10-21）**：`SkiaCanvas` 接通全部 6 个 Optional op（能力位满 9 位），`RasterCanvas` 仍显式 E-OP-UNSUPPORTED。★ **复合数据过桥 = 打包字节串**（桥 ABI 只有标量 + string，数组参数不可用）：`Path` → `verbs`（每命令 1 字节）+ `coords`（每命令 4 个 little-endian float）；`ImageHandle` → RGBA 行优先字节串，C++ 按 `w*h*4` 校验长度后重建（长度不足**响亮跳过**，与 `ImageHandle` 构造期校验同判据）。★ Skia 新 API 又三坑（同样只有 T2 构建抓得到）：**`SkPath` 已无 `moveTo/lineTo/quadTo/close`，路径构造必须走 `SkPathBuilder` + `detach()`**（`SkPathBuilder.h` 要单独 include）；`SkImage::RasterFromPixmapCopy` 是 **`SkImages` 命名空间的自由函数**（非成员）；`drawImageRect` 有 4 参（仅 dst）与 6 参（src+dst，第 5 是 paint、第 6 是 `Rect` 约束）两个重载。★ `drawShadow` 无独立原语 ⇒ 画「带 `SkMaskFilter::MakeBlur` 的圆角矩形」，`blur=0` 时关 mask filter（硬边阴影）。★ 夹具：smoke 831 → 840/0（+9：能力位满 9、drawPath 的 verbs/coords 打包、drawImage 长度、drawShadow 标量、layer/clip 配对）；T2 showcase 构建 ✓ + selftest 840/0；真机截图确认阴影/半透明层/渐变图/填充三角/路径裁剪 5 类都在真Skia 下出图。★★ **P4 真机首轮三角与路径裁剪全部无墨（阴影/层/图像正常），两个根因都只有真机才暴露**：① `packPath` 用 `$verbs .= …` 在循环里累加**二进制**串 ⇒ AOT 下写坏多字节串、C++ 读到乱码 verb ⇒ 空路径（解释器 smoke 全过；lint 早有 `str-concat-loop` 规则，是自己先构建后跑 lint）；② `drawPath`/`pushClipPath` 照"几何走画布 `translate()`"的注释写，但本仓 `translate()` **只改 PHP 侧 `offsetX/offsetY`、从不调 Skia** ⇒ 路径落在画布原点、被自绘盒裁掉（其余 op 正常是因为它们各自把偏移加进坐标）。修法：`packPath` 数组攒 + `implode`，并在同一处施加 `(逻辑 + 偏移) × dpr` 再量化（与 `drawLine`/`toDevice` 同口径）。★ 新增回归闸门：`translate(10,20)` + dpr=2 下断言打包坐标 = 22/44（去掉偏移即红，反例自检 840 ok/1 FAIL）。夹具 840 → **841/0**。② 自绘画家的几何必须**收进自绘盒**（超盒即被 C5 裁掉，golden 基线只测一半 —— 第一版表盘 ry 过大，指针与下沿被裁，量化后修为宽扁椭圆）。 | CustomPaint 逃生舱（19 op 之外的一切自绘控件）；此前 19 op 引用 0 文件、逃生舱 0 文件

### 24.3 G2 例外登记表（允许静默的场合）

★ §1.2 说"绝不静默"。**以下是显式例外** —— 登记在此，以免 lint 把它们误判为 A6：

| 场合 | 为什么允许静默 | 边界 |
|------|--------------|------|
| **`WindowStateStore::restore()` 失败** | 显示器拔了 / 分辨率变了 / 配置文件被删 —— 都不该让应用起不来 | **只降级到默认值**，且写一条 Diagnostic |
| **`ignore:` 指向不存在的路径** | tpc 有意跳过（源码注释说明） | 但 `include:` 的路径写错**会让 tpc 崩溃** ⇒ §4.6 约束 3 |
| **`Dialog` / `Tray` 返回 `null`** | "不支持"是**类型层面**的合法状态 | 调用方必须处理 `null`，不许当空操作吞掉（★ `Clipboard` 2026-10-21 起有 Win32 实现，不再是 `null` —— 端口的 `null` 语义不变，只是 Win32 这一支填上了） |
| **`select` 返回值相等 ⇒ 不重建** | 这是**门控**，不是吞错 | 门控判据必须显式（`===`），不许自定义"模糊相等" |

### 24.4 文档地图

| 文件 | 内容 | 什么时候读 |
|------|------|-----------|
| `DESIGN.md` | 方案本体（设计意图与裁决，**已瘦身**：§23/§24.1/§A 已拆出） | 想知道"我们决定了什么" |
| `docs/_archive/design-proposals.md` | §23 优化提案（O-01~O-17 提案表 + 已落地清单 + 批准依赖图） | 想看待办 / 提案来源 |
| `docs/_archive/changelog-v2.md` | §24.1 v2 文档内不一致修正记录 | 想看 v2 修订痕迹 |
| `docs/_archive/examples.md` | §A 完整示例（users 应用 + 能力示例 + 反模式对照 + 工具链） | 要动手写代码 |
| `docs/architecture/` | **当前实现架构**（基于 `src/` 真实代码，分册） | 想知道"现在实际怎么搭的" |
| `docs/design-v7/` | v7 设计方案（27 册分册，范式参考） | 想看设计蓝图 |
| `docs/_archive/CROSS-PLATFORM.md` | 跨平台能力矩阵与设计 | 要落地新平台 |
| `docs/EVAL-design-v7-2026-10-21.md` | design-v7 与实现差距评估 | 想看设计 vs 实现差距 |
| `docs/ARCH-REVIEW-2026-10-21.md` | **架构重审**：11 份调研 + Drift3 四代草案对账；六个结构性补丁 + A1/A2/A3 三条破坏性改进（覆盖层栈 / 控件基座 / 两后端能力等价） | 想知道"下一个控件会不会再打补丁" |
| `docs/ISSUE-typephp-*.md` | TypePHP 编译器坑的实测分析（C2872/C2065） | 踩 AOT 编译错时 |
| `probes/RESULTS.md` | 探针原始数据 | 想看数字 |
| `docs/_archive/` | 历史 / 过期文档（DECISIONS/HISTORY/REVIEW/SKIA-BUILD/v1.0 等） | 想复核已作废结论 |

---

> **§A 完整示例已拆分到 [`docs/_archive/examples.md`](docs/_archive/examples.md)。** 含 A.1 最小可运行、A.2 完整应用 users、A.3 能力示例集、A.4 反模式对照、A.5 工具链用法。

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
| 31 | ✅ **已闭环（2026-10-21）—— 文本选区模型 + 键盘命令 + 选区渲染**（`TextField` 三件套的第二步；剪贴板端口与 Ctrl+C/V/X 的接线在下一步，端口层已就绪）。★ **形态裁决：锚点 + 活动端两个字节偏移**（`selAnchor` / `selExtent`，恒字符边界），**相等即无选区** —— 不另设 bool，少一个需要同步的真相源。Shift+方向键只挪**活动端**（锚点不动）⇒「从起点一路选到当前」与「从当前往回选」是同一个模型；不带 Shift 的移动 / 任何一次真的文本改动都**折叠**选区（选中的是旧文本的字节区间，替换之后它已不存在）。★ **一次换算三者共用**：`RenderTextField::advanceAt()`（字节偏移 → 文本区内 x）同时喂 `caretRect()` / `selectionRect()` / `paint()` ⇒ 插入符右边缘与选区右边缘**不可能**算出两个值（否则"选到插入符处"差出一个亚像素级的缝）。★ 高亮色 = `Color::lerp(Accent, Pane, 0.72)`（**不透明混色，不用 alpha** —— 软/硬两后端对半透明的处理不一致，§14.1 的后端等价性不能挂在运气上）；换肤自动跟随。★ 键路由：`Shift+Left/Right/Home/End` 扩展、`Ctrl+A` 全选、`Esc` 吃掉选区并**消费按键**（不放行，否则 `Application::activateFocused` 会顺手把焦点按钮触发掉）；`Ctrl+C/V/X` 留在 `Application` 层（`State` 拿不到平台，§5.1）。★ 端点吸附抽成 `boundaries()` 之外的独立判据并**专门喂坏值测**：Shift+方向键给的偏移天生是边界，不专门喂一次"字符中间"的偏移，那段吸附代码等于从没被测过（反例实测：整段删掉吸附，前 26 条夹具一条都不红 ⇒ 补第三条夹具后才红）。★ 夹具 smoke 29 条（选区构造与折叠 / Shift 四键方向性 / Ctrl+A / Esc 消费 / 键入·退格·Delete 吃整段 / 多字节端点 0..8 / 端点吸附与越界夹取 / selectionRect 几何与同源性 / **offsetAt 真实往返** / 端到端墨量差），**反例自检 5/5**（selectAll 只挪插入符 12 红 / insert 不吃选区 2 红 / 不画高亮 1 红 / 删吸附 1 红 / offsetAt 中点判据写反 1 红）。T1：lint 386/0 · smoke **897 ok / 0 FAIL**。
| 32 | ✅ **已闭环（2026-10-21）—— 编辑命令环（Ctrl+C/X/V）+ 指针框选 + Ctrl+A 独占**（`TextField` 三件套的第三步；剪贴板端口层 `Win32Clipboard` + C 侧三桥已写在树上但**故意未提交** —— AOT 只编译可达代码，等 #51 showcase 接线后随 T2 一起提交）。★ **环的位置裁决**：`dispatchEditCommand()` 插在加速键**之前**（不走让位规则 ② 的 `claims()` 路径）—— `State` 拿不到平台（§5.1），而"能不能复制"是平台问题；四条门控任一不满足**整族回落**给加速键（不吞键 ⇒ 没有剪贴板时 `Ctrl+C` 不是死键）。★ **门控判据**：焦点是**编辑中**的 `TextFieldState`（与 `imeCaretRect()` 共用助手 `editingTextFieldState()` ⇒ 不可能一个放行一个拦住）/ 平台递了剪贴板（`Application::clipboard(): ?Clipboard` 组合根覆写，默认 `null`，与 `theme()` / `taskHost()` 同一形态）/ C·X 要求 `hasSelection()` / V 要求 `hasText()`。★ **指针框选三相位**（down 落插入符 + 起手势 / move 延伸活动端 / up·leave 收口）只存 **State 不存盒** —— 编辑只标脏 ⇒ 帧末 flush 重建盒，缓存盒等于拿上一帧的几何算这一帧；盒每步经 `Element::findBox()` 现取（**穿过 label 包装层**，与 IME 候选框 2026-10-19 贴角的坑同源）。★ **ESC 也收口框选手势**（选区折叠归焦点控件的 Esc 分支，手势本身住 App）。★ **过程中就地补掉两处框架缺口**：① `TextField`（`StatefulWidget`）调 `cursor()` **静默无效** —— `Element::syncBox()` 只把光标落在**自己产盒**的 Widget 上 ⇒ IBeam 声明挪到真正产盒的 `TextFieldBox`（否则命中链永远拿默认箭头且不报错，正是该处注释警告的"漏抄"形态）；② lint `DependencyDirection` 的 `ALLOWED` 表**漏了 `App` 行**（§4.3 写「`App | 全部`」，实现靠"表里没有就跳过"顺带放行）⇒ 显式列出全部层（行为不变、表与文档一致，`App` 文件从此也参与检查）。★ 夹具 smoke 18 条（门控④条逐一回落 / 复制·剪切·粘贴全链 / Ctrl+A 生产消费者 / 框选 down-move-up·反向·leave / IBeam 光标）+ **反例自检 3/3**（环挪到加速键后 3 红 / move 不延伸 4 红 / 不声明 Ctrl+A 1 红）。★ 反例跑法坑：`exec('php …')` 在 Windows 上会解析到**另一个 php**（`D:\PHP\bin\php-8.4.15`，`memory_limit=128M`）⇒ 读 11MB 字体 OOM **假红**；脚本必须用绝对路径 `D:\TypePHP\typephp-windows-x64\php.exe`（bash 直跑解析到的那个，限额 `-1`）。T1：lint **387/0** · smoke **915 ok / 0 FAIL**。★ **T2 真机（2026-10-21，`showcase.exe` 8.0 MB / 23s）**：① 构建**第一次**就抓到 **C2601** —— 剪贴板三桥被插进了 `php_drift_win_uia_push` 的**函数体内**（锚点选了它的收尾 `}`），4 个函数全报"本地函数定义非法" + 3 个 C3861；**PHP smoke 一个字都看不出来**（`function_exists` 假桥兜底 + AOT 只编译可达代码）⇒ 平台层改动只能靠 T2，这也是"故意延迟提交"这条决策当场兑现价值的地方（否则提交的就是一段没人编译过的 C++）。改用"追加到**文件真末尾** + `str_ends_with` 幂等断言"后过。② 指针框选真机可见：`--cursor=250,456 --mdrag=250,456,400,456` 后字段带墨量 **4.3% → 6.9%**，肉眼见强调色混画布 72% 的高亮从 offset 3 铺到 22、插入符在右端（`_build/clip-C1.png`）。③ ★★ **新探针坑**：`PostMessage` 合成 `WM_MOUSEMOVE` 时系统**不认为指针在窗内** ⇒ 壳侧 `TrackMouseEvent(TME_LEAVE)` 立刻回一条 `WM_MOUSELEAVE` ⇒ 应用按设计"指针出窗 ⇒ 手势收口"，现象是**拖到第 2 步就断**、且**间歇复现**（成功率 ~50%，定位靠临时日志：`MOVE x=275 sel=null`）。修法在探针侧：加 `--cursor=x,y`（`SetCursorPos` 真挪物理光标）+ `--mdrag` 每步真挪 ⇒ ~5/6；**残余 1/6 取决于窗口是否被别的窗口遮住**，是探针的物理约束而非框架缺陷（真实拖动出窗本来就该收口 —— 无 `SetCapture` 是代码注释里写明的诚实边界）。④ **键盘侧真机未验**，原因写死在这里：`probe-grab` 无键注入能力，而壳侧 `modifierBits()` 读的是 `GetKeyState`（**本线程真实键态**）⇒ `PostMessage(WM_KEYDOWN)` 造不出 `Ctrl+` 组合。键盘链由 smoke 的 18 条夹具覆盖，真机只验到"指针框选 + 高亮渲染"这一层。
| 33 | ✅ **已闭环（2026-10-21）—— 动画相位机：`AnimationController` + `Curve`（§17.4.11，EVAL §7 第 4 项）**。★ **为什么不是"再加一个 Spinner"**：§17.4.10 把帧驱动铺好了（`tickFrame` → `wantsFrame` → 帧时钟起停），缺的是**插值原语** —— 此前任何过渡都得在 State 里手搓"起手时间戳 + 上次值 + 完成标志"三件套，抄两遍就开始漂。★ 六条裁决**：① **不新开 `Drift\Animation` 层** —— 它只被 `State` 持有，进 `Drift\Widget` 最省（表与 §4.3 一致）；② **不持时钟不读环境时钟**：推进只在 `tick($timestampMs)`，时间戳由 `tickFrame()` 注入（G3）⇒ 解释器与 AOT 真机推进完全一致；③ **起点在第一帧才钉**（`forward()` 只置 `startPending`）—— 用墙钟记起点会让首帧就跳掉一个 15ms，短动画整体快一档；④ **反向按剩余距离缩时长**（从 `0.6` 回 `0` 只跑 `0.6×duration`，速度恒定 —— 回退是取消不是重播）；⑤ **终点帧仍返回 `true`，再下一帧收口** —— 这是"最后一帧也要画到终点"的唯一实现，否则调用方 `if ($busy) markDirty()` 就会让画面永远停在 99% 且**不报错**；⑥ `jumpTo` **不放完成回调**（打断 = 取消，不是完成）。★ `Curve` 用 `int $kind` + `===` 比较链，**不用 `switch`**：本仓 `src/` 此前零处 `switch`，而 tpc 给 `switch ($this->kind)` 生成的属性读**不加 `php::toInt()`** ⇒ `php::Int tmp = this_.attr(...)` ⇒ **C2440**（Variant→Int），**只有 T2 构建看得见**（解释器 smoke 全绿）。同时禁用 `pow()` / 三次贝塞尔（跨 libm 末位差异会破坏 §14.1 的双后端像素一致，与 `RenderSpinner` 禁 `cos/sin` 同纪律）。★ `Opacity` / `Transform` 这类"整棵子树一起动"的包装**仍不做** —— 前置是层合成语义（EVAL §7 第 4 项的前提）；本轮给的是**值**，喂给已有控件的数值入口（`Progress::value()` / `SizedBox::width()`）就够动。★ 夹具 smoke **21 条** + **反例自检 3/3**（抽掉 `tickFrame` 里的 `markDirty` ⇒「每帧重建」红 / 抽掉 `Curve::map()` 的夹取早退 ⇒「入参夹取」红 / 终点帧改成直接 `finish()` ⇒「终点帧仍返回 true」红）。② 层判据钉的是**链条**：`State::tickFrame` → `animating` → `wantsFrame` → `builds` 真的增长 → 收口后回落且**不再增长**。★ T2 真机（`showcase.exe` 8.0 MB / 27s）：**第一次构建就抓到 C2440**（见上），改 `switch` 后过。示例台第 9 页「动画 Motion」同时长两条曲线（`easeOut` / `linear`）同按一次「播放」⇒ 首帧图里 easeOut 条明显更长，曲线差别肉眼可见。逐帧判据：`c1..c8` 全 > 0（7969 / 4702 / 2915 / 3965 / 3018 / 2201 / 2379 / 2382），`c9..c13` 回到**精确 0**（`BitBlt` 同像素同值）⇒ 动画期间每帧有新像素、跑完帧时钟真的停了（§17.4.9 的⑧）。★ 同轮抓到的第二条：**`SizedBox::height()` 小于子内容会 Fatal 掉整帧** —— `RenderText` 报「画出的尺寸超出父容器给的可用空间：实际 8.0 × 50.4，最多只能用 8.0 × 0.0」，真机现象是**点进页面界面一动不动、也没报错**（帧被中止 ⇒ 旧画面留着）。定位靠 `Errors::setLogPath()` + `Frame->run()` 的栈帧。修法：定尺寸只给 `width`，高度让给内容。T1：lint **391/0** · smoke **936 ok / 0 FAIL**（+21）。 |
