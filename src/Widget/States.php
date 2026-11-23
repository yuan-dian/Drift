<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 「Element 有没有 State、是什么」的**唯一**穿透点（S1 第 2 步）。
 *
 * ★ 存在的理由只有一条：帧遍历（`tickAnimations`）、命令 bubbling、滚动路由、
 *   焦点同步（`didChangeFocus`）都要"沿链逐个问 Element 的 State" —— 没有这个
 *   工具时每处都写一遍 `instanceof StatefulElement` + `->state()`，该判据散几份
 *   就会有几份不同的漏法（`state()` 可空 / 非 StatefulElement 节点）。
 *
 * ★ 它不引入新知识：`StatefulElement` 与 `State` 的配对规则本来就住在 Element
 *   树里，这里只把"穿透"收成一处 —— `FocusManager` 的私有 `stateOf()` 同源，
 *   已改调本类（知识单一来源）。
 *
 * @api
 */
final class States
{
    /** @return State|null 非 `StatefulElement`（或无 State）⇒ null */
    public static function of(?Element $element): ?State
    {
        return $element instanceof StatefulElement ? $element->state() : null;
    }
}
