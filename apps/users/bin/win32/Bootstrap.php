<?php
declare(strict_types=1);

namespace App\Users;

use Drift\Errors;
use Drift\Platform\Win32\Win32Platform;

use App\Users\Store\MemoryUserSource;

/**
 * 组合根（§A.2.4 / §5.1）：**全应用唯一提到平台实现的地方**。
 *
 * 共享代码（`UsersApplication` / 页面 / 控件 / store）零平台引用；跨平台 = 同一份
 * 应用源码为每个 OS **各编译一次**（AOT 单 exe）。换平台 = 换这个文件。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` 没有 `run()`，只提供
 *   `attach()` 与 `frame()` —— `while ($platform->pump())` 这一行就是
 *   "框架绝不自建循环"的全部代价。
 * ★ **中止序列第 ② 步在别的一切之前装好**（§1.2）：引导阶段就崩的话，没有错误框
 *   就只剩"闪退"。传 callable 而不是 Platform 实例（`Errors` 不依赖 Platform 类型）。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 900;

    private const WINDOW_HEIGHT = 640;

    public static function run(): void
    {
        $platform = new Win32Platform();

        Errors::installFatalBox(
            static function (string $title, string $body) use ($platform): void {
                $platform->fatalBox($title, $body);
            }
        );

        // ★ 后端挂在**源**上，不挂 Application（§11.7）：
        //   M1–M3 同步 ⇒ MemoryUserSource；M4 线程池 ⇒ `new HttpUserSource(new ThreadPoolTaskHost())`，
        //   只改这一行，页面与 store 代码零改动。
        $src = new MemoryUserSource();
        $app = new UsersApplication($src);

        $window = $platform->window();
        $handle = $window->createWindow('用户管理', self::WINDOW_WIDTH, self::WINDOW_HEIGHT);
        $window->show($handle);

        $provider = $platform->surface($handle);
        if ($provider === null) {
            // `Errors::report()` 是 Fatal ⇒ 走 §1.2 中止序列，不会返回。
            // 这一行 `return` 只为让下面的 `$provider->surface()` 类型收窄成立。
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return;
        }

        $screen = $platform->screen();
        $surface = $provider->surface();

        // 首帧之前把表面尺寸定下来：`attach()` 内部就会跑一帧（`Application::attach`），
        // 而那帧要往表面上画 ⇒ 尺寸为 0 时那一帧会被丢弃（画面到第二次 resize 才出现）。
        $surface->resize(
            (int) round((float) self::WINDOW_WIDTH * $screen->scale()),
            (int) round((float) self::WINDOW_HEIGHT * $screen->scale()),
            $screen->scale(),
        );

        $app->attach($surface, $screen->viewport());

        // ★ **事件队列必须每帧前抽干**（§5.2 裁决 1：派发归框架）—— 不抽干的话
        //   队列会无限增长，而窗口对点击/按键**毫无反应**，且不报错。
        // ★ `dispatchInput()` 对指针事件返回命中链解析出的光标形状 ⇒ 由这里喂给
        //   `setCursor()`：应用它需要 `WindowHost`，而框架不持平台（§5.1）。
        $input = $platform->input();
        while ($platform->pump()) {
            while (($event = $input->poll()) !== null) {
                $shape = $app->dispatchInput($event);
                if ($shape !== null) {
                    $window->setCursor($handle, $shape);
                }
            }

            $app->frame($platform->now(), $screen->viewport());
        }

        // ★ 窗口状态读回 / 写回（`WindowStateStore`）归 M5（§19）：启动一次、退出一次同步 IO。
    }
}
