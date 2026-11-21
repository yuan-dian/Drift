<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;

/**
 * P2：嵌套重绘边界命中时，子命令流整张作为**引用**嵌进父流，不再逐条拷贝。
 *
 * ★ 两条判据各守一面：
 *   ① 结构：父流只有 1 条 OP_DRAW_LIST，且它持有子流本身（不是 N 条拷贝）；
 *   ② 语义：这条引用回放到真画布后像素与直接回放子流一致（递归展开没丢命令）。
 * ★ 只验结构会让"嵌入一条空命令"蒙混过关；只验像素会放过"逐条拷贝"的旧实现。
 */
final class DisplayListNestingTest extends SmokeTestCase
{
    private const W = 80;

    public function testChildStreamIsEmbeddedByReferenceAndReplaysIdentically(): void
    {
        $child = $this->makeChildStream();
        check('P2 · 子流确有多条命令（否则引用判据是空壳）', $child->count() > 3, 'count=' . $child->count());

        // ① 结构：replay 到录制画布 ⇒ 整张嵌入为一条引用
        $parentList = new DisplayList();
        $parentRec = new RecordingCanvas($parentList);
        $child->replay($parentRec);
        check('P2 · 父流只有一条 OP_DRAW_LIST（不是逐条拷贝）', $parentList->count() === 1, 'count=' . $parentList->count());
        check('P2 · 该命令是 OP_DRAW_LIST', $parentList->count() === 1 && $parentList->opAt(0) === DisplayList::OP_DRAW_LIST);
        check(
            'P2 · 引用指向子流本身（同一对象，非副本）',
            $parentList->count() === 1 && $parentList->argAt(0, 0) === $child,
        );

        // ② 语义：引用回放到真画布，像素与直接回放子流逐像素一致
        $direct = $this->raster();
        $child->replay($direct);
        $nested = $this->raster();
        $parentList->replay($nested);
        check('P2 · 嵌套回放与直接回放像素完全等价', $direct->toRgba() === $nested->toRgba());

        $this->flushChecks();
    }

    /** 三层嵌套也要能展开（验证递归不是只支持一层） */
    public function testDeepNestingReplaysIdentically(): void
    {
        $leaf = $this->makeChildStream();
        $midList = new DisplayList();
        $leaf->replay(new RecordingCanvas($midList));

        $topList = new DisplayList();
        $midList->replay(new RecordingCanvas($topList));

        $direct = $this->raster();
        $leaf->replay($direct);
        $nested = $this->raster();
        $topList->replay($nested);
        check('P2 · 三层嵌套展开后像素一致', $direct->toRgba() === $nested->toRgba());

        $this->flushChecks();
    }

    private function makeChildStream(): DisplayList
    {
        $list = new DisplayList();
        $rec = new RecordingCanvas($list);
        $paint = Paint::fill(Color::fromRgb(200, 30, 40));
        for ($i = 0; $i < 5; $i++) {
            $rec->drawRect(new Rect(2.0 + $i * 10.0, 4.0, 8.0, 20.0), $paint);
        }

        return $list;
    }

    private function raster(): RasterCanvas
    {
        return new RasterCanvas(self::W, self::W, Color::white(), Scale::of(1.0));
    }
}
