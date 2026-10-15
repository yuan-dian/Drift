<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Geometry\BoxConstraints;
use Drift\Layout\RenderBox;
use Drift\Rendering\RenderSurface;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;

/**
 * 一帧：flush 脏 Element → layout → paint → present（§5.2）。
 *
 * ★ **为什么这类东西必须住 `Drift\App`**：它同时要碰 `Element`（Widget）与
 *   `CommandRouter`（Commands），而 §4.3 禁 `Widget → Commands`。放进 Widget 层
 *   就得为它再开一个协议例外 —— 而例外是**按文件**登记的，多一个文件多一条缝。
 *
 * ★ 它**不拥有循环**（§5.2 裁决 1）：`pump()` 由平台阻塞等待，帧时钟由平台驱动。
 *   本类只是"被告知该跑一帧时"把三段按顺序做一遍 —— 于是测试可以**手动**驱动
 *   任意帧序列，gif/golden 也能喂固定时间戳（G3 的物质基础）。
 *
 * @api platform
 */
final class Frame
{
    public function __construct(
        private readonly RenderSurface $surface,
    ) {
    }

    /**
     * 跑一帧。
     *
     * @param BoxConstraints $constraints 表面给出的约束（通常 = 表面尺寸的紧约束）
     * @param float $timestampMs 帧时间戳，由 `FrameClock` 注入，**不读环境时钟**（§5.2 裁决 2）
     *
     * @api
     */
    public function run(Element $root, BoxConstraints $constraints, float $timestampMs): void
    {
        // ① 帧末统一重建（§5.2 裁决 3）：派发中途的 setState 只标脏
        $root->settle();

        // ② layout
        // ★ 必须用 `effectiveBox()` 而不是 `renderObject()`：应用根常是
        //   `StatelessWidget`（§A.2 的 `UsersRoot` 就是这个形态），它自己没有盒、
        //   只有它底下的 `Column` 有。用 `renderObject()` ⇒ 拿 null ⇒ 直接返回
        //   ⇒ **整屏空白且不报错**（C6 只查上界，不画任何东西也不算违反）。
        //   这正是 G2 要消灭的静默形态。
        $box = $root->effectiveBox();
        if ($box === null) {
            return; // 整棵子树没有任何可绘制内容（例如空页）⇒ 这一帧无事可做
        }

        $box->layout($constraints);

        // ③ paint → ④ present
        $canvas = $this->surface->beginFrame($timestampMs);
        $box->paint($canvas);
        $this->surface->endFrame();
    }
}
