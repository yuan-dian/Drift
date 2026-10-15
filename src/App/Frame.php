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
        $this->flush($root);

        // ② layout
        $box = $root->renderObject();
        if ($box === null) {
            return; // 结构根（例如一个纯 Column 之外还有别的）没有盒 ⇒ 无从排起
        }

        $box->layout($constraints);

        // ③ paint → ④ present
        $canvas = $this->surface->beginFrame($timestampMs);
        $box->paint($canvas);
        $this->surface->endFrame();
    }

    /**
     * 走全树，把标脏的 StatefulElement 重建掉。
     *
     * ★ **只在脏的时候 rebuild** —— 无条件全树重建就退回成"每帧重排整棵树",
     *   §13.3 的脏边界也就白做了。冒烟里有一条专门断"没脏的节点不重建"。
     *
     * ★ 递归在 `rebuild()` **之后**才进子层：`rebuild()` 刚换过 `children`
     *   （`updateChildren` 是同步的），先下去才會走进旧的子列表。
     */
    private function flush(Element $element): void
    {
        if ($element instanceof StatefulElement && $element->state()?->needsBuild()) {
            $element->rebuild(); // rebuild() 内部消费脏标记
        }

        // 普通闭包而不是箭头函数：`fn(): void => $this->flush($c)` 会报
        // "A void method must not return a value" —— 箭头函数**隐式返回**它的
        // 表达式，而 `flush()` 返回 void，不是值。
        $element->visitChildren(function (Element $child): void {
            $this->flush($child);
        });
    }
}
