<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * 订阅责任的**标记接口**（§10.3 第 5 条 / §16.6.1 R8）。
 *
 * 为什么需要它：订阅责任**只能落在实现了本接口的控件上**（`SubjectBuilder` /
 * `PageHost` / 用户写的 `StatusBar`），不能落在渲染型控件上 —— 否则"哪个类在
 * 订阅"就只剩命名约定，`dead-subscription`（A16）那条 lint 的判据
 * （"`build()` 体内出现对 `Subject` 的方法调用 ⇒ 必须实现本接口"）也就没有抓手。
 *
 * ★ 空接口是刻意的：它是**给 lint 与读代码的人看的标记**，不是行为契约。
 *   订阅行为由 `Subject` / `State::watch()` 承载，不在这里。
 *
 * @api
 */
interface SubscribingWidget
{
}
