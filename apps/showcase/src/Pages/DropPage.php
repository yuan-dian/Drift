<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Input\FilesDroppedEvent;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 31「拖文件进窗 Drop」—— A5-② 的唯一消费者（落地记录 §27）。
 *
 * ★★ **为什么这一页必须存在**：`WM_DROPFILES` 通道是新开的，没有调用方就没人能
 *   回答"拖进来之后 UI 说什么""命中怎么算""没接收者会怎样"。这一页是它唯一的
 *   落点，也是 T2 真机取证的落点（§16：未被消费的分支 = 未实现）。
 *
 * ★ **声明挂在根 Widget 上**：`Card` 上 `->onFilesDropped(...)`，命中点沿父链
 *   冒泡总能找到它 ⇒ 拖到本页任意位置都算，不需要一个大号"拖放区"来接。
 *   视觉上仍给一块提示区，暗示"这里是目标"。
 *
 * ★ **状态存字符串而不是数组**（`$lastPaths` 是 `'\n'` 分隔原始串）：数组字段跨
 *   实例读在 AOT 上是 C2679/`php::Var` 高发区（§24）；显示时才 explode。
 *
 * ★ 构造无必填参数 ⇒ 能进 golden 整页基线（不像 `DialogPage` 要递端口）。
 */
final class DropPage extends StatefulWidget
{
    public function createState(): State
    {
        return new DropPageState();
    }
}

final class DropPageState extends State
{
    /** 最近一次拖进来的路径，`'\n'` 分隔原始串（空串 = 还没拖过） */
    private string $lastPaths = '';

    /** 最近一次的文件个数 */
    private int $lastCount = 0;

    /** 累计拖放次数（证明"每次都调了回调"，而不只是显示了最后一条） */
    private int $dropCount = 0;

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))
            ->paddingAll(0.0)
            ->onFilesDropped(function (FilesDroppedEvent $event): void {
                $this->accept($event);
            });
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('拖文件进窗 Drop')->title(),
            Text::make('A5-②：把一个或多个文件从资源管理器拖进这个窗口。'
                . '落点命中 → 沿父链冒泡找到最近的 onFilesDropped 声明者 → 回调。')->caption(),

            Card::make(Column::make()->spacing(8.0)->children([
                Text::make('拖放区域')->heading(),
                Text::make($this->statusText()),
            ]))->paddingAll(16.0),

            Card::make(Column::make()->spacing(6.0)->children($this->pathLines()))->paddingAll(12.0),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① **整页接收**：onFilesDropped 声明在本页根 Widget 上，'
                . '命中点沿父链冒泡总能找到它 —— 拖到页面任意位置都算。')->caption(),
            Text::make('② **只投最近的一个**：一次拖放只给落点那条链上最近的声明者，'
                . '不广播。拖到不接收的控件上是合法的，静默丢弃。')->caption(),
            Text::make('③ **WM_DROPFILES**：只解文件路径 + 落点，没有 OLE 悬停反馈；'
                . '多选与选目录没有消费者就不发明。')->caption(),
            Text::make('④ **载荷是 \'\\n\' 分隔的字符串**：数组字段跨实例读在 AOT 上是'
                . ' C2679 高发区，所以事件对象存原始串，paths() 里 explode 还原。')->caption(),
        ]);
    }

    /** 拖放回调：把"这次拖了什么"落到 State。 */
    private function accept(FilesDroppedEvent $event): void
    {
        // ★ 先 `(string)` 落局部再参与运算/传参：`$event->pathsText` 跨实例读到的是
        //   标量 / Var（§24 纪律③）。不调 `$event->fileCount()` 也是同一考虑 ——
        //   少一次跨实例方法调用，count 在这里用同一套逻辑自己算。
        $text = (string) $event->pathsText;
        $count = $text === '' ? 0 : substr_count($text, "\n") + 1;

        $this->setState(function () use ($text, $count): void {
            $this->lastPaths = $text;
            $this->lastCount = $count;
            $this->dropCount++;
        });
    }

    private function statusText(): string
    {
        if ($this->dropCount === 0) {
            return '还没拖过文件。从资源管理器拖一个或多个文件进来试试。';
        }

        return '第 ' . $this->dropCount . ' 次拖放 · 本次 ' . $this->lastCount . ' 个文件';
    }

    /** @return list<Widget> 最近一次的路径（最多列 6 条），空时也给一行占位 */
    private function pathLines(): array
    {
        if ($this->lastPaths === '') {
            return [Text::make('（还没有文件）')->caption()];
        }

        $parts = explode("\n", $this->lastPaths);
        $total = count($parts);
        $shown = $total < 6 ? $total : 6;

        $out = [];
        for ($i = 0; $i < $shown; $i++) {
            $out[] = Text::make('- ' . $parts[$i])->body();
        }
        if ($total > $shown) {
            $out[] = Text::make('... 还有 ' . ($total - $shown) . ' 个文件')->caption();
        }

        return $out;
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
