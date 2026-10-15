<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Geometry\Rect;

/**
 * IME 协议（§8.4）：编辑器实现，平台侧桥接。
 *
 * ★ GUI 仓实测的硬约束：第三方输入法（搜狗 / QQ 拼音）靠 `GetGUIThreadInfo`
 *   取**插入符矩形**来定位候选窗 —— **自绘窗口不登记 ⇒ 候选框永不出现**。
 *   所以 `caretRect()` 不是可选项，是 IME 能用的前提。
 *
 * ★ IME 定位一律用**光标行几何**；焦点判据用 `focusedElementId`，
 *   不用可能过期的副本（§8.4）。
 *
 * @api extension
 */
interface TextInputClient
{
    /** 上报编辑态：文本 + 选区 + 组合区间（组合区间为空 = 不在组字中） */
    public function setEditingState(
        string $text,
        int $selectionStart,
        int $selectionEnd,
        int $composingStart,
        int $composingEnd,
    ): void;

    /** 插入符矩形（**窗口坐标**）—— 平台 IME 用它定位候选窗 */
    public function caretRect(): Rect;

    /** 用户确认输入（IME 提交 / 直接键入） */
    public function commit(string $text): void;

    /** 取消当前组合（Esc / 失焦） */
    public function cancelComposition(): void;

    /** 撤销回写：平台撤销栈要求把文本还原回去 */
    public function restore(string $text, int $selectionStart, int $selectionEnd): void;
}
