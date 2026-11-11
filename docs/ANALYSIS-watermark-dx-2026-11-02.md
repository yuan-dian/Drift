# watermark 实现评估：与成熟 GUI 框架的对比、开发者体验与心智负担

> 评估对象：`apps/watermark`（3,765 行 PHP / 24 文件）+ 其依赖的 Drift 框架（27,831 行 / 374 文件）。
> 日期：2026-11-02。所有数字来自仓库实测，不是估计。
> 结论先行：**这不是"能不能用"的问题，是"谁来付税、付多少"的问题**。三类税（世界税 / 静默税 / 手写税）里，
> 一类是结构的、消不掉但可补偿；一类是工程的、会随时间收敛；一类是纯设计债、今天就能砍。

---

## 一、事实底座

### 1.1 代码规模与分布

| 层 | 行数 | 说明 |
|---|---|---|
| `main.php` + `Bootstrap.php` | 138 | 入口 / 平台装配 |
| `WatermarkApplication.php` | 123 | 组合根（title / buildRoot / 依赖装配） |
| `WatermarkRoot.php` | 81 | 页面骨架（AppBar + 三张卡 + 状态栏），**只组合不布局** |
| `Store/` | 1,056 | **`WatermarkStore` 906 行** + 两个值对象 |
| `Api/` | 694 | ApiClient 276 / TaskResult 216 / SignInfo 126 / Config 58 |
| `Widgets/` | 1,196 | 7 个控件，最大 `ResultCard` 270 |
| `Jobs/` | 477 | 5 个后台任务 + 注册表 |
| **合计** | **3,765** | 24 个文件 |

框架侧：`src/` 27,831 行 / 374 文件，`src/Widget/` 有 134 个文件（控件 + Element + State）。
应用实际只用掉约 **20 种**控件（Text / Row / Column / SizedBox / Spacer / Button / Each / Image /
ScrollView / Select / Spinner / Progress / Stack / Wrap / Card / Dock / AppBar / TextField…）。

★ 第一个可量化的事实：**应用 : 框架 ≈ 1 : 7.4**。成熟框架下这个比例没有意义（框架是第三方），
它说明的是"框架仍在自建期" —— 应用每写一行业务，背后有 7 行基础设施在同时被写或修。

### 1.2 注释密度（心智负担的第一个指标）

`WatermarkStore.php` 906 行里 **182 行是注释（20.1%）**，且注释内容几乎全是"为什么必须这样"：

```php
// ★ 接力标志只在**终结态**（到手 / 失败）消费 —— 2026-11-02 修：
//   `AsyncCommand::start()` 会先 emit(loading) 再落地，而这个订阅对**每一次**通知都跑。
//   原来的写法无条件 false，于是那一趟 loading 就把标志吃掉了…
```

框架侧 `src/` 里 `★` 出现 **3,440 次**。这不是风格问题，是**接口的自解释性不足**：
当一个 20% 的注释比例成为必需品时，说明"代码本身无法表达约束"，只能靠人写下来、后来者读。

### 1.3 验证链（真实反馈环）

| 层 | 手段 | 成本 | 能抓什么 |
|---|---|---|---|
| T0 | `php -l` | 即时 | 语法 |
| 内环 | `drift-preview`（软光栅 → SVG，**不编译**） | 秒级 | 布局 / 绘制 / 主题 |
| T1 | `smoke 471` + `lint 925` + `phpunit 1125` | 数十秒 | 解释器下的行为 |
| 闸门 | `gate 14 项` | 123.8s | 契约 / 像素 / 解码 |
| T2 | AOT 增量构建（30–60s）／全量（~3min）+ 真机看图 | 分钟级 | **唯一能证明"真的能跑"** |

★ 这条链设计得比多数开源框架的默认配置**更完整**（多数项目只有单测 + 手动点）。
但它同时是负担的来源：**同一个行为要在三个世界里各验一遍**（见 §四「世界税」）。

---

## 二、这个应用是怎么写出来的

### 2.1 三层职责，边界很硬

