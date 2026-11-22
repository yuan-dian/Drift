<?php
declare(strict_types=1);

namespace App\Users\Widgets;

use App\Users\Store\AppStore;
use Drift\Widget\Bind;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 状态栏（§A.2.11）：只跟 `AppStore::statusLine()` 那一格。
 *
 * ★ 历史（值得留着）：2026-11-03 之前这里是 104 行的 `StatefulWidget implements
 *   SubscribingWidget` + 私有 `State`（首帧手取 → `watch()` → 自己门控 →
 *   `setState()`）；那之后收成 `SubjectBuilder` 的两段闭包；现在收成一句 `Bind`
 *   —— **切片不用写了**，builder 里读哪个格子就订哪个格子。
 * ★ 状态栏是底部条，重建成本极低，不值得再拆；`Bind` 在这里的作用只是"页名 /
 *   主题那一堆格子的变化不惊动它"。
 */
final class StatusBar
{
    /** 状态栏（读 `statusLine` 一格） */
    public static function make(AppStore $store): Widget
    {
        return Bind::make(static fn(): Widget => Row::make()->children([
            Text::make($store->statusLine())->caption(),
            Spacer::make(),
        ]));
    }
}
