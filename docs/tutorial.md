# 从零写一个 Drift 应用

> 这份文档对应 `apps/tutorial/` —— **一个能跑的四段教程**。
> 想直接看代码：`apps/tutorial/src/Stages/` 下四个文件，一段一个文件。
> 想跑起来：`php tools/drift-preview.php --app=apps/tutorial/src/TutorialApplication.php --ascii`
> （无头出图，秒级，不编译）。

---

## 0. 一个应用是五个文件

| # | 文件 | 作用 | 你要改的部分 |
|---|---|---|---|
| ① | `main.php` | 入口：全局 `main()` 转给组合根 | **不改**（三行固定写法） |
| ② | `project.yml` | 告诉 tpc 由哪些源码组成 | 只改 `name` / `info` |
| ③ | `bin/win32/Bootstrap.php` | 组合根：平台 + 表面 + 输入 + 主循环 | **只改 2 行**（见下） |
| ④ | `src/<Name>Application.php` | 应用本体：`buildRoot()` / `title()` | 全是你写的 |
| ⑤ | `composer.json`（**仓库根**） | PSR-4 登记 `App\<Name>\` | 加一行 |

★ **⑤ 别漏**：`composer.json` 在本仓的首要身份是**物理边界表**（`tools/lint.php`
与 `tools/autoload.php` 都读它）。不登记 ⇒ `php.exe` 侧（smoke / preview）找不到类，
而 **AOT 侧靠 `project.yml` 的 `sources` 自动收** ⇒ 两边不同源，只有内环炸。
所以它是**必须**登记，不是可选的便利。

★ 加起来 188 行，其中 **121 行是 `Bootstrap.php` 的固定样板**，只有 20 行左右是
"这个应用自己的东西"。下一节说清楚为什么样板不能少。

---

## 1. `main.php`：三件必须一字不差的事

```php
namespace {
    function main(): void
    {
        \App\Tutorial\Bootstrap::run();
    }
}
```

1. 函数名 `main`、**无参数、无返回值**；
2. 必须在**全局**命名空间 —— tpc 会加 `/SUBSYSTEM:WINDOWS /ENTRY:mainCRTStartup`，
   C++ 侧找的就是这个符号；
3. 调全局符号写**全限定名**（前导 `\`）。

入口保持"哑"：不写任何装配。装配在 `Bootstrap.php`。

---

## 2. `project.yml`：从仓库根调用

```bash
php tools/drift-build.php tutorial
# 等价于：tpc apps/tutorial/project.yml -O2 -m bin
```

**必须从仓库根调用**，因为 `cxx-flags` / `ld-flags` 不经过 tpc 的路径解析
（相对编译进程的 cwd），输出 exe 也落在 cwd。反过来，**被 tpc 解析的路径**
（`sources` / `build-dir`）以 `project.yml` 所在目录为基准 ⇒ 必须写 `../../` 前缀。

编译参数（`no-console` / `cxx-std` / flags / libs / `ignore` / `build-dir`）全在
`build/base.yml`，四个应用共用，**别复制进各应用**。

---

## 3. `Bootstrap.php`：121 行样板，为什么不能少

整段照抄，只改这两行：

```php
$app = new TutorialApplication();                       // ← 换类名
$handle = $window->createWindow('Drift 教程', 720, 560);  // ← 换标题/尺寸
```

★ **主循环为什么归应用、不进框架**：`while ($platform->pump())` 里除了派发，
还要做**应用自己的每帧工作** —— `apps/users` 在这里 reap 子进程、
`apps/showcase` 在这里泵 `FrameClock` 并在关窗时存窗口几何。抽进框架就必须
长出"每帧钩子 / 启动钩子 / 关窗钩子"三套回调，而消费者只有 3 个且各需不同钩子。

★ `Application` **没有** `run()`，只有 `attach()` 与 `frame()` ——
"循环"这一行就是这条裁决的全部代价。

★ 循环里有四行**看着多余、删了就静默坏**：

| 那一行 | 删了会怎样 |
|---|---|
| `while (($event = $input->poll()) !== null)` | 事件队列无限增长，窗口对点击/按键**毫无反应**，不报错 |
| `$window->setCursor($handle, $shape)` | 光标声明全部失效（命中链算出来的形状没人喂给平台） |
| `$platform->imeCaret($app->imeCaretRect())` | 输入法候选框贴在窗口左上角（能打字、位置错） |
| **`$clock->tick($app, ...)` 换成 `$app->frame(...)`** | **异步与动画永远不动**：`pump()` 阻塞在 `GetMessageW`，没有输入事件时循环一直睡；`wantsFrame()` 只是"回答要不要跑下一帧"，**没人问就没人答** ⇒ `after()` 排的回调永不触发、`Spinner` 永不转。**闸门抓不到**（smoke 自己推进时钟） |

★ 最后那条是 2026-10-25 从本教程的 ④ 段**真机**测出来的：点「开始加载」后停住，
1500ms 后的截图与 400ms 时**逐字节相同**（md5 一致），而 smoke 全绿。
所以 `FrameClock` 不是可选项 —— 它管"什么时候该跑下一帧"，`Application` 只管"这一帧画什么"。

```php
$clock = new FrameClock($platform->timers());
$clock->pace($app->wantsFrame());   // 进循环前先上膛，否则第一次 pump 无限阻塞
while ($platform->pump()) {
    /* 抽干事件队列 + dispatchInput + imeCaret */
    $clock->tick($app, $platform->now(), $screen->viewport());   // = frame() + 重新上膛
    /* imeCaret */
}
$clock->stop();
```

---

## 4. `Application`：只写三样

```php
final class TutorialApplication extends Application
{
    public function buildRoot(): Widget { return TutorialPage::make(...); }
    public function title(): string     { return 'Drift 教程'; }
    // commands(): iterable —— 不用命令系统就不写
}
```

★★★ **覆写了 `__construct()` 就必须调 `parent::__construct()`**：基类在构造器里
建七个拆分片（router / textEditing / scrollInput / overlayHost / input /
overlayLayout / pumps）。漏掉 ⇒ 那七个字段全是未初始化，真机 `attach()` 第一句就崩，
而错误信息只有一句 `Attempt to read property on null`，**没有文件名没有行号**
（AOT 把未初始化属性读成 `null`，解释器本该报 `must not be accessed before initialization`）。
闸门：`tools/Lint/Rules/AppSubclassCtorParent.php`。

★ 三个覆写是 `public` 不是 `protected`：基类定义成 public，子类收窄可见性 ⇒ PHP Fatal。

---

## 5. 四段：从静态到有状态到浮层到异步

| 段 | 文件 | 教的东西 | 要接线吗 |
|---|---|---|---|
| ① 最小应用 | `src/Stages/HelloStage.php` | `buildRoot()` 返回什么就画什么 | 否 |
| ② 计数器 | `src/Stages/CounterStage.php` | `StatefulWidget` + `State` + `setState()` | 否 |
| ③ 浮层 | `src/Stages/OverlayStage.php` | `Widget::overlayPanel()` 声明式缝 | **否** |
| ④ 异步 | `src/Stages/AsyncStage.php` | 能力由组合根递下去 | **是** |

### ② 状态：Widget 不可变，状态住 State

```php
final class CounterStage extends StatefulWidget { /* 声明"我有状态" */ }
final class CounterState extends State             { /* 可变字段住这里 */ }
public function createState(): State               { return new CounterState(); }
```

★ `$count` 绝不放在 `CounterStage` 上 —— 每次重建都会造一个新的 Widget 实例，
放那儿等于每帧清零。

★ `setState()` **只标脏**：闭包当帧跑完，重建发生在**帧末**。所以回调里连点十次
只重建一次，"build 期 setState 触发重入"这类问题在结构上不存在。

### ③ 浮层：先学不接线的那条路

| 写法 | 谁开 | 要接线吗 |
|---|---|---|
| `Widget::overlayPanel($factory)` | 框架在**激活**那一刻开（点击 / Enter） | **否** |
| `Application::showModal()` / `showMenu()` / `showSnackbar()` | 应用自己调 | 是 |

`Button` 一旦声明了 `overlayPanel`，激活它就等于"开 / 关面板"，**不走 `onPressed`**
（"浮一个面板"比"按一下"更具体）。工厂的形参是**框架注入的 `dismiss` 闭包** ——
面板里的按钮靠它关掉自己（面板内容够不到 `Application`）。

★ 工厂**只收一个形参**是刻意的：tpc 生成的 C++ 侧**闭包 arity 没有系统验证过**。
要往工厂里多塞"锚点宽度"这类信息，宁可给 `OverlayEntry` 加字段，也不要加形参。

### ④ 异步：控件层够不到 `Application`

★★★ `$this->application()` **这个方法不存在**（Widget 层不许反向依赖 App 层）。
而 tpc **不校验方法存在性** ⇒ 写错了照样编译通过、smoke 也全绿、`Errors.log` 里
什么都没有，**真机一点就崩**。这条在示例台上真的咬过一次。

合规路径只有两条：

1. **组合根接线** —— `buildRoot()` 把自己的方法包成闭包递下去：
   ```php
   return TutorialPage::make(
       function (int $ms, \Closure $cb): void { $this->after($ms, $cb); },
   );
   ```
2. **框架托管** —— 用框架已经接好线的声明式缝（`overlayPanel()` / `tooltip()`）。

★ `after()` 的回调在 **UI 线程**上跑（所以能直接 `setState`）；真异步换
`Job` + `TaskHost`，**形状完全一样**：发出去时 `setState(loading)`，`onDone` 里
`setState(ready)`。但 `Job::run()` 在后台线程，它**不许碰** Widget / Element / State。

---

## 6. 四个静默坑（今天各踩过一次）

### 6.1 新增中文文案可能**静默无墨**

内置点阵字模是**生成物**，覆盖范围 = 上一次扫描时全仓字符串字面量里出现过的字符。
新写的字若不在表里，**不画 tofu、也不报错，直接零宽隐形** ⇒ 一句话少了一截。

```bash
php tools/font-scan-chars.php        # ① 扫全仓 → _build/cjk_used.json
python tools/font-gen-glyphs.py      # ② 栅格化 → src/Text/CjkGlyphData.php
```

★ 生成器要 `pillow` 与 `C:/Windows/Fonts/NotoSansSC-VF.ttf`（SIL OFL 1.1，可再分发；
别换成 SimHei / 雅黑 —— 由专有字体派生的点阵数据不能入库）。

★ 重生成是**纯新增**（2026-10-25 实测：补 45 字，240 行插入、0 行删除）——
既有字形逐字节不动，所以 smoke 的 md5 断言不受影响。

### 6.2 `composer.json` 不登记 ⇒ 只有内环炸

见第 0 节 ⑤。

### 6.3 组合根漏了 `parent::__construct()`

见第 4 节。

### 6.4 真机坐标别用眼睛量

截图是**缩小显示**的（1920 宽显示成 1080）⇒ 目测坐标要乘缩放比，差 8–10px 就点在
盒外、**静默不生效**。更可靠的是**扫像素量几何**（纯色块扫列找连续 y 段）。

---

## 7. 三条命令

```bash
# ① 无头预览（秒级，不编译）：确认"排开了、画出来了"
php tools/drift-preview.php --app=apps/tutorial/src/TutorialApplication.php --ascii

# ② 闸门（改了 apps/ 或 src/ 就跑）
php tools/smoke.php && php tools/lint.php

# ③ 真机 exe（1–3 分钟，要 MSVC）
php tools/drift-build.php tutorial
```

★ **② 全绿 ≠ ③ 正确**：`$this->application()` 那类坑只有 ③ 才咬得到。

---

## 8. 接下来该读什么

| 想知道 | 去哪 |
|---|---|
| 现在代码怎么写的 | `docs/architecture/` |
| 为什么这么设计 | 根 `DESIGN.md` |
| AOT 编译约束与坑 | `docs/AOT-CONSTRAINTS.md` |
| 想加一个新控件 | `docs/architecture/08-控件面.md` |
| 想抄一个完整应用 | `apps/users/`（命令系统 + 多页 + 表单 + 异步取数） |
| 想看全部控件 | `apps/showcase/`（19 个页面） |
