<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 一条状态消息（原文的 `.status-bar.status-{kind}`）。
 *
 * ★ **没有消息时返回 0×0**（不是 `null`、不是空文本）：`build()` 必须返回 Widget，
 *   而空文本会在 `Column` 里占一行高度 —— 卡片高度就会随"有没有消息"跳。
 * ★ 配色由 `StatusTone` → `ColorToken` → 当前主题解析，应用里不再有状态色 hex。
 */
final class MessageLine extends StatelessWidget
{
    public function __construct(private readonly StatusMessage $message)
    {
        parent::__construct();
    }

    public static function make(StatusMessage $message): self
    {
        return new self($message);
    }

    public function build(BuildContext $ctx): Widget
    {
        if (!$this->message->isVisible()) {
            return SizedBox::make()->size(0.0, 0.0);
        }

        return Row::make()
            ->padding(EdgeInsets::symmetric(12.0, 8.0))
            ->background($ctx->color($this->message->tone()->surfaceToken()))
            ->radius(8.0)
            ->children([
                // ★ 字色交给正文角色：浅底上的深字比"同色系深字"对比度更高。
                Text::make($this->message->text)->size(13.0),
            ]);
    }
}
