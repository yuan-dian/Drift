# Drift2 当前实现架构

> 状态：**实现写实（基于 `src/` 真实代码，非设计意图）**。生成于 2026-10-21。
> 关系：本文描述"现在代码里实际是什么"；设计意图与裁决见 `DESIGN.md`（方案 v2）与
> `docs/design-v7/`（v7 设计方案，27 册）。本文与它们的**差异**单独成册
> （[13-与-v7-设计对照](13-与-v7-设计对照.md)）。
> 取数口径：每一册末尾都给出"怎么验证"—— 用 `grep` / `php` 能当场复算的事实，不靠记忆。

---

## 0. 一句话

**一棵树拆成三层（Widget 描述 / Element 身份+State / RenderObject 几何+绘制），一帧走
`settle → layout → paint → present` 五个纯阶段，绘制是 `Canvas` 接口的扁平 op 流、双后端
（纯 PHP 软光栅 + Skia）录制-回放，命令走字符串名的派发链，状态/订阅/命令三条缝分离，
全部跑在 TypePHP 的 AOT（PHP→C++17）编译产物上，首版只落 Win32。**

---

## 1. 六条由代码事实逼出来的不变式

| # | 不变式 | 代码里的硬证据 |
|---|---|---|
| **I1** | **三层结构**：Widget（不可变描述）/ Element（身份+State 宿主）/ RenderObject（几何+绘制） | `src/Widget/Widget.php:25`、`src/Widget/Element.php:19`、`src/Layout/RenderObject.php:21` 三套基类；`Element::syncBox()`（`src/Widget/Element.php:106`）在挂载时由 Widget 造 RenderObject |
| **I2** | **写即标脏，帧末统一重建**：不在事件中途重排 | `Element::settle()`（`src/Widget/Element.php:282`）每帧只重建标脏节点；`State::markDirty()`（`src/Widget/State.php:81`）是唯一的写入口副作用 |
| **I3** | **身份 = 位置 + Key**：改孩子只有 `updateChildren()` 一个入口 | `Element::updateChildren()`（`src/Widget/Element.php:344`）用 `(type, key)` diff 决定复用/重建 |
| **I4** | **一帧 = 五个纯阶段**：`pump → tick → settle → layout → paint → present` | `Application::frame()`（`src/App/Application.php:1001`）+ `Frame::run()`；顺序由注释与 `SurfaceProfile` 段键固定 |
| **I5** | **绘制 = 扁平 op 流**：`paint($canvas)` 直接画，`Canvas` 是接口不是 `final class` | `src/Rendering/Canvas.php:29` 接口；三实现 `RasterCanvas` / `SkiaCanvas` / `RecordingCanvas` |
| **I6** | **受控控件不自持状态**：点击只回调新值，值由应用 `setState` 改 | `ToggleBase::activate()`（`src/Widget/ToggleBase.php:133`）只发 `onChanged(新值)`；`TextField` 同路 |

★ 与 design-v7 的"六条不变式"相比：I1/I2/I3/I4/I5 在代码里成立；I6 是 Drift2 自己的补强
（design-v7 没显式写"受控"）。design-v7 的"命令 ID = 阿基米德点"在**实现里让位给了字符串名
命令**（见 [07](07-状态-订阅-命令.md) 与 [13](13-与-v7-设计对照.md)）。

---

## 2. 四条由 AOT 编译器逼出来的编码律（代码实证）

