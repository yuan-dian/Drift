<?php
declare(strict_types=1);

namespace App;

use Drift\State\Subject;
use Drift\State\SubjectBuilder;
use Drift\State\SubscribingWidget;
use Drift\Widget\State;

/**
 * 夹具：`subscriber-mark` 的已知错误 / 已知正确（§16.6.3 #8）。
 *
 * ★ 判据（2026-10-16 收紧）：只认**自己承担订阅**的形态 `$this->select(` / `$this->watch(`。
 *   接收者不是 `$this` 的调用（`SubjectBuilder::make($s)->select(...)` / `$this->widget->select(...)`）
 *   是"配置一个专职订阅者" —— 订阅责任在**那个**对象身上，调用方不是订阅者 ⇒ 不判。
 *   ★ 本夹具必须跟着判据走：2026-10-16 判据收紧时夹具没同步 ⇒ 该规则**失去正向测试**
 *     （selftest 报 "期望 2 条、实际 0 条"）。教训与 `dead-command` 同族：**规则改了，
 *     夹具必须同轮改**，否则"有测试"是假象。
 *
 * 该响：
 * - `BadStatusBar::pick()`：`$this->select(` 发起订阅，但类没 implements SubscribingWidget
 * - `BadWatcher::listen()`：`$this->watch(` 同理（另一个动作名，同一判据）
 *
 * 不该响：
 * - `GoodStatusBar`：专职订阅者（标了接口）
 * - `WatcherState`：`State` 子树 —— `watch()` 的账簿本来就归 `State`（同 `dead-subscription`）
 * - `ConfiguredBuilder`：**委托式订阅**（§A.2.9 官方写法）—— 责任在那个专职订阅者身上
 */
final class BadStatusBar
{
    public function pick(): void
    {
        $this->select(static fn (Subject $s): string => (string) $s->value());
    }
}

final class BadWatcher
{
    public function listen(): void
    {
        $this->watch(static function (): void {
        });
    }
}

final class GoodStatusBar implements SubscribingWidget
{
    public function pick(): void
    {
        $this->select(static fn (Subject $s): string => (string) $s->value());
    }
}

final class WatcherState extends State
{
    public function initState(): void
    {
        // `State` 子树豁免：这里 `$this->watch(` 与 BadWatcher 形态完全相同，
        // 唯一区别是父类 ⇒ 这条真的在测"豁免分支"（写 `$this->source->watch(` 测不到，
        // 那个形态因接收者不是 `$this` 本来就不判）。
        $this->watch(static function (): void {
        });
    }
}

final class ConfiguredBuilder
{
    public function pick(Subject $source): SubjectBuilder
    {
        // 委托式：订阅责任在 `SubjectBuilder`（它 implements SubscribingWidget）身上
        return SubjectBuilder::make($source)
            ->select(static fn (Subject $s): string => (string) $s->value());
    }
}
