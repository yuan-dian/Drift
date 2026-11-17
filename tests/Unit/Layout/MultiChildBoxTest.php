<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\ChildContainer;
use Drift\Layout\RenderBox;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;

/**
 * `MultiChildBox`：四个多子盒的 kids 增删 / 遍历 / 绘制序单点。
 *
 * ① 行为：四盒各挂两个子盒，`children` / `visitChildren` 顺序正确、`clearChildren`
 *   清空、`paintContent` 把每个子盒各画一次 —— 证明 trait 方法用各宿主自己的
 *   `$kids` 字段工作，而非某个盒缺了接线还照样绿。
 * ② 结构：四盒 `use MultiChildBox`、不再自持五方法、仍 `implements ChildContainer`
 *   且保留 `$kids` 字段（trait 读写它）。附量具自证防路径写坏时空跑恒绿。
 *
 * ★ ② 跳过 docblock：注释里出现方法名不算违规。
 */
final class MultiChildBoxTest extends SmokeTestCase
{
    private const HOSTS = ['RenderFlex', 'RenderWrap', 'RenderStack', 'RenderSegmentedControl'];
    private const METHODS = 'addChild|clearChildren|children|visitChildren|paintContent';

    /** @return array<string, RenderBox&ChildContainer> */
    private static function hosts(): array
    {
        $out = [];
        foreach (self::HOSTS as $short) {
            $rc = new \ReflectionClass('Drift\\Layout\\' . $short);
            $box = $rc->newInstanceWithoutConstructor();
            if ($box instanceof RenderBox && $box instanceof ChildContainer) {
                $out[$short] = $box;
            }
        }

        return $out;
    }

    private static function kid(float $w): SolidBox
    {
        $box = new SolidBox(new Size($w, 10.0), $w, 10.0);
        $box->layout(BoxConstraints::tight(new Size($w, 10.0)));

        return $box;
    }

    public function testEachHostDrivesKidsThroughTrait(): void
    {
        $hosts = self::hosts();
        foreach ($hosts as $name => $box) {
            $a = self::kid(10.0);
            $b = self::kid(20.0);

            $box->addChild($a);
            $box->addChild($b);
            check(
                "$name · addChild 两子后有 2 个且顺序是插入序",
                $box->children() === [$a, $b],
                'count=' . \count($box->children()),
            );

            $seen = [];
            $box->visitChildren(static function (RenderBox $kid) use (&$seen): void {
                $seen[] = $kid;
            });
            check(
                "$name · visitChildren 按插入序访问（trait 遍历各宿主的 \$kids）",
                $seen === [$a, $b],
                'seen=' . \count($seen),
            );

            $list = new DisplayList();
            (new \ReflectionMethod($box, 'paintContent'))->invoke($box, new RecordingCanvas($list));
            $rects = 0;
            for ($i = 0; $i < $list->count(); $i++) {
                if ($list->opAt($i) === DisplayList::OP_DRAW_RECT) {
                    $rects++;
                }
            }
            check(
                "$name · paintContent 把每个子盒各画一次（录制里 2 个 drawRect）",
                $rects === 2,
                'drawRect=' . $rects,
            );

            $box->clearChildren();
            check(
                "$name · clearChildren 清空 kids",
                $box->children() === [],
                'count=' . \count($box->children()),
            );
        }

        $this->flushChecks();
    }

    public function testHostsShareTraitAndNoLongerOwnTheMethods(): void
    {
        $bad = [];
        foreach (self::hostFiles() as $file => $src) {
            $code = self::codeOnly($src);
            $short = \basename($file);

            if (!\preg_match('/^[ \t]*use[ \t]+MultiChildBox[ \t]*;/m', $code)) {
                $bad[] = $short . '（未 use trait）';
            }
            if (\preg_match('/function[ \t]+(' . self::METHODS . ')\s*\(/', $code) === 1) {
                $bad[] = $short . '（仍自持五方法之一）';
            }
            if (\preg_match('/\bimplements[ \t]+ChildContainer\b/', $code) !== 1) {
                $bad[] = $short . '（丢了 ChildContainer：多子 Element 靠它识别）';
            }
            if (\preg_match('/private[ \t]+array[ \t]+\$kids\b/', $code) !== 1) {
                $bad[] = $short . '（丢了 $kids 字段：trait 读写它）';
            }
        }

        \sort($bad);
        check(
            '四盒共用 MultiChildBox：不各自实现五方法，保留 ChildContainer / $kids',
            $bad === [],
            $bad === [] ? '' : \implode(', ', $bad),
        );
        $this->flushChecks();
    }

    /** 量具自证：扫描确实命中四个宿主，防止路径写坏时上一条恒绿 */
    public function testGaugeSeesAllFourHosts(): void
    {
        $found = \array_map('\basename', \array_keys(self::hostFiles()));
        \sort($found);
        $want = \array_map(static fn(string $s): string => $s . '.php', self::HOSTS);
        \sort($want);

        check(
            '量具自证：确实找到 4 个多子宿主（扫描没写坏）',
            $found === $want,
            \implode(', ', $found),
        );
        $this->flushChecks();
    }

    /** @return array<string, string> */
    private static function hostFiles(): array
    {
        $out = [];
        $root = \repoRoot() . '/src/Layout/';
        foreach (self::HOSTS as $name) {
            $file = $root . $name . '.php';
            if (\is_file($file)) {
                $out[$file] = (string) \file_get_contents($file);
            }
        }

        return $out;
    }

    private static function codeOnly(string $src): string
    {
        $keep = [];
        foreach (\explode("\n", $src) as $line) {
            $trim = \ltrim($line);
            if (\str_starts_with($trim, '*') || \str_starts_with($trim, '/*')
                || \str_starts_with($trim, '//') || \str_starts_with($trim, '#')) {
                continue;
            }
            $keep[] = $line;
        }

        return \implode("\n", $keep);
    }
}
