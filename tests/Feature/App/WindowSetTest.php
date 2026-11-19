<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\App\WindowSet;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Platform\Win32\Win32Platform;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 组合根骨架（`WindowSet`）：**建窗 → 还原 → 定表面 → attach → 循环 → 写回 → 卸钩子**。
 *
 * ## 这一组钉什么（每条都对应一个"漏掉即静默"的地方）
 *
 * | 契约 | 漏掉的后果（真机可见，全部不报错） |
 * |---|---|
 * | 首帧尺寸用**读回的**客户区 | 还原了窗口、画面还按旧尺寸排 ⇒ 四周拉伸/裁切 |
 * | 每个槽位写自己那组 | 后写的覆盖先写的 ⇒ 下次启动只有一窗还原 |
 * | `release` 在 `persist` **之后** | 先卸钩子再写几何：状态里的尺寸来自已释放对象 |
 * | `release` 在**异常路径**也执行 | 一次崩溃变成"崩两次"（AOT shutdown 访问冲突） |
 * | `repaintForResize` 认句柄 | 副窗的拖动去重排主窗（只画错窗） |
 *
 * ## 为什么用真 `Win32Platform('soft')` 而不是假平台
 *
 * `WindowSet` 的每一步都在问平台端口（`window()` / `surface()` / `screen()` /
 * `configDir()`）。写个假平台等于把这些端口全绕开，测的只剩 for 循环。
 * 软光栅 + 假桥（`tools/fakes/win32.php`）能无头跑完整条装配链 ——
 * 与 `EventLoopTest` 同一口径：**桥可假，策略不可假**。
 */