```
Application（组合根）   title() / buildRoot() / 依赖装配 / prewarm()
   └─ Root（组合）      只把 AppBar + 三张卡 + StatusBar 摆好，不读任何状态值
        └─ Widget       每张卡各自 SubjectBuilder 订阅**自己那一片**
Store（纯状态）          906 行，业务编排全在这，不持有 Application
Jobs（后台）             5 个 NamedJob，跑在常驻子进程池
```

这条边界的收益是真实的：`Widget` 不许依赖 `Drift\Platform`，所以"打开文件对话框"被包成
`\Closure(): void` 从 `Application` 往下递；`Store` 不许持有 `Application`，所以轮询节奏被包成
`scheduler` 闭包注入。**结果是每个文件都能单独测**（`smoke` 里可以 `new WatermarkStore(null)` 跑同步）。

代价是**能力要靠闭包手工搬运**：一个"打开对话框"要跨三层传递，每次都要写一遍"为什么是闭包"。

### 2.2 状态：手写切片

```php
SubjectBuilder::make($store)
    ->select(static fn(WatermarkStore $s): string => $s->time())
    ->builder(static fn(string $value): Widget => TextField::make()->value($value)->onChanged(...))
```

`TaskCard` 里三个输入框**各一个 `SubjectBuilder`**，注释直接写：

> 大订阅的话，改时间会让地址框也重建 —— `TextField` 重建会重置 `TextDraft`（光标回开头），
> 表现就是"打字时光标乱跳"。切片到单个字段是必须的，不是优化。

这是全篇最值得记住的一句：**粒度选错不是"性能差一点"，是"功能坏掉"**。而框架不强制、
不检查、不提示 —— 只有踩过的人知道。

### 2.3 异步：五个命令 + 四次落地 + 一次接力

> ★ **本节是 2026-11-02 的现状快照，已过期**：2026-11-04 迁移成
> 「1 个 `AsyncCommand` + 1 个 `Phase` + 一段顺序代码」，见 `DEBT-2026-11-02.md` §32。
> 保留原文是为了留住"当时为什么会痛"的现场。

`WatermarkStore` 里是 5 个 `AsyncCommand`（sign / upload / task / poll / download），
每个都是"发起 → 落地（absorb）→ notify"三步：

```
upload()  ──签名有效──→ startUpload() → uploadCommand->run()
   │                          ↓ loading / progress / data / error
   └─签名过期─→ signCommand->run() → absorbSign() ──接力标志──→ startUpload()
```

★ 那段接力标志的注释（§1.2 引的）是一次真实事故：`AsyncCommand` 每次通知都会触发订阅，
`loading` 那一趟把标志吃掉了 ⇒ **签名取回来了却永远不上传，界面停在"正在获取上传凭证…"**。
而且被"首屏预热"掩盖 —— 预热先拿到签名，点上传走的是另一条直路，接力分支从没被执行过。

这就是异步编排的典型成本：**状态机是隐式的**（写在字段 + 回调顺序里），不是显式的
（`Idle → Signing → Uploading → Done`）。成熟框架给的是 `async/await` 或 `Task` 组合子，
把"顺序"写成代码的形状；这里"顺序"藏在回调里，错了不报错、只是不动。

### 2.4 双路径：一条业务，两套实现

每个 `runXxx()` 都长这样：

```php
if ($host === null) {
    return Future::resolved((new ApiClient($base))->sign()->toArray());  // 解释器/单测
}
return $host->submit(new FetchSignJob($base));                           // 真机/子进程池
```

5 条链 × 2 套 = 10 处实现。注释说明了必要性（"测试里不能有常驻进程池"），但这是**纯样板**：
同一个 HTTP 调用被写了三遍（ApiClient 里一次、Job 里一次、参数编解码再各一次）。

### 2.5 跨进程序列化：`toArray()` / `fromArray()` 遍地

`TaskResult` 216 行里大半是编解码；`SignInfo` 126 行同理。原因：后台任务跑在**独立进程**，
返回值必须过 `mixed` 边界。这是进程隔离的必然代价，但没有代码生成兜底 ⇒ **每加一个字段要改四处**
（值对象属性 / toArray / fromArray / Job::args）。

