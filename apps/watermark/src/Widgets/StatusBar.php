<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\State\Subject;
use Drift\State\SubjectBuilder;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 底部状态栏：只跟 `WatermarkStore::statusLine()` 这一片。
 *
 * ## 2026-11-03：从 190 行手写订阅器收成 30 行
 *
 * 原来这里是 `StatefulWidget implements SubscribingWidget` + 一个私有 `State`：
 * `initState()` 手动取首帧值 → `watch()` → 自己写 `slice()` 收窄 → 自己写
 * `equals()` 门控 → `setState()`。**这四件事框架的 `SubjectBuilder` 一件不少地
 * 在做**，框架外再做一遍 = 两套订阅设施，谁改都得改两处。
 *
 * 之所以当初没直接用：`SubjectBuilder` 的默认门控是 `===`，而 `statusLine()`
 * 每次 `new` 一个 `StatusMessage` ⇒ 内容没变也判"变了" ⇒ 每次通知都重建。
 * 2026-11-03 给 `SubjectBuilder` 补了 `equals()`（门控策略归调用方，与
 * Flutter/Riverpod/Compose 的做法一致）⇒ 手写的那份没有存在理由了。
 *
 * ★ `slice()`（`mixed` → `StatusMessage` 的唯一收窄点）保留了：`select` 的
 *   静态返回类型是 `mixed`，类型不对时退回空消息而不是抛 —— 状态栏不该因为
 *   一个写错的切片函数把整帧打掉。
 * ★ 仍然是**专职订阅者**，只是订阅责任现在落在 `SubjectBuilder` 身上（它
 *   `implements SubscribingWidget`）⇒ lint 的 `subscriber-mark` 判据照旧成立。
 */
final class StatusBar
{
    /** 状态栏（订阅 `statusLine()` 一片） */
    public static function make(WatermarkStore $store): Widget
    {
        return SubjectBuilder::make($store)
            ->select(static fn (Subject $s): StatusMessage => self::slice($s))
            ->equals(static fn (StatusMessage $a, StatusMessage $b): bool => $a->equals($b))
            ->builder(static fn (StatusMessage $m): Widget => Row::make()
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
                ]));
    }

    /**
     * 从源上切一片（`mixed` → `StatusMessage`）。
     *
     * ★ 类型不对时退回空消息而不是抛：状态栏不该因为一个写错的切片函数把整帧
     *   打掉（与 `Subject` 的"订阅者异常隔离"同一个态度）。
     */
    private static function slice(Subject $source): StatusMessage
    {
        if (!$source instanceof WatermarkStore) {
            return StatusMessage::none();
        }

        return $source->statusLine();
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
