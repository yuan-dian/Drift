<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * **手写订阅**的标记接口（§10.3 第 5 条 / §16.6.1 R8）。
 *
 * 为什么需要它：订阅责任**只能落在实现了本接口的控件上**（`SubjectBuilder` /
 * 用户自己写的 `StatusBar`），不能落在渲染型控件上 —— 否则"哪个类在订阅"
 * 就只剩命名约定，`dead-subscription`（A16）那条 lint 的判据
 * （"`build()` 体内出现对 `Subject` 的方法调用 ⇒ 必须实现本接口"）也就没有抓手。
 *
 * ★ **默认不需要它**（2026-11-22）：读 `Cell` / `Computed` / `AsyncCommand` 走
 *   `State` 的依赖采集，订阅由框架在 build 后统一建立 —— 那是 `Bind` 与页面
 *   `build()` 的路径，不经过本接口。只有**手写** `subscribeFor()` / `watch()`
 *   的类才要标记，本仓现在只剩 `SubjectBuilder`。
 *
 * ★ 空接口是刻意的：它是**给 lint 与读代码的人看的标记**，不是行为契约。
 *   订阅行为由 `Subject` / `State::watch()` 承载，不在这里。
 *
 * @api
 */
interface SubscribingWidget
{
}