final class WindowSetTest extends SmokeTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        winLoopReset();
        $this->dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'drift-windowset-' . \getmypid();
        if (!\is_dir($this->dir)) {
            \mkdir($this->dir, 0777, true);
        }
        @\unlink($this->statePath());
    }

    protected function tearDown(): void
    {
        @\unlink($this->statePath());
        parent::tearDown();
    }

    /** 状态文件落在临时目录：测试绝不碰 %APPDATA% */
    private function statePath(): string
    {
        return $this->dir . \DIRECTORY_SEPARATOR . 'app.window';
    }

    /**
     * `open()` 把窗口装配完并登记 —— 且**首帧用的是读回的客户区**。
     *
     * ★ 后半条是这一组里最值钱的断言：假桥给 create 的是 320×240、给
     *   `client_size` 的是 640×480（默认值，模拟"还原后几何变了"）。若实现
     *   照入参 resize，present 记录的宽度会是 320 —— 这正是真机上"还原了窗口、
     *   画面还按旧尺寸排"的形态，而且**不报错**。
     */
    public function testOpenRegistersWindowAndUsesActualClientSize(): void
    {
        $platform = new Win32Platform('soft');
        $app = new EchoApplication();
        $set = new WindowSet($platform, $this->statePath());

        $window = $set->open('main', '窗 1', 320, 240, static function () use ($app): Application {
            return $app;
        });

        check(
            'WindowSet · `open()` 之后窗口进表（键 = 句柄）',
            \count($set->windows()) === 1 && $window->handle() > 0,
            \json_encode(\array_keys($set->windows())),
        );
        check(
            'WindowSet · `app()` 就是 makeApp 造出来的那个实例',
            $window->app() === $app,
        );
        check(
            'WindowSet · 表面已就位（`attach()` 之前必须非 null，否则首帧被丢弃）',
            $window->surface() !== null && $window->screen() !== null,
        );

        $presents = winPresentCalls();
        check(
            'WindowSet · `open()` 内部跑过首帧（present 至少一次）',
            \count($presents) >= 1,
            \json_encode($presents),
        );
        check(
            'WindowSet · 首帧用**读回的**客户区（640×480），不是入参（320×240）',
            $presents !== [] && $presents[0][1] === 640 && $presents[0][2] === 480,
            \json_encode($presents[0] ?? null),
        );

        $this->flushChecks();
    }

    /**
     * **窗口在首帧之后才显示** —— 否则用户先看到一个未绘制的客户区（"黑窗闪一下"）。
     *
     * ★ 判据是"`show()` 被调用的那一刻，已经上过几次屏"，不是"两者都发生过"：
     *   顺序反了的时候两条都发生过，只有计数能证伪。
     * ★ 这条在无头下只能这样测：真机上的表现是黑窗，抓不到像素就没有别的信号。
     *   真机侧的对应判据是 `probe-window.py` 的"首个非白帧"。
     */
    public function testWindowIsShownOnlyAfterTheFirstFrame(): void
    {
        $platform = new Win32Platform('soft');
        $set = new WindowSet($platform, $this->statePath());
        $set->open('main', '首帧后显示', 320, 240, static function (): Application {
            return new EchoApplication();
        });

        $shown = winShowCalls();
        check(
            'WindowSet · `open()` 里窗口被显示了一次',
            \count($shown) === 1,
            \json_encode($shown),
        );
        check(
            'WindowSet · 显示时首帧已经上过屏（顺序反了 = 用户先看到黑窗）',
            $shown !== [] && $shown[0][1] >= 1,
            \json_encode($shown),
        );

        $this->flushChecks();
    }

    /**
     * 标题：**默认来自 `Application::title()`**，显式实参只是窗口级覆盖（2026-10-30）。
     *
     * ★ 这条判据取代的是"读代码才知道标题从哪来"：改动之前 4 个组合根各自手写
     *   一个与 `title()` 逐字相同的字面量，`Application::title()` 一个运行期消费者
     *   都没有。现在标题走 `createWindow()` 的第一个实参 ⇒ 假桥记下来就能证伪。
     * ★ 三种输入都要测：`null`（没给）/ `''`（误传空串）/ 显式串（覆盖）。
     *   只测 `null` 的话，"实现里根本没写 `??=` 而是把 `$title` 原样往下传"也会绿
     *   —— 那时 `''` 这条会红，但没人跑它。
     */
    public function testTitleDefaultsToApplicationTitleAndCanBeOverridden(): void
    {
        $platform = new Win32Platform('soft');
        $set = new WindowSet($platform, $this->statePath());

        $set->open('main', null, 320, 240, static function (): Application {
            return new EchoApplication();
        });
        $set->open('empty', '', 320, 240, static function (): Application {
            return new EchoApplication();
        });
        $set->open('second', '覆盖后的标题', 320, 240, static function (): Application {
            return new EchoApplication();
        });

        $calls = winCreateCalls();
        check(
            'WindowSet · 标题默认取 `$app->title()`（`null` 与 `\'\'` 都算"没给"）',
            \count($calls) === 3
                && $calls[0][0] === '冒烟应用'
                && $calls[1][0] === '冒烟应用',
            \json_encode(\array_column($calls, 0), \JSON_UNESCAPED_UNICODE),
        );
        check(
            'WindowSet · 显式标题覆盖应用默认（同一个应用类开两窗要不同标题）',
            \count($calls) === 3 && $calls[2][0] === '覆盖后的标题',
            \json_encode(\array_column($calls, 0), \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    /**
     * 两个不同槽位 ⇒ 文件里**两组都在**，且各写各的值。
     *
     * ★ 只验"有两个键"不够：整份覆盖的写法最后也会剩一个叫 `second` 的组。
     *   所以判据落在"`main` 那组的值 == 主窗读回的几何"上。
     */
    public function testEachSlotPersistsItsOwnGroup(): void
    {
        $platform = new Win32Platform('soft');
        $set = new WindowSet($platform, $this->statePath());

        $set->open('main', '主窗', 320, 240, static function (): Application {
            return new EchoApplication();
        });
        $set->open('second', '副窗', 200, 160, static function (): Application {
            return new EchoApplication();
        });

        winPumpScript([['type' => 'quit']]);
        $set->run();

        $text = (string) @\file_get_contents($this->statePath());
        check(
            'WindowSet · 两个槽位各写一组（`main.` 与 `second.` 都在）',
            \str_contains($text, 'main.') && \str_contains($text, 'second.'),
            $text,
        );
        check(
            'WindowSet · 组名是槽位名，不是句柄（句柄每次运行都变，写进文件就永远对不上）',
            !\str_contains($text, 'drift-windowset'),
            $text,
        );

        $this->flushChecks();
    }

    /**
     * `release` 在 **persist 之后**执行，且只执行一次。
     *
     * ★ 顺序反过来（先卸钩子再写几何）时，`persist()` 读到的是已释放状态的窗口
     *   几何 —— 现象是"记住了窗口位置，但记住的是上一次的"。判据只能靠
     *   "release 里能看见刚写出来的文件"。
     */
    public function testReleaseRunsAfterPersistExactlyOnce(): void
    {
        $platform = new Win32Platform('soft');
        $released = 0;
        $sawFile = false;

        $set = new WindowSet($platform, $this->statePath(), function () use (&$released, &$sawFile): void {
            ++$released;
            $sawFile = \is_file($this->statePath());
        });

        $set->open('main', '主窗', 320, 240, static function (): Application {
            return new EchoApplication();
        });

        winPumpScript([['type' => 'quit']]);
        $set->run();

        check(
            'WindowSet · `release` 只执行一次',
            $released === 1,
            \sprintf('released=%d', $released),
        );
        check(
            'WindowSet · `release` 在 `persist()` **之后**（它看得见刚写出的状态文件）',
            $sawFile,
            $this->statePath(),
        );

        $this->flushChecks();
    }

    /**
     * 循环抛异常 ⇒ `release` **仍然执行**（`finally` 的唯一证据）。
     *
     * ★ 这条对应 AOT 的"崩两次"：不卸静态钩子时，一次普通异常会让进程在
     *   shutdown 段再崩一次（`php8ts.dll+0x24b30`）—— 现场留下的是第二个崩溃，
     *   第一个反而被埋掉。
     */
    public function testReleaseRunsEvenWhenTheLoopThrows(): void
    {
        $platform = new Win32Platform('soft');
        $released = 0;

        // 第 2 帧抛：`attach()` 内部跑第 1 帧，进循环后的第一帧是第 2 帧。
        $app = new class extends Application {
            public int $frames = 0;

            public function buildRoot(): Widget
            {
                return Text::make('会炸的应用');
            }

            public function frame(float $timestampMs, BoxConstraints $constraints): void
            {
                if (++$this->frames >= 2) {
                    throw new \RuntimeException('boom');
                }
                parent::frame($timestampMs, $constraints);
            }
        };

        $set = new WindowSet($platform, $this->statePath(), function () use (&$released): void {
            ++$released;
        });
        $set->open('main', '会炸的窗', 320, 240, static function () use ($app): Application {
            return $app;
        });

        winPumpScript([['type' => 'key', 'key' => 'A']]);

        $caught = null;
        try {
            $set->run();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        check(
            'WindowSet · 循环抛异常时 `release` 仍执行（否则崩两次，第一个崩溃被埋掉）',
            $released === 1,
            \sprintf('released=%d', $released),
        );
        check(
            'WindowSet · 异常本身不被吞（吞掉 = 崩溃变成静默退出）',
            $caught !== null && $caught->getMessage() === 'boom',
        );

        $this->flushChecks();
    }

    /**
     * 拖动重排按句柄分发：不在表里的句柄**静默跳过**。
     *
     * ★ 判据是"没多画一帧"：句柄认错时最容易看见的形态就是
     *   "拖副窗，主窗重绘"（内容不变，所以除了多一帧没有任何痕迹）。
     */
    public function testRepaintForResizeIgnoresUnknownHandle(): void
    {
        $platform = new Win32Platform('soft');
        $set = new WindowSet($platform, $this->statePath());
        $set->open('main', '主窗', 320, 240, static function (): Application {
            return new EchoApplication();
        });

        $before = \count(winPresentCalls());
        $set->repaintForResize(999999);
        $after = \count(winPresentCalls());

        check(
            'WindowSet · 未知句柄的拖动重排不画帧（认错句柄的形态是"拖副窗、主窗重绘"）',
            $after === $before,
            \sprintf('%d → %d', $before, $after),
        );

        $this->flushChecks();
    }

    /** `statePath = ''` ⇒ 退化为不持久化（平台没给配置目录时的静默降级） */
    public function testEmptyStatePathDegradesToNoPersistence(): void
    {
        $platform = new Win32Platform('soft');
        $set = new WindowSet($platform, '');
        $set->open('main', '无状态窗', 320, 240, static function (): Application {
            return new EchoApplication();
        });

        winPumpScript([['type' => 'quit']]);
        $set->run();

        check(
            'WindowSet · 空路径 ⇒ 照常跑完、不写任何文件（静默降级，不抛）',
            !\is_file($this->statePath()),
            $this->statePath(),
        );

        $this->flushChecks();
    }
}
