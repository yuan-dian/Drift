<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Layout\FlexAlign;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 卡片外壳：白底 + 圆角 + 序号徽章 + 标题 + 内容槽（原文的 `.card` + `.step-badge`）。
 *
 * ★ 三张卡片共用一份：外壳的圆角 / 内边距 / 徽章尺寸散在三个类里，改一次要改三处
 *   —— 而这三处一定会漂（本仓的"手写六遍的换算"那条账就是这么来的）。
 * ★ 内容槽是**注入的 Widget**（`$body`）：外壳不 `build` 内容，所以内容里的
 *   `Bind` 订阅点仍然在各自的控件里（订阅不该被外壳吃掉）。
 */
final class CardShell extends StatelessWidget
{
    /** 徽章边长（原文 `width:34px; height:34px`） */
    private const BADGE = 34.0;

    public function __construct(
        private readonly string $step,
        private readonly string $title,
        private readonly Widget $body,
    ) {
        parent::__construct();
    }

    public static function make(string $step, string $title, Widget $body): self
    {
        return new self($step, $title, $body);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()
            ->spacing(16)
            // ★ `cross(Stretch)`：卡片内容列**拉满卡片宽**。不给的话，内容列按"最宽
            //   那个孩子"收缩（上传区只有一行文字的宽度、输入框只有 placeholder 那么
            //   长），卡片右边留一大片空白 —— 看起来像没做完。
            //   ★ 它只解决"孩子填满父"，父自己的宽仍由 `Card` 的紧约束给（`Card`
            //     是 `RenderProxy`：约束原样下传）。
            ->cross(FlexAlign::Stretch)
            ->padding(EdgeInsets::all(24.0))
            ->background(Palette::card())
            ->radius(16.0)
            ->children([
                Row::make()->spacing(10)->children([
                    SizedBox::make(
                        Center::make(Text::make($this->step)->bold()->size(14.0))
                    )
                        ->size(self::BADGE, self::BADGE)
                        ->background(Palette::badge())
                        // ★ 圆角取边长的一半 = 正圆（原文是 `border-radius:50%`）
                        ->radius(self::BADGE / 2.0),

                    Text::make($this->title)->title(),
                ]),

                $this->body,
            ]);
    }
}
