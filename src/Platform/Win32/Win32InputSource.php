<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Input\InputEvent;
use Drift\Platform\InputSource;

/**
 * Win32 的输入队列（§9.1 路由链的输入侧）。
 *
 * ★ **它不做归一化** —— 归一化发生在桥里（`WM_KEYDOWN` → 键名 + 修饰键位，
 *   见 `cpp-src/win32/drift_win32.cc::keyNameFromVk`），这里只做**入队/出队**。
 *   把 VK 表搬到 PHP 就是把平台常量漏进上层（§5.1 纪律 1）。
 *
 * ★ **队列而不是单个"最近事件"**：一帧里可能积累多条（快速连按、一次
 *   `WM_SIZE` 后紧跟一次点击），单槽会让前一条**静默消失**。队列无上界是因为
 *   生产者是消息泵：它一次只投一条、消费者一帧抽干一次 ⇒ 长度天然被
 *   "一帧能产生多少输入"限住。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\InputSource`
 */
final class Win32InputSource implements InputSource
{
    /** @var list<InputEvent> */
    private array $queue = [];

    public function poll(): ?InputEvent
    {
        $event = array_shift($this->queue);

        return $event instanceof InputEvent ? $event : null;
    }

    public function push(InputEvent $event): void
    {
        $this->queue[] = $event;
    }

    /** 队列长度（自检用：断言"一帧抽干"时数得出来） */
    public function pending(): int
    {
        return count($this->queue);
    }
}
