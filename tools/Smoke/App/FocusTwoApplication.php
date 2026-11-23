<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Button;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：两个可聚焦按钮 + 一个常转的 `Spinner`。
 *
 * 存在的理由（`pumpSemantics` 的 `|| $focusChanged` 分支）：要一条断言证明
 * **焦点变会绕过 coalesce 直接 full**，就必须有一个"焦点能动 + **动画中**"
 * 的场景 —— 不带动画时证伪不了（删掉 `|| $focusChanged` 后焦点变的帧会落到
 * ③ 的无条件落地推，仍推 1 次 `full`，分不出 ① 与 ③）。
 *
 * ★ 用 `Button` 而不是 `TextField`（2026-10-24 教训）：第一版 TextField 的 Tab
 *   根本没让焦点动，断言里又没验 ⇒ 假绿。`Button` 是 `RevealApplication` / ⑱
 *   已经验过会动焦点的形状。
 * ★ `Spinner` 是为了 `$animating` 为真（同 `SpinnerApplication` 的用法），
 *   它没有可 Tab 的焦点链，所以要和 Button 并排放。
 */
final class FocusTwoApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Row::make()->children([
            Button::make('甲'),
            Button::make('乙'),
            Spinner::make()->diameter(20.0),
        ]);
    }

    /** 夹具用：当前焦点按钮的标签（断言 Tab 真的把焦点移走了） */
    public function focusedLabel(): ?string
    {
        $widget = $this->focus()?->focused()?->widget();

        return $widget instanceof Button ? $widget->label() : null;
    }

    public function title(): string
    {
        return '双按钮+转圈';
    }
}
