<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 手势竞技场（§9.1 手势消歧，与 Flutter 同口径）。
 *
 * 一次指针按下 = 一个 arena，成员（识别器）竞争同一个手势：
 * - `move` 过阈值：第一个 `claims()` 为真的成员**主动胜出**，其余让位；
 * - `up` 时无人 claim：**第一个成员**胜（默认胜者 —— 故接线须把 `Tap` 放在首位）；
 * - `leave`：关闭，**不触发任何人**（拖出窗口即取消，无 `SetCapture` 的诚实边界）。
 *
 * ★ 识别器是"无状态判定函数 + 成员资格"（见 `GestureRecognizer`）⇒ 本类不持有
 *   任何控件 / 盒，只按位移裁决 ⇒ 零平台改动、可头侧断言。
 * ★ 成员顺序即默认胜者顺序：`up` 无人 claim 时取 `members[0]`。
 *
 * @internal 实现细节；消费方是 `Application::dispatchPointer()`。
 */
final class GestureArena
{
    /** 拖动阈值（**逻辑**像素，平方比较免开方）—— Windows `SM_CXDRAG` 同量级 */
    public const DRAG_THRESHOLD_PX = 6.0;

    /** @var list<GestureRecognizer> */
    private array $members = [];

    private float $originX;

    private float $originY;

    private bool $closed = false;

    public function __construct(float $x, float $y)
    {
        $this->originX = $x;
        $this->originY = $y;
    }

    /** 登记一个成员（顺序即默认胜者优先级；arena 关闭后忽略） */
    public function add(GestureRecognizer $recognizer): void
    {
        if (!$this->closed) {
            $this->members[] = $recognizer;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** 指针移动：第一个主动 claim 的成员胜出，其余让位（arena 随之关闭） */
    public function move(float $x, float $y): void
    {
        if ($this->closed) {
            return;
        }

        $dx = $x - $this->originX;
        $dy = $y - $this->originY;

        foreach ($this->members as $recognizer) {
            if ($recognizer->claims($x, $y, $dx, $dy)) {
                $this->closed = true;
                $recognizer->win($x, $y);

                return;
            }
        }
    }

    /** 指针抬起：无人 claim ⇒ 第一个成员胜（默认胜者） */
    public function up(float $x, float $y): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->members !== []) {
            $this->members[0]->win($x, $y);
        }
    }

    /** 指针离开客户区 / ESC：关闭，不触发任何人 */
    public function leave(): void
    {
        $this->closed = true;
    }
}
