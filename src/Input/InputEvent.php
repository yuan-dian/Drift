<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 一条**已归一化的平台事件**（§9.1 路由链的输入侧）。
 *
 * ★ 它是 `Platform::input()` 队列的元素类型，只回答一件事："这是**哪一类**事件"
 *   —— 于是 `InputSource::poll()` 能有一个**单一**返回类型（`?InputEvent`），
 *   而不用联合类型：AOT 下联合类型的支持面比单一类型窄，而这里**不需要**它
 *   （消费者一律先按类型分支，再取具体字段）。
 *
 * ★ **它是标记接口，不带方法**：事件的具体字段（坐标 / 按键 / 文本）语义差别太大，
 *   抽成公共方法只能抽出"type()"这种与 `instanceof` 重复的东西。将来若真需要
 *   统一遍历（例如 devtools 的事件录制），再补方法 —— 今天没有那个消费者（A15）。
 *
 * ★ 实现者是**值对象**（`final readonly`），不是平台句柄的包装：平台在
 *   `Platform::pumpEvents()` 里就把 Win32 `MSG` 翻成这些对象，PHP 侧因此不持有任何
 *   Win32 常量、不做 `wParam` 位运算（§9.1）。
 *
 * ```php
 * while (($event = $source->poll()) !== null) {
 *     if ($event instanceof KeyEvent)     { $focus->dispatchKey($event); }
 *     if ($event instanceof PointerEvent) { $focus->dispatchPointer($event); }
 * }
 * ```
 *
 * @api
 */
interface InputEvent
{
}
