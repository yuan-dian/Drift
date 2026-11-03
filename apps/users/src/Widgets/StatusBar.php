<?php
declare(strict_types=1);

namespace App\Users\Widgets;

use App\Users\Store\AppStore;
use Drift\State\Subject;
use Drift\State\SubjectBuilder;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 状态栏（§A.2.11）：只跟 `AppStore::statusLine()` 这一片。
 *
 * ★ 2026-11-03：原来是 104 行的 `StatefulWidget implements SubscribingWidget` +
 *   私有 `State`（首帧手取 → `watch()` → 自己门控 → `setState()`）。那四件事
 *   `SubjectBuilder` 全都在做 ⇒ 收成一个静态工厂。切片是 `string`，默认的
 *   `===` 门控就够（只有值对象才需要 `equals()`）。
 * ★ 订阅责任现在落在 `SubjectBuilder`（它 `implements SubscribingWidget`）⇒
 *   lint 的 `subscriber-mark` 判据照旧成立。
 */
final class StatusBar
{
    /** 状态栏（订阅 `statusLine()` 一片） */
    public static function make(AppStore $store): Widget
    {
        return SubjectBuilder::make($store)
            ->select(static fn (Subject $s): string => $s instanceof AppStore ? $s->statusLine() : '')
            ->builder(static fn (string $text): Widget => Row::make()->children([
                Text::make($text)->caption(),
                Spacer::make(),
            ]));
    }
}
