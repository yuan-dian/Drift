<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 平台 = **能力端口的集合**，不是上帝接口（§5.1）。
 *
 * ★ 本接口只有四类东西：
 *   ① **六个子端口**（`screen()` / `window()` / `surface()` / `input()` / `timers()` /
 *      `fatalBox()`）—— 每个平台都必须有，故**不进主接口就没有"不支持"这个状态**；
 *   ② **三个可空壳服务**（`clipboard()` / `dialog()` / `tray()`）—— 跨平台语义差异大，
 *      "不支持"在**类型层面**表达（返回 `null`，§24.3 例外登记表）；
 *   ③ **循环的两个入口**（`pump()` / `now()`）—— 见下；
 *   ④ **一个目录查询**（`configDir()`，§5.4）—— 用户文件放哪，平台必然知道；
 *      "不知道"用空字符串表达（不升级成 `null`：消费者只有一个降级动作）。
 *
 * ★★ **循环归平台，派发归框架**（§5.2 裁决 1）：框架**绝不自建循环**。
 *   `pump()` 阻塞等待下一条 OS 消息（事件驱动，**不空转**），归一化后交给
 *   `input()` 的队列；返回 `false` 表示循环该结束了。`now()` 给的是**单调**毫秒
 *   时钟（`GetTickCount64` / `mach_absolute_time`），帧时间戳由它注入 ——
 *   框架**不读环境时钟**，这是 G3 确定性的物质基础（§5.2 裁决 2）。
 *
 * ```php
 * // apps/<app>/bin/<platform>/Bootstrap.php —— 组合根（§4.5）
 * $platform = new \Drift\Platform\Win32\Win32Platform();
 * $handle   = $platform->window()->createWindow('Hello Drift', 640, 400);
 * $platform->window()->show($handle);
 *
 * $app->attach($platform->surface($handle)?->surface(), $platform->screen()->viewport());
 * while ($platform->pump()) {
 *     $app->frame($platform->now(), $platform->screen()->viewport());
 * }
 * ```
 *
 * ★ **平台类名只出现在组合根**（§5.1）：共享代码（页面 / 控件 / store / Application
 *   子类）零平台引用；跨平台 = 同一份应用源码为每个 OS **各编译一次**。
 *
 * ★ **`fatalBox()` 是唯一一个"不为功能、只为失败"的端口方法** —— §1.2 的标准中止
 *   序列第 ② 步。它进主接口不拆独立端口：每个平台都有（Win32 用 `MessageBoxW`，
 *   其它平台最差也能写 stderr）⇒ **不存在"不支持"这个状态**（§5.1 裁决 4）。
 *
 * ★ **这里【不】加 `menu()`** —— 菜单是自绘控件，不是平台能力（§5.1 裁决 1）。
 *   `dropTarget()` 为 M5+ 预留位（OS 级拖放需 OLE，明确不做，§12.4）。
 *
 * @api platform
 */
interface Platform
{
    /** DPI / 视口 / 系统主题（§5.1 / §9.2） */
    public function screen(): Screen;

    /** 窗口生命周期与运行期控制（§5.1 纪律 3："生"与"活"分开） */
    public function window(): WindowHost;

    /**
     * 按窗口给出渲染表面；**该平台不支持渲染 ⇒ `null`**（§5.1）。
     *
     * @param int $windowHandle `window()->createWindow()` 给的不透明句柄
     */
    public function surface(int $windowHandle): ?SurfaceProvider;

    /** 原始事件泵（§9.1 路由链的输入侧） */
    public function input(): InputSource;

    /** 定时器：帧时钟（VSync / `WM_TIMER`）由它驱动（§5.2 裁决 1） */
    public function timers(): TimerHost;

    /**
     * ★ Fatal 中止序列第 ② 步（§1.2）：弹一个**原生**错误框。
     *
     * 走平台端口而不是直调 `MessageBoxW`，是为了不破坏"平台名只出现在组合根"
     * （§5.1）。它**不依赖渲染栈** —— 渲染栈本身可能正是崩掉的那一环。
     */
    public function fatalBox(string $title, string $body): void;

    /** 壳服务：不支持 ⇒ `null`（调用方必须处理，不许当空操作吞掉，§24.3） */
    public function clipboard(): ?Clipboard;

    public function dialog(): ?Dialog;

    public function tray(): ?Tray;

    /**
     * 阻塞等待下一条 OS 消息并归一化，返回**循环是否该继续**（§5.2 裁决 1）。
     *
     * ★ 语义三条：
     *   ① **阻塞** —— 没有消息就等，**不空转**（GUI 仓实测形态是手动泵 + 帧睡眠）；
     *   ② **归一化** —— Win32 `MSG` → Drift 事件，交给 `input()` 的队列；
     *      PHP 侧因此**不持有任何 Win32 常量、不做位运算**；
     *   ③ `false` = 收到退出（`WM_QUIT` / 窗口关闭）⇒ 调用方跳出循环。
     *
     * ★ 消息泵在**平台**上而不在 `WindowHost` 上（§5.2 的图里写作 `WindowHost::pump`，
     *   那是本接口定形前的旧写法）：泵要**归一化**事件并投给 `input()`，而
     *   `input()` 归 `Platform` 所有 —— 拆到 `WindowHost` 上只会让一次操作跨两个对象。
     */
    public function pump(): bool;

    /**
     * IME 光标上报（§8.4）。`null` = 无插入符；`[x, y, h]` = **逻辑像素**窗口坐标
     * （组合根从 `Application::imeCaretRect()` 拿）。
     *
     * ★ 平台侧负责量化（× dpr）—— §6.1「逻辑→物理只走 quantizeRect」的同类：
     *   框架永远给逻辑值。Win32 上它就是 `drift_win_ime_caret()` 那一个桥调用。
     */
    public function imeCaret(?array $rect): void;

    /**
     * **单调**毫秒时钟（帧时间戳的唯一来源，§5.2 裁决 2）。
     *
     * ★ 必须单调：`microtime()` 会被系统时间调整，而动画相位 = f(帧时间戳)，
     *   时间倒流 ⇒ 动画倒放。Win32 走 `GetTickCount64`。
     * ★ 绝对原点无意义，**只做减法**（GUI 仓教训：`GetTickCount64` 的更新粒度
     *   就是系统时钟中断，约 15.6ms，短段会被量化成 0）。
     */
    public function now(): float;

    /**
     * 用户级配置目录（§5.4 `WindowStateStore` 的落盘处）。
     *
     * ★ **为什么它在主接口上**：每个平台都有这样一个"属于这个用户的目录"
     *   （Win32 = `%APPDATA%`；等价于 mac 的 `~/Library/Application Support`），
     *   且它不随窗口 / 表面 / 输入而变 ⇒ 与 `fatalBox()` 同属"平台必然有"的
     *   那一类。"平台没有这个概念"不用 `null` 表达，用**空字符串**
     *   （消费者据此跳过持久化，见 §5.4 纪律 2 的静默降级）。
     * ★ **`getenv('APPDATA')` 在 PHP 侧是被禁的**（§16.7 `nano-hygiene`
     *   编译期就拒）⇒ 它是**平台能力**，只能由平台实现回答。
     * ★ **只给路径，不创建目录**：创建归写入方（`WindowStateStore`），
     *   问一次路径不该有副作用。
     */
    public function configDir(): string;
}