---

## 三、与成熟框架对比

### 3.1 能力矩阵

| 维度 | Drift (watermark) | Flutter | Qt 6 (QML) | WinUI 3 / WPF | Electron | Compose Desktop |
|---|---|---|---|---|---|---|
| 渲染 | 自绘 + Skia CPU 光栅 | 自绘 + Impeller/Skia | 原生 + 可选 RHI | 原生 | Chromium | 自绘 + Skia |
| 语言 / 产物 | PHP → C++ → **原生 AOT** | Dart → AOT/JIT | C++ / QML | C# / XAML | JS/TS + Chromium | Kotlin/JVM |
| 布局模型 | **Flutter 式约束**（下传约束、上传尺寸） | 同 | Anchor/布局管理器 | Grid/StackPanel | CSS（最自由） | 同 Flutter |
| 状态模型 | `Subject` + 手写 `select` 切片 | `setState` / Provider / Riverpod | 属性绑定 + signal/slot | `INotifyPropertyChanged` + 绑定 | hooks / 任意 | `remember` + 快照 |
| 异步模型 | `Future` + **子进程池**（无共享内存） | `Isolate`（有 SendPort） | 线程 + 信号槽 | `async/await` + `Task` | 单线程事件循环 | 协程 |
| 热重载 | **无**（AOT 分钟级） | 有（<1s） | QML 热加载 | XAML 热重载 | HMR | 有（部分） |
| 调试器 / Inspector | 无 widget inspector；崩溃靠 map + `rva2sym` | DevTools 全套 | Qt Creator 调试器 | Visual Studio 全套 | Chrome DevTools | Layout Inspector |
| 生态 | **0 个第三方** | pub.dev 数万 | 模块 + KDE | NuGet | npm 百万级 | Maven |
| 分发体积 | **49.87 MiB**（含 2 个字体 20 MiB） | 20–40 MiB | 30 MB+（动态） | 依赖 .NET 运行时 | 100 MB+ | 依赖 JVM |
| 冷启动 | 原生，无 VM 预热 | 较快 | 快 | 中（.NET 首启） | 慢 | 慢（JVM） |
| 帧预算实测 | 最重页面 **1.5–2.0 ms**（占 16.7ms 的 9–12%） | 视场景 | 视场景 | 视场景 | 视场景 | 视场景 |

### 3.2 公平性说明

拿"自研 1 个月 / 27.8k 行"对"十年 / 百万行"的框架直接比，多数差距是**时间**不是**设计**。
所以下面把差距分成三类，结论完全不同：

| 类别 | 含义 | 例子 | 能否消除 |
|---|---|---|---|
| **阶段性** | 工程量问题，方向已对 | 生态、控件数、文档、DevTools、安装器 | 会随时间收敛 |
| **结构性** | 技术选型的必然代价 | 无热重载（AOT）、无共享内存（进程池）、静默失败（PHP 动态 + 编译期不可知） | **消不掉，只能补偿** |
| **设计债** | 今天就能改，且改了就少一半负担 | 手写切片、双路径样板、Job 序列化样板 | **可以砍** |

★ 这个分法比"优缺点清单"有用：它直接告诉你"哪些抱怨是白抱怨"。

---

## 四、心智负担：三种税

用户问"心智负担是否比较大"。答案是：**是，但分布极不均匀，且大头是设计债。**

### 4.1 世界税（结构性，消不掉）

同一份代码活在三个世界里，行为**不完全一致**：

| 世界 | 跑什么 | 缺什么 |
|---|---|---|
| 解释器（`php.exe`） | smoke / phpunit / preview | 没有 `drift_win_*` ⇒ 无窗口、无输入、无 `after()` |
| AOT（真机 exe） | 唯一真实 | 编译期 3 分钟、无源码级调试 |
| 软光栅（SVG 预览） | 布局 / 绘制 | 无交互、无动画 |

