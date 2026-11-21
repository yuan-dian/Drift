<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;


use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\LazyColumn;
use Drift\Widget\LazyRow;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 长列表（虚拟化）—— `LazyColumn` / `LazyRow` 的**真实消费者**（M5）。
 *
 * ★ 它存在的理由不是"多一个示例页"，而是让虚拟化有一条**在生产形态下**被走通的
 *   路径：10000 行、每行定高 44，只造视口覆盖的十几行。换成 `Each` 全量列表，
 *   同一份数据实测单帧约 **0.8 秒**（`_build/bench-list3.php` 的 0.076ms/行）。
 *
 * ★ 滚动位置看得见：每行都印自己的行号 ⇒ 滚到底能核对"第 10000 行"真的在。
 *
 * ★ 补横向条（`LazyRow`）：同一页里放两个方向的虚拟列表，是为了让
 *   "轴只是一个参数"这条**在真机上被看见** —— 纵向条滚轮滚上下、横向条滚轮滚
 *   左右，两者共用一份 `LazyListState` 逻辑。
 */
#[Route(id: 'big-list', title: '长列表 Big', icon: IconName::ListBullets)]
final class BigListPage extends ShowcasePage
{
    private const ROWS = 10000;

    /** 行高（逻辑像素）—— 虚拟化的窗口计算全靠它，必须与行内容实际高度一致 */
    private const ROW_EXTENT = 44.0;

    /** 横向条：卡片宽（主轴尺寸）与条高（交叉轴，必须有界） */
    private const CARD_EXTENT = 168.0;
    private const STRIP_HEIGHT = 88.0;
    private const CARDS = 200;

    public function build(BuildContext $ctx): Widget
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = $i;
        }

        $cards = [];
        for ($i = 1; $i <= self::CARDS; $i++) {
            $cards[] = $i;
        }

        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('长列表（虚拟化）')->title(),
                Text::make(
                    '10000 行 × 定高 44 —— 只造视口覆盖的十几行（LazyColumn）。'
                    . '同样数据换成 Each 全量列表，单帧约 0.8 秒。'
                )->caption(),

                // ★ 横向条：`LazyRow` 的消费者（200 张卡，只造视口覆盖的几张）。
                //   与 LazyColumn 同一条纪律 —— 它自己就是滚动视口，交叉轴必须有界
                //   （这里是 SizedBox 定高；放进无界高度会响亮失败）。
                //   滚轮在它上面滚的是**横向**（路由只递标量 Δ，轴在 State 里）。
                SizedBox::make(
                    LazyRow::make(self::CARD_EXTENT)
                        ->items($cards, static fn(int $i): Widget => BigListPage::card($i))
                )->height(self::STRIP_HEIGHT),

                // ★ 必须放进有界高度里：LazyColumn 自己就是滚动视口
                Expanded::make(
                    LazyColumn::make(self::ROW_EXTENT)
                        ->items($rows, static fn(int $i): Widget => BigListPage::row($i))
                ),
            ])
        )->paddingAll(0.0);
    }

    /** 横向条的一张卡（宽度由 `LazyRow` 的 `SizedBox` 定死，这里只管内容） */
    private static function card(int $index): Widget
    {
        return Card::make(
            Padding::make(
                Column::make()->spacing(4.0)->children([
                    Text::make(sprintf('卡片 %d', $index))->body(),
                    Text::make('横向滚动')->caption(),
                ])
            )->all(10.0)
        );
    }

    private static function row(int $index): Widget
    {
        return Padding::make(
            Row::make()->spacing(8.0)->children([
                Expanded::make(Text::make(sprintf('第 %d 行', $index))->body()),
                Text::make($index % 2 === 0 ? '偶数' : '奇数')->caption(),
            ])
        )->symmetric(12.0, 8.0);
    }
}
