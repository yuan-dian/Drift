<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Input\InputEvent;

/**
 * 原始事件泵：平台只负责把 Win32 MSG 归一化，不负责路由。
 *
 * `waitEvents()` 阻塞等待，`pumpEvents()` 归一化后 `push()`，EventLoop 一帧抽干。
 * PHP 侧不持有 Win32 常量、不做 wParam 位运算。
 *
 * @api platform
 */
interface InputSource
{
    /**
     * 取一条已归一化事件；队列空 ⇒ null（非阻塞）。
     * "这一帧没事件"是正常状态，用 null 而不是抛，不许调用方空转等待。
     */
    public function poll(): ?InputEvent;

    /**
     * 入队一条事件（平台实现调用；暴露在接口上是为了测试合成事件序列）。
     */
    public function push(InputEvent $event): void;
}