于是出现一句被写进记忆的硬判据：**"smoke 全绿 ≠ 真机正确，必须看图"**。
更狠的例子：`$this->可空字段?->m()` 在 AOT 里**不崩、只静默**；`instanceof` 漏 `use` 恒 false；
`\#[Getter]` 在 php.exe 里根本不生成方法 —— 每一条都是"某个世界里成立、另一个世界里失效"。

成熟框架把这种差异产品化了：Flutter 的 DevTools 直接连真机、C# 的编译器强制 null 检查、
Electron 只有"一个世界"（所以你从不会问"这在打包后会怎样"）。

### 4.2 静默税（结构性，但可以大幅降低）

这是最贵的一项。当前项目用**两份文档**来对冲：

- `MEMORY.md`：15.5 KB 的"违反就出静默 bug"判据
- `PITFALLS.md`：**188 条 / 4,222 行 / 302 KB** 的踩坑全表

188 条坑 ÷ 3,765 行应用代码 ≈ **每 20 行业务代码对应一条坑**。这个比值在任何成熟框架里都是
不可接受的 —— 它意味着"写出能跑的代码"这件事本身需要外部知识库支撑。

典型坑长什么样（都实测过）：

```text
漏 use ⇒ instanceof 恒 false（不报错）
?Obj / 联合类型 ⇒ 编译期退化成 php::Var（不报错）
新增中文文案没重跑字模 ⇒ 零宽隐形（不报错）
$this->不存在的方法() ⇒ 编译成功、真机崩
新增类文件 + 增量构建 ⇒ 类没被编译，启动即 0xC0000005
```

**共同特征：没有一条会给出"你错了"的错误信息。** 这比"报错难懂"严重一个量级 ——
报错难懂只是慢，静默失败是"以为自己对了"。

### 4.3 手写税（设计债，可砍）

| 手写的东西 | 每处成本 | 能否自动化 |
|---|---|---|
| `SubjectBuilder->select(...)->builder(...)` | 每个订阅点 6–10 行 | **能**（自动依赖收集） |
| `TextField::value() + onChanged` 双向同步 | 每个字段 8 行 | **能**（`bind()`） |
| `runXxx()` 双路径 | 每链 2 份实现 | **能**（统一到 Job，测试用同步 host） |
| `toArray/fromArray` | 每字段 4 处 | **能**（编译期注解生成） |
| 轮询节奏（`scheduler` 闭包 + 自己排下一次） | 每处 15 行 | 部分（`Polling` 原语） |
| `static fn` + `use ($store)` 样板 | 每闭包 1 行 | 无所谓 |

保守估计：**§4.3 这六项占应用代码的 30–40%**，且是最容易写错的部分（写错就静默）。

### 4.4 谁会觉得重、谁不会

- **Flutter 背景**：布局与 Widget 声明几乎零学习成本，`Subject` ≈ `ChangeNotifier`，
  第一天就能出界面。**卡点在第二周**——当他发现"没有热重载"和"改一行要 3 分钟"。
- **Qt/WPF 背景**：`Subject` 比 signal/slot 简单；但会困惑"为什么绑定要手写切片"、
  "为什么没有 designer"。
- **Web 前端背景**：最痛苦。React 的 `useState` 会自动重渲染，这里要手写选哪一片；
  CSS 的自由度没有替代品；**没有 DevTools** 等于回到 `console.log` 时代。
- **纯 PHP 后端**：反而最顺 —— 同步阻塞的直觉在 `TaskHost` 上仍然成立，只是要理解"UI 线程不许干活"。

一句话：**UI 声明层的心智负担接近 Flutter，状态/异步/验证层接近"自研引擎"**。

---

## 五、优点（说清楚哪些是真优势，不是自我安慰）

1. **产物形态是降维打击**：单 exe、原生码、无 VM 预热、无运行时安装（除 CRT + 2 个 dll）。
   对比 Electron 的 100 MB + Chromium、Compose 的 JVM 首启，这是硬优势。
