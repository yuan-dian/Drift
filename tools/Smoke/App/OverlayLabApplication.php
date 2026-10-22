<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\OverlayEntry;
use Drift\Commands\CommandArg;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Modal;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Dev\Smoke\Overlay\FlatBox;

/**
 * @internal 冒烟夹具：浮层栈的实验台（arch-v2 `01` §12.1 的八条夹具）。
 *
 * 根树几何确定：`Column` = [锚点按钮 · 背景按钮]。两个按钮都可聚焦可点 ⇒
 * 既能验"锚点钉在控件旁"，也能验"点浮层外会不会穿透到下面的按钮"。
 *
 * ★ **不给 `Application` 加新的公共 push API**：夹具走 `showMenu()` 再改
 *   栈顶条目的字段 —— `OverlayEntry` 的字段本来就是公开可配的（那是它存在的
 *   意义），夹具要造的形态（`barrier=false` 的 Tooltip、`ABOVE` 的翻转）目前
 *   还没有对应的控件（W8 才做 Tooltip），现在给框架加 API 就是给未来的控件
 *   猜形状。
 */
final class OverlayLabApplication extends Application
{
    /** @var list<string> 收到过的命令名 */
    public array $seen = [];

    /** @var list<Modal> 依次打开过的模态（夹具要读它们的 `dismissible`） */
    public array $modals = [];

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Button::make('锚点')->command('lab.anchor'),
            Button::make('背景')->command('bg.hit'),
            // 底部按钮：离顶部的浮层足够远 ⇒ "点浮层外会不会穿透"用它当靶子
            // （离得近的靶子会被面板盒盖住，测出来的是"点到了面板"）
            FlatBox::make(320.0, 120.0),
            Button::make('底部')->command('lab.bottom'),
        ]);
    }

    public function commands(): iterable
    {
        $handler = new RecordingHandler(function (string $name, CommandArg $arg): void {
            $this->seen[] = $name;
        });

        return [
            'lab.anchor' => $handler,
            'bg.hit' => $handler,
            'lab.bottom' => $handler,
            'modal.inside' => $handler,
        ];
    }

    /**
     * 开一层浮层并返回它（夹具直接改字段造形态）。
     *
     * @param int $prefer `OverlayEntry::BELOW` / `ABOVE` / `RIGHT` / `LEFT` / `CENTER`
     */
    public function openPanel(
        Widget $panel,
        ?\Drift\Widget\Element $anchor = null,
        int $prefer = OverlayEntry::BELOW,
        bool $barrier = true,
    ): ?OverlayEntry {
        $this->showMenu($panel, $anchor);

        $e = $this->overlays()->top();
        if ($e === null) {
            return null;
        }

        $e->prefer = $prefer;
        $e->barrier = $barrier;

        return $e;
    }

    /**
     * 开一个内容尺寸可控的模态（面板大小 = 内容 + 内边距）。
     *
     * @param bool $withButton 内容里放一个可聚焦按钮（验焦点陷阱 / Tab 序要用；
     *                         `FlatBox` 不可聚焦 ⇒ 只放它的话焦点链是空的）
     */
    public function openModalBox(float $w, float $h, string $title = '对话框', bool $withButton = false): void
    {
        $content = $withButton
            ? Column::make()->spacing(4.0)->children([
                FlatBox::make($w, $h),
                Button::make('模态内按钮')->command('modal.inside'),
            ])
            : FlatBox::make($w, $h);

        $modal = Modal::make()
            ->title($title)
            ->child($content);

        $this->modals[] = $modal;
        $this->showModal($modal);
    }

    // ── 焦点探针（`$focus` 是 protected，外部读不到 ⇒ 夹具代劳）──────────

    public function focusForTest(?\Drift\Widget\Element $element): void
    {
        if ($element !== null) {
            $this->focus?->focus($element);
        }
    }

    public function focusedForTest(): ?\Drift\Widget\Element
    {
        return $this->focus?->focused();
    }

    /** @return list<\Drift\Widget\Element> */
    public function focusOrderForTest(): array
    {
        return $this->focus?->order() ?? [];
    }
}
