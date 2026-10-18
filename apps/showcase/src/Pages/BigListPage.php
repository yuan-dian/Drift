<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\LazyColumn;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 7「长列表（虚拟化）」—— `LazyColumn` 的**真实消费者**（M5，2026-10-18）。
 *
 * ★ 它存在的理由不是"多一个示例页"，而是让虚拟化有一条**在生产形态下**被走通的
 *   路径：10000 行、每行定高 44，只造视口覆盖的十几行。换成 `Each` 全量列表，
 *   同一份数据实测单帧约 **0.8 秒**（`_build/bench-list3.php` 的 0.076ms/行）。
 *
 * ★ 滚动位置看得见：每行都印自己的行号 ⇒ 滚到底能核对"第 10000 行"真的在。
 */
final class BigListPage extends StatelessWidget
{
    private const ROWS = 10000;

    /** 行高（逻辑像素）—— 虚拟化的窗口计算全靠它，必须与行内容实际高度一致 */
    private const ROW_EXTENT = 44.0;

    public function build(BuildContext $ctx): Widget
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = $i;
        }

        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('长列表（虚拟化）')->title(),
                Text::make(
                    '10000 行 × 定高 44 —— 只造视口覆盖的十几行（LazyColumn，§13.4）。'
                    . '同样数据换成 Each 全量列表，单帧约 0.8 秒。'
                )->caption(),

                // ★ 必须放进有界高度里：LazyColumn 自己就是滚动视口
                Expanded::make(
                    LazyColumn::make(self::ROW_EXTENT)
                        ->items($rows, static fn(int $i): Widget => BigListPage::row($i))
                ),
            ])
        )->paddingAll(0.0);
    }

    private static function row(int $index): Widget
    {
        return Padding::make(
            Row::make()->spacing(8.0)->children([
                Text::make(sprintf('第 %d 行', $index))->body(),
                Spacer::make(),
                Text::make($index % 2 === 0 ? '偶数' : '奇数')->caption(),
            ])
        )->symmetric(12.0, 8.0);
    }
}