2. **性能余量极大**：最重页面 1.5–2.0 ms / 16.7 ms 预算。余量大意味着"以后加功能不用先优化"。
3. **测试基础设施超出平均水平**：headless 预览（秒级 SVG）、471 条 smoke、1,125 条单测、
   14 项闸门、golden 像素判据 —— **多数商业 GUI 项目没有这些**。这是自研带来的意外红利：
   因为渲染是纯函数（`Frame → DisplayList → RasterCanvas`），golden 测试才成立。
4. **分层边界硬**：Widget 不碰平台、Store 不碰 Application ⇒ 每一层都能单独测。
5. **确定性**：软光栅 + 自绘 + 内嵌字模 ⇒ 跨机器像素一致，这在 Qt/WinUI 上做不到。
6. **状态模型对 Flutter 用户是熟悉的**：`Subject` + `SubjectBuilder` ≈ `ChangeNotifier` +
   `AnimatedBuilder/Selector`，`AsyncValue` 几乎照搬 Riverpod（`fold` / `carriedFrom` / `hasData`）。

---

## 六、缺点与差距（按"会不会咬人"排序）

| # | 问题 | 后果 | 类别 |
|---|---|---|---|
| 1 | **静默失败**（§4.2） | 以为对了，真机才炸 | 结构 |
| 2 | **无热重载 + 无 inspector** | 调一个间距要 30–60s；改状态要 3min | 结构（AOT）/ 阶段（工具） |
| 3 | **订阅粒度手工控制** | 选错 = 光标乱跳 / 整树重建 | 设计债 |
| 4 | **无生态** | 每个能力自己写（日期选择器、表格、图表全是框架自建） | 阶段 |
| 5 | **表单/输入体验粗糙** | 受控组件 + 手写同步，富文本/选区/IME 边界全靠自己 | 阶段 |
| 6 | **分发 49.87 MiB**，字体占 20 MiB | 下载体验差；且必须带 VC 运行库 | 阶段（可子集化） |
| 7 | **单线程 UI，节奏自管** | 轮询/动画/超时都要自己排定时器 | 设计债（缺原语） |
| 8 | **文档全在注释里** | 3,440 个 ★，但没有 cookbook / API 站点 | 阶段 |
| 9 | **无第三方调试** | 崩溃要看 map、翻 `rva2sym`，没有断点 | 阶段 |
| 10 | **应用/框架 1:7.4** | 改框架的风险由应用承担（一次 tpc 改动波及 4 个 app） | 阶段 |

---

## 七、优化空间（按 ROI 排序，全部可落地）

### P0 · 状态订阅自动化 —— 砍掉最大一块手写税

现状：每个订阅点手写 `select`，切错就坏。
目标：**读的时候自动记依赖**（MobX / React hooks 的思路）。

可行路径（不需要改渲染层）：

```php
// 现在
SubjectBuilder::make($store)->select(fn($s) => $s->time())->builder(fn($v) => …)

// 目标
Builder::make(fn() => TextField::make()->value($store->time())->onChanged(...))
// 实现：Builder 里把 $store 切到"记录模式"，属性被读时登记，构建后比对上一轮快照
```

关键：`Subject` 已经有 `notifyChanged()` 与版本号，只要加一个"读取拦截层"
（所有 getter 走 `read('time', $this->time)`）即可，**不需要 AOP**。
收益：应用里 20+ 个 `SubjectBuilder` 全部降为 1 行；且**再也不会有"切太粗导致光标跳"这类 bug**。
风险：中（要改 Store 的取值风格，但可增量迁移 —— 旧 `select` 保留）。

### P0 · 表单绑定 —— 消掉 8 行/字段

```php
TextField::make()->bind($store->timeSlot())   // 内部：value + onChanged + 切片
```
需要一个新的 `Slot` 值对象（读/写/标识），约 60 行框架代码，换掉应用里所有输入框样板。
且能顺手解决"重建重置 `TextDraft`"这个已知陷阱（`Slot` 自带稳定 identity）。

### P1 · Job / 序列化代码生成

已有 `#[\Attribute]` + 编译期代码生成的机制（V5），把：

