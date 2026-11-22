<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use Drift\Geometry\EdgeInsets;
use Drift\Rendering\ColorToken;
use Drift\Rendering\StatusTone;
use Drift\Widget\BuildContext;
use Drift\Widget\Icon;
use Drift\Widget\IconName;
use Drift\Widget\Row;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** 已上传对象键的一行：成功色从当前主题取，不把 hex 留在 Bind 闭包里。 */
final class UploadedRow extends StatelessWidget
{
    public function __construct(private readonly string $objectKey)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Row::make()
            ->spacing(10)
            ->padding(EdgeInsets::symmetric(14.0, 10.0))
            ->background($ctx->color(ColorToken::SuccessSurface))
            ->radius(8.0)
            ->children([
                Icon::make(IconName::Check, 14.0)
                    ->color($ctx->color(StatusTone::Success->foregroundToken())),
                Text::make($this->objectKey)->caption(),
            ]);
    }
}
