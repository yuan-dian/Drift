<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 平台 = 能力端口的集合，不是上帝接口。
 * 五个子端口（每平台必有）、三个可空壳服务（不支持⇒null）、
 * 循环三入口（waitEvents 等 / pumpEvents 派发 / now 单调时钟）、目录查询（configDir）。
 * 窗口级能力一律句柄参数化：两套"指定窗口"写法能力相同，多一层对象只增歧义；
 *   有状态对象由平台按句柄缓存，句柄即状态 key。
 *
 * @api platform
 */
interface Platform
{
    /**
     * 该窗口的 DPI / 视口 / 系统主题。
     * ★ 带 `$windowHandle`：scale() 是 per-monitor DPI、viewport() 是那个窗口客户区，
     *   两窗跨不同 DPI 屏时值不同；改动前读"最后建的窗"，多窗口下静默用错比例。
     * 返回对象不持状态，每次现读，无需缓存。
     */
    public function screen(int $windowHandle): Screen;

    /** 窗口生命周期与运行期控制（"生"与"活"分开） */
    public function window(): WindowHost;

    /**
     * 按窗口给出渲染表面；平台不支持渲染 ⇒ `null`。
     * @param int $windowHandle `window()->createWindow()` 给的不透明句柄
     */
    public function surface(int $windowHandle): ?SurfaceProvider;

    /**
     * 该窗口的原始事件队列。
     * ★ 带 `$windowHandle`：pumpEvents() 按事件自带 hwnd 投递、EventLoop 按窗口抽干；
     *   改动前只有一条队列，多窗口下两个应用会重复消费同一条事件。
     */
    public function input(int $windowHandle): InputSource;

    /**
     * Fatal 中止序列第 ② 步：弹一个原生错误框。
     * 走平台端口而非直调 MessageBoxW，是不破坏"平台名只出现在组合根"。
     * 它不依赖渲染栈 —— 渲染栈本身可能正是崩掉的那一环。
     */
    public function fatalBox(string $title, string $body): void;

    /** 壳服务：不支持 ⇒ `null`（调用方必须处理，不许当空操作吞掉） */
    public function clipboard(): ?Clipboard;

    public function dialog(): ?Dialog;

    public function tray(): ?Tray;

    /**
     * 原生模态这段时间阻塞了循环多久（毫秒；读后即清）。
     * ★ 读后即清：调用方每轮读一次、交给 FrameClock::advance() 剔除；
     *   不清会把同一段模态时间反复剔除，把真卡顿也剔没了。无模态 ⇒ 0.0。
     */
    public function consumeModalBlockedMs(): float;

    /**
     * 等一条 OS 消息，最多 `$timeoutMs`，返回为什么醒。
     * `$timeoutMs` 三态：-1.0 无限等、0.0 只探、>0 等到点。
     * 本方法只取不派发：取到先暂存，pumpEvents() 才归一化投进 input()；派发了却不跑帧 = 队列涨、画面不动，不报错。
     * 不返回 bool —— 见 {@see WakeReason}。
     */
    public function waitEvents(float $timeoutMs): WakeReason;

    /**
     * 把这次唤醒带来的事件全部归一化并投给 input()，返回投出去的条数。
     * 非阻塞、不等。桌面只取一条、在此翻译；移动端只能 poll；唤醒被 wake() 打断时条数 0 是正常返回值。
     */
    public function pumpEvents(): int;

    /**
     * IME 光标上报。`null` = 无插入符；`[x, y, h]` = 逻辑像素窗口坐标。
     * 平台侧负责量化（× dpr）：框架永远给逻辑值。
     * 带 `$windowHandle`：插入符是线程级资源，"贴哪个窗口"必须由调用方指定。
     *
     * @internal 只有 `EventLoop` 调（应用侧给 `Application::imeCaretRect()`）
     */
    public function imeCaret(int $windowHandle, ?array $rect): void;

    /**
     * 单调毫秒时钟（帧时间戳的唯一来源）。
     * 必须单调：microtime() 会被系统时间调整 ⇒ 动画倒放。绝对原点无意义，只做减法。
     * 分辨率是契约：不能退回"秒/时钟中断级"时钟源（QPC 为 100ns 计数）。
     */
    public function now(): float;

    /**
     * 平台基础配置目录（Win32 `%APPDATA%` / X11 `$XDG_CONFIG_HOME`）。
     * ★ **不含应用身份**：框架名不进路径，多个第三方应用共用同一框架时不会互撞；
     *   应用身份由组合根经 `AppPaths::forApp()` 拼成 `<base>/<appname>/<file>`。
     * 每个平台都有，且不随窗口/表面/输入而变；"没有这个概念"用空字符串（消费者跳过持久化），不用 null。
     * getenv('APPDATA') 在 PHP 侧被禁 ⇒ 只能由平台实现回答。只给路径，不创建目录。
     */
    public function configDir(): string;

    /**
     * 进程级静态钩子的装配端口（fatal box / resize pump）。
     * 由平台实现自己 new 并缓存，`WindowSet` 代管 install/release；
     * 组合根不直接碰，也不写平台判断。
     */
    public function hooks(): PlatformHooks;
}
