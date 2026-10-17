<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 指针事件的相位（2026-10-17 随 move/up/leave 通路落地，§9.1）。
 *
 * **为什么相位是枚举而不是四个事件类**：move/up/leave 与 down **同坐标系、
 * 同命中链、同三方消费**（一次命中同时喂光标 / 焦点 / 拖放）—— 它们是"指针
 * 事件的不同相位"，不是不同种类。对照 `ScrollEvent` 的反面决定：滚轮无相位、
 * 不参与焦点与光标 ⇒ 才独立成类。
 *
 * ★ **`value` 就是桥的 ABI 编号**（`PendingEvent.d`，见
 *   `cpp-src/drift_win32.cc`）—— 与 `CursorShape` 同一条纪律：改这里的 value
 *   等于改 ABI，必须与桥同步。
 *
 * @api
 */
enum PointerPhase: int
{
    /** 按下（左/中/右键，`PointerEvent::$button` 区分） */
    case Down = 0;

    /** 移动（含按住键的移动 = 拖动过程） */
    case Move = 1;

    /** 抬起（与 down 同键配对） */
    case Up = 2;

    /**
     * 指针离开客户区（`TrackMouseEvent` + `WM_MOUSELEAVE`）。
     *
     * ★ 载荷坐标是**最后已知位置**（系统不再给新坐标）—— 消费方只看"离开了"，
     *   不用坐标。
     */
    case Leave = 3;
}
