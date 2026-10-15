<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 一次指针事件（§9.1 路由链：`PointerEvent → 命中测试（RenderObject 树，从前到后）`）。
 *
 * 坐标是**逻辑单位**（§6.1），相对**视口左上角** —— 与 `RenderBox::hitTest()`
 * 同一坐标系（物理像素是 `Platform` 层的事，不进这一层）。
 *
 * ★ 今天只有**按下**（`down()`）—— 它足够驱动"点一下 ⇒ 给焦点"这条闭环；
 *   移动 / 抬起 / 滚动留给 M4 的完整输入层（那时才需要 `GestureArena` 消歧）。
 *   与 `KeyEvent` 同一条纪律：**不预先发明没消费者的相位**。
 *
 * @api
 */
final readonly class PointerEvent
{
    private function __construct(
        public float $x,
        public float $y,
        public int $button,
    ) {
    }

    /** `$button`：0 = 主键（左键），1 = 中键，2 = 右键（§9.1 上下文菜单走右键手势） */
    public static function down(float $x, float $y, int $button = 0): self
    {
        return new self($x, $y, $button);
    }

    public function isPrimary(): bool
    {
        return $this->button === 0;
    }
}
