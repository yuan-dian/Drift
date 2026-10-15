<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 有**纯 UI 态**的控件（§11.5 / §12.3）。
 *
 * ★ 用它的判据：这个值**必须跨 build 活**、且**只影响这一棵子树**（悬停项、展开
 *   状态、编辑中的草稿）。业务数据、跨页共享、被虚拟化列表的滚动位置 ⇒ 全归
 *   Store（§11.5 / §12.3），不放这里。
 *
 * @api
 */
abstract class StatefulWidget extends Widget
{
    /**
     * 造 State。
     *
     * ★ **挂载时只调一次**。更新时 Element 复用同一个 State（否则 position 一换
     *   全丢了）—— 要"状态跟着新描述走"是 `State::didUpdateWidget()` 的事。
     */
    abstract public function createState(): State;
}
