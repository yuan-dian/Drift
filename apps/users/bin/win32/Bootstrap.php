<?php
declare(strict_types=1);

namespace App\Users;

use Drift\App\AppEntry;
use Drift\App\Window;
use Drift\App\WindowSet;
use Drift\Errors;
use Drift\Platform\Backdrop;
use Drift\Platform\WindowMaterial;
use Drift\Platform\Win32\Win32Platform;
use Drift\Rendering\Color;

use App\Users\Store\MemoryUserSource;
use App\Users\Jobs\JobRegistry;

/**
 * 组合根（§A.2.4 / §5.1）：**全应用唯一提到平台实现的地方**。
 *
 * 共享代码（`UsersApplication` / 页面 / 控件 / store）零平台引用；跨平台 = 同一份
 * 应用源码为每个 OS **各编译一次**（AOT 单 exe）。换平台 = 换这个文件。
 *
 * ★ 建窗 / 还原几何 / 定表面首帧 / `attach` / 主循环 / 几何写回 / 卸静态钩子
 *   全在 `WindowSet`（2026-10-30）。本文件只剩**本应用自己的四件事**：
 *   后台任务宿主、窗口材质（三个环境变量旋钮）、无边框窗、最小尺寸。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` 没有 `run()`，等待由
 *   `Platform::waitEvents()` 给，`WindowSet::run()` 只编排（一行 OS 调用都没有）。
 * ★ **中止序列第 ② 步在别的一切之前装好**（§1.2）：引导阶段就崩的话，没有错误框
 *   就只剩"闪退"。传 callable 而不是 Platform 实例（`Errors` 不依赖 Platform 类型）。
 */
