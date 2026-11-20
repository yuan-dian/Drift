<?php
declare(strict_types=1);

namespace App\Showcase\Pages\States;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\ScrollView;
use Drift\Widget\Snackbar;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;
use App\Showcase\Pages\Base\ShowcaseState;

final class SnackbarState extends ShowcaseState
{
    /** 「撤销」被点了几次（证明动作真的接上了） */
    private int $undone = 0;

    /** @param \Closure(Snackbar): void $notifier */
    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('底部通知 Snackbar')->title(),
            Text::make('贴底居中一条，到点自动消失；连续触发排队，前一条走了才上位。')
                ->caption(),

            $this->sectionBar('① 普通通知 · 4s 自动消失'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('已保存')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn() => $this->notify('已保存到本地')),
                Button::make('长文案')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn() => $this->notify(
                        '这是一段刻意写长的通知文案，用来验证面板有宽度上限：'
                        . '没有上限的话它会一路横穿整个窗口。'
                    )),
            ]))->paddingAll(12.0),

            $this->sectionBar('② 带动作 · 点「撤销」先调处理器再收掉'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('删除一条')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn() => $this->notifyUndoable()),
                Text::make('已撤销 ' . $this->undone . ' 次')->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('③ 排队 · 一次推三条（FIFO）'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('推三条')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn() => $this->burst()),
            ]))->paddingAll(12.0),

            $this->sectionBar('④ 不自动消失 · duration(0)'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('弹一条错误')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn() => $this->caps->notify(
                        Snackbar::make('保存失败：磁盘只读')->duration(0)->action(
                            '知道了',
                            fn() => $this->bump()
                        )
                    )),
            ]))->paddingAll(12.0),
            Text::make('`duration(0)` = 永不自动消失，只能点动作或由应用显式 dismiss —— '
                . '错误提示要用户确认时用这个。')->caption(),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 进出动画：没有 —— 瞬时出现/消失。要做就得给浮层加一条"框架能驱动'
                . '面板"的通道（现在框架只给落点，面板是 Widget 自己画自己）。')->caption(),
            Text::make('② 深色反色底：没有 —— `Text` 没有颜色 API，加它要动 `Text` + '
                . '`RenderText` + 哨兵，且与「主题 token 与显式样式只合并一次」冲突。')->caption(),
            Text::make('③ 悬停暂停倒计时：没有 —— 4s 够读完两行；加它会让悬停监听变成'
                . '多职责（现在归 Tooltip）。')->caption(),
            Text::make('④ 贴顶 / 右侧角标形态：没有 —— 只有贴底居中，要别的形态得加'
                . ' `OverlayEntry` 方向常量。')->caption(),
        ]);
    }

    private function notify(string $message): void
    {
        $this->caps->notify(Snackbar::make($message));
    }

    private function notifyUndoable(): void
    {
        $this->caps->notify(
            Snackbar::make('已删除「草稿.docx」')->action('撤销', fn() => $this->bump())
        );
    }

    private function burst(): void
    {
        $this->caps->notify(Snackbar::make('第 1 条：立刻显示'));
        $this->caps->notify(Snackbar::make('第 2 条：排队等待'));
        $this->caps->notify(Snackbar::make('第 3 条：排在最后'));
    }

    private function bump(): void
    {
        $this->setState(function (): void {
            $this->undone++;
        });
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
