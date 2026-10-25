<?php
declare(strict_types=1);

namespace App\Tutorial;

use App\Tutorial\Stages\AsyncStage;
use App\Tutorial\Stages\CounterStage;
use App\Tutorial\Stages\HelloStage;
use App\Tutorial\Stages\OverlayStage;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Divider;
use Drift\Widget\ScrollView;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 根 Widget：四段教程排成一列。
 *
 * ★ `buildRoot()` 返回什么，窗口里就画什么 —— 本类是 `StatelessWidget`
 *   （自己不留状态），四段里需要状态的两段各自是 `StatefulWidget`。
 *   **状态放在"需要它的最小范围"**：整页重建不该带着计数器一起重建。
 *
 * ★ 页面 = **一整块白色面板 + 面板内滚动**（`Card` 套 `ScrollView`）：
 *   卡片撑满视口高、内容在卡片里滚。反过来（卡片只包内容）会让卡片只占
 *   上半屏，下半屏露出灰底 —— 看着像"没加载完"。
 */
final class TutorialPage extends StatelessWidget
{
    /**
     * @param \Closure(int, \Closure): void $after 组合根递下来的"延时回调"能力
     *        （形参 = 毫秒 + 回调；见 `TutorialApplication::buildRoot()`）
     */
    public function __construct(private readonly \Closure $after)
    {
        parent::__construct();
    }

    public static function make(\Closure $after): self
    {
        return new self($after);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            ScrollView::make(
                Column::make()->spacing(24.0)->padding(EdgeInsets::all(24.0))->children([
                    Text::make('从零写一个 Drift 应用')->title(),
                    Text::make('四段依次是：最小应用 / 计数器 / 浮层 / 异步。'
                        . '每一段都能单独跑，代码在 apps/tutorial/src/Stages/。')->caption(),

                    HelloStage::make(),
                    Divider::make(),

                    CounterStage::make(),
                    Divider::make(),

                    OverlayStage::make(),
                    Divider::make(),

                    // ★ 只有这一段需要外部能力 ⇒ 只有它收参数
                    AsyncStage::make($this->after),
                ])
            )
        )->paddingAll(0.0);
    }
}
