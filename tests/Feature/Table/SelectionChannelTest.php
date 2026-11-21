<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Core\Pipeline;
use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\TableSelection;
use Drift\Widget\Widget;

/**
 * 选中集两种形态的**重建归属**：通道形态只重建表格，值形态重建整页。
 *
 * ★ 为什么需要这条判据：`Table::selectionFrom()` 在改造前只有单测里的调用点，
 *   "点行不重建页面"这句承诺没有任何运行期证据（注释里甚至写着"读点没下沉"）。
 *   这里用页面自己的 `$builds` 取证 —— 它只增不减，且不依赖任何私有字段。
 * ★ 对照组（值形态）是必需的一半：没有它，"表格干脆不读通道"这种坏实现
 *   也能让第一条绿。
 * ★ 第三条单独验 `TableSelection::equals()`：语义相同的选中集不触发重建
 *   （`Cell` 的门控按值，不是按实例身份）。
 */
final class SelectionChannelTest extends SmokeTestCase
{
    /** @return array{0: FrameTaxApp, 1: SelectionChannelPage} */
    private function mount(bool $channel): array
    {
        $page = new SelectionChannelPage($channel);
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));
        $app = new FrameTaxApp(static fn(): Widget => $page->host());
        $surface = new SurfaceProbe();
        $surface->resize((int) Fixtures::VIEW_W, (int) Fixtures::VIEW_H, 1.0);
        $app->attach($surface, $tight);
        $app->frame(16.0, $tight);

        return [$app, $page];
    }

    public function testChannelSelectionRebuildsOnlyTheTable(): void
    {
        [$app, $page] = $this->mount(true);
        $before = $page->builds;
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));

        $page->selection->set(TableSelection::of([2]));
        $app->frame(32.0, $tight);

        check('通道形态 · 页面 build 一次都不跑', $page->builds === $before, 'builds=' . $page->builds);

        $keys = $page->table?->selectionSet()?->keys() ?? [];
        check('通道形态 · 表格读到的是通道里的新值（不是首帧的快照）', $keys === [2], \json_encode($keys));

        $rebuilt = Pipeline::current()->rebuiltCount();
        $page->selection->set(TableSelection::of([2]));
        $app->frame(48.0, $tight);
        check(
            '通道形态 · 语义相同的选中集不触发重建（Cell 门控按值）',
            Pipeline::current()->rebuiltCount() === $rebuilt,
            'rebuilt=' . (Pipeline::current()->rebuiltCount() - $rebuilt),
        );

        $this->flushChecks();
    }

    public function testValueSelectionRebuildsWholePage(): void
    {
        [$app, $page] = $this->mount(false);
        $before = $page->builds;
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));

        $page->selection->set(TableSelection::of([3]));
        $app->frame(32.0, $tight);

        check('值形态 · 选中变了整页重建（这就是通道形态要省的）', $page->builds === $before + 1, 'builds=' . $page->builds);

        $keys = $page->table?->selectionSet()?->keys() ?? [];
        check('值形态 · 表格也读到了新值', $keys === [3], \json_encode($keys));

        $this->flushChecks();
    }
}
