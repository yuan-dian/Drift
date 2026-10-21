<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\AnimationController;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Curve;
use Drift\Widget\Divider;
use Drift\Widget\Expanded;
use Drift\Widget\Progress;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 9「动画 Motion」—— `AnimationController` + `Curve` 的第一个消费者。
 *
 * ★ **这一页存在的理由**（§14.3 / §24.2 的判据）：框架能力**没有消费者 = 未实现**。
 *   `AnimationController::tick()` 若只被 smoke 夹具调到，它仍然是"一段没人用的
 *   算术"；进了示例台才有"每帧真的变了"这件事。
 *
 * ★ **演示的正是曲线的差别**：两个控制器**同时长**，一个 `easeOut`、一个 `linear`，
 *   同一个按钮一起 `forward()` ⇒ 两条进度条分开跑，一眼看出"快起慢收"是什么。
 */
final class MotionPage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new MotionState();
    }
}

final class MotionState extends State
{
    /** 单程时长（毫秒）—— 与 §17.4.9 的 15ms 帧间隔配得起（≈40 帧） */
    private const DURATION_MS = 600.0;

    private AnimationController $eased;

    private AnimationController $linear;

    public function initState(): void
    {
        $this->eased = AnimationController::make(self::DURATION_MS)->curve(Curve::easeOut());
        $this->linear = AnimationController::make(self::DURATION_MS);
    }

    /**
     * 每帧推进（§17.4.10 的入口）。
     *
     * ★ **不许短路**：两个控制器都得收到这一帧，否则"第二个永远卡在起点"
     *   （与 `Application::tickSubtree()` 不短路同一个理由）。
     * ★ 返回值直接喂 `wantsFrame()` ⇒ 两个都跑完就没人返回 true ⇒ 帧时钟停摆。
     */
    public function tickFrame(float $timestampMs): bool
    {
        $busy = false;

        if ($this->eased->tick($timestampMs)) {
            $busy = true;
        }

        if ($this->linear->tick($timestampMs)) {
            $busy = true;
        }

        if ($busy) {
            $this->markDirty();
        }

        return $busy;
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        $easedValue = $this->eased->value();
        $linearValue = $this->linear->value();

        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('动画 Motion')->title(),
            Text::make('控制器不持时钟、不读环境时钟：推进靠 State::tickFrame() 注入的帧时间戳 ⇒ 同一棵树在解释器与 AOT 真机里推进完全一致。跑完自动停，帧时钟停摆、空闲 CPU 归零。')
                ->caption(),

            // ── 操作条 ──
            Row::make()->spacing(8.0)->children([
                Button::make('播放')
                    ->variant(ButtonVariant::Primary)
                    ->onPressed(function (): void {
                        $this->eased->forward();
                        $this->linear->forward();
                        $this->markDirty();
                    }),
                Button::make('倒放')
                    ->onPressed(function (): void {
                        $this->eased->reverse();
                        $this->linear->reverse();
                        $this->markDirty();
                    }),
                Button::make('跳到 0.5')
                    ->onPressed(function (): void {
                        $this->eased->jumpTo(0.5);
                        $this->linear->jumpTo(0.5);
                        $this->markDirty();
                    }),
                Button::make('停')
                    ->onPressed(function (): void {
                        $this->eased->stop();
                        $this->linear->stop();
                        $this->markDirty();
                    }),
            ]),

            // ── 两条同时长、不同曲线的进度条 ──
            Text::make('同时长（600ms）、不同曲线')->heading(),
            Row::make()->spacing(8.0)->children([
                Text::make('easeOut'),
                Expanded::make(Progress::make($easedValue)),
                Text::make($this->percent($easedValue)),
            ]),
            Row::make()->spacing(8.0)->children([
                Text::make('linear '),
                Expanded::make(Progress::make($linearValue)),
                Text::make($this->percent($linearValue)),
            ]),
            Text::make('缓动值喂的是控件的**数值入口**（这里是 Progress::value）—— 不需要 Opacity / Transform 这类"整棵子树一起动"的包装（那要先有层合成语义，EVAL §7 第 4 项）。')
                ->caption(),

            Divider::make(),

            // ── 动画值进布局：宽度跟着跑 ──
            Text::make('值进布局：盒子宽度跟着动画')->heading(),
            // ★ 只定 `width`：**不定 `height`** —— `SizedBox` 的 height 一旦小于
            //   子内容（本例是 Card 里的字），`RenderText` 就会报「画出的尺寸超出父
            //   容器给的可用空间」并 Fatal 掉整帧（真机现象：点进本页，界面一动
            //   不动，也没报错）。高度让给内容。
            SizedBox::make(
                Card::make(Text::make('滑动块'))
            )->width(80.0 + 160.0 * $easedValue),
            Text::make('宽度 = 80 + 160 × 缓动值 ⇒ 每帧一次重排重画，肉眼可见"盒子变宽"。')
                ->caption(),

            Divider::make(),

            // ── 读数 ──
            Text::make('读数')->heading(),
            Text::make(sprintf(
                '线性 t = %.3f ｜ 缓动值 = %.3f ｜ 在跑：%s',
                $this->eased->progress(),
                $easedValue,
                $this->eased->isAnimating() ? '是' : '否',
            ))->body(),
            Text::make('「倒放」按剩余距离缩时长（速度恒定）：从 0.6 回 0 只跑 0.6×600ms —— 回退是取消，不是重播。')
                ->caption(),
        ]);
    }

    private function percent(float $value): string
    {
        return sprintf('%d%%', (int) round($value * 100.0));
    }
}
