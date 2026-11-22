<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\WatermarkStore;
use Drift\Widget\Bind;
use Drift\Widget\Widget;

/**
 * 底部状态栏：只跟 `WatermarkStore::statusLine()` 这一格。
 *
 * ★ 依赖是 `Bind` 在 builder 里读出来的（`statusLine()` 内部读那个 `Cell`），
 *   不用 `select` 声明切片 —— "漏写依赖 ⇒ 永不更新"这个失效模式因此不存在。
 * ★ 相等门控在格子上（`StatusMessage` 实现 `Equatable`）：同内容的新实例不通知，
 *   所以"每次都 new 一个消息"不会变成"每次都重建状态栏"。
 * ★ builder 只交数据；颜色在 `StatusBarRow::build(ctx)` 里按当前主题解析。
 */
final class StatusBar
{
    public static function make(WatermarkStore $store): Widget
    {
        return Bind::make(static fn (): Widget => new StatusBarRow($store->statusLine()));
    }
}
