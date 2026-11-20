<?php
declare(strict_types=1);

namespace App\Showcase\Routes;

use App\Showcase\Routes\Snackbar\NotifyEffect;
use App\Showcase\Routes\Snackbar\SnackbarEvent;
use App\Showcase\Routes\Snackbar\SnackbarIntent;
use App\Showcase\Routes\Snackbar\SnackbarModel;
use App\Showcase\Routes\Snackbar\SnackbarServices;
use Drift\Geometry\EdgeInsets;
use Drift\Route\RouteEffect;
use Drift\Route\RouteEvent;
use Drift\Route\RouteModel;
use Drift\Route\RouteUpdate;
use Drift\Route\RouteView;
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

/**
 * 底部通知 Snackbar —— 四段式 `RouteView` 的样板实现。
 *
 * 导航身份归 {@see \App\Showcase\Pages\SnackbarPage} 的 `#[Route]` 注解；
 * 本类只管页面内部：状态（`SnackbarModel`）、状态怎么变（`update()`）、
 * 画面长什么样（`view()`）。副作用只有一个落点（`apply()`），不再散进按钮闭包。
 *
 * 四段演示（与迁移前逐像素一致）：
 *
 *   ① 普通通知：4s 后自动消失；
 *   ② 带动作：点「撤销」→ 先走回调，再收掉通知；
 *   ③ 排队：一次推三条 —— 第一条立即显示，后两条排队；
 *   ④ 不自动消失（`duration(0)`）：错误提示要用户自己确认。
 *
 * ★ 页面只拿到 `SnackbarServices`（一条 `notify`），不再拿 6 参能力束。
 * ★ `update()` 是纯函数：可以构造事件直接单测，不需要窗口、不需要能力束。
 * ★ 能力边界（如实记录，与迁移前相同）：没有进出动画、没有深色反色底、
 *   没有悬停暂停倒计时、没有贴顶/角标形态。
 *
 * @api
 */
final class SnackbarRoute implements RouteView
{
    public function __construct(
        private readonly SnackbarServices $services,
    ) {
    }

    public function init(): RouteModel
    {
        return new SnackbarModel();
    }

    public function view(BuildContext $ctx, RouteModel $model, \Closure $dispatch): Widget
    {
        \assert($model instanceof SnackbarModel);

        return Card::make(ScrollView::make($this->body($model, $dispatch)))->paddingAll(0.0);
    }

    public function update(RouteModel $model, RouteEvent $event): RouteUpdate
    {
        \assert($model instanceof SnackbarModel);
        \assert($event instanceof SnackbarEvent);

        return match ($event->intent) {
            SnackbarIntent::Saved => new RouteUpdate($model, [
                new NotifyEffect('已保存到本地'),
            ]),
            SnackbarIntent::LongText => new RouteUpdate($model, [
                new NotifyEffect(
                    '这是一段刻意写长的通知文案，用来验证面板有宽度上限：'
                    . '没有上限的话它会一路横穿整个窗口。'
                ),
            ]),
            SnackbarIntent::Undoable => new RouteUpdate($model, [
                new NotifyEffect('已删除「草稿.docx」', actionLabel: '撤销'),
            ]),
            SnackbarIntent::Burst => new RouteUpdate($model, [
                new NotifyEffect('第 1 条：立刻显示'),
                new NotifyEffect('第 2 条：排队等待'),
                new NotifyEffect('第 3 条：排在最后'),
            ]),
            SnackbarIntent::PersistentError => new RouteUpdate($model, [
                new NotifyEffect('保存失败：磁盘只读', durationMs: 0, actionLabel: '知道了'),
            ]),
            SnackbarIntent::UndoClicked => new RouteUpdate(
                new SnackbarModel($model->undone + 1),
            ),
        };
    }

    public function apply(RouteEffect $effect, \Closure $dispatch): void
    {
        if (!$effect instanceof NotifyEffect) {
            throw new \InvalidArgumentException(sprintf(
                'SnackbarRoute 不认识 effect "%s"：新 effect 必须在这里给出落点',
                \get_class($effect),
            ));
        }

        $snackbar = Snackbar::make($effect->message);

        if ($effect->durationMs !== null) {
            $snackbar = $snackbar->duration($effect->durationMs);
        }

        if ($effect->actionLabel !== '') {
            // ★ 动作回调必须回主路：就地改 model 会让状态出现第二条写路径。
            $snackbar = $snackbar->action(
                $effect->actionLabel,
                fn () => $dispatch(new SnackbarEvent(SnackbarIntent::UndoClicked)),
            );
        }

        $this->services->notify($snackbar);
    }

    /** @param \Closure(RouteEvent): void $dispatch */
    private function body(SnackbarModel $model, \Closure $dispatch): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('底部通知 Snackbar')->title(),
            Text::make('贴底居中一条，到点自动消失；连续触发排队，前一条走了才上位。')
                ->caption(),

            $this->sectionBar('① 普通通知 · 4s 自动消失'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('已保存')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn () => $dispatch(new SnackbarEvent(SnackbarIntent::Saved))),
                Button::make('长文案')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn () => $dispatch(new SnackbarEvent(SnackbarIntent::LongText))),
            ]))->paddingAll(12.0),

            $this->sectionBar('② 带动作 · 点「撤销」先调处理器再收掉'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('删除一条')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn () => $dispatch(new SnackbarEvent(SnackbarIntent::Undoable))),
                Text::make('已撤销 ' . $model->undone . ' 次')->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('③ 排队 · 一次推三条（FIFO）'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('推三条')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn () => $dispatch(new SnackbarEvent(SnackbarIntent::Burst))),
            ]))->paddingAll(12.0),

            $this->sectionBar('④ 不自动消失 · duration(0)'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('弹一条错误')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(fn () => $dispatch(new SnackbarEvent(SnackbarIntent::PersistentError))),
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

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
