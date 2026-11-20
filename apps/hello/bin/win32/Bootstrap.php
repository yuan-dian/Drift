<?php
declare(strict_types=1);

namespace App\Hello;

use Drift\App\AppPaths;
use Drift\App\WindowSet;
use Drift\Platform\Win32\Win32Platform;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`HelloApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 * 换平台 = 换这个文件（`bin/<os>/Bootstrap.php`），§4.5。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，只提供
 *   `attach()` 与 `frame()`；等待仍由 `Platform::waitEvents()` 给，
 *   `WindowSet::run()` 只编排（等 / 派发 / 跑帧）。
 *
 * ★ 进程级钩子（fatal box / resize pump）由平台 `hooks()` 提供，`WindowSet`
 *   构造时装、`run()` 的 finally 卸；本文件不再接线，只决定开哪些窗。
 *
 * ★ **本文件是 §19 M1 的出口判据**："一个窗口画出「Hello Drift」"。
 *   它也是 `apps/hello` 里唯一引用 `Drift\Platform\Win32` 的文件 ⇒
 *   `apps` 只依赖 `@api` 这条规则（§4.3）唯一需要 `@api platform` 标注的地方。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 640;

    private const WINDOW_HEIGHT = 400;

    /** 最小客户区（§5.4）：挡住"拖到比内容更小" */
    private const MIN_WIDTH = 420;

    private const MIN_HEIGHT = 320;

    /** 窗口状态文件名（§5.4）：**一个应用一个文件**，里面按槽位分组 */
    private const APP_NAME = 'hello';

    public static function run(): void
    {
        $platform = new Win32Platform();
        $set = new WindowSet($platform, AppPaths::forApp($platform, self::APP_NAME));

        $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function (): HelloApplication {
                return new HelloApplication();
            },
        )->setMinSize(self::MIN_WIDTH, self::MIN_HEIGHT);

        // ★★ **真机多窗口探针**（`DRIFT_TWO_WINDOWS=1`）：再建一个窗口、装第二个
        //   `HelloApplication` 实例。验收点是"两个窗口各自收自己的事件、各自跑帧、
        //   关一个另一个还在"—— 这三条只有真实组合根才成立（探针脚本跑不进 AOT 产物）。
        //   ★ 两窗用**不同的槽位**（`main` / `second`）⇒ 几何各存各的。槽位相同的话
        //     第二个窗口会覆盖第一个的几何（**不报错**，下次启动只有一窗还原）。
        //   ★ 默认不设变量 ⇒ 单窗，与 2026-10-30 之前逐字节相同。
        if (\getenv('DRIFT_TWO_WINDOWS') === '1') {
            $set->open(
                'second',
                480,
                360,
                static function (): HelloApplication {
                    return new HelloApplication();
                },
                'Hello Drift · 第二窗',
            )->setMinSize(self::MIN_WIDTH, self::MIN_HEIGHT);
        }

        $set->run();
    }
}
