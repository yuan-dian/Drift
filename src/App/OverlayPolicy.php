<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 浮层条目的交互策略：这一层对外界是什么态度。
 *
 * 只回答"拦不拦、关不关、管不管焦点"，不碰落位（`prefer` / `atX` / `maxWidth` /
 * `inset` 仍在条目上）—— 那是几何，与"对外态度"是两条正交的轴。同 Compose 把
 * `PopupProperties` 与 `PopupPositionProvider` 分开，而不是 Qt 那样塞进一组 window flags。
 *
 * ★ 七个开关是**正交**的，没有哪个能由别的推出来：`tooltip` 与 `snackbar` 只差
 *   "参不参与命中"（提示抢走悬停目标会显隐死循环，通知的动作按钮要能点）。
 * ★ 具名预设是唯一入口：入栈方声明形态，`OverlayEntry` 只记录。裸布尔逐个赋值的
 *   写法会写出"拦层外但点外不关"这类半套状态，而那种状态没有任何合法用途。
 */
final readonly class OverlayPolicy
{
    private function __construct(
        private bool $blocksOutsidePointer,
        private bool $paintsScrim,
        private bool $dismissesOnTapOutside,
        private bool $dismissesOnEsc,
        private bool $trapsFocus,
        private bool $participatesInHitTest,
        private bool $restoresFocusOnClose,
    ) {
    }

    /**
     * 轻量弹出层：菜单、右键菜单、控件下拉面板。
     *
     * 三者行为完全一致 —— 拦层外、无遮罩、点外与 Esc 都关、不困焦点、关掉回锚。
     * 分开命名只会长出三个内容相同的工厂，故共用本预设。
     */
    public static function popup(): self
    {
        return new self(
            blocksOutsidePointer: true,
            paintsScrim: false,
            dismissesOnTapOutside: true,
            dismissesOnEsc: true,
            trapsFocus: false,
            participatesInHitTest: true,
            restoresFocusOnClose: true,
        );
    }

    /**
     * 提示：不拦、不关、不参与命中、不抢焦点。
     *
     * `participatesInHitTest = false` 是硬要求：提示一出现在指针附近就会命中自己 ⇒
     * 锚点悬停丢失 ⇒ 隐藏 ⇒ 又命中锚点 ⇒ 再显示，无限闪。
     */
    public static function tooltip(): self
    {
        return new self(
            blocksOutsidePointer: false,
            paintsScrim: false,
            dismissesOnTapOutside: false,
            dismissesOnEsc: false,
            trapsFocus: false,
            participatesInHitTest: false,
            restoresFocusOnClose: false,
        );
    }

    /** 通知条：与提示同为"不拦不关"，但动作按钮要能点 ⇒ 参与命中。 */
    public static function snackbar(): self
    {
        return new self(
            blocksOutsidePointer: false,
            paintsScrim: false,
            dismissesOnTapOutside: false,
            dismissesOnEsc: false,
            trapsFocus: false,
            participatesInHitTest: true,
            restoresFocusOnClose: false,
        );
    }

    /**
     * 模态：盖满 + 遮罩 + 困焦点，关掉回锚。
     *
     * @param bool $dismissible 用户动作（点遮罩 / Esc）能不能关；应用显式关不受它限制
     */
    public static function modal(bool $dismissible): self
    {
        return new self(
            blocksOutsidePointer: true,
            paintsScrim: true,
            dismissesOnTapOutside: $dismissible,
            dismissesOnEsc: $dismissible,
            trapsFocus: true,
            participatesInHitTest: true,
            restoresFocusOnClose: true,
        );
    }

    /**
     * 同一形态但点外穿透（`WS_EX_TRANSPARENT` 那半）。
     *
     * 目前只有冒烟夹具用它造"点外面能穿到下面控件"的场景；真实控件没有这个形态。
     */
    public function withoutPointerBarrier(): self
    {
        return new self(
            blocksOutsidePointer: false,
            paintsScrim: $this->paintsScrim,
            dismissesOnTapOutside: $this->dismissesOnTapOutside,
            dismissesOnEsc: $this->dismissesOnEsc,
            trapsFocus: $this->trapsFocus,
            participatesInHitTest: $this->participatesInHitTest,
            restoresFocusOnClose: $this->restoresFocusOnClose,
        );
    }

    /** 层外的指针事件拦不拦（`false` ⇒ 穿透到下面的层与主树）。 */
    public function blocksOutsidePointer(): bool
    {
        return $this->blocksOutsidePointer;
    }

    /** 画不画遮罩。与"拦不拦"是两个参数：遮罩是视觉，拦截是命中。 */
    public function paintsScrim(): bool
    {
        return $this->paintsScrim;
    }

    /**
     * 点层外关不关。
     *
     * ★ 只在 `blocksOutsidePointer()` 为真时有意义：穿透的层永远轮不到这一支。
     */
    public function dismissesOnTapOutside(): bool
    {
        return $this->dismissesOnTapOutside;
    }

    /** Esc 关不关。`false` ⇒ 吞掉该键但不关层（不是"放行给加速键"）。 */
    public function dismissesOnEsc(): bool
    {
        return $this->dismissesOnEsc;
    }

    /** Tab 序要不要被限制在本层内。 */
    public function trapsFocus(): bool
    {
        return $this->trapsFocus;
    }

    /** 指针命中测试看不看得见这一层。 */
    public function participatesInHitTest(): bool
    {
        return $this->participatesInHitTest;
    }

    /** 关掉后要不要把焦点还回入栈前那个节点。 */
    public function restoresFocusOnClose(): bool
    {
        return $this->restoresFocusOnClose;
    }

    /**
     * 是不是"轻量弹出层开着"（`isMenuOpen()` 的判据）。
     *
     * 拦层外且无遮罩 —— 菜单、下拉、右键菜单都在内，模态因有遮罩被排除。
     * 问的是"有没有东西在替用户挡着下面"，不是"栈顶是不是菜单"。
     */
    public function isLightPopup(): bool
    {
        return $this->blocksOutsidePointer && !$this->paintsScrim;
    }
}