```php
final class PollResultJob implements NamedJob { name(); args(); run(); }
```
变成：

```php
#[\Job('watermark.poll')]
final class PollResultJob { public function run() { return (new ApiClient($b))->getResult($t); } }
```
`name/args/toArray/fromArray` 全部生成。**预计消掉 300–400 行样板**（占 Jobs+Api 的 40%），
且消除"加字段忘记改 fromArray"的静默 bug。

### P1 · 双路径收敛

把 `if ($host === null)` 的二分支收进 `AsyncCommand` 的 runner：
`TaskHost` 提供一个**同步实现**（`SyncTaskHost`，`submit()` 当场跑），
测试就注入它 —— 于是 `runXxx()` 只有一份实现。改动约 100 行框架 + 应用 5 处删除。

### P2 · 把"静默"变成编译期/lint 报错

按"踩过的坑"逐条加规则（已有的 24 条 lint 是模板）：

| 坑 | 规则 |
|---|---|
| `?Obj` 退化 `php::Var` | **warn**：类属性/参数用联合或可空对象时提示"将失去类型检查" |
| `$this->不存在的方法()` | 已有 lint，扩展到**跨类**（静态分析可达类的方法表） |
| 新增类没被编译 | `drift-build` 结束前扫 `build/` 是否含新类 `.cc`（已有先例，做成判据） |
| 缺字模 | 已有 `font-scan-chars`，接进 `drift-build` 前置 |

收益：把 188 条坑里的**至少 30 条**从"人要记住"变成"工具会喊"。

### P2 · 交互式预览（补偿"无热重载"）

现状 `drift-preview` 只出静态 SVG。可以加"点击热区 + 重跑"：
SVG 里每个 Element 带 id，点击 → 用 fixture 的 Store 触发一次 hit-test → 重绘。
**成本远低于做真热重载**，能覆盖 60% 的日常调整（间距、颜色、条件分支）。

### P3 · 文档层：从 188 条坑反推 cookbook

`PITFALLS.md` 是"按症状查"，缺的是"按任务查"：
「做一个表单卡片」「做一个轮询任务」「做一个文件拖放」。20 篇、每篇 50 行，
比任何 API 文档都值钱 —— 因为它是从真实踩坑反推出来的**正确姿势**。

### 不建议做

- **热重载**：AOT 语义决定它不存在。硬做（比如解释器里跑真窗口）需要重建整个平台层，收益/成本比极差。
- **扩控件数量**：134 个 Widget 层文件（控件 + Element + State）已超过应用实际需要的 6 倍。
  先补深度（表格/富文本/虚拟列表的正确性），别追广度。
- **UPX / 二进制壳**：只改磁盘数字，换来杀软误报和启动解压（§18.6 已论证）。

---

## 八、结论

| 问题 | 回答 |
|---|---|
| 实现质量如何？ | **高于预期**。分层硬、注释诚实、测试链完整，906 行的 Store 是"把复杂度集中到一处"的自觉选择，不是失控。 |
| 和成熟框架差多少？ | **能力面差 3–5 年（阶段性），体验面差 1 个代际（结构性）**。分发体积、生态、DevTools 是时间问题；无热重载、静默失败是选型代价。 |
| 开发者体验差距在哪？ | 三处：① 反馈环 30s vs 1s；② 错误提前量（静默 vs 断言）；③ 缺少可观测性（无 inspector / 无断点）。 |
| 心智负担大吗？ | **大，但不在 UI 层**。写界面接近 Flutter；难的是"三个世界 + 静默失败 + 手写切片"。188 条坑表就是它的价格标签。 |
| 有优化空间吗？ | **有，而且大头是设计债**：P0 两项（订阅自动化 + 表单绑定）能砍掉 30% 应用代码，且消掉最易错的模式。建议先做这两项，再做代码生成，最后才是工具链。 |

一句话：**这个框架的问题不是"能力不够"，是"容错太低"** —— 它要求使用者比框架作者更懂框架。
降低这个要求，比增加控件更能提升开发者体验。
