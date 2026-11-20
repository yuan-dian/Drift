<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\App\AppPaths;
use Drift\App\WindowStateStore;
use Drift\Dev\Smoke\WindowState\FakeWindowHost;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\Point;
use Drift\Geometry\Size;

/**
 * 窗口状态（§5.4 / M5）。
 *
 * 这一组验 `WindowStateStore` 的 persist/restore 全部分支，以及
 * `AppPaths` 的路径解析。量具每条都钉了一个"反例"——
 * 没有反例的断言就是空转分支（写了对但没被走通）。
 *
 * 临时文件走 setUp/tearDown，不污染仓库；⑦-b 的深目录在方法里自建自删。
 */
final class WindowStateTest extends SmokeTestCase
{
    private string $statePath;
    private string $stateMissing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = tempnam(sys_get_temp_dir(), 'drift-state-');
        $this->stateMissing = $this->statePath . '.missing';
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @unlink($this->stateMissing);
        parent::tearDown();
    }

    // ① 路径解析：基础目录/应用名任一为空 ⇒ 空路径（跳过持久化的信号）；
    //    尾分隔符与前导斜杠归一；分隔符按平台而非硬编码 '\'（X11 下 '\' 是合法文件名）
    public function testAppPaths(): void
    {
        $sep = DIRECTORY_SEPARATOR;
        check(
            'State · AppPaths 基础目录为空 ⇒ 空路径（跳过持久化的信号）',
            AppPaths::at('', 'users')->windowState() === '',
            (string) strlen(AppPaths::at('', 'users')->windowState()),
        );
        check(
            'State · AppPaths 应用名为空 ⇒ 空路径（应用身份不可省）',
            AppPaths::at('C:\base', '')->windowState() === '',
        );
        check(
            'State · AppPaths 归一尾分隔符与前导斜杠，且拼应用身份目录',
            AppPaths::at('C:\base/', '/users')->file('/state') === 'C:\base' . $sep . 'users' . $sep . 'state',
            AppPaths::at('C:\base/', '/users')->file('/state'),
        );
        check(
            'State · windowState() 固定文件名，一个文件按槽位装多窗',
            AppPaths::at('/base', 'app')->windowState() === '/base' . $sep . 'app' . $sep . 'window.state',
            AppPaths::at('/base', 'app')->windowState(),
        );
        $this->flushChecks();
    }

    // ② 写回 → ③ 读回 → ④ 坏文件 → ⑤ 版本不符 → ⑥ 缺文件
    // → ⑦ 空路径 → ⑦-b 自建目录 → ⑧ 最小化 → ⑨ max=0 → ⑩ 尺寸非正
    public function testPersistRestoreBranches(): void
    {
        $path = $this->statePath;

        // ② 写回：persist() 把位置/尺寸/最大化写成 kv（含版本号 + 槽位前缀）
        $win = new FakeWindowHost(Point::of(120.0, 80.0), Size::of(1000.0, 700.0), true);
        (new WindowStateStore($path))->persist($win, 7);
        $text = (string) file_get_contents($path);
        check(
            'State · persist 写 kv 文件（main.x=120 main.y=80 main.w=1000 main.h=700 main.max=1）',
            str_contains($text, 'main.x=120')
                && str_contains($text, 'main.y=80')
                && str_contains($text, 'main.w=1000')
                && str_contains($text, 'main.h=700')
                && str_contains($text, 'main.max=1')
                && str_contains($text, 'v=2'),
            trim($text),
        );

        // ③ 读回：restore() 把同一份几何交回 place()（最大化标记一起，§5.4 的"一次到位"）
        $win2 = new FakeWindowHost();
        (new WindowStateStore($path))->restore($win2, 7);
        $placed = $win2->placed;
        check(
            'State · restore 读回并 place()（Rect + 最大化一起还原）',
            count($placed) === 1
                && $placed[0][0]->left === 120.0
                && $placed[0][0]->top === 80.0
                && $placed[0][0]->width === 1000.0
                && $placed[0][0]->height === 700.0
                && $placed[0][1] === true,
            count($placed) === 1
                ? sprintf(
                    '(%s,%s %sx%s) max=%s',
                    (string) $placed[0][0]->left,
                    (string) $placed[0][0]->top,
                    (string) $placed[0][0]->width,
                    (string) $placed[0][0]->height,
                    var_export($placed[0][1], true),
                )
                : 'placed=' . count($placed),
        );

        // ④ 坏文件 ⇒ 不 place + 一条 Diagnostic（G2 的显式例外：静默降级，但不无声无息）
        file_put_contents($path, "v=1\nx=not-a-number\ny=80\nw=1000\nh=700\nmax=1\n");
        Errors::reset();
        $win3 = new FakeWindowHost();
        (new WindowStateStore($path))->restore($win3, 7);
        check(
            'State · 坐标不可信 ⇒ 不 place + 一条 Diagnostic（不报错）',
            $win3->placed === [] && count(Errors::recentDiagnostics()) === 1,
            'placed=' . count($win3->placed) . ' diag=' . count(Errors::recentDiagnostics()),
        );

        // ⑤ 版本不符 ⇒ 整份降级（半兼容比全不认更糟）
        file_put_contents($path, "v=99\nx=10\ny=10\nw=800\nh=600\nmax=0\n");
        Errors::reset();
        $win4 = new FakeWindowHost();
        (new WindowStateStore($path))->restore($win4, 7);
        check(
            'State · 版本不符 ⇒ 整份降级（不半兼容）+ 一条 Diagnostic',
            $win4->placed === [] && count(Errors::recentDiagnostics()) === 1,
            'placed=' . count($win4->placed) . ' diag=' . count(Errors::recentDiagnostics()),
        );

        // ⑥ 文件不存在 ⇒ 静默通过，且不刷 Diagnostic（第一次运行不是错误）
        Errors::reset();
        $win5 = new FakeWindowHost();
        (new WindowStateStore($this->stateMissing))->restore($win5, 7);
        check(
            'State · 文件不存在 ⇒ 什么都不做且不写 Diagnostic（首启不是错误）',
            $win5->placed === [] && Errors::recentDiagnostics() === [],
            'diag=' . count(Errors::recentDiagnostics()),
        );

        // ⑦ 空路径（平台没给配置目录）⇒ 整个 store 退化为空操作
        $win6 = new FakeWindowHost(Point::of(1.0, 2.0), Size::of(3.0, 4.0), false);
        (new WindowStateStore(''))->persist($win6, 7);
        $win7 = new FakeWindowHost();
        (new WindowStateStore(''))->restore($win7, 7);
        check(
            'State · 空路径 ⇒ persist/restore 都是空操作（平台没给目录时）',
            !is_file('') && $win7->placed === [],
            'placed=' . count($win7->placed),
        );

        // ⑦-b 目录不存在 ⇒ persist 自己建目录（壳只给路径不创建，§5.4）。真机踩出来的。
        $deep = dirname($path) . '/drift-state-deep/sub/users.window';
        $winDeep = new FakeWindowHost(Point::of(12.0, 34.0), Size::of(300.0, 200.0), false);
        (new WindowStateStore($deep))->persist($winDeep, 7);
        $deepOk = is_file($deep)
            && str_contains((string) file_get_contents($deep), 'x=12');
        @unlink($deep);
        @rmdir(dirname($deep));
        @rmdir(dirname(dirname($deep)));
        check(
            'State · persist 自己建目录（壳只给路径 → 创建归写入方；真机踩过）',
            $deepOk,
            $deepOk ? 'created+written' : 'no file',
        );

        // ⑧ 最小化 ⇒ position() 为 null ⇒ persist 跳过写入，上一份留在文件里
        $min = new FakeWindowHost(null, Size::of(1000.0, 700.0), false);
        file_put_contents($path, "v=1\nx=55\ny=66\nw=777\nh=555\nmax=0\n");
        (new WindowStateStore($path))->persist($min, 7);
        $textAfterMin = (string) file_get_contents($path);
        check(
            'State · position() 为 null（最小化）⇒ 不写文件，保留上一份状态',
            str_contains($textAfterMin, 'x=55') && str_contains($textAfterMin, 'w=777'),
            trim($textAfterMin),
        );

        // ⑨ 默认（非最大化）还原：max=0 ⇒ place() 收到 false（布尔别被"非空即真"吞掉）
        file_put_contents($path, "v=1\nx=10\ny=20\nw=800\nh=600\nmax=0\n");
        $win8 = new FakeWindowHost();
        (new WindowStateStore($path))->restore($win8, 7);
        check(
            'State · max=0 ⇒ place() 收到 false（布尔别被"非空即真"吞掉）',
            count($win8->placed) === 1 && $win8->placed[0][1] === false,
            count($win8->placed) === 1 ? var_export($win8->placed[0][1], true) : 'placed=0',
        );

        // ⑩ 尺寸非正 ⇒ 整份降级（半份状态比没有更糟）
        file_put_contents($path, "v=1\nx=10\ny=20\nw=0\nh=600\nmax=0\n");
        Errors::reset();
        $win9 = new FakeWindowHost();
        (new WindowStateStore($path))->restore($win9, 7);
        check(
            'State · 尺寸非正 ⇒ 不 place + 一条 Diagnostic',
            $win9->placed === [] && count(Errors::recentDiagnostics()) === 1,
            'placed=' . count($win9->placed) . ' diag=' . count(Errors::recentDiagnostics()),
        );

        $this->flushChecks();
    }

    /**
     * 多窗口：**一个文件装 N 组状态**，各存各的、各取各的（2026-10-30）。
     *
     * 每一条都对着一个"不分组就会静默错"的形态：后写的抹掉先写的、串槽取到对方的
     * 几何、老文件升级丢位置。这些错误的共同点是**不报错**——窗口照样出现，
     * 只是位置不对。
     */
    public function testMultiWindowSlots(): void
    {
        $path = $this->statePath;

        $mainWin = new FakeWindowHost(Point::of(10.0, 20.0), Size::of(800.0, 600.0), false);
        $sideWin = new FakeWindowHost(Point::of(900.0, 60.0), Size::of(320.0, 240.0), true);

        // ⑪ 两窗各 persist 一次 ⇒ 两组并存（后写的不能抹掉先写的）
        (new WindowStateStore($path, 'main'))->persist($mainWin, 1);
        (new WindowStateStore($path, 'side'))->persist($sideWin, 2);
        $text = (string) file_get_contents($path);
        check(
            'State · 两窗各 persist 一次 ⇒ 两组并存（后写的没抹掉先写的）',
            str_contains($text, 'main.w=800')
                && str_contains($text, 'side.w=320')
                && str_contains($text, 'side.max=1'),
            trim($text),
        );

        // ⑫ 各取各的：串槽时会取到对方的值，而且不报错
        $gotMain = new FakeWindowHost();
        $gotSide = new FakeWindowHost();
        (new WindowStateStore($path, 'main'))->restore($gotMain, 1);
        (new WindowStateStore($path, 'side'))->restore($gotSide, 2);
        check(
            'State · 两窗各自还原自己的几何（串槽时取到的是对方的值）',
            count($gotMain->placed) === 1
                && $gotMain->placed[0][0]->width === 800.0
                && $gotMain->placed[0][1] === false
                && count($gotSide->placed) === 1
                && $gotSide->placed[0][0]->width === 320.0
                && $gotSide->placed[0][1] === true,
            'main=' . (count($gotMain->placed) === 1 ? (string) $gotMain->placed[0][0]->width : '-')
                . ' side=' . (count($gotSide->placed) === 1 ? (string) $gotSide->placed[0][0]->width : '-'),
        );

        // ⑬ 文件在、但没有本槽位 ⇒ 静默用默认几何（新加的窗口第一次出现，不是错误）
        Errors::reset();
        $fresh = new FakeWindowHost();
        (new WindowStateStore($path, 'third'))->restore($fresh, 3);
        check(
            'State · 文件在但没有本槽位 ⇒ 静默用默认几何（新窗口首启不是错误）',
            $fresh->placed === [] && Errors::recentDiagnostics() === [],
            'placed=' . count($fresh->placed) . ' diag=' . count(Errors::recentDiagnostics()),
        );

        // ⑭ v1 老文件（平铺键，单窗时代）⇒ **整体**视为 main 组，升级后位置不丢
        file_put_contents($path, "v=1\nx=111\ny=222\nw=333\nh=444\nmax=1\n");
        $legacyMain = new FakeWindowHost();
        $legacySide = new FakeWindowHost();
        (new WindowStateStore($path, 'main'))->restore($legacyMain, 1);
        (new WindowStateStore($path, 'side'))->restore($legacySide, 2);
        check(
            'State · v1 老文件整体归到 main（升级不丢位置）；副窗没有记录 ⇒ 默认几何',
            count($legacyMain->placed) === 1
                && $legacyMain->placed[0][0]->width === 333.0
                && $legacyMain->placed[0][1] === true
                && $legacySide->placed === [],
            'main=' . count($legacyMain->placed) . ' side=' . count($legacySide->placed),
        );

        // ⑮ 在 v1 文件上 persist 副窗 ⇒ 抬到 v2，且 main 组随文件带走（不是只写自己那组）
        (new WindowStateStore($path, 'side'))->persist($sideWin, 2);
        $upgraded = (string) file_get_contents($path);
        check(
            'State · 在 v1 文件上 persist 副窗 ⇒ 抬到 v2 且 main 组被带走',
            str_contains($upgraded, 'v=2')
                && str_contains($upgraded, 'main.w=333')
                && str_contains($upgraded, 'side.w=320'),
            trim($upgraded),
        );

        $this->flushChecks();
    }
}
