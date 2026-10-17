<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\CursorShape;
use Drift\Widget\Column;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Padding;
use Drift\Widget\Spacer;
use Drift\Widget\Widget;

/**
 * 指针四相位夹具应用（2026-10-17 move/up/leave 通路，§9.1 语义 #5 / §A.3.5）。
 *
 * ★ 几何全用 `Padding::all(N) + Spacer` 造（**确定性，不赌字模**）：三块
 *   40×40 竖排 —— ① 悬停目标（带 `hover` + 光标声明）② 拖放源（`Draggable`）
 *   ③ 拖放目标（`DragTarget`）。视口 300×200 ⇒ 三块都在 y=0..120，指针坐标
 *   (20, 20) / (20, 60) / (20, 100) 分别命中。
 *
 * ★ 为什么命中成立：`RenderBox::hitTest` 子节点不中 ⇒ **返回容器自己**
 *   （"容器吞掉事件"）⇒ Padding（40×40）可被命中，哪怕 Spacer 子盒是 0×0。
 *
 * @internal 测试夹具
 */
final class PointerApplication extends Application
{
    /** 悬停进出计数（断言 §9.1 语义 #5：目标变化才派发） */
    public int $hoverEnters = 0;

    public int $hoverLeaves = 0;

    /** 拖放目标进出计数（`DragController` 的悬停派发 —— 拖动期独立于普通悬停） */
    public int $targetEnters = 0;

    public int $targetLeaves = 0;

    /** 放下是否发生 + 交出的负载（断言 drop 把 payload 原样交出） */
    public bool $dropped = false;

    /** @var mixed */
    public $droppedPayload = null;

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            // ① 悬停目标：40×40，y=0..40
            Padding::make(Spacer::make())->all(20)
                ->cursor(CursorShape::PointingHand)
                ->hover(function (bool $entered): void {
                    if ($entered) {
                        $this->hoverEnters++;
                    } else {
                        $this->hoverLeaves++;
                    }
                }),
            // ② 拖放源：40×40，y=40..80
            Padding::make(Spacer::make())->all(20)
                ->draggable(Draggable::make('payload-1')),
            // ③ 拖放目标：40×40，y=80..120
            Padding::make(Spacer::make())->all(20)
                ->dragTarget(
                    DragTarget::make()
                        ->onAccept(function ($payload): void {
                            $this->dropped = true;
                            $this->droppedPayload = $payload;
                        })
                        ->onHover(function (bool $entered): void {
                            if ($entered) {
                                $this->targetEnters++;
                            } else {
                                $this->targetLeaves++;
                            }
                        })
                ),
        ]);
    }
}