| # | 律 | 编译器事实 | 代码里的样子 |
|---|---|---|---|
| **L1** | **热路径零 `switch`、零 `mixed` 三元、零 `Native` 进容器** | tpc 给 `switch ($this->intProp)` 生成的属性读不加 `php::toInt()` ⇒ C2440（只有 T2 AOT 构建才炸）；`?:` 两支类型不同 ⇒ C2678 | `src/` 里**控制流 `switch` 零处**（唯一 `switch` 是 `SemanticsRole` 枚举的 `case Switch`，`src/Input/SemanticsRole.php:60`）；分派一律 `int $kind` + `===` 链（`ToggleBase`，`src/Widget/ToggleBase.php:141`、`163`） |
| **L2** | **行为走 `int $kind` + 比较链，不靠虚方法分派** | 静态类型已知时虚方法被去虚化，子类覆写被静默忽略（C6） | `RenderToggle` 一个盒子吃 `KIND_*`（`src/Layout/RenderToggle.php`），`ToggleBase` 按 `kind` 分支；`SpinnerState` 用方向表而非 `cos/sin` |
| **L3** | **被继承的类的字段必须 `protected` 不能 `private`** | tpc 按运行时类型查属性表，父类 `private` 不进子类属性表 ⇒ 子类实例访问动态建属性（值/语义全丢） | `RenderBox::$size`、`Element::$box`、`ToggleBase` 字段均为 `protected`（`src/Widget/ToggleBase.php:37` 注释明示） |
| **L4** | **`#[Native]` 类不能做节点**：容器嵌不了原生类属性、PHP 数组存不了 Native Object | 复合数据过桥只能走打包字节串 | 节点全是普通 `class`；几何/路径/图像过桥走 `Path` / `ImageHandle` 的字节序列化（`src/Rendering/Path.php`、`src/Rendering/ImageHandle.php`） |

★ 完整坑清单见 [11-编译器约束与-AOT-坑](11-编译器约束与-AOT-坑.md)。

---

## 3. 文档索引

| 册 | 标题 | 回答的问题 |
|---|---|---|
| [01](01-三层树与身份.md) | 三层树与身份 | Widget/Element/RenderObject 怎么连？为什么 Seal-on-Mount？身份怎么定？改孩子为什么只一个入口？ |
| [02](02-帧管线与异步.md) | 帧管线与异步 | 一帧哪五个阶段？动画/任务/IO 谁先谁后？空闲怎么不空转？出错走哪条通道？ |
| [03](03-布局与渲染盒.md) | 布局与渲染盒 | `layout()` 为什么是 `final`？约束/尺寸怎么传？C1–C6 契约是什么？命中测试怎么走？ |
| [04](04-绘制与双后端.md) | 绘制与双后端 | op 流什么形状？14 个 op 怎么分三层？Raster/Skia/Recording 各自干嘛？`translate` 纪律是什么？ |
| [05](05-文本与字体栈.md) | 文本与字体栈 | 谁做度量？整形/度量/编辑三拆？字体回退链与缺字怎么处理？IME 走哪？ |
| [06](06-输入-手势与焦点.md) | 输入、手势与焦点 | 手势 arena 为什么 Tap 居首？命中一次三方消费是什么？焦点链怎么走？拖放/菜单/悬停怎么接？ |
| [07](07-状态-订阅-命令.md) | 状态、订阅与命令 | `State`/`Subject`/`Cell` 怎么分工？订阅为什么异常隔离？命令为什么是字符串名 + 派发链？ |
| [08](08-控件面.md) | 控件面 | 63 个控件怎么分族？受控控件长什么样？虚拟化怎么做？ |
| [09](09-主题与语义.md) | 主题与语义 | 颜色/字号为什么用 token 不写死？语义树怎么建、怎么推给 UIA？ |
| [10](10-平台端口与-Win32-壳.md) | 平台端口与 Win32 壳 | 端口怎么拆？能力三态怎么表达？Skia/Raster 两种 surface 怎么选？退出路径/resize/IME 怎么处理？ |
| [11](11-编译器约束与-AOT-坑.md) | 编译器约束与 AOT 坑 | 每条 TypePHP 事实 → 一条代码纪律（带"否则会怎样"与真实报错号） |
| [12](12-工程与工具.md) | 工程与工具 | `project.yml` + tpc 怎么出 exe？lint/smoke/golden/probe 怎么守？四个 app 是什么？ |
| [13](13-与-v7-设计对照.md) | 与 v7 设计对照 | design-v7 哪条判据该搬、哪条实现里让位了、哪些能力还没做 |