final class Bootstrap extends AppEntry
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 900;

    private const WINDOW_HEIGHT = 640;

    protected function jobs(): array
    {
        return JobRegistry::make();
    }

    protected function start(): void
    {
        $platform = new Win32Platform();

        // ★ 首屏取数走常驻子进程（真 HTTP 后端，故意慢 1.2s）⇒ `loading()` 那一态
        //   第一次真的被用户看见。`useBackgroundJobs()` 收掉 exe → 池 → 预热 → 装端口；
        //   取不到 exe（解释器）就不装后台端口，submit 给 rejected Future。
        $src = new MemoryUserSource();
        $set = new WindowSet($platform, 'users');

        // 业务状态恢复：构造即读回，应用构造时 bucket 已有值；写回由 WindowSet 在退出时做一次。
        $scope = \Drift\App\AppScope::make()
            ->setRestoration(new \Drift\App\Restoration\RestorationStore(
                $set->file('state')
            ))
            ->useBackgroundJobs($this->jobs());

        // 应用构造仍收 host：它要据此把首屏取数接到后台或同步源（二者业务路径不同）。
        $taskHost = $scope->taskHost();

        $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function () use ($src, $taskHost, $scope): UsersApplication {
                return new UsersApplication($src, $taskHost, \App\Users\Jobs\FetchUsersJob::DEFAULT_BASE, $scope);
            },
            '',   // 标题走 UsersApplication::title()；占位只为把后面的回调送到正确参数位
            // ══════════ 建窗之后、还原几何之前 ══════════
            // （无边框会让客户区变大：多出原标题栏高度 ⇒ 还原的客户区↔外框换算
            //  必须按**新**口径算，否则每次启动窗口都比上次高一条标题栏）
            static function (Window $window): void {
                self::configureFrameless($window);
            },
            // ══════════ 表面就绪之后、首帧之前 ══════════
            // （材质必须在 `attach()` 之前：否则首帧画完再改材质 ⇒ 闪一下默认外观）
            static function (Window $window): void {
                self::applyMaterial($window);
            },
        )
            // ★ 最小窗口尺寸（§5.4）：挡住"拖到比内容更小"——那种状态会踩 C5/C6
            //   的布局硬校验（用户实测：可用宽 93 vs 条轨 94 ⇒ 弹 Fatal）。
            ->setMinSize(420, 320)
            // ★ UIA 无障碍（§9 / M5）：语义树是每窗一棵。不接 ⇒ 不建树、零开销。
            ->onSemantics();

        $set->run();
    }

    /**
     * 无边框窗（P1 §质感 / Stage 1b）：环境变量驱动，**默认不变**。
     *
     * `DRIFT_WINDOW_FRAMELESS=1` ⇒ 去标题栏，顶条自己当标题栏。三件事必须**一起**做，
     * 少一件就是"半可用"：
     *  ① `setFrameless()` —— 客户区=整窗（`WM_NCCALCSIZE`，样式不变 ⇒ 贴边吸附 /
     *     快照布局 / 最大化动画仍是系统的）；
     *  ② `windowDrag()` —— 顶条可拖（否则窗口拖不动）；
     *  ③ `window.*` 三条命令 —— 顶条右侧的自绘窗口按钮（否则关不掉）。
     *
     * ★ 拖动带传 **0**：壳的原生拖动带（`WM_NCHITTEST` → `HTCAPTION`）只适用于
     *   "应用不画顶条"的场景；这里顶条自己声明拖动，要能精确让位给按钮
     *   （壳看不见控件树，原生带会把按钮点击一起吞掉）。
     */
    private static function configureFrameless(Window $window): void
    {
        $framelessEnv = \getenv('DRIFT_WINDOW_FRAMELESS');
        if ($framelessEnv === false || $framelessEnv === '' || $framelessEnv === '0') {
            return;
        }

        $host = $window->host();
        $handle = $window->handle();
        $app = $window->app();

        $host->setFrameless($handle, 0);
        // ★ 注册到门面而不是应用：`Application` 不该长出一个"窗口拖动"字段，
        //   它只是个转发口。注册之后 `UsersRoot` 用 `supportsWindowDrag()` 判
        //   "画不画三个自绘按钮"，两件事因此同源。
        $app->environment()->setWindowDrag(static function () use ($host, $handle): void {
            $host->beginDrag($handle);
        });

        $commands = new WindowCommands($host, $handle);
        $app->router()->on('window.minimize', $commands);
        $app->router()->on('window.maximize', $commands);
        $app->router()->on('window.close', $commands);
    }

    /**
     * 整窗不透明度 / 底色 / 背后材质 / 一次应用完整材质快照（P1 §质感 Stage 1a/2b/3）。
     *
     * 三个旋钮都由环境变量驱动、**默认不变**；`WindowMaterial` 按值调和，与调用顺序无关。
     *
     * ★ `DRIFT_WINDOW_OPACITY=0.85` ⇒ 整窗 85% 不透明（半透明）。
     * ★ `DRIFT_WINDOW_BACKGROUND=#FFFFFF00` ⇒ 全透明底（应用没画到的地方透出桌面）。
     *   它是 `RenderSurface::setBackground()` 的唯一入口，也是**逐像素透明**的唯一
     *   入口（`applyMaterial()` 据底色 alpha 推出模式）。解析失败是 Fatal ——
     *   环境变量写错不该静默退化成白底。
     * ★ **带 alpha 时必须同时 `DRIFT_WINDOW_FRAMELESS=1`**：`applyMaterial()` 硬校验
     *   （漏设当场 Fatal，不再静默回落 GDI）。
     * ★ `DRIFT_WINDOW_BACKDROP=blur|acrylic|off`：**必须配合带 alpha 的底色**才有
     *   观察通道 —— 不透明窗口每个像素都由应用画满，DWM 画在背后的模糊被整块盖住
     *   （不是失败，是看不见）。写错**不静默退化**：走 §1.2 中止序列。
     */
    private static function applyMaterial(Window $window): void
    {
        $material = WindowMaterial::make();
        $surface = $window->surface();
        if ($surface === null) {
            return;   // 不可达：`open()` 在表面就绪之后才调本钩子
        }

        $opacityEnv = \getenv('DRIFT_WINDOW_OPACITY');
        if ($opacityEnv !== false && $opacityEnv !== '') {
            $material = $material->opacity((float) $opacityEnv);
        }

        $bgEnv = \getenv('DRIFT_WINDOW_BACKGROUND');
        if ($bgEnv !== false && $bgEnv !== '') {
            $material = $material->background(Color::fromHex($bgEnv));
        }

        $backdropEnv = \getenv('DRIFT_WINDOW_BACKDROP');
        if ($backdropEnv !== false && $backdropEnv !== '') {
            // ★ 用 if/else 而不是 `match`：tpc 对 `match` 的窄化没验证过，
            //   而这里要的是"三种已知值 + 一个 Fatal 兜底"，if/else 零风险。
            if ($backdropEnv === 'blur') {
                $material = $material->backdrop(Backdrop::Blur);
            } elseif ($backdropEnv === 'acrylic') {
                $material = $material->backdrop(Backdrop::Acrylic);
            } elseif ($backdropEnv === 'off') {
                $material = $material->backdrop(null);
            } else {
                Errors::report('DRIFT_WINDOW_BACKDROP 只认 blur / acrylic / off，收到：' . $backdropEnv);
            }
        }

        // ★ 底色像素仍归 Surface 画（`setBackground` 不动它的职责）；这里只交"模式"。
        if ($material->backgroundColor() !== null) {
            $surface->setBackground($material->backgroundColor());
        }
        $window->host()->applyMaterial($window->handle(), $material);
    }
}
