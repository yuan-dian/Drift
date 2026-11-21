<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\Bind;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 底部状态栏：只跟 `WatermarkStore::statusLine()` 这一格。
 *
 * ★ 依赖是 `Bind` 在 builder 里读出来的（`statusLine()` 内部读那个 `Cell`），
 *   不用 `select` 声明切片 —— "漏写依赖 ⇒ 永不更新"这个失效模式因此不存在。
 * ★ 相等门控在格子上（`StatusMessage` 实现 `Equatable`）：同内容的新实例不通知，
 *   所以"每次都 new 一个消息"不会变成"每次都重建状态栏"。
 *
 * 迁移前这里是 `StatefulWidget implements SubscribingWidget` + 私有 `State`：手动
 * 取首帧值 → `watch()` → 自己收窄类型 → 自己判等 → `setState()`。这四件事现在
 * 由 `Bind` 与 `Cell` 各管一段，本类只剩排版。
 */
final class StatusBar
{
    /** 状态栏（订阅 `statusLine()` 那一格） */
    public static function make(WatermarkStore $store): Widget
    {
        return Bind::make(static fn (): Widget => self::bar($store->statusLine()));
    }

    private static function bar(StatusMessage $m): Widget
    {
        return Row::make()
            ->spacing(8)
            ->padding(EdgeInsets::symmetric(16.0, 9.0))
            ->background(Palette::listRow())
            ->children([
                // ★ 状态点：`SizedBox` 自带 `background()` + `radius()`（`ImageThumb`
                //   里同一个用法）—— 不必为一个 8px 的圆点引入新控件。
                SizedBox::make()
                    ->size(8.0, 8.0)
                    ->background(self::dotColor($m->kind))
                    ->radius(4.0),

                Text::make($m->text)->caption(),
                Spacer::make(),
            ]);
    }

    /**
     * `kind` → 状态点颜色。
     *
     * ★ 未知 kind 走"提示"那一档（不画透明、不崩）：多一种 kind 而忘了配色的症状
     *   应该是"颜色不对"，不是"点没了"。
     */
    private static function dotColor(string $kind): Color
    {
        if ($kind === StatusMessage::SUCCESS) {
            return Palette::successText();
        }
        if ($kind === StatusMessage::ERROR) {
            return Palette::errorText();
        }
        if ($kind === StatusMessage::LOADING) {
            return Palette::loadingText();
        }

        return Palette::hint();
    }
}