**建议阅读顺序**：00 → 01 → 03 → 04 → 02 → 07 → 其余按需。
**做真应用之前必须读**：`05`（文本与颜色的解析点）+ `10`（五件"框架外的事"）+ `07`（订阅与命令）。
**必须一起读的两组**：`03 + 04`（布局两遍 + 绘制，缺一另一个就白搭）；`07 + 09`（状态写值 + 主题/语义推拉）。

---

## 4. 与既有工作的关系（借了什么 / 实际偏离了什么）

### 从 Drift2 之前的 GUI 仓实现借（踩坑经验，已落成代码）
- 确定性软光栅 + Skia 双后端：`RasterCanvas` 纯 PHP，天生可移植；`Canvas` 第一天就是接口。
- `Canvas` 是 op 天花板（不是 `DisplayList`）：主树 `paint($canvas)` 直接画，录制在 `RecordingCanvas`。
- 拖动 resize 走壳侧同步泵 + 轻量重绘入口（`Application::repaintForResize()`）。
- 退出路径上窗口已死 ⇒ 壳侧缓存最后已知几何（`absoluteOrigin()` 单一出处）。
- 溢出/最小尺寸/IME 候选框/滚轮取负/手势 arena：每条都是真机踩出来的。
- 扁平语义快照数组：同时满足 UIA 的"推"与 AT-SPI 的"拉"。

### 实际偏离 design-v7 设计稿的地方（详见 [13](13-与-v7-设计对照.md)）
- **命令 = 字符串名，不是 int 命令 ID**：实现用 `CommandRouter::on(名, handler)` + 派发链（`src/Commands/CommandRouter.php`），design-v7 的"int 命令 ID 阿基米德点"未落地。
- **`measure`/`place` 两步分离被取消**：`RenderBox::layout()` 是单遍 `final`，子类只实现 `performLayout()`（`src/Layout/RenderBox.php:88`）。design-v7 主张的分离在本实现里是 no-op（布局位置无关，缓存层判定不做）。
- **`switch` 在控制流里彻底消失**：design-v7 说"行为走 `int $kind` + switch"，实现里 `switch` 控制流零处、一律 `===` 链（AOT 硬坑逼的，不是口味）。

---

## 5. 目标与非目标（当前实现）

**已实现的目标**
1. Win32 首版可交付：能跑真应用（`apps/users` 是完整示例）。
2. 抽象先到位：`Platform` 是端口集合，第二个平台只写壳 + 适配器，框架零改动（`src/Platform/Platform.php`）。
3. 可测：整条管线无窗可跑，喂 `Frame::run()` 出像素（`tools/smoke.php` + `RecordingCanvas`）。
4. 可诊断：`SurfaceProfile` 段计时 + `Errors` 分级上报，一帧慢能指到阶段 + 节点。
5. 可编程：命令 + 订阅让 UI 状态机可被程序驱动。

**非目标（首版不做）**
- GPU 加速（离屏光栅 + 整帧上屏已够；`GpuContext` 接口留口未实现）。
- 原生控件（一律自绘；菜单是数据，系统强制处才渲染原生 `HMENU`）。
- 多窗口、富文本编辑（首版单行 + 多行纯文本 + 选区）、移动端。

---

## 6. 取数口径（每册怎么验证）

本册集拒绝"凭印象"。每册末尾给的验证命令都是**当场可复算**的，例如：
- 文件数：`find src -name '*.php' | wc -l`（当前 223）。
- 类清单：`grep -rn "^abstract class\|^final class\|^class\|^interface\|^trait" src/`。
- 某个不变式：`grep -rn "function layout" src/Layout/RenderBox.php` 看是不是 `final`。
- 某个坑：`grep -rn "switch" src/ | grep -v "//" | grep -v "\*"` 看控制流 `switch` 是不是 0。

凡是标了 `file:line` 的，都可以在 `src/` 里 `grep -n` 复现。
