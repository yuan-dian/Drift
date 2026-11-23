<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Widget\ScrollLane;

/**
 * 单轴滚动 State 的共用宿主面：一根 `ScrollLane` + 每帧推进。
 *
 * ★ 只收三个实现（ScrollView / LazyList / TextArea）逐字相同的部分：`$lane` 与每帧推进。
 *   `Scrollable` / `ScrollMetrics` 的其余方法分两层：
 *   - 查 `RenderScrollView` 的那一组进 `ScrollViewHost`（TextArea 的盒是
 *     `RenderTextArea`，进来会白得两个恒零读数）；
 *   - `scrollBy()` 按各自盒类型夹取，留在各类。
 * ★ 宿主必须自己声明 `private ?ScrollLane $lane`（本 trait 只读写、不声明），
 *   并实现 `Scrollable::scrollBy()` —— tick 的位移仍走那一条夹取路。
 *
 * @internal
 */
trait SingleAxisScrollHost
{
    /** 这根轴的滚动状态机（懒建：从没滚过的容器不建） */
    protected function lane(): ScrollLane
    {
        $lane = $this->lane;
        if ($lane === null) {
            $lane = new ScrollLane(0.0, null, $this);
            $this->lane = $lane;
        }

        return $lane;
    }

    /**
     * 推进这一帧的滚动动画与滚动条淡出（先滚动、后淡出）。
     * 位移交给宿主的 `scrollBy()` ⇒ 夹取/换窗/锚点/提交编辑复用既有那一条路。
     */
    public function tickFrame(float $timestampMs): bool
    {
        $lane = $this->lane();

        $busy = $lane->tick($timestampMs, function (float $delta): void {
            $this->scrollBy($delta);
        });

        if ($lane->fade()->tickFor($this, $timestampMs)) {
            $busy = true;
        }

        return $busy;
    }
}
