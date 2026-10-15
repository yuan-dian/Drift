<?php
declare(strict_types=1);

namespace App;

use Drift\State\Subject;
use Drift\State\SubscribingWidget;
use Drift\Widget\State;

/**
 * 夹具：`subscriber-mark` 的已知错误 / 已知正确（§16.6.3 #8）。
 *
 * 该响：
 * - `BadStatusBar::pick()`：`->select(` 发起订阅，但类没 implements SubscribingWidget
 * - `BadWatcher::listen()`：`->watch(` 同理（另一个动作名，同一判据）
 *
 * 不该响：
 * - `GoodStatusBar`：专职订阅者（标了接口）
 * - `WatcherState`：`State` 子树 —— `watch()` 的账簿归 `State`，与 `dead-subscription` 同理由
 */
final class BadStatusBar
{
    public function pick(Subject $source): void
    {
        $source->select(static fn (Subject $s): string => (string) $s->value());
    }
}

final class BadWatcher
{
    public function listen(Subject $source): void
    {
        $source->watch(static function (): void {
        });
    }
}

final class GoodStatusBar implements SubscribingWidget
{
    public function pick(Subject $source): void
    {
        $source->select(static fn (Subject $s): string => (string) $s->value());
    }
}

final class WatcherState extends State
{
    public function initState(): void
    {
        $this->source->watch(static function (): void {
        });
    }
}
