<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\App\Paths;
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
 * `Paths::userConfig` 的路径解析。量具每条都钉了一个"反例"——
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

    // ① 路径解析：空目录 ⇒ 空路径（调用方据此跳过持久化）；尾分隔符/前导斜杠归一
    public function testUserConfigPaths(): void
    {
        check(
            'State · Paths::userConfig 空目录 ⇒ 空路径（跳过持久化的信号）',
            Paths::userConfig('', 'users.window') === '',
            (string) strlen(Paths::userConfig('', 'users.window')),
        );
        check(
            'State · Paths::userConfig 归一尾分隔符与前导斜杠（C:\Drift + /b.window）',
            Paths::userConfig('C:\Drift', '/users.window') === 'C:\Drift\users.window',
            Paths::userConfig('C:\Drift', '/users.window'),
        );
        $this->flushChecks();
    }

    // ② 写回 → ③ 读回 → ④ 坏文件 → ⑤ 版本不符 → ⑥ 缺文件
    // → ⑦ 空路径 → ⑦-b 自建目录 → ⑧ 最小化 → ⑨ max=0 → ⑩ 尺寸非正
    public function testPersistRestoreBranches(): void
    {
        $path = $this->statePath;

        // ② 写回：persist() 把位置/尺寸/最大化写成 kv（含版本号）
        $win = new FakeWindowHost(Point::of(120.0, 80.0), Size::of(1000.0, 700.0), true);
        (new WindowStateStore($path))->persist($win, 7);
        $text = (string) file_get_contents($path);
        check(
            'State · persist 写 kv 文件（x=120 y=80 w=1000 h=700 max=1）',
            str_contains($text, 'x=120')
                && str_contains($text, 'y=80')
                && str_contains($text, 'w=1000')
                && str_contains($text, 'h=700')
                && str_contains($text, 'max=1')
                && str_contains($text, 'v=1'),
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
}
