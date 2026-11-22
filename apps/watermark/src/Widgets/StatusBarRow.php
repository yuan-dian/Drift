<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** 状态栏的一帧：颜色在 `build(ctx)` 里解析，不用让 `Bind` 闭包抓住旧 context。 */
final class StatusBarRow extends StatelessWidget
{
    public function __construct(private readonly StatusMessage $message)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Row::make()
            ->spacing(8)
            ->padding(EdgeInsets::symmetric(16.0, 9.0))
            ->background(Palette::listRow())
            ->children([
                SizedBox::make()
                    ->size(8.0, 8.0)
                    ->background($ctx->color($this->message->tone()->foregroundToken()))
                    ->radius(4.0),
                Text::make($this->message->text)->caption(),
                Spacer::make(),
            ]);
    }
}
