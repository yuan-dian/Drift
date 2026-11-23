<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\WatermarkStore;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 底部状态栏：只跟 `WatermarkStore::statusLine()` 这一格。
 *
 * ★ 依赖是 `Bind` 在 builder 里读出来的（`statusLine()` 内部读那个 `Cell`），
 *   不用声明切片 —— "漏写依赖 ⇒ 永不更新"这个失效模式因此不存在。
 * ★ 相等门控在格子上（`StatusMessage` 实现 `Equatable`）：同内容的新实例不通知，
 *   所以"每次都 new 一个消息"不会变成"每次都重建状态栏"。
 * ★ 颜色在 builder 里按当前主题解析（builder 收 `BuildContext`）—— 语义点与文字
 *   都从 `StatusMessage` 来，这里只排版。
 */
final class StatusBar
{
    public static function make(WatermarkStore $store): Widget
    {
        return Bind::make(static function (BuildContext $ctx) use ($store): Widget {
            $message = $store->statusLine();

            return Row::make()
                ->spacing(8)
                ->padding(EdgeInsets::symmetric(16.0, 9.0))
                ->background(Palette::listRow())
                ->children([
                    SizedBox::make()
                        ->size(8.0, 8.0)
                        ->background($ctx->color($message->tone()->foregroundToken()))
                        ->radius(4.0),
                    Text::make($message->text)->caption(),
                    Spacer::make(),
                ]);
        });
    }
}
