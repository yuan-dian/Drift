<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\Paths;
use Drift\App\WindowStateStore;
use Drift\App\EventLoop;
use Drift\Errors;
use Drift\Platform\Win32\Win32Platform;
use Drift\Platform\Win32\ResizeHookState;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`ShowcaseApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 * 换平台 = 换这个文件（`bin/<os>/Bootstrap.php`），§4.5。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，只提供
 *   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
 *   `EventLoop::run($platform, [$handle => $app])` 这一行就是"框架绝不自建循环"
 *   的全部代价（等待仍由平台给：`Platform::waitEvents()`）。
 *
 * ★ 本文件与 hello / users 的 Bootstrap 逐行同构（§4.5 组合根形态）——
 *   换应用只换 `new ShowcaseApplication()` 一行。
 *   它是 `apps/hello` 里唯一引用 `Drift\Platform\Win32` 的文件 ⇒ 也因此是
 *   `apps` 只依赖 `@api` 这条规则（§4.3）唯一需要 `@api platform` 标注的地方。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 960;

    private const WINDOW_HEIGHT = 640;

    public static function run(): void
    {
        $platform = new Win32Platform();

        // ★ 中止序列第 ② 步必须在**别的一切之前**装好（§1.2）：引导阶段就崩的话，
        //   没有错误框就只剩"闪退"。★ 传的是 callable 而不是 Platform 实例 ——
        //   `Errors` 不依赖 Platform 类型，"平台名只出现在组合根"才不被破坏（§5.1）。
        Errors::installFatalBox(
            static function (string $title, string $body) use ($platform): void {
                $platform->fatalBox($title, $body);
            }
        );

        $window = $platform->window();
        $handle = $window->createWindow('Drift 示例台', self::WINDOW_WIDTH, self::WINDOW_HEIGHT);
        $window->show($handle);

        // ★ 窗口状态读回（§5.4 / §19 M5）：**启动一次同步 IO**，不在帧内。
        //   ★ 顺序在 `surface->resize()` **之前** —— resize 定首帧尺寸，而首帧就该是
        //     还原后的尺寸；放到后面用户会先看到一帧默认尺寸再被拽过去。
        //   ★ 没有配置文件 / 读坏了 / 平台没给配置目录 ⇒ 一律**静默降级**到默认几何
        //     （§5.4 纪律 2，G2 的显式例外；每次降级写一条 Diagnostic）。
        $state = new WindowStateStore(Paths::userConfig($platform->configDir(), 'showcase.window'));
        $state->restore($window, $handle);

        $provider = $platform->surface($handle);
        if ($provider === null) {
            // `Errors::report()` 是 Fatal ⇒ 走 §1.2 中止序列，不会返回。
            // 这一行 `return` 只为让下面的 `$provider->surface()` 类型收窄成立。
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return;
        }

        $screen = $platform->screen($handle);
        $surface = $provider->surface();

        // 首帧之前把表面尺寸定下来：`attach()` 内部就会跑一帧（`Application::attach`），
        // 而那帧要往表面上画 ⇒ 尺寸为 0 时那一帧会被丢弃（画面到第二次 resize 才出现）。
        // 还原后的实际客户区（读回，不是常量）：断言"缩放到真实几何"，否则
        // 还原了窗口大小、画面还按旧尺寸排 ⇒ 四周出现拉伸/裁切且不报错。
        $restored = $window->clientSize($handle);
        $surface->resize(
            (int) round($restored->width * $screen->scale()),
            (int) round($restored->height * $screen->scale()),
            $screen->scale(),
        );

        // ★ 剪贴板端口在这里递进应用（2026-10-21，#51）：框架不持平台（§5.1）
        //   ⇒ `Application::clipboard()` 默认 null；示例台是 `Ctrl+C/X/V`
        //   （§9.1 让位规则 ② 的编辑命令环）的第一个真实消费者。
        //   ★ 递的是**端口**不是平台实例 —— Win32 实现仍只出现在本文件。
        // ★ 对话框端口同理（2026-10-30，A5-①）：`Dialog` 的消费者是「对话框」
        //   示例页，而页面够不到 `Application`（§4.3）⇒ 端口从本文件经
        //   `ShowcaseApplication` 递到 `ShowcaseRoot`。
        //   ★ 它排在第一个：`?Clipboard` 之后不能再接必选参数（tpc：可选参数
        //     不许排在必选之前）。
        $app = new ShowcaseApplication($platform->dialog(), $platform->clipboard());
        $app->attach($surface, $screen->viewport());

        // ★ 真机探针钩子（`tools/probe-close.py ... stay`）：`DRIFT_CLOSE_CONFIRM=1`
        //   ⇒ 启动即把守卫答案设成"拒绝"，不必先点导航到「对话框」页再点开关
        //   （那一页在滚动区深处，坐标脆弱）。默认路径不变：不设变量 ⇒ `false`。
        if (\getenv('DRIFT_CLOSE_CONFIRM') === '1') {
            $app->setConfirmClose(true);
        }

        // ★ 最小窗口尺寸（§5.4）：挡住"拖到比内容更小"——那种状态会踩 C5/C6
        //   的布局硬校验（用户实测：可用宽 93 vs 条轨 94 ⇒ 弹 Fatal，拖动被打断）。
        //   "窗口被拖小"是用户行为、不是编程错误；最小尺寸是 Qt/WPF/Flutter 的标配。
        //   420×320 逻辑像素 = 顶条（标题 + 3 个导航 + 文件菜单）留余量 + 状态栏 + 数行列表。
        $window->setMinSize($handle, 420, 320);

        // ★ 关闭可拦截（2026-10-30）：声明这个窗口"关之前要问一句"。
        //   声明之后 `WM_CLOSE` 不再直接销毁 —— 它变成一条 `CloseRequestedEvent`，
        //   在帧边界落到 `ShowcaseApplication::onCloseRequested()`。
        //   ★ 示例台的答案取决于「对话框」页那个开关（默认关 ⇒ 行为与之前一样）：
        //     这条线的**唯一**目的就是让"拒绝关闭"这一支在真机上看得见。
        //   ★ 声明本身是**窗口级**的：它不改变"谁能关"，只改变"关了会不会先问"。
        $window->setCloseGuard($handle, true);

        // ★ 拖动边缘重排（§5.2）：模态尺寸循环里主循环被系统挂起，壳在 WM_SIZE
        //   里**同步调回** PHP 走轻量重绘（只 settle/layout/paint/present，不碰
        //   任务/动画/焦点/语义）。入口 `drift_resize_hook_pump()` 是 AOT 具名
        //   符号（见 src/Platform/Win32/ResizeHook.php）。
        //   不注入 ⇒ 壳退回"原尺寸贴 + 余区填底色"（拖动中不放大、松手补齐）。
        ResizeHookState::$pump = static function (int $windowHandle) use ($handle, $app, $surface, $screen): void {
            if ($windowHandle !== $handle) {
                return;   // 本组合根只建一个窗：别的句柄不归它管（多窗口宿主见 apps/hello）
            }
            $dpr = $screen->scale();
            $vp = $screen->viewport();
            $surface->resize(
                (int) round($vp->maxWidth * $dpr),
                (int) round($vp->maxHeight * $dpr),
                $dpr,
            );
            $app->repaintForResize($vp);
        };

        // ★ UIA 无障碍（§9 / M5）：把语义树消费者接到窗口 —— 框架只产
        //   SemanticsTree、经闭包交给平台（框架不持平台，§5.1）。不接 ⇒ 不建语义树、
        //   零开销。★ showcase 此前没接 ⇒ UIA 客户端只看到根节点（值型 Pattern
        //   无从验证）；接上后它是无障碍能力的**展示台**，与 users 同形。
        // ★ `$handle` 一并捕获（2026-10-30，M1-a）：语义树是**每窗一棵**，
        //   不指定窗口就会推到"最后建的窗"的 provider 上。
        $app->setSemanticsSink(static function (array $nodes) use ($window, $handle): void {
            $window->pushSemantics($handle, $nodes);
        });


        // ★★ 主循环（§17.4.9 / N2）：等 / 派发 / 跑帧全在 `EventLoop` 里。
        //   为什么不能自己写：`pump()` 阻塞在壳的 `GetMessageW`，没输入事件时循环
        //   一直睡 ⇒ 后台结果只能等下一条用户消息捎带（现象：任务完了界面几秒后才变、
        //   Loading 转圈图不动）。"等多久"现在由 `FrameClock::nextWaitMs()` 按
        //   **还有什么活**算（动画/异步在途 ⇒ 15ms 帧节奏；只有定时器 ⇒ 睡到点；没事 ⇒ 无限等）。
        EventLoop::run($platform, [$handle => $app]);

        // ★ 窗口状态写回（§5.4）：**退出一次同步 IO**（循环之外，不占帧）。
        //   最小化时退出 ⇒ 平台侧 position() 给 null ⇒ 跳过写入，保留上一份状态。
        $state->persist($window, $handle);

        // ★★ **卸下两个进程级钩子**（2026-10-30）：两处都是**静态**属性，捕获的闭包
        //   分别持有应用对象图与 `$platform`（渲染表面 / 句柄）。留着 ⇒ 它们在 PHP
        //   shutdown 才释放 ⇒ AOT 产物在那时解引用已销毁的类元数据 ⇒
        //   `php8ts.dll+0x24b30` 访问冲突（窗口已关完、状态也写完，最后一步崩）。
        //   **两个都要卸**（实测只卸一个仍是 6~7/8 崩，两个都卸是 0/8）；
        //   完整取证见 `Errors::installFatalBox()` 的 docblock。
        ResizeHookState::$pump = null;
        Errors::installFatalBox(null);
    }
}
