<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\DropApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\FilesDroppedEvent;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * 页 31「拖文件进窗 Drop」：派发 FilesDroppedEvent ⇒ 页面状态流转（A5-②，§27）。
 *
 * ★★ 为什么是 feature 而不是 unit：要证的是「事件落到 UI 上说了什么」——
 *   拖放次数、文件数、路径列表三态。这条翻译链只有真挂树、真派发输入才走得到。
 * ★★ 为什么不用像素断言：像素由 golden 基线与 T2 真机守；这里验状态。
 * ★ 视口取 1000×1400，整页在一屏里排得下，命中测试打得到根 Card。
 */
final class DropPageTest extends SmokeTestCase
{
    private const W = 1000.0;

    private const H = 1400.0;

    /** @return array{0: DropApplication, 1: BoxConstraints} */
    private static function boot(): array
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new DropApplication();
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $constraints];
    }

    /** `setState` 之后必须再跑一帧才会重建 + 重排，否则读到的是旧树 */
    private static function tick(DropApplication $app, BoxConstraints $constraints, float $ts = 33.0): void
    {
        $app->frame($ts, $constraints);
    }

    /** 页面上**包含**某片段的那条 Text 内容；找不到 ⇒ 空串 */
    private static function textWith(Element $root, string $needle): string
    {
        $found = findElement(
            $root,
            static fn(Element $el): bool => $el->widget() instanceof Text
                && str_contains($el->widget()->content(), $needle)
        );

        return $found === null ? '' : $found->widget()->content();
    }

    public function testInitialStateShowsNoDropYet(): void
    {
        [$app] = self::boot();

        check(
            '拖文件页 · 首屏说"还没拖过"，不是"0 个"（初始态与"拖了空"要分开）',
            self::textWith($app->root(), '还没拖过文件') !== ''
        );
        check(
            '拖文件页 · 路径占位也建出来了（空列表给一行占位，不是空 Column）',
            self::textWith($app->root(), '（还没有文件）') !== ''
        );
    }

    public function testDropUpdatesCountAndListsPaths(): void
    {
        [$app, $constraints] = self::boot();

        $app->dispatchInput(new FilesDroppedEvent("C:/合同.md\nD:/图片/a.png", self::W / 2, self::H / 2));
        self::tick($app, $constraints);

        check(
            '拖文件页 · 第一次拖放 ⇒ 计数 1、文件数 2（状态真的写进去了）',
            str_contains(self::textWith($app->root(), '· 本次'), '第 1 次拖放 · 本次 2 个文件'),
            '实际：' . self::textWith($app->root(), '· 本次')
        );
        check(
            '拖文件页 · 路径列表里出现第一条（原始串被 explode 还原）',
            self::textWith($app->root(), 'C:/合同.md') !== ''
        );
        check(
            '拖文件页 · 路径列表里出现第二条（多路径没有只取第一个）',
            self::textWith($app->root(), 'D:/图片/a.png') !== ''
        );
    }

    public function testSecondDropAccumulatesAndReplacesList(): void
    {
        [$app, $constraints] = self::boot();

        $app->dispatchInput(new FilesDroppedEvent('C:/a.md', self::W / 2, self::H / 2));
        self::tick($app, $constraints);
        $app->dispatchInput(new FilesDroppedEvent('D:/b.txt', self::W / 2, self::H / 2));
        self::tick($app, $constraints, 49.0);

        check(
            '拖文件页 · 第二次拖放 ⇒ 计数 2（证明"每次都调回调"，不只是显示最后一条）',
            str_contains(self::textWith($app->root(), '· 本次'), '第 2 次拖放'),
            '实际：' . self::textWith($app->root(), '· 本次')
        );
        check(
            '拖文件页 · 第二次覆盖列表（不是把两次叠着显示）',
            self::textWith($app->root(), 'D:/b.txt') !== ''
                && self::textWith($app->root(), 'C:/a.md') === ''
        );
    }
}
