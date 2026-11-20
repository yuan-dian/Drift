<?php
declare(strict_types=1);

namespace Drift\Route;

/**
 * 副作用的值类型标记。
 *
 * 页面把自己的副作用定义成 `final readonly` 值对象（`NotifyEffect` 等）：
 * `update()` 只负责把它们放进 `RouteUpdate`，真正执行在 `RouteView::apply()`。
 *
 * ★ 为什么是标记接口，而不是 `(string kind, ?object payload)`：
 *   字符串 kind 会退化成"字符串分派"——拼错不会报错，只是静默不执行；
 *   具体值类由页面定义后，`apply()` 里的类型关系是编译期/静态检查能查的，
 *   单测也能直接断言"更新后产生了哪个 effect"。
 * ★ 为什么值里不放闭包：effect 要能被记录、比较、重放。回调一律经 `dispatch`
 *   重新走 `update()` 主路，不在副作用里就地改状态。
 *
 * @api
 */
interface RouteEffect
{
}
