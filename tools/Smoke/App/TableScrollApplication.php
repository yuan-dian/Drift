<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\Widget;

/**
 * 表格**双轴滚动条**的真实路由夹具（P2 阶段 5，2026-10-27）。
 *
 * ## 为什么需要它
 *
 * 阶段 5 新增了两件只有真实路由才能验的事：
 *
 * 1. **横条能拖**：`ScrollInputRouter` 按 `scrollAxes()` 的顺序**反序**命中
 *    （后画的在上层）—— 直接调 `dragThumb()` 绕过命中，验不出"点在哪根条上"。
 * 2. **角落归竖条**：两根条在右下角交叠 6×6。顺序写反的表现是"拖竖条把表
 *    横向拽走"，画面上看不出来（两根条都在动）。
 *
 * ## 尺寸的用处
 *
 * 两列各 300 宽、视口 320 ⇒ 内容宽 600，**横向必溢出**（maxScrollX = 280）。
 * 100 行 × 40 ⇒ 4000 高，**纵向必溢出**（maxScroll = 4000 − 208 = 3792）。
 * 两根条同时存在，才谈得上"角落归属"。
 *
 * @internal 测试夹具
 */
final class TableScrollApplication extends Application
{
    /**
     * @param int $rows 行数。默认 100 ⇒ 纵向必溢出（两根条同时在，才谈角落归属）；
     *   传 3 ⇒ 纵向装得下、横向仍溢出 —— "双轴容器的竖轮**不回落**"那条要靠它
     *   （回落只发生在**单轴**容器上）。
     */
    public function __construct(private readonly int $rows = 100)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        $rows = [];
        for ($i = 1; $i <= $this->rows; $i++) {
            $rows[] = ['id' => $i, 'name' => '用户 ' . $i];
        }

        return Table::make(
            [
                TableColumn::make('id', 'ID')
                    ->field('id')
                    ->width(TableColumnWidth::fixed(300.0)),
                TableColumn::make('name', '姓名')
                    ->field('name')
                    ->width(TableColumnWidth::fixed(300.0)),
            ],
            $rows,
        )->keyOf(static fn(array $row): int => (int) $row['id'])
            ->rowHeight(40.0)
            ->headerHeight(32.0);
    }
}
